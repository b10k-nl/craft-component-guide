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
    var GROUP_COOKIE = 'cg-picker-grouped';
    var mapPromise = null;

    var isGroupingEnabled = function () {
        var m = document.cookie.match(/(?:^|;\s*)cg-picker-grouped=([^;]*)/);
        return m ? m[1] === '1' : true; // grouped by default
    };

    var setGroupingEnabled = function (on) {
        document.cookie = GROUP_COOKIE + '=' + (on ? '1' : '0')
            + '; path=/; max-age=31536000; samesite=lax';
    };

    // Collapsed group labels, persisted as a JSON array in a cookie.
    var COLLAPSED_COOKIE = 'cg-picker-collapsed';

    var getCollapsedGroups = function () {
        var m = document.cookie.match(/(?:^|;\s*)cg-picker-collapsed=([^;]*)/);
        if (!m) { return {}; }
        try {
            var map = {};
            JSON.parse(decodeURIComponent(m[1])).forEach(function (label) {
                map[label] = true;
            });
            return map;
        } catch (e) {
            return {};
        }
    };

    var setCollapsedGroups = function (map) {
        var labels = Object.keys(map).filter(function (k) { return map[k]; });
        document.cookie = COLLAPSED_COOKIE + '=' + encodeURIComponent(JSON.stringify(labels))
            + '; path=/; max-age=31536000; samesite=lax';
    };

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

        var groupToggle = document.createElement('label');
        groupToggle.className = 'cg-picker-group-toggle';
        var groupCheckbox = document.createElement('input');
        groupCheckbox.type = 'checkbox';
        groupCheckbox.checked = isGroupingEnabled();
        groupToggle.appendChild(groupCheckbox);
        groupToggle.appendChild(document.createTextNode(Craft.t('component-guide', 'Group')));
        searchWrap.appendChild(groupToggle);

        var grid = document.createElement('div');
        grid.className = 'cg-picker-grid';

        var applyFilter = function () {
            var term = search.value.trim().toLowerCase();
            // While a term is active, collapsed groups open up visually so
            // matches are never hidden; clearing restores the collapse state.
            grid.classList.toggle('is-searching', term !== '');
            grid.querySelectorAll('.cg-picker-card').forEach(function (card) {
                card.hidden = term !== '' && (card.dataset.search || '').indexOf(term) === -1;
            });
            // A group with every card filtered out disappears, heading included.
            grid.querySelectorAll('.cg-picker-group').forEach(function (group) {
                group.hidden = !group.querySelector('.cg-picker-card:not([hidden])');
            });
        };
        search.addEventListener('input', applyFilter);

        var onKey = function (e) {
            // While a Craft overlay is open the panel sits behind it (see
            // trackOverlays) — Escape belongs to the overlay then, and
            // Garnish will close it; closing the hidden panel too would be
            // surprising.
            if (e.key === 'Escape' && !document.body.classList.contains('cg-overlay-open')) {
                closePanel();
            }
        };

        // Fit the thumbnail to the component's real rendered height — a short
        // strip (stats bar) shouldn't sit in a tall empty box. Same-origin, so
        // the preview document's height is readable after load.
        var sizeThumb = function (iframe) {
            var thumb = iframe.parentElement;
            var w = thumb.clientWidth;
            if (!w) { return; }
            // Re-inserting an iframe reloads it, and the load event can fire
            // for the intermediate about:blank document — measuring that would
            // collapse the thumb to the 80px floor. Keep the last good size
            // and wait for the real document's load.
            try {
                if (iframe.contentDocument
                    && iframe.contentDocument.location.href === 'about:blank') {
                    return;
                }
            } catch (e) { /* cross-origin — measure below via the fallback */ }
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

        // Re-measure every thumbnail whose preview document is ready — used
        // after re-layout (grouping toggle), where widths may change and, on
        // browsers without moveBefore(), reloaded iframes need a re-fit.
        var resizeThumbs = function () {
            requestAnimationFrame(function () {
                grid.querySelectorAll('.cg-picker-card__thumb iframe').forEach(function (iframe) {
                    try {
                        if (iframe.contentDocument
                            && iframe.contentDocument.readyState === 'complete') {
                            sizeThumb(iframe);
                        }
                    } catch (e) { /* not readable yet — load listener will fit it */ }
                });
            });
        };

        var buildCard = function (item, comp) {
            // Craft's own card classes do the styling (border, titlebar,
            // radius); cg-* classes only add picker behavior on top.
            var card = document.createElement('button');
            card.type = 'button';
            card.className = 'card cg-picker-card';
            card.dataset.search = [
                item.type,
                item.label,
                comp ? comp.title : '',
                comp && comp.description ? comp.description : '',
            ].join(' ').toLowerCase();

            // Native card titlebar, same markup as Cp::elementCardHtml().
            var head = document.createElement('div');
            head.className = 'card-titlebar';
            var headFlex = document.createElement('div');
            headFlex.className = 'flex flex-nowrap flex-gap-s';

            // Reuse the entry type's icon from the native menu item; carry its
            // color class over so Craft tints it exactly like the block does.
            var nativeIcon = item.el.querySelector('span.icon, .cp-icon');
            var nativeSvg = nativeIcon && nativeIcon.querySelector('svg');
            if (nativeSvg) {
                var icon = document.createElement('div');
                icon.className = 'cp-icon small';
                nativeIcon.classList.forEach(function (cls) {
                    if (cls !== 'icon' && cls !== 'cp-icon') {
                        icon.classList.add(cls);
                    }
                });
                icon.setAttribute('aria-hidden', 'true');
                icon.appendChild(nativeSvg.cloneNode(true));
                headFlex.appendChild(icon);
            }

            var title = document.createElement('div');
            title.className = 'card-titlebar-label';
            title.textContent = comp ? comp.title : item.label;
            headFlex.appendChild(title);
            if (comp && comp.status) {
                var chip = document.createElement('span');
                chip.className = 'cg-chip cg-chip--status cg-chip--' + comp.status;
                chip.textContent = comp.status;
                headFlex.appendChild(chip);
            }
            head.appendChild(headFlex);
            card.appendChild(head);

            // Description + thumbnail in a native card body under the titlebar.
            // Bare entry types (no matching component) collapse to the bar.
            if (comp && (comp.description || comp.previewUrl)) {
                var main = document.createElement('div');
                main.className = 'card-main';
                var content = document.createElement('div');
                content.className = 'card-content';
                var body = document.createElement('div');
                body.className = 'card-body';

                if (comp.description) {
                    var desc = document.createElement('div');
                    desc.className = 'cg-picker-card__desc';
                    desc.textContent = comp.description;
                    body.appendChild(desc);
                }

                if (comp.previewUrl) {
                    var thumb = document.createElement('span');
                    thumb.className = 'cg-picker-card__thumb';
                    var iframe = document.createElement('iframe');
                    iframe.src = comp.previewUrl;
                    iframe.loading = 'lazy';
                    iframe.tabIndex = -1;
                    iframe.setAttribute('title', '');
                    iframe.addEventListener('load', function () { sizeThumb(iframe); });
                    thumb.appendChild(iframe);
                    body.appendChild(thumb);
                }

                content.appendChild(body);
                main.appendChild(content);
                card.appendChild(main);
            }

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

            return card;
        };

        // Build every card once, in native menu order — grouping only decides
        // the layout, so toggling re-parents the same nodes.
        var entries = collectTypeItems(field).map(function (item) {
            var comp = comps[item.type];
            return { comp: comp, card: buildCard(item, comp) };
        });

        // Re-parent without reloading: appendChild on a connected iframe
        // resets its document (and the load event can catch it mid-reload,
        // mis-measuring the thumb), while moveBefore() moves it atomically.
        var moveInto = function (parent, node) {
            if (parent.isConnected && node.isConnected && parent.moveBefore) {
                try {
                    parent.moveBefore(node, null);
                    return;
                } catch (e) { /* fall back to a plain append */ }
            }
            parent.appendChild(node);
        };

        var renderGrid = function (grouped) {
            // Old group wrappers are removed only AFTER their cards have been
            // moved out, so the cards never leave the document.
            var stale = Array.prototype.slice.call(
                grid.querySelectorAll(':scope > .cg-picker-group')
            );

            if (!grouped) {
                // Ungrouped cards still live inside a single (headingless)
                // .cg-picker-group wrapper. As DIRECT children of the scroll
                // container, Chromium sizes their grid rows from the .card
                // button's containment-affected intrinsic height and the card
                // bodies overflow onto the following cards; one nesting level
                // below the scroller (exactly like grouped mode) lays out
                // correctly.
                var section = document.createElement('div');
                section.className = 'cg-picker-group';
                grid.appendChild(section);
                entries.forEach(function (entry) {
                    moveInto(section, entry.card);
                });
                stale.forEach(function (el) { el.remove(); });
                applyFilter();
                resizeThumbs();
                return;
            }

            // Group cards by the component's story group ("Page Builder",
            // "Content Blocks", …), in order of first appearance; entry types
            // without a matching component collapse into a trailing "Other".
            var groups = [];
            var byLabel = {};
            entries.forEach(function (entry) {
                var label = entry.comp && entry.comp.group ? entry.comp.group : 'Other';
                if (!byLabel[label]) {
                    byLabel[label] = { label: label, entries: [] };
                    groups.push(byLabel[label]);
                }
                byLabel[label].entries.push(entry);
            });
            groups.sort(function (a, b) {
                return (a.label === 'Other' ? 1 : 0) - (b.label === 'Other' ? 1 : 0);
            });

            var collapsedMap = getCollapsedGroups();

            groups.forEach(function (group) {
                var section = document.createElement('div');
                section.className = 'cg-picker-group';
                if (groups.length > 1) {
                    var heading = document.createElement('button');
                    heading.type = 'button';
                    heading.className = 'cg-picker-group__title';
                    heading.textContent = group.label + ' (' + group.entries.length + ')';
                    var isCollapsed = !!collapsedMap[group.label];
                    section.classList.toggle('is-collapsed', isCollapsed);
                    heading.setAttribute('aria-expanded', String(!isCollapsed));
                    heading.addEventListener('click', function () {
                        var nowCollapsed = !section.classList.contains('is-collapsed');
                        section.classList.toggle('is-collapsed', nowCollapsed);
                        heading.setAttribute('aria-expanded', String(!nowCollapsed));
                        collapsedMap[group.label] = nowCollapsed;
                        setCollapsedGroups(collapsedMap);
                    });
                    section.appendChild(heading);
                }
                // Connect the section before moving cards in — moveBefore()
                // only preserves iframe state between connected parents.
                grid.appendChild(section);
                group.entries.forEach(function (entry) {
                    moveInto(section, entry.card);
                });
            });
            stale.forEach(function (el) { el.remove(); });
            applyFilter();
            resizeThumbs();
        };

        renderGrid(groupCheckbox.checked);

        groupCheckbox.addEventListener('change', function () {
            setGroupingEnabled(groupCheckbox.checked);
            renderGrid(groupCheckbox.checked);
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

    // --- Live Preview header shortcut ------------------------------------
    // The field's own "Blocks gallery" button sits in its bottom .buttons
    // row, which on long Matrix fields is below the fold. Duplicate the
    // trigger in the Live Preview editor pane's header so it is always in
    // reach. It only proxies a click to the field's live button, so panel
    // logic stays in one place and stale-DOM re-renders are a non-issue.
    var enhancePreviewHeader = function (container) {
        if (container.dataset.cgPickerHeader) { return; }

        // Fields are enhanced asynchronously (picker map fetch); until a real
        // gallery button exists there is nothing to proxy — the mutation
        // observer will re-run this scan once it appears.
        if (!container.querySelector('.cg-picker-open:not(.cg-picker-open--header)')) { return; }

        container.dataset.cgPickerHeader = '1';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cg-picker-open cg-picker-open--header';
        btn.textContent = Craft.t('component-guide', 'Blocks gallery');
        btn.addEventListener('click', function () {
            // Re-resolve on every click — Preview re-renders its editor pane.
            var live = container.querySelector('.cg-picker-open:not(.cg-picker-open--header)');
            if (live) { live.click(); }
        });

        var header = container.querySelector('header');
        if (header) {
            header.appendChild(btn);
        } else {
            // Fallback for markup drift: our own slim sticky bar at the top.
            var bar = document.createElement('div');
            bar.className = 'cg-picker-headerbar';
            bar.appendChild(btn);
            container.insertBefore(bar, container.firstChild);
        }
    };

    // --- Overlay stacking -------------------------------------------------
    // Craft's modals, slideouts and HUDs all sit at z-index 100 — the same
    // layer the panel needs to beat the Live Preview containers (also 100).
    // A static z-index can't stack between them, so track open overlays via
    // Garnish's class-level events and flag <body>; picker.css drops the
    // panel below the overlay layer while the flag is on.
    var trackOverlays = function () {
        if (!window.Garnish || typeof Garnish.on !== 'function') { return; }

        var open = [];
        var flag = function () {
            document.body.classList.toggle('cg-overlay-open', open.length > 0);
        };
        var track = function (cls, showEvent, hideEvent) {
            if (!cls) { return; }
            Garnish.on(cls, showEvent, function (ev) {
                if (open.indexOf(ev.target) === -1) { open.push(ev.target); }
                flag();
            });
            Garnish.on(cls, hideEvent, function (ev) {
                var i = open.indexOf(ev.target);
                if (i !== -1) { open.splice(i, 1); }
                flag();
            });
        };

        track(Garnish.Modal, 'show', 'hide');
        track(Garnish.HUD, 'show', 'hide');
        track(window.Craft && Craft.Slideout, 'open', 'close');
    };

    var scan = function (root) {
        (root || document).querySelectorAll('.matrix-field').forEach(enhance);
        document.querySelectorAll('.lp-editor-container').forEach(enhancePreviewHeader);
    };

    var init = function () {
        trackOverlays();
        scan(document);
        // Matrix fields can appear later (slideouts, lazy tabs). CP pages
        // mutate the DOM constantly (Live Preview, editors), so instead of
        // querying on every added node we coalesce each mutation burst into a
        // single document scan on the next frame — enhance() is idempotent
        // (dataset flag), so re-scanning is cheap.
        var scanScheduled = false;
        new MutationObserver(function (mutations) {
            if (scanScheduled) { return; }
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes.length) {
                    scanScheduled = true;
                    requestAnimationFrame(function () {
                        scanScheduled = false;
                        scan(document);
                    });
                    return;
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
