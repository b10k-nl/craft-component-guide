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

    // Which edition is running. Seeded from the page so the flag exists before
    // the map arrives, then overwritten by the map, which is the authority —
    // the server decides this, not the browser. Withholding the prefill is done
    // server-side regardless; this only chooses what the panel says.
    var isPro = !!(window.CraftComponentGuide && window.CraftComponentGuide.pro);

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
            }).then(function (data) {
                if (typeof data.pro === 'boolean') { isPro = data.pro; }
                return data;
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

    // --- Add sources --------------------------------------------------------
    // Two Matrix UIs, one gallery. “Inline-editable blocks” is driven by
    // Craft.MatrixInput: a `.buttons` row whose menu items carry data-type.
    // Cards and Index modes are driven by Craft.NestedElementManager, which
    // has none of that markup — entry types live in its settings as numeric
    // IDs and adding goes through createElement() (server-side create + a
    // slideout). Both are wrapped in a source object with the same shape:
    //
    //   items() → [{type, label, el, createAttributes?}]
    //   add(item) → the live field element (prefillable), true, or null
    //
    // Everything below the sources is UI and knows nothing about either mode.

    var inlineSource = function (field) {
        return {
            items: function () {
                return collectTypeItems(field);
            },
            add: function (item) {
                // Entering Live Preview re-renders the editor, so every DOM
                // reference from panel-build time may be stale. Re-resolve the
                // LIVE field by id, then go straight to Matrix's own input
                // instance (stored via jQuery data on the container) — clicking
                // a stale menu item crashes MatrixInput on an unknown handle.
                var live = field.isConnected
                    ? field
                    : (field.id ? document.getElementById(field.id) : null);
                if (!live) { closePanel(); return null; }

                var matrix = window.jQuery ? window.jQuery(live).data('matrix') : null;
                if (matrix && matrix.entryTypesByHandle && matrix.entryTypesByHandle[item.type]
                    && typeof matrix.addEntry === 'function') {
                    matrix.addEntry(item.type); // appends at the end
                    return live;
                }

                // Fallback: click the live field's native menu item.
                var fresh = collectTypeItems(live).find(function (it) { return it.type === item.type; });
                if (fresh && fresh.el.isConnected) {
                    fresh.el.click();
                    return live;
                }

                return null;
            },
        };
    };

    var nestedSource = function (manager, typesById) {
        return {
            items: function () {
                var attrs = manager.settings && manager.settings.createAttributes;
                if (!Array.isArray(attrs)) { return []; }

                return attrs.map(function (entry, i) {
                    var typeId = entry.attributes && entry.attributes.typeId;
                    var type = typesById[typeId];

                    // buildCard() lifts the entry type's icon out of the
                    // native menu item; here the icon comes as HTML in the
                    // create-attributes, so wrap it in the same shape.
                    var el = document.createElement('div');
                    el.className = 'cp-icon';
                    if (entry.icon) { el.innerHTML = entry.icon; }
                    if (entry.color) { el.classList.add(entry.color); }

                    return {
                        type: type ? type.handle : ('cg-unknown-' + i),
                        label: entry.label || (type ? type.name : ''),
                        el: el,
                        createAttributes: entry.attributes || {},
                    };
                });
            },
            add: function (item) {
                if (typeof manager.createElement !== 'function') { return null; }
                // Craft creates the entry server-side and opens its slideout;
                // there is no local form to prefill (see PLAN: prefill v2).
                manager.createElement(item.createAttributes);
                return true;
            },
        };
    };

    // --- Prefill ------------------------------------------------------------
    // A block added from the gallery starts empty — invisible on the page and
    // easy to read as “broken”. Copy the first story's scalar args into the
    // new block's matching inputs (by field handle) so it renders with the
    // same content its gallery card previews. Rich/nested fields are left
    // alone: this is a head start, not a content import.
    // Craft renders Dropdown fields through Selectize, which draws its own
    // control and never looks at the underlying <select> again — so writing to
    // that element changes nothing the editor can see, and the card offering
    // the dark variant handed them the light one. Selectize also attaches
    // after Matrix has inserted the block, so the value waits for it instead of
    // racing it. The direct write happens once, on the first pass, for fields
    // that turn out to be plain selects; after that only Selectize is watched,
    // so a value the editor picks meanwhile is never overwritten.
    // Craft attaches Selectize through jQuery and reads it back with
    // `.data('selectize')`; the instance is not reliably mirrored onto the
    // element, so looking only there finds nothing.
    var selectizeOf = function (select) {
        if (select.selectize) {
            return select.selectize;
        }
        if (window.jQuery) {
            return window.jQuery(select).data('selectize') || null;
        }
        return null;
    };

    // Craft stores Dropdown option values as `base64:<base64>`, so a story's
    // plain `dark` matches nothing by string comparison. Decoding what the
    // field offers is safer than encoding what we want: it works whether or
    // not a particular field turns out to be encoded at all.
    var optionMatches = function (optionValue, wanted) {
        if (optionValue === wanted) {
            return true;
        }
        if (String(optionValue).slice(0, 7) !== 'base64:') {
            return false;
        }
        try {
            return decodeURIComponent(escape(window.atob(String(optionValue).slice(7)))) === wanted;
        } catch (e) {
            return false;
        }
    };

    // Once Selectize has attached it takes the options over, leaving only the
    // selected one on the underlying <select> — so the element is not a
    // trustworthy source for what the field actually offers.
    var offeredValues = function (select, selectize) {
        if (selectize && selectize.options) {
            return Object.keys(selectize.options);
        }
        return Array.prototype.map.call(select.options, function (option) {
            return option.value;
        });
    };

    // Dropdowns were skipped entirely before, so a card previewing the dark
    // hero handed the editor a light one. Selectize also attaches after Matrix
    // inserts the block, so the value waits for it rather than racing it; the
    // direct write happens once, for fields that turn out to be plain selects.
    var setSelectValue = function (select, wanted) {
        var attempts = 0;

        var apply = function (first) {
            var selectize = selectizeOf(select);
            var offered = offeredValues(select, selectize);
            var match = null;

            for (var i = 0; i < offered.length; i++) {
                if (optionMatches(offered[i], wanted)) {
                    match = offered[i];
                    break;
                }
            }

            // A story naming a variant this project does not have leaves the
            // field alone rather than blanking it. Keep looking only while
            // Selectize might still be assembling its option list.
            if (match === null) {
                if (!selectize && ++attempts < 20) {
                    setTimeout(function () { apply(false); }, 50);
                }
                return;
            }

            if (selectize) {
                if (String(selectize.getValue()) !== match) {
                    selectize.setValue(match, false);
                }
                return;
            }

            if (first && select.value !== match) {
                select.value = match;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }

            if (++attempts < 20) {
                setTimeout(function () { apply(false); }, 50);
            }
        };

        apply(true);
    };

    var fillBlock = function (blockEl, prefill) {
        // Keys are real field handles now: the server reads them off the
        // adapter's include site, which is the only place the mapping between
        // a presentational argument and a field is actually written down. The
        // browser guesses nothing.
        Object.keys(prefill).forEach(function (handle) {
            var value = prefill[handle];
            var input = blockEl.querySelector(
                'input[type="text"][name$="[' + handle + ']"], '
                + 'textarea[name$="[' + handle + ']"], '
                + 'select[name$="[' + handle + ']"]'
            );
            if (!input) { return; }

            if (input.tagName === 'SELECT') {
                // A select always holds a value, so "only fill the empty ones"
                // cannot apply. Whether the option exists is decided in there,
                // against what the field really offers.
                setSelectValue(input, String(value));
                return;
            }

            if (input.value !== '') { return; }
            input.value = typeof value === 'boolean' ? (value ? '1' : '') : String(value);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
    };

    // Matrix inserts the new block asynchronously; poll the field for an
    // element of the added type that wasn't there before the click.
    //
    // Two things wait on that element and they become ready at different
    // moments. The inputs exist as soon as the markup lands, so prefill can run
    // then; Craft.MatrixInput.Entry — the object that knows how to take the
    // block out again — is only constructed after the insert animation
    // finishes. onReady is therefore held back until that object exists, so an
    // Undo affordance is never drawn for a block it could not actually remove.
    var watchForNewBlock = function (field, type, prefill, onReady) {
        var deadline = Date.now() + 4000;
        var existing = new Set(
            Array.prototype.slice.call(field.querySelectorAll('[data-type="' + type + '"]'))
        );
        var found = null;
        var tick = function () {
            if (!found) {
                var candidates = field.querySelectorAll('[data-type="' + type + '"]');
                for (var i = candidates.length - 1; i >= 0; i--) {
                    var el = candidates[i];
                    // Menu buttons carry data-type too — a real block has inputs.
                    if (!existing.has(el) && el.tagName !== 'BUTTON' && el.querySelector('input, textarea')) {
                        found = el;
                        if (prefill) { fillBlock(el, prefill); }
                        break;
                    }
                }
            }

            if (found) {
                if (!onReady) { return; }
                var entry = window.jQuery ? window.jQuery(found).data('entry') : null;
                if (entry && typeof entry.selfDestruct === 'function') {
                    onReady(found, entry);
                    return;
                }
            }

            if (Date.now() < deadline) { requestAnimationFrame(tick); }
        };
        requestAnimationFrame(tick);
    };

    // --- Undo ---------------------------------------------------------------
    // Craft has a notification of its own whose details area takes arbitrary
    // markup, so there is no reason to build a snackbar. One detail of
    // Notification.show() decides the markup: it moves focus into the
    // notification as soon as the details contain a <button> or an <input>,
    // which would pull the cursor out of the field Matrix had just focused in
    // the new block. A link does the same job, stays keyboard-reachable, and
    // leaves the focus where the editor expects it.
    var undoNotification = null;

    var offerUndo = function (label, field, uid) {
        // One notice at a time: adding three blocks in a row should not leave
        // three stacked Undos, only the last of which still means anything.
        if (undoNotification) {
            undoNotification.close();
            undoNotification = null;
        }

        var details = document.createElement('div');
        details.className = 'cg-undo';

        var link = document.createElement('a');
        link.className = 'cg-undo__btn';
        link.href = '#';
        link.textContent = Craft.t('component-guide', 'Undo');
        details.appendChild(link);

        var notification = Craft.cp.displayNotice(
            Craft.t('component-guide', '“{block}” added.', { block: label }),
            { details: details, class: 'cg-undo-notification' },
        );
        undoNotification = notification;

        link.addEventListener('click', function (event) {
            event.preventDefault();

            // Resolve the block now, not at add time. Entering Live Preview
            // re-renders the editor, and every node captured before that is
            // detached — an Undo holding one would quietly remove nothing,
            // which is exactly the failure this button must not have. The uid
            // outlives the re-render, so the block is found through it.
            var live = field.isConnected
                ? field
                : (field.id ? document.getElementById(field.id) : null);
            var block = live
                ? live.querySelector('.matrixblock[data-uid="' + uid + '"]')
                : null;
            var entry = (block && window.jQuery) ? window.jQuery(block).data('entry') : null;

            // The editor may have deleted the block themselves in the meantime;
            // undoing one that is already gone is a no-op, not an error.
            // selfDestruct() is Craft's own delete — the same call behind the
            // block's action menu — so nothing is reimplemented here.
            if (entry && typeof entry.selfDestruct === 'function') {
                entry.selfDestruct();
                Craft.cp.announce(Craft.t('component-guide', 'Block removed.'));
            }

            if (undoNotification === notification) { undoNotification = null; }
            notification.close();
        });
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

    /**
     * Draws the Lite footer once per panel: what a click would do, and where to
     * change that. Craft's own plugin-store page is the destination — the buy
     * flow belongs to the host, not to us.
     */
    var buildUpgradeNote = function () {
        var note = document.createElement('div');
        note.className = 'cg-picker-note';

        var text = document.createElement('span');
        text.textContent = Craft.t(
            'component-guide',
            'Adding a block from the gallery is a Pro feature.',
        );
        note.appendChild(text);

        var link = document.createElement('a');
        link.href = Craft.getCpUrl('plugin-store/component-guide');
        link.textContent = Craft.t('component-guide', 'See the Pro edition');
        note.appendChild(link);

        return note;
    };

    // Answering a blocked click by doing nothing reads as a bug. The note is
    // already on screen, so the click points at it rather than adding noise.
    var flashUpgradeNote = function (card) {
        var note = activePanel && activePanel.el.querySelector('.cg-picker-note');
        if (!note) { return; }
        note.classList.remove('is-flashing');
        void note.offsetWidth; // restart the animation
        note.classList.add('is-flashing');
        card.classList.remove('is-denied');
        void card.offsetWidth;
        card.classList.add('is-denied');
    };

    var openPanel = function (source, comps) {
        closePanel();

        var panel = document.createElement('div');
        panel.className = 'cg-picker-panel';
        panel.setAttribute('role', 'complementary');
        panel.setAttribute('aria-label', Craft.t('component-guide', 'Blocks gallery'));

        var head = document.createElement('div');
        head.className = 'cg-picker-panel__head';
        head.innerHTML = '<strong>' + Craft.t('component-guide', 'Blocks gallery') + '</strong>'
            + '<span class="cg-picker-panel__hint">' + (isPro
                ? Craft.t('component-guide', 'Click a block to add it')
                : Craft.t('component-guide', 'Browse the blocks this field accepts')) + '</span>';
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

            // Only an explicit `stable` opens the card. A missing status used
            // to count as stable, which read silence as a promise — the one
            // reading this plugin exists to stop. No story and no matching
            // component are still NOT signals: those blocks stay addable
            // (empty), so the gallery never blocks normal work, and the native
            // “New Block” menu is untouched either way.
            var addable = !comp || comp.status === 'stable';
            var blockReason = null;
            if (!addable) {
                blockReason = comp.status
                    ? Craft.t('component-guide', 'Marked “{status}” — not ready for editors yet.', { status: comp.status })
                    : Craft.t('component-guide', 'No status yet — mark it stable to offer it to editors.');
                card.disabled = true;
                card.classList.add('cg-picker-card--disabled');
                card.title = blockReason;
            }

            // Which state of this component the card is currently offering.
            // Only reproducible ones reach the browser — the server decides
            // that, because it is the same question the index badge answers.
            var variants = (comp && comp.stories) ? comp.stories : [];
            var active = variants[0] || null;
            var frame = null;

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
                    frame = iframe;
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

            // A component with several reproducible states gets a switcher.
            // Built after the body so it can point at the thumbnail, appended
            // to the titlebar so it reads as part of the card's own header.
            // A dropdown rather than a row of chips: titles are written by the
            // developer and can be any length, and a wrapping row pushes the
            // card's own title around.
            if (variants.length > 1) {
                var variantWrap = document.createElement('div');
                variantWrap.className = 'select small cg-picker-card__variants';

                var variantSelect = document.createElement('select');
                variantSelect.setAttribute('aria-label', Craft.t('component-guide', 'Variant'));

                variants.forEach(function (story, index) {
                    var option = document.createElement('option');
                    option.value = String(index);
                    option.textContent = story.title;
                    variantSelect.appendChild(option);
                });

                // The whole card is the add button, so every interaction with
                // the dropdown — merely opening it included — has to stop here.
                // Otherwise choosing a variant would silently add a block.
                ['click', 'mousedown', 'keydown'].forEach(function (type) {
                    variantSelect.addEventListener(type, function (event) {
                        event.stopPropagation();
                    });
                });

                variantSelect.addEventListener('change', function (event) {
                    event.stopPropagation();
                    active = variants[variantSelect.selectedIndex] || active;
                    if (frame && active && active.previewUrl) {
                        frame.src = active.previewUrl;
                    }
                });

                variantWrap.appendChild(variantSelect);
                head.appendChild(variantWrap);
            }

            if (blockReason) {
                var reason = document.createElement('div');
                reason.className = 'cg-picker-card__reason';
                reason.textContent = blockReason;
                card.appendChild(reason);
            }

            if (!isPro) {
                card.classList.add('cg-picker-card--locked');
            }

            card.addEventListener('click', function () {
                // Lite browses; it does not add. Giving away the click would
                // give away the gallery itself and leave only the prefill
                // behind the paywall — and choosing a block by looking at it is
                // most of what an editor is paying for. Craft's own “New
                // Block” menu is untouched, so nothing an editor already had
                // is taken away.
                if (!isPro) {
                    flashUpgradeNote(card);
                    return;
                }

                // The source knows how its Matrix UI adds entries: inline
                // fields hand back the live field element (prefillable),
                // Cards/Index mode just returns true — Craft opens its own
                // slideout there, so there is no local form to fill.
                var target = source.add(item);
                if (!target) { return; }

                // What the editor picked is what they get: the block starts
                // from the state whose preview they were looking at.
                var prefill = active ? active.prefill : (comp ? comp.prefill : null);

                // Inline fields hand back the live field element, so the new
                // block can be found — and taken back out if the editor changes
                // their mind. Cards/Index mode returns true instead: there the
                // entry was created on the server and Craft opened a slideout
                // over it, so undo would mean a real delete behind the editor's
                // back. Nothing is offered there rather than an Undo that only
                // works in one of the two Matrix UIs.
                if (target.nodeType === 1) {
                    watchForNewBlock(target, item.type, prefill, function (blockEl) {
                        // Name it the way the card did. item.label is the
                        // native button's text, which for a single-entry-type
                        // field is the field's create-button label — “New
                        // entry”, not the block's name.
                        var name = (comp ? comp.title : item.label) || item.type;
                        var uid = blockEl.getAttribute('data-uid');
                        if (uid) { offerUndo(name, target, uid); }
                    });
                }

                card.classList.remove('is-added');
                void card.offsetWidth; // restart the animation
                card.classList.add('is-added');
            });

            return card;
        };

        // Build every card once, in native menu order — grouping only decides
        // the layout, so toggling re-parents the same nodes.
        var entries = source.items().map(function (item) {
            var comp = comps[item.type];
            return { comp: comp, card: buildCard(item, comp) };
        });

        // Grouping one group is grouping nothing: renderGrid() already leaves
        // the heading off in that case, so the checkbox would change the layout
        // in no visible way. A control that does nothing reads as a broken one.
        var groupLabels = {};
        entries.forEach(function (entry) {
            groupLabels[(entry.comp && entry.comp.group) ? entry.comp.group : 'Other'] = true;
        });
        if (Object.keys(groupLabels).length < 2) { groupToggle.hidden = true; }

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
        if (!isPro) { panel.appendChild(buildUpgradeNote()); }
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

    // Components keyed by the entry-type handle they answer to. What "answers
    // to" means — case and separators ignored — is decided on the server, which
    // ships the resulting key on both the components and the entry types. So
    // this file normalises nothing: one rule, in one language. A component the
    // server left out of the map (no story, an ambiguous name) simply isn't
    // here, and its card collapses to a bare title bar like any undocumented
    // block type.
    var componentsByHandle = function (data) {
        var byKey = {};
        (data.components || []).forEach(function (c) { byKey[c.matchKey] = c; });

        var comps = {};
        (data.entryTypes || []).forEach(function (t) {
            if (byKey[t.matchKey]) { comps[t.handle] = byKey[t.matchKey]; }
        });

        return comps;
    };

    // --- Field enhancement --------------------------------------------------

    var enhance = function (field) {
        if (field.dataset.cgPicker) { return; }
        field.dataset.cgPicker = '1';

        var items = collectTypeItems(field);
        if (!items.length) { return; }

        loadMap().then(function (data) {
            var comps = componentsByHandle(data);

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
                openPanel(inlineSource(field), comps);
            });

            anchor.appendChild(btn);
        });
    };

    // --- Cards / Index mode enhancement -------------------------------------
    // Matrix fields in “Cards” or “Index” view mode render no `.matrix-field`
    // markup at all — they are Craft.NestedElementManager instances. Rather
    // than guess at their DOM (which changes between Craft minors), hook the
    // class-level `afterInit` event and use the manager's own public API:
    // `settings.createAttributes` for the entry types, `addButton()` to place
    // the trigger (button row in cards, toolbar in index) and `createElement()`
    // to add one.
    var enhanceNestedManager = function (manager) {
        if (!manager || manager.cgPicker || !manager.settings) { return; }
        if (!manager.settings.canCreate) { return; }
        manager.cgPicker = true;

        loadMap().then(function (data) {
            var comps = componentsByHandle(data);

            var typesById = {};
            (data.entryTypes || []).forEach(function (t) { typesById[t.id] = t; });

            var source = nestedSource(manager, typesById);
            if (!source.items().some(function (it) { return comps[it.type]; })) { return; }

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cg-picker-open';
            btn.textContent = Craft.t('component-guide', 'Blocks gallery');
            btn.addEventListener('click', function () {
                openPanel(source, comps);
            });

            if (typeof manager.addButton === 'function' && window.jQuery) {
                manager.addButton(window.jQuery(btn));
            } else if (manager.$container && manager.$container.length) {
                manager.$container[0].appendChild(btn);
            }
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

        // Cards/Index-mode fields announce themselves; there is no markup to
        // scan for. Registered before the first scan so no instance is missed
        // (Craft fires afterInit ~100ms after the field's own init).
        if (window.Garnish && typeof Garnish.on === 'function'
            && window.Craft && Craft.NestedElementManager
        ) {
            Garnish.on(Craft.NestedElementManager, 'afterInit', function (ev) {
                enhanceNestedManager(ev.target);
            });
        }

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
