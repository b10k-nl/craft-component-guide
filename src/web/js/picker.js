/* Component Guide — Matrix block picker.
 *
 * Enhances every Matrix field's "New Block" UI with a "Blocks gallery" button
 * that opens a visual catalog. Cards are matched to entry types by handle:
 * a component whose template base name equals the entry type handle (e.g.
 * statsBar.twig ↔ statsBar) gets a live thumbnail; other types get a plain
 * tile. Picking a card clicks the NATIVE menu item for that type, so block
 * creation goes through Matrix's own code path.
 */
(function () {
    'use strict';

    var THUMB_VIEWPORT = 1280; // px — matches the guide's index thumbnails
    var mapPromise = null;

    var loadMap = function () {
        if (!mapPromise) {
            mapPromise = fetch(Craft.getCpUrl('component-guide/picker-map'), {
                headers: { 'Accept': 'application/json' },
            }).then(function (r) {
                return r.ok ? r.json() : { components: [] };
            }).catch(function () {
                return { components: [] };
            });
        }
        return mapPromise;
    };

    // --- Native "New Block" item discovery -------------------------------

    // Returns [{type, label, el}] for every addable entry type of a Matrix
    // field: the single-type add button, or the items of its disclosure
    // menu(s) (which Garnish appends to <body>, linked via aria-controls).
    //
    // Scoped STRICTLY to the field's bottom `.buttons` row — each block also
    // has its own action menu with `data-type` items ("Add … above"), and
    // clicking those would insert mid-list. The gallery always appends to the
    // end; reordering stays Craft's own drag-and-drop job.
    var collectTypeItems = function (field) {
        var items = [];
        var seen = {};
        var zone = field.querySelector(':scope > .buttons');
        if (!zone) { return items; }

        var push = function (el, label) {
            var type = el.getAttribute('data-type');
            if (type && !seen[type]) {
                seen[type] = true;
                items.push({ type: type, label: label || el.textContent.trim(), el: el });
            }
        };

        zone.querySelectorAll('button.add[data-type], button.dashed[data-type]').forEach(function (el) {
            push(el);
        });

        zone.querySelectorAll('button[aria-controls]').forEach(function (btn) {
            var menu = document.getElementById(btn.getAttribute('aria-controls'));
            if (menu) {
                menu.querySelectorAll('[data-type]').forEach(function (el) {
                    push(el);
                });
            }
        });

        return items;
    };

    // --- Gallery panel ------------------------------------------------------
    // A docked right-hand panel (not a modal): it stays open so an editor can
    // compose a whole page — every card click appends one more block to the
    // field. No backdrop, the page stays interactive.

    var activePanel = null;

    var closePanel = function () {
        if (activePanel) {
            activePanel.el.remove();
            document.removeEventListener('keydown', activePanel.onKey);
            activePanel = null;
        }
    };

    var openPanel = function (field, comps) {
        closePanel();

        var panel = document.createElement('div');
        panel.className = 'cg-picker-panel';
        panel.setAttribute('role', 'complementary');
        panel.setAttribute('aria-label', Craft.t('component-guide', 'Blocks gallery'));

        var head = document.createElement('div');
        head.className = 'cg-picker-panel__head';
        head.innerHTML = '<strong>' + Craft.t('component-guide', 'Blocks gallery') + '</strong>'
            + '<span class="cg-picker-panel__hint">' + Craft.t('component-guide', 'Click a block to add it') + '</span>';
        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'cg-picker-panel__close';
        closeBtn.setAttribute('aria-label', Craft.t('app', 'Close'));
        closeBtn.innerHTML = '&times;';
        head.appendChild(closeBtn);

        var searchWrap = document.createElement('div');
        searchWrap.className = 'cg-picker-search';
        var search = document.createElement('input');
        search.type = 'search';
        search.className = 'text fullwidth';
        search.placeholder = Craft.t('component-guide', 'Search blocks…');
        searchWrap.appendChild(search);

        var grid = document.createElement('div');
        grid.className = 'cg-picker-grid';

        search.addEventListener('input', function () {
            var term = search.value.trim().toLowerCase();
            grid.querySelectorAll('.cg-picker-card').forEach(function (card) {
                card.hidden = term !== '' && (card.dataset.search || '').indexOf(term) === -1;
            });
        });

        var onKey = function (e) {
            if (e.key === 'Escape') { closePanel(); }
        };

        // Fit the thumbnail to the component's real rendered height — a short
        // strip (stats bar) shouldn't sit in a tall empty box. Same-origin, so
        // the preview document's height is readable after load.
        var sizeThumb = function (iframe) {
            var thumb = iframe.parentElement;
            var w = thumb.clientWidth;
            if (!w) { return; }
            var s = w / THUMB_VIEWPORT;
            var h = 800;
            try {
                h = Math.max(iframe.contentDocument.body.scrollHeight, 80);
            } catch (e) { /* keep the default */ }
            h = Math.min(h, 900);
            iframe.style.height = h + 'px';
            iframe.style.transform = 'scale(' + s + ')';
            thumb.style.aspectRatio = 'auto';
            thumb.style.height = Math.ceil(h * s) + 'px';
        };

        collectTypeItems(field).forEach(function (item) {
            var comp = comps[item.type];

            var card = document.createElement('button');
            card.type = 'button';
            card.className = 'cg-picker-card';
            card.dataset.search = [
                item.type,
                item.label,
                comp ? comp.title : '',
                comp && comp.description ? comp.description : '',
            ].join(' ').toLowerCase();

            // Only components get a thumbnail — a big empty placeholder says
            // nothing, so bare entry types collapse to icon + title.
            if (comp && comp.previewUrl) {
                var thumb = document.createElement('span');
                thumb.className = 'cg-picker-card__thumb';
                var iframe = document.createElement('iframe');
                iframe.src = comp.previewUrl;
                iframe.loading = 'lazy';
                iframe.tabIndex = -1;
                iframe.setAttribute('title', '');
                iframe.addEventListener('load', function () { sizeThumb(iframe); });
                thumb.appendChild(iframe);
                card.appendChild(thumb);
            }

            var body = document.createElement('span');
            body.className = 'cg-picker-card__body';

            // Reuse the entry type's own icon from the native menu item.
            var iconSvg = item.el.querySelector('svg');
            if (iconSvg) {
                var icon = document.createElement('span');
                icon.className = 'cg-picker-card__icon';
                icon.setAttribute('aria-hidden', 'true');
                icon.appendChild(iconSvg.cloneNode(true));
                body.appendChild(icon);
            }

            var title = document.createElement('span');
            title.className = 'cg-picker-card__title';
            title.textContent = comp ? comp.title : item.label;
            body.appendChild(title);
            if (comp && comp.status) {
                var chip = document.createElement('span');
                chip.className = 'cg-chip cg-chip--status cg-chip--' + comp.status;
                chip.textContent = comp.status;
                body.appendChild(chip);
            }
            if (comp && comp.description) {
                var desc = document.createElement('span');
                desc.className = 'cg-picker-card__desc';
                desc.textContent = comp.description;
                body.appendChild(desc);
            }
            card.appendChild(body);

            card.addEventListener('click', function () {
                // Entering Live Preview re-renders the editor, so every DOM
                // reference from panel-build time may be stale. Re-resolve the
                // LIVE field by id, then go straight to Matrix's own input
                // instance (stored via jQuery data on the container) — clicking
                // a stale menu item crashes MatrixInput on an unknown handle.
                var liveField = field.isConnected
                    ? field
                    : (field.id ? document.getElementById(field.id) : null);
                if (!liveField) { closePanel(); return; }

                var matrix = window.jQuery ? window.jQuery(liveField).data('matrix') : null;
                var added = false;

                if (matrix && matrix.entryTypesByHandle && matrix.entryTypesByHandle[item.type]
                    && typeof matrix.addEntry === 'function') {
                    matrix.addEntry(item.type); // appends at the end
                    added = true;
                } else {
                    // Fallback: click the live field's native menu item.
                    var fresh = collectTypeItems(liveField).find(function (it) { return it.type === item.type; });
                    if (fresh && fresh.el.isConnected) {
                        fresh.el.click();
                        added = true;
                    }
                }

                if (added) {
                    card.classList.remove('is-added');
                    void card.offsetWidth; // restart the animation
                    card.classList.add('is-added');
                }
            });

            grid.appendChild(card);
        });

        panel.appendChild(head);
        panel.appendChild(searchWrap);
        panel.appendChild(grid);
        document.body.appendChild(panel);
        search.focus();
        activePanel = { el: panel, onKey: onKey };

        // Scale thumbnails once cards have a layout size.
        requestAnimationFrame(function () {
            grid.querySelectorAll('.cg-picker-card__thumb iframe').forEach(function (iframe) {
                var w = iframe.parentElement.clientWidth;
                if (w > 0) {
                    iframe.style.transform = 'scale(' + (w / THUMB_VIEWPORT) + ')';
                }
            });
        });

        closeBtn.addEventListener('click', closePanel);
        document.addEventListener('keydown', onKey);
    };

    // --- Field enhancement --------------------------------------------------

    var enhance = function (field) {
        if (field.dataset.cgPicker) { return; }
        field.dataset.cgPicker = '1';

        var items = collectTypeItems(field);
        if (!items.length) { return; }

        loadMap().then(function (data) {
            var comps = {};
            (data.components || []).forEach(function (c) { comps[c.name] = c; });

            // Only offer the gallery where it adds value: at least one entry
            // type has a matching component.
            if (!items.some(function (it) { return comps[it.type]; })) { return; }

            // The "New Block" UI always lives in the field's .buttons row.
            var anchor = field.querySelector(':scope > .buttons') || field.querySelector('.buttons');
            if (!anchor) { return; }

            // NO `btn` class: MatrixInput binds its add-entry handler to every
            // `.btn:not(.menubtn)` inside `.buttons` (re-running on re-init, e.g.
            // entering Live Preview) — a captured button without data-type then
            // crashes it. Styled to match via our own class instead.
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cg-picker-open';
            btn.textContent = Craft.t('component-guide', 'Blocks gallery');
            btn.addEventListener('click', function () {
                openPanel(field, comps);
            });

            anchor.appendChild(btn);
        });
    };

    var scan = function (root) {
        (root || document).querySelectorAll('.matrix-field').forEach(enhance);
    };

    var init = function () {
        scan(document);
        // Matrix fields can appear later (slideouts, lazy tabs).
        new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) {
                        if (node.classList && node.classList.contains('matrix-field')) {
                            enhance(node);
                        } else if (node.querySelectorAll) {
                            scan(node);
                        }
                    }
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
