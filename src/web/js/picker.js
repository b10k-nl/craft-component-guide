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

    // Which edition is running. Seeded from the page so the flag exists before
    // the map arrives, then overwritten by the map, which is the authority —
    // the server decides this, not the browser. Withholding the prefill is done
    // server-side regardless; this only chooses what the panel says.
    var isPro = !!(window.CraftComponentGuide && window.CraftComponentGuide.pro);
    // Preview Zoom setting; on unless the plugin says otherwise.
    var zoomEnabled = !(window.CraftComponentGuide && window.CraftComponentGuide.zoom === false);

    // What the gallery should still look like after a reload: open or not,
    // which story each block's card was showing, grouping, Variants as cards
    // and the collapsed groups. Kept in localStorage rather than cookies — the
    // server never reads any of it, so it has no business riding along on
    // every request. Storage can be unavailable (private windows, blocked site
    // data); then the gallery simply starts fresh.
    var STATE_KEY = 'componentGuide.picker';

    var readState = function () {
        try {
            var state = JSON.parse(window.localStorage.getItem(STATE_KEY) || '{}');
            return state && typeof state === 'object' ? state : {};
        } catch (e) {
            return {};
        }
    };

    var writeState = function (update) {
        try {
            var state = readState();
            update(state);
            window.localStorage.setItem(STATE_KEY, JSON.stringify(state));
        } catch (e) { /* not remembered, nothing else lost */ }
    };

    // Until 1.13.0 these three were cookies. Read once, carried over, and the
    // cookie expired — an editor's grouping and collapsed groups survive the
    // update.
    var fromOldCookie = function (name) {
        var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
        if (!m) { return null; }
        document.cookie = name + '=; path=/; max-age=0; samesite=lax';
        return m[1];
    };

    var preference = function (key, cookie, parse, fallback) {
        var state = readState();
        if (Object.prototype.hasOwnProperty.call(state, key)) {
            return state[key];
        }
        var old = fromOldCookie(cookie);
        if (old === null) {
            return fallback;
        }
        var value;
        try { value = parse(old); } catch (e) { return fallback; }
        writeState(function (s) { s[key] = value; });
        return value;
    };

    var isGroupingEnabled = function () {
        return preference('grouped', 'cg-picker-grouped', function (v) { return v === '1'; }, true) !== false;
    };

    var setGroupingEnabled = function (on) {
        writeState(function (s) { s.grouped = !!on; });
    };

    // "Variants as cards": every reproducible story of a block gets its own
    // card instead of a dropdown on one card. A story's args can change the
    // whole layout — a split hero is not a recoloured centred one — and an
    // editor choosing by looking should not have to open a menu to find it.
    // Off by default; remembered per browser like grouping.
    var isSplitEnabled = function () {
        return preference('split', 'cg-picker-split', function (v) { return v === '1'; }, false) === true;
    };

    var setSplitEnabled = function (on) {
        writeState(function (s) { s.split = !!on; });
    };

    // Collapsed group labels.
    var getCollapsedGroups = function () {
        var labels = preference('collapsed', 'cg-picker-collapsed', function (v) {
            return JSON.parse(decodeURIComponent(v));
        }, []);
        var map = {};
        (Array.isArray(labels) ? labels : []).forEach(function (label) {
            if (typeof label === 'string') { map[label] = true; }
        });
        return map;
    };

    var setCollapsedGroups = function (map) {
        var labels = Object.keys(map).filter(function (k) { return map[k]; });
        writeState(function (s) { s.collapsed = labels; });
    };

    var loadMap = function () {
        if (!mapPromise) {
            // An action URL, not the CP route: Craft lets nobody open a
            // plugin's section pages without “Access Component Guide”, and an
            // editor with only the gallery permission has the gallery, not
            // the section.
            mapPromise = fetch(Craft.getActionUrl('component-guide/picker/map'), {
                headers: { 'Accept': 'application/json' },
            }).then(function (r) {
                return r.ok ? r.json() : { components: [] };
            }).then(function (data) {
                if (typeof data.pro === 'boolean') { isPro = data.pro; }
                if (typeof data.zoom === 'boolean') { zoomEnabled = data.zoom; }
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

    // Craft renders a Lightswitch field as a <button class="lightswitch">
    // holding a hidden input. There is no text box or select to find, so until
    // 1.5.1 a boolean arg — a "reversed" variant, a dark-theme toggle — was
    // dropped without a word, and the editor who picked that variant in the
    // gallery got the default block. The preview showed one thing and the
    // click added another.
    var setLightswitch = function (hidden, value) {
        var sw = hidden.closest('.lightswitch');
        if (!sw) { return; }
        var on = value === true || value === 1 || value === '1' || value === 'true';

        var instance = window.jQuery ? window.jQuery(sw).data('lightswitch') : null;
        if (instance && typeof instance.turnOn === 'function') {
            // Craft's own API: moves the handle, updates the hidden input and
            // fires the change the element editor listens for.
            if (on) { instance.turnOn(); } else { instance.turnOff(); }
            return;
        }

        // Not initialised yet — Matrix attaches its UI after the markup lands.
        // Craft.LightSwitch reads its state from the `on` class when it is
        // constructed, so leaving the markup exactly as the server renders an
        // "on" switch is all it takes; the instance picks it up from there.
        sw.classList.toggle('on', on);
        sw.setAttribute('aria-checked', on ? 'true' : 'false');
        hidden.value = on ? (sw.getAttribute('data-value') || '1') : '';
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
    };

    // Craft's CKEditor field is a `<textarea class="hidden">` with the editor
    // drawn after it; the editor reads the textarea once, on creation, and
    // never again. Writing the textarea alone therefore saves text the editor
    // does not show — so the content goes through the editor itself. CKEditor
    // puts its instance on the editable element as `ckeditorInstance`. It is
    // created after Matrix inserts the block, so it is waited for, and an
    // editor that already holds text is left alone, like any other field.
    var setRichText = function (textarea, html) {
        var attempts = 0;
        var apply = function () {
            var holder = textarea.parentElement;
            var editable = holder ? holder.querySelector('.ck-editor__editable') : null;
            var editor = editable ? editable.ckeditorInstance : null;
            if (editor && typeof editor.setData === 'function') {
                if (typeof editor.getData !== 'function' || editor.getData() === '') {
                    editor.setData(html);
                }
                return;
            }
            if (++attempts < 60) { setTimeout(apply, 50); }
        };
        apply();
    };

    // The same, for Craft 6, where the editor is found inside the field's
    // control wrapper (it may still be loading when the block appears).
    var setRichTextIn = function (control, html) {
        var attempts = 0;
        var apply = function () {
            var editable = control.querySelector('.ck-editor__editable');
            var editor = editable ? editable.ckeditorInstance : null;
            if (editor && typeof editor.setData === 'function') {
                if (typeof editor.getData !== 'function' || editor.getData() === '') {
                    editor.setData(html);
                }
                return;
            }
            if (++attempts < 100) { setTimeout(apply, 50); }
        };
        apply();
    };

    var fillBlock = function (blockEl, prefill) {
        // Keys are real field handles now: the server reads them off the
        // adapter's include site, which is the only place the mapping between
        // a presentational argument and a field is actually written down. The
        // browser guesses nothing.
        Object.keys(prefill).forEach(function (handle) {
            var value = prefill[handle];
            var tail = '[name$="[' + handle + ']"]';

            // A switch always holds a value, like a select, so it is set
            // whichever way the story says — a variant that turns it off must
            // be able to turn it off.
            var hidden = blockEl.querySelector('.lightswitch input[type="hidden"]' + tail);
            if (hidden) {
                setLightswitch(hidden, value);
                return;
            }

            var rich = blockEl.querySelector('textarea.hidden' + tail);
            if (rich) {
                setRichText(rich, String(value));
                return;
            }

            // Craft 6: a CKEditor field has no named input at all — only its
            // control wrapper, `[data-ui-control-path]` ending in the handle,
            // with the editor drawn inside it.
            var control = blockEl.querySelector('[data-ui-control-path$=\'"fields","' + handle + '"]\']');
            if (control && !control.querySelector('[name]')) {
                setRichTextIn(control, String(value));
                return;
            }

            // Radio Buttons store option values the way Dropdown does — encoded
            // — so the match goes through the same function.
            var radios = blockEl.querySelectorAll('input[type="radio"]' + tail);
            if (radios.length) {
                for (var r = 0; r < radios.length; r++) {
                    if (optionMatches(radios[r].value, String(value))) {
                        if (!radios[r].checked) { radios[r].click(); }
                        break;
                    }
                }
                return;
            }

            // Number, URL, Email and Phone fields are text boxes of another
            // type, and were skipped for the same reason the switch was.
            var input = blockEl.querySelector(
                'input[type="text"]' + tail + ', '
                + 'input[type="number"]' + tail + ', '
                + 'input[type="url"]' + tail + ', '
                + 'input[type="email"]' + tail + ', '
                + 'input[type="tel"]' + tail + ', '
                + 'textarea' + tail + ', '
                + 'select' + tail
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

    // --- Reading a block back -------------------------------------------------
    // The inverse of fillBlock(): what an editor has typed into a block, for the
    // fields a variant can keep. Same field types, same decoding, so a value
    // read here is one fillBlock() can put back.
    var decodeOption = function (value) {
        value = String(value);
        if (value.slice(0, 7) !== 'base64:') { return value; }
        try {
            return decodeURIComponent(escape(window.atob(value.slice(7))));
        } catch (e) {
            return value;
        }
    };

    var fieldLabel = function (input, handle) {
        // Craft 6: <craft-field label="…">.
        var cField = input && input.closest('craft-field');
        if (cField && cField.getAttribute('label')) { return cField.getAttribute('label'); }
        var field = input && input.closest('.field');
        var label = field && field.querySelector('.heading label, legend');
        return label ? label.textContent.trim() : handle;
    };

    var readBlock = function (blockEl, handles) {
        var values = {};
        var kept = [];
        var skipped = [];

        handles.forEach(function (handle) {
            // Only the block's own inputs: a nested Matrix inside it has fields
            // of its own whose names end the same way.
            var own = Array.prototype.filter.call(
                blockEl.querySelectorAll('[name$="[fields][' + handle + ']"]'),
                function (el) { return el.closest('.matrixblock, [data-matrix-block]') === blockEl; }
            );
            if (!own.length) {
                // Craft 6: a CKEditor field has no named input, only its control
                // wrapper — read the editor itself (fillBlock writes it the
                // same way, so a saved variant comes back whole).
                var control = blockEl.querySelector('[data-ui-control-path$=\'"fields","' + handle + '"]\']');
                var editable = control && control.closest('[data-matrix-block]') === blockEl
                    ? control.querySelector('.ck-editor__editable') : null;
                var editor = editable ? editable.ckeditorInstance : null;
                if (editor && typeof editor.getData === 'function') {
                    var html = editor.getData();
                    if (html === '') { return; }
                    if (html.length > 5000) { skipped.push(fieldLabel(control, handle)); return; }
                    values[handle] = html;
                    kept.push(fieldLabel(control, handle));
                }
                return;
            }

            var hidden = own.filter(function (el) {
                return el.type === 'hidden' && el.closest('.lightswitch');
            })[0];
            if (hidden) {
                values[handle] = hidden.value !== '';
                kept.push(fieldLabel(hidden, handle));
                return;
            }

            var radios = own.filter(function (el) { return el.type === 'radio'; });
            if (radios.length) {
                var checked = radios.filter(function (el) { return el.checked; })[0];
                if (checked) {
                    values[handle] = decodeOption(checked.value);
                    kept.push(fieldLabel(checked, handle));
                }
                return;
            }

            var input = own.filter(function (el) {
                return el.tagName === 'SELECT'
                    || el.tagName === 'TEXTAREA'
                    || ['text', 'number', 'url', 'email', 'tel'].indexOf(el.type) !== -1;
            })[0];
            if (!input) { return; }

            // A rich-text field keeps its real value in its editor, not in the
            // textarea it hides, and fillBlock() cannot write into it either —
            // so it is named as not kept rather than saved half-right.
            // (Craft's CKEditor field renders it as `<textarea class="hidden">`;
            // a collapsed block hides everything, so visibility is no test.)
            if (input.tagName === 'TEXTAREA'
                && (input.classList.contains('hidden') || (input.parentElement && input.parentElement.querySelector('.ck-editor')))) {
                skipped.push(fieldLabel(input, handle));
                return;
            }

            var value = input.tagName === 'SELECT' ? decodeOption(input.value) : input.value;
            if (value.length > 500) {
                skipped.push(fieldLabel(input, handle));
                return;
            }
            values[handle] = value;
            kept.push(fieldLabel(input, handle));
        });

        return { values: values, kept: kept, skipped: skipped };
    };

    // --- Craft 6: "Save as my variant" from the gallery ----------------------
    // Pick mode: the field's blocks outline on hover; a click on one opens a
    // small form at the top of the panel. Escape, Cancel or closing the panel
    // leaves without saving.
    var variantPick = null;

    var stopVariantPick = function () {
        if (!variantPick) { return; }
        document.removeEventListener('click', variantPick.onClick, true);
        document.removeEventListener('keydown', variantPick.onKey, true);
        document.body.classList.remove('cg-variant-picking');
        if (variantPick.bar && variantPick.bar.parentNode) { variantPick.bar.remove(); }
        variantPick = null;
    };

    var panelNote = function (panel, className) {
        var old = panel.querySelector('.cg-variant-inline');
        if (old) { old.remove(); }
        var box = document.createElement('div');
        box.className = 'cg-variant-inline ' + (className || '');
        var search = panel.querySelector('.cg-picker-search');
        panel.insertBefore(box, search ? search.nextSibling : null);
        return box;
    };

    var startVariantPick = function (source, comps, panel) {
        stopVariantPick();
        if (!isPro) {
            Craft.cp.displayNotice(Craft.t('component-guide', 'Saving your own variants is a Pro feature.'));
            return;
        }

        var bar = panelNote(panel, 'cg-variant-inline--pick');
        var text = document.createElement('span');
        text.textContent = Craft.t('component-guide', 'Click the block you want to keep as your variant.');
        var cancel = document.createElement('craft-button');
        cancel.setAttribute('type', 'button');
        cancel.setAttribute('size', 'small');
        cancel.textContent = Craft.t('app', 'Cancel');
        cancel.addEventListener('click', stopVariantPick);
        bar.appendChild(text);
        bar.appendChild(cancel);
        document.body.classList.add('cg-variant-picking');

        var onClick = function (event) {
            var field = source.field();
            var block = event.target && event.target.closest ? event.target.closest('[data-matrix-block]') : null;
            if (!block || !field || !field.contains(block)) { return; }
            // The block's own outermost one: a click inside a nested block's
            // fields still means the block of this field.
            while (block.parentElement && block.parentElement.closest('[data-matrix-block]')
                && field.contains(block.parentElement.closest('[data-matrix-block]'))) {
                block = block.parentElement.closest('[data-matrix-block]');
            }
            event.preventDefault();
            event.stopPropagation();
            var comp = comps[block.getAttribute('data-type')];
            if (!comp || !comp.baseStoryId || !Object.keys(comp.editableFields || {}).length) {
                Craft.cp.displayNotice(Craft.t('component-guide', 'This block has no story to keep a variant of.'));
                return;
            }
            stopVariantPick();
            variantForm(block, comp, panel);
        };
        var onKey = function (event) {
            if (event.key === 'Escape') { event.stopPropagation(); stopVariantPick(); }
        };
        document.addEventListener('click', onClick, true);
        document.addEventListener('keydown', onKey, true);
        variantPick = { onClick: onClick, onKey: onKey, bar: bar };
    };

    var variantForm = function (block, comp, panel) {
        var read = readBlock(block, Object.keys(comp.editableFields || {}));
        if (!Object.keys(read.values).length) {
            Craft.cp.displayError(Craft.t('component-guide', 'None of this block’s fields can be saved in a variant yet.'));
            return;
        }

        var box = panelNote(panel, 'cg-variant-inline--form');
        var form = document.createElement('form');
        form.className = 'cg-variant-form';

        var inputId = 'cg-variant-name';
        var label = document.createElement('label');
        label.className = 'cg-variant-form__label';
        label.setAttribute('for', inputId);
        label.textContent = Craft.t('component-guide', 'Name this variant');
        var field = document.createElement('craft-input');
        var input = document.createElement('input');
        input.setAttribute('slot', 'input');
        input.type = 'text';
        input.id = inputId;
        input.className = 'text fullwidth form-control';
        input.maxLength = 80;
        input.required = true;
        input.value = Craft.t('component-guide', '{block} — my variant', { block: comp.title });
        field.appendChild(input);
        form.appendChild(label);
        form.appendChild(field);

        var kept = document.createElement('p');
        kept.className = 'cg-variant-form__note';
        kept.textContent = Craft.t('component-guide', 'Keeps: {fields}.', { fields: read.kept.join(', ') });
        form.appendChild(kept);
        if (read.skipped.length) {
            var skipped = document.createElement('p');
            skipped.className = 'cg-variant-form__note';
            skipped.textContent = Craft.t('component-guide', 'Not kept: {fields} — these start from the block’s default.', { fields: read.skipped.join(', ') });
            form.appendChild(skipped);
        }

        var buttons = document.createElement('div');
        buttons.className = 'cg-variant-form__buttons';
        var submit = document.createElement('craft-button');
        submit.setAttribute('type', 'submit');
        submit.setAttribute('variant', 'primary');
        submit.setAttribute('size', 'small');
        submit.textContent = Craft.t('component-guide', 'Save');
        var cancel = document.createElement('craft-button');
        cancel.setAttribute('type', 'button');
        cancel.setAttribute('size', 'small');
        cancel.textContent = Craft.t('app', 'Cancel');
        cancel.addEventListener('click', function () { box.remove(); });
        buttons.appendChild(submit);
        buttons.appendChild(cancel);
        form.appendChild(buttons);
        box.appendChild(form);
        setTimeout(function () { input.focus(); input.select(); }, 50);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submit.disabled = true;
            Craft.sendActionRequest('POST', 'component-guide/user-stories/save', {
                data: {
                    componentId: comp.id,
                    baseStoryId: comp.baseStoryId,
                    title: input.value,
                    values: read.values,
                },
            }).then(function (response) {
                writeState(function (state) {
                    state.stories = state.stories || {};
                    state.stories[comp.id] = 'mine-' + response.data.id;
                });
                mapPromise = null;
                refreshPanel(comp.id);
                Craft.cp.displaySuccess(Craft.t('component-guide', 'Saved “{title}”. You’ll find it in the blocks gallery.', { title: response.data.title }));
            }).catch(function (error) {
                submit.disabled = false;
                var message = error && error.response && error.response.data && error.response.data.message;
                Craft.cp.displayError(message || Craft.t('component-guide', 'Could not save the variant.'));
            });
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
        var deadline = Date.now() + 10000;
        // Craft 6: ids taken before the click, and a way to find the field
        // again after Vue has replaced it (see vueSource.add).
        var idsBefore = field.cgBlockIdsBefore || null;
        var relocate = field.cgRelocate || null;
        field.cgBlockIdsBefore = null;
        field.cgRelocate = null;
        var existing = idsBefore ? null : new Set(
            Array.prototype.slice.call(field.querySelectorAll('[data-type="' + type + '"]'))
        );
        var isOld = function (el) {
            return idsBefore ? idsBefore.has(el.getAttribute('data-id')) : existing.has(el);
        };
        var found = null;
        var tick = function () {
            if (!found) {
                if (!field.isConnected && relocate) { field = relocate() || field; }
                var candidates = field.querySelectorAll('[data-type="' + type + '"]');
                for (var i = candidates.length - 1; i >= 0; i--) {
                    var el = candidates[i];
                    // Menu buttons carry data-type too — a real block has inputs.
                    if (!isOld(el) && el.tagName !== 'BUTTON' && el.querySelector('input:not([type="hidden"]), textarea, select')) {
                        found = el;
                        if (window.cgPickerDebug) {
                            console.log('[component-guide] new block', el, Array.prototype.map.call(
                                el.querySelectorAll('[name]'), function (i) { return i.tagName.toLowerCase() + (i.type ? '[' + i.type + ']' : '') + ' ' + i.name; }));
                        }
                        if (prefill) { fillBlock(el, prefill); }
                        break;
                    }
                }
            }

            if (found) {
                if (!onReady) { return; }
                // Craft 6 has no jQuery entry object to wait for: the block is
                // ready as soon as it is there (Undo goes through its menu).
                if (idsBefore) {
                    onReady(found, null);
                    return;
                }
                var entry = window.jQuery ? window.jQuery(found).data('entry') : null;
                if (entry && typeof entry.selfDestruct === 'function') {
                    onReady(found, entry);
                    return;
                }
            }

            if (Date.now() < deadline) {
                requestAnimationFrame(tick);
            } else if (!found && window.cgPickerDebug) {
                console.log('[component-guide] no new block', type, field,
                    field.querySelectorAll('[data-type="' + type + '"]').length, idsBefore ? idsBefore.size : existing.size);
            }
        };
        requestAnimationFrame(tick);
    };

    // --- Follow the new block in Live Preview ------------------------------
    // The gallery appends, so in Live Preview the block an editor just added
    // lands at the end of the page builder — usually below the fold — while
    // Craft keeps the preview where it was.
    //
    // The front end has no marker for "the block with this uid", and a block
    // may have no text at all, so it is found by the page's shape instead.
    // Before the click the preview's element tree is recorded (tag and first
    // class, and how many elements sit under each node — no content). In the
    // first refresh that has more elements, the walk goes down the branch that
    // grew; where a node has gained a child, the first child that differs from
    // the recorded one — comparing from the start, because everything before
    // an appended block is unchanged — is the new block. That holds with a
    // static section after the page builder, and when the new block is the
    // same kind as the one before it.
    //
    // Nothing happens without Live Preview, with a preview on another origin,
    // or when no refresh within a few seconds adds anything to the page.
    var SKIP_TAGS = { SCRIPT: 1, STYLE: 1, TEMPLATE: 1, NOSCRIPT: 1, LINK: 1, META: 1 };

    var shapeOf = function (el) {
        var kids = [];
        for (var c = el.firstElementChild; c; c = c.nextElementSibling) {
            if (!SKIP_TAGS[c.tagName]) { kids.push(shapeOf(c)); }
        }
        var size = kids.length;
        kids.forEach(function (k) { size += k.size; });
        var firstClass = (el.getAttribute('class') || '').trim().split(/\s+/)[0] || '';
        return { sig: el.tagName + '.' + firstClass, size: size, kids: kids, el: el };
    };

    // The element in `after` that `before` did not have, or null.
    var addedElement = function (before, after) {
        if (after.size <= before.size) { return null; }

        if (after.kids.length > before.kids.length) {
            for (var i = 0; i < after.kids.length; i++) {
                if (i >= before.kids.length || before.kids[i].sig !== after.kids[i].sig) {
                    return after.kids[i].el;
                }
            }
        }

        for (var j = 0; j < after.kids.length && j < before.kids.length; j++) {
            if (after.kids[j].size > before.kids[j].size) {
                return addedElement(before.kids[j], after.kids[j]);
            }
        }

        return null;
    };

    // Craft 6: no Craft.Preview to listen to. The preview is simply the
    // largest visible same-origin iframe outside the gallery; it is polled
    // until a new document in it (or the same one, updated in place) has the
    // added block, then scrolled to it once its height has settled.
    var previewFrame = function () {
        var best = null;
        var bestArea = 0;
        document.querySelectorAll('iframe').forEach(function (f) {
            if (f.closest('.cg-picker-panel, .cg-zoom') || !f.offsetParent) { return; }
            var r = f.getBoundingClientRect();
            var area = r.width * r.height;
            if (area < 40000) { return; }
            try { if (!f.contentDocument) { return; } } catch (e) { return; }
            if (area > bestArea) { best = f; bestArea = area; }
        });
        return best;
    };

    var followInPreviewVue = function () {
        var frame = previewFrame();
        if (!frame) { return; }
        var beforeDoc;
        var before;
        try {
            beforeDoc = frame.contentDocument;
            if (!beforeDoc || !beforeDoc.body || !beforeDoc.body.firstElementChild) { return; }
            before = shapeOf(beforeDoc.body);
            (function strip(node) { node.el = null; node.kids.forEach(strip); })(before);
        } catch (e) {
            return;
        }

        var done = false;
        var deadline = Date.now() + 12000;
        var stop = function () { done = true; };
        var userEvents = ['wheel', 'touchstart', 'mousedown', 'keydown'];
        var watched = [];
        var watchUser = function (target) {
            if (watched.indexOf(target) !== -1) { return; }
            watched.push(target);
            userEvents.forEach(function (type) {
                target.addEventListener(type, stop, { once: true, passive: true });
            });
        };
        try { watchUser(beforeDoc); } catch (e) { /* ignore */ }

        var lastHeight = -1;
        var steady = 0;
        var target = null;
        var check = function () {
            if (done) { return; }
            if (Date.now() > deadline) { stop(); return; }
            var f = previewFrame();
            var doc = null;
            try { doc = f ? f.contentDocument : null; } catch (e) { stop(); return; }
            if (doc && doc.readyState === 'complete' && doc.body) {
                watchUser(doc);
                if (!target || !target.isConnected) {
                    target = addedElement(before, shapeOf(doc.body));
                    steady = 0;
                }
                if (target) {
                    // Settled: the page's height held still for a few checks.
                    var h = doc.documentElement.scrollHeight;
                    steady = h === lastHeight ? steady + 1 : 0;
                    lastHeight = h;
                    if (steady >= 3) {
                        try { target.scrollIntoView({ block: 'start' }); } catch (e) { /* old browser */ }
                        stop();
                        return;
                    }
                }
            }
            setTimeout(check, 150);
        };
        setTimeout(check, 150);
    };

    var followInPreview = function () {
        if (document.querySelector('.lp-editor-pane')) { followInPreviewVue(); }
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

    // The Delete item of a Craft 6 block's action menu: its own menu (the
    // block's header), not one of a nested block inside it. Craft marks it as
    // the destructive item (variant="danger"); the icon is a fallback.
    var vueDeleteItem = function (block) {
        var items = Array.prototype.filter.call(
            block.querySelectorAll('craft-action-item'),
            function (it) { return it.closest('[data-matrix-block]') === block; }
        );
        var pick = function (test) { return items.filter(test)[0] || null; };
        var item = pick(function (it) { return it.getAttribute('variant') === 'danger'; })
            || pick(function (it) { return /^(trash|xmark|circle-xmark)$/.test(it.getAttribute('icon') || ''); });
        if (!item && window.cgPickerDebug) {
            console.log('[component-guide] no delete item', items.map(function (it) {
                return (it.getAttribute('icon') || '') + ' ' + (it.getAttribute('variant') || '') + ' ' + it.textContent.trim();
            }));
        }
        return item;
    };

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

        // Craft keeps a notice with details open until it is closed, so the
        // Undo closes itself after a while — but not under the mouse or the
        // keyboard: reaching for it must not make it go away.
        var UNDO_MS = 8000;
        var timer = null;
        var holder = details.closest('.notification') || details.parentElement || details;
        var arm = function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                if (undoNotification === notification) { undoNotification = null; }
                if (notification && typeof notification.close === 'function') { notification.close(); }
            }, UNDO_MS);
        };
        var hold = function () { clearTimeout(timer); };
        ['mouseenter', 'focusin'].forEach(function (t) { holder.addEventListener(t, hold); });
        ['mouseleave', 'focusout'].forEach(function (t) { holder.addEventListener(t, arm); });
        arm();

        link.addEventListener('click', function (event) {
            event.preventDefault();

            // Resolve the block now, not at add time. Entering Live Preview
            // re-renders the editor, and every node captured before that is
            // detached — an Undo holding one would quietly remove nothing,
            // which is exactly the failure this button must not have. The uid
            // outlives the re-render, so the block is found through it.
            var live = field.isConnected
                ? field
                : (field.matches && field.matches('[data-matrix-field]')
                    ? vueLiveField(field)
                    : (field.id ? document.getElementById(field.id) : null));

            // Craft 6: the block's own action menu holds Delete — clicking that
            // item runs Craft's delete, so nothing is reimplemented here.
            var vueBlock = live ? live.querySelector('[data-matrix-block][data-id="' + uid + '"]') : null;
            if (vueBlock) {
                var del = vueDeleteItem(vueBlock);
                if (del) {
                    del.click();
                    if (Craft.cp && Craft.cp.announce) { Craft.cp.announce(Craft.t('component-guide', 'Block removed.')); }
                }
            } else {
                var block = live
                    ? live.querySelector('.matrixblock[data-uid="' + uid + '"]')
                    : null;
                var entry = (block && window.jQuery) ? window.jQuery(block).data('entry') : null;

                // The editor may have deleted the block themselves in the
                // meantime; undoing one that is already gone is a no-op.
                // selfDestruct() is Craft's own delete — the same call behind
                // the block's action menu.
                if (entry && typeof entry.selfDestruct === 'function') {
                    entry.selfDestruct();
                    Craft.cp.announce(Craft.t('component-guide', 'Block removed.'));
                }
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

    // Closed on purpose — the × or Escape — is the only close that is
    // remembered. A panel that goes because the field went away (Live Preview
    // re-rendering) should come back with it.
    var closeByEditor = function () {
        writeState(function (state) { state.open = null; });
        closePanel();
    };

    var reopenIfRemembered = function (key, open) {
        var remembered = readState().open;
        if (!activePanel && remembered && remembered.key === key
            && remembered.path === window.location.pathname) {
            open();
        }
    };

    // Redraws an open gallery from a fresh map — after a variant is saved —
    // keeping the search term and bringing the changed block's card into view.
    // The thumbnails reload, so the old scroll offset would point at the wrong
    // card; the block is what the editor is looking for.
    var refreshPanel = function (componentId) {
        if (!activePanel) { return; }
        var previous = activePanel;
        var search = previous.el.querySelector('.cg-picker-search input[type="search"]');
        var term = search ? search.value : '';

        loadMap().then(function (fresh) {
            if (activePanel !== previous) { return; }
            openPanel(previous.source, componentsByHandle(fresh), previous.key);

            var panel = activePanel.el;
            var input = panel.querySelector('.cg-picker-search input[type="search"]');
            if (input && term) {
                input.value = term;
                input.dispatchEvent(new Event('input'));
            }

            var card = componentId
                ? panel.querySelector('.cg-picker-card[data-component="' + componentId + '"]')
                : null;
            if (card) {
                card.scrollIntoView({ block: 'center' });
                // Again once the thumbnails have their real heights.
                setTimeout(function () {
                    if (activePanel && activePanel.el === panel && card.isConnected) {
                        card.scrollIntoView({ block: 'center' });
                    }
                }, 600);
            }
        });
    };

    // Craft overlays (modal, slideout, HUD) that are open — see trackOverlays.
    // The panel steps below only the ones opened after it: a field inside a
    // slideout opens the panel over that slideout, not behind it.
    var openOverlays = [];
    var overlaysUnderPanel = 0;
    var flagOverlays = function () {
        document.body.classList.toggle('cg-overlay-open', openOverlays.length > overlaysUnderPanel);
    };

    var closePanel = function () {
        stopVariantPick();
        if (activePanel) {
            activePanel.el.remove();
            document.removeEventListener('keydown', activePanel.onKey, true);
            activePanel = null;
        }
        overlaysUnderPanel = 0;
        flagOverlays();
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

    // Which cards the gallery shows, in native menu order. One card per entry
    // type, with its stories behind a dropdown — or, when split, one card per
    // story, kept together in story order. A block with a single story is one
    // card either way. `story: null` means "the block's card, with switcher".
    var galleryCards = function (items, comps, split) {
        var out = [];
        items.forEach(function (item) {
            var comp = comps[item.type];
            var stories = (comp && comp.stories) ? comp.stories : [];
            if (split && stories.length > 1) {
                stories.forEach(function (story) {
                    out.push({ item: item, comp: comp, story: story });
                });
            } else {
                out.push({ item: item, comp: comp, story: null });
            }
        });
        return out;
    };

    // Zoom, as on the guide's index: rest the mouse on the magnifier in a
    // thumbnail's bottom-right corner and a larger copy opens to the left of
    // the panel, over the entry. Only the corner, so moving across the cards
    // doesn't keep popping previews up. The same
    // address as the thumbnail, so the browser has it already. Lives inside
    // the panel, so it stacks with it and goes when the panel closes.
    // Only for a mouse: on a touch screen the tap adds the block anyway.
    var bindZoom = function (panel, grid) {
        var canHover = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        if (!canHover || !zoomEnabled) { return; }

        var zoom = document.createElement('div');
        zoom.className = 'cg-picker-zoom';
        zoom.hidden = true;
        zoom.setAttribute('aria-hidden', 'true');
        var zoomFrame = document.createElement('iframe');
        zoomFrame.tabIndex = -1;
        zoomFrame.title = '';
        zoom.appendChild(zoomFrame);
        panel.appendChild(zoom);

        var ZOOM_DELAY = 350;
        var zoomTimer = null;
        var zoomedThumb = null;

        var setZoomCursor = function (thumb, on) {
            var card = thumb && thumb.closest('.cg-picker-card');
            if (card) { card.classList.toggle('cg-picker-card--zoom', on); }
        };

        var hideZoom = function () {
            clearTimeout(zoomTimer);
            zoomTimer = null;
            setZoomCursor(zoomedThumb, false);
            zoomedThumb = null;
            zoom.hidden = true;
        };

        var showZoom = function (thumb) {
            var iframe = thumb.querySelector('iframe');
            var src = iframe && iframe.getAttribute('src');
            if (!src || !thumb.isConnected) { return; }
            var rect = thumb.getBoundingClientRect();
            if (!rect.width || !rect.height) { return; }
            var gap = 16;
            var panelLeft = panel.getBoundingClientRect().left;
            // Thumbnails follow the component's real height, so the zoom keeps
            // the thumbnail's own proportions — and fits the window.
            var ratio = rect.height / rect.width;
            var width = Math.min(720, panelLeft - gap - 8);
            var height = width * ratio;
            var maxHeight = window.innerHeight - 16;
            if (height > maxHeight) {
                height = maxHeight;
                width = height / ratio;
            }
            // Too narrow a window to show more than the thumbnail does.
            if (width < rect.width * 1.25) { return; }
            width = Math.round(width);
            height = Math.round(height);
            var top = Math.max(8, Math.min(rect.top, window.innerHeight - height - 8));
            if (zoomFrame.getAttribute('src') !== src) { zoomFrame.setAttribute('src', src); }
            zoomFrame.style.height = (parseFloat(iframe.style.height) || 800) + 'px';
            zoomFrame.style.transform = 'scale(' + (width / THUMB_VIEWPORT) + ')';
            zoom.style.width = width + 'px';
            zoom.style.height = height + 'px';
            zoom.style.left = (panelLeft - gap - width) + 'px';
            zoom.style.top = top + 'px';
            zoom.hidden = false;
        };

        // The thumbnail lets the pointer through to the card, so whether the
        // mouse is on its magnifier is read from the card and the hint's box.
        var thumbAt = function (event) {
            var card = event.target.closest && event.target.closest('.cg-picker-card');
            var thumb = card && card.querySelector('.cg-picker-card__thumb');
            var hint = thumb && thumb.querySelector('.cg-picker-card__zoom-hint');
            if (!hint) { return null; }
            var r = hint.getBoundingClientRect();
            return event.clientX >= r.left && event.clientX <= r.right && event.clientY >= r.top && event.clientY <= r.bottom
                ? thumb : null;
        };

        panel.addEventListener('mousemove', function (event) {
            var thumb = thumbAt(event);
            if (thumb === zoomedThumb) { return; }
            hideZoom();
            if (!thumb) { return; }
            zoomedThumb = thumb;
            setZoomCursor(thumb, true);
            zoomTimer = setTimeout(function () { showZoom(thumb); }, ZOOM_DELAY);
        });
        panel.addEventListener('mouseleave', hideZoom);
        // A click, a variant switch or typing changes what is under the mouse.
        panel.addEventListener('mousedown', hideZoom);
        panel.addEventListener('change', hideZoom);
        panel.addEventListener('keydown', hideZoom);
        grid.addEventListener('scroll', hideZoom);
        window.addEventListener('blur', hideZoom);
    };

    var openPanel = function (source, comps, key) {
        closePanel();

        // Open until the editor closes it: a reload, or saving the entry,
        // brings the gallery back on the same page and field.
        if (key) {
            writeState(function (state) {
                state.open = { path: window.location.pathname, key: key };
            });
        }

        var panel = document.createElement('div');
        panel.className = 'cg-picker-panel';
        panel.setAttribute('role', 'complementary');
        panel.setAttribute('aria-label', Craft.t('component-guide', 'Blocks gallery'));

        // Header markup of Craft 6's own slideouts (SlideoutScreen.vue):
        // <header> > flex row > heading (h2 title + meta) | actions (close).
        var head = document.createElement('header');
        head.className = 'cg-picker-panel__head';
        var headRow = document.createElement('div');
        headRow.className = 'flex items-center gap-sm justify-between';
        var heading = document.createElement('div');
        heading.className = 'cg-picker-panel__heading flex gap-md items-center';
        var headTitle = document.createElement('h2');
        headTitle.className = 'cg-picker-panel__title';
        headTitle.textContent = Craft.t('component-guide', 'Blocks gallery');
        var headHint = document.createElement('span');
        headHint.className = 'cg-picker-panel__hint';
        headHint.textContent = isPro
            ? Craft.t('component-guide', 'Click a block to add it')
            : Craft.t('component-guide', 'Browse the blocks this field accepts');
        heading.appendChild(headTitle);
        heading.appendChild(headHint);
        var headActions = document.createElement('div');
        headActions.className = 'flex gap-sm items-center';
        // Same close button as a slideout's: small, plain, flush to the edge.
        var closeBtn = document.createElement('craft-button');
        closeBtn.setAttribute('type', 'button');
        closeBtn.setAttribute('icon', '');
        closeBtn.setAttribute('size', 'small');
        closeBtn.setAttribute('variant', 'plain');
        closeBtn.setAttribute('flush', 'inline-end');
        closeBtn.className = 'cg-picker-panel__close';
        var closeIcon = document.createElement('craft-icon');
        closeIcon.setAttribute('name', 'xmark-large');
        closeIcon.setAttribute('label', Craft.t('app', 'Close'));
        closeBtn.appendChild(closeIcon);
        // Craft 6 has no hook for a plugin item in a Matrix block's own menu,
        // so "Save as my variant" starts here: pick a block in the field, name
        // it in the panel.
        if (typeof source.field === 'function') {
            var variantBtn = document.createElement('craft-button');
            variantBtn.setAttribute('type', 'button');
            variantBtn.setAttribute('size', 'small');
            variantBtn.setAttribute('variant', 'plain');
            variantBtn.setAttribute('icon', 'star');
            variantBtn.className = 'cg-picker-panel__variant';
            variantBtn.setAttribute('title', Craft.t('component-guide', 'Save a block as my variant'));
            variantBtn.setAttribute('aria-label', Craft.t('component-guide', 'Save a block as my variant'));
            variantBtn.addEventListener('click', function () { startVariantPick(source, comps, panel); });
            headActions.appendChild(variantBtn);
        }
        headActions.appendChild(closeBtn);
        headRow.appendChild(heading);
        headRow.appendChild(headActions);
        head.appendChild(headRow);

        var searchWrap = document.createElement('div');
        searchWrap.className = 'cg-picker-search';
        // <craft-input> with the native input slotted in, the markup
        // Craft 6's Input component renders — so `search` stays a plain
        // <input> for everything below.
        var searchField = document.createElement('craft-input');
        searchField.className = 'cg-picker-search__field';
        searchField.setAttribute('type', 'search');
        searchField.setAttribute('placeholder', Craft.t('component-guide', 'Search blocks…'));
        var search = document.createElement('input');
        search.setAttribute('slot', 'input');
        search.type = 'search';
        search.className = 'text fullwidth form-control';
        search.placeholder = Craft.t('component-guide', 'Search blocks…');
        search.setAttribute('aria-label', Craft.t('component-guide', 'Search blocks…'));
        searchField.appendChild(search);
        searchWrap.appendChild(searchField);

        var groupToggle = craftCheckbox('cg-picker-group', Craft.t('component-guide', 'Group'), isGroupingEnabled());
        groupToggle.className = 'cg-picker-group-toggle';
        var groupCheckbox = groupToggle.querySelector('input');
        searchWrap.appendChild(groupToggle);

        var items = source.items();

        var splitToggle = craftCheckbox('cg-picker-split', Craft.t('component-guide', 'Variants as cards'), isSplitEnabled());
        splitToggle.className = 'cg-picker-group-toggle cg-picker-split-toggle';
        var splitCheckbox = splitToggle.querySelector('input');
        // Same rule as the Group checkbox: a control that would change nothing
        // in this field is not offered.
        splitToggle.hidden = !items.some(function (item) {
            var comp = comps[item.type];
            return !!(comp && comp.stories && comp.stories.length > 1);
        });
        searchWrap.appendChild(splitToggle);

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
                // Over a slideout, Escape closes the panel only — not the
                // slideout under it too (Garnish listens on the document).
                if (overlaysUnderPanel > 0) { e.stopPropagation(); }
                closeByEditor();
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

        var buildCard = function (item, comp, story) {
            // Craft's own card classes do the styling (border, titlebar,
            // radius); cg-* classes only add picker behavior on top.
            // <craft-card>, Craft 6's own card: its header, badge, actions,
            // body and footer slots carry the look, so the gallery changes
            // whenever Craft's cards do. The card is the add button, so it
            // takes the button role and keyboard itself (a <button> may not
            // contain the variant <select>).
            var card = document.createElement('craft-card');
            card.className = 'cg-picker-card';
            card.setAttribute('role', 'button');
            card.tabIndex = 0;
            card.addEventListener('keydown', function (event) {
                if (event.target !== card) { return; }
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    card.click();
                }
            });
            if (comp) { card.dataset.component = comp.id; }
            card.dataset.search = [
                item.type,
                item.label,
                comp ? comp.title : '',
                comp && comp.description ? comp.description : '',
                story ? story.title : '',
                story && story.description ? story.description : '',
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
                card.setAttribute('aria-disabled', 'true');
                card.classList.add('cg-picker-card--disabled');
                card.title = blockReason;
            }

            // Which state of this component the card is currently offering.
            // Only reproducible ones reach the browser — the server decides
            // that, because it is the same question the index badge answers.
            // A per-story card (split mode) offers exactly that story.
            var variants = story ? [story] : ((comp && comp.stories) ? comp.stories : []);
            // The state the editor last chose for this block, if it still
            // exists — a deleted variant or a renamed story falls back to the
            // first.
            var remembered = (!story && comp) ? (readState().stories || {})[comp.id] : null;
            var active = (remembered && variants.filter(function (v) { return v.id === remembered; })[0])
                || variants[0] || null;
            var frame = null;
            var previewUrl = (active && active.previewUrl) || (comp ? comp.previewUrl : null);
            // A story's own description says what makes it different, which is
            // the point of giving it a card; the block's covers the rest.
            var description = (story && story.description) || (comp ? comp.description : null);

            // Native card titlebar, same markup as Cp::elementCardHtml().
            // Header slots of <craft-card>: label (icon + name), badge
            // (status and review), actions (variant switcher or story name).
            var headFlex = document.createElement('div');
            headFlex.setAttribute('slot', 'label');
            // Same element and classes as the Matrix block header's label.
            headFlex.className = 'flex flex-nowrap gap-sm items-center cg-picker-card__headline';
            headFlex.setAttribute('data-card-label', '');
            if (item.color) { card.setAttribute('data-color', item.color); }
            var badges = document.createElement('div');
            badges.setAttribute('slot', 'badge');
            badges.className = 'cg-picker-card__badges';

            // Reuse the entry type's icon from the native menu item; carry its
            // color class over so Craft tints it exactly like the block does.
            // Craft 6: the entry type's own icon as a <craft-icon>, the way
            // the Matrix block header draws it (item.icon comes from the map).
            if (item.icon) {
                var icon = document.createElement('craft-icon');
                icon.setAttribute('name', item.icon);
                icon.setAttribute('aria-hidden', 'true');
                headFlex.appendChild(icon);
            } else {
                // Craft 5 menu markup.
                var nativeIcon = item.el && item.el.querySelector('span.icon, .cp-icon');
                var nativeSvg = nativeIcon && nativeIcon.querySelector('svg');
                if (nativeSvg) {
                    var legacyIcon = document.createElement('div');
                    legacyIcon.className = 'cp-icon small';
                    nativeIcon.classList.forEach(function (cls) {
                        if (cls !== 'icon' && cls !== 'cp-icon') { legacyIcon.classList.add(cls); }
                    });
                    legacyIcon.setAttribute('aria-hidden', 'true');
                    legacyIcon.appendChild(nativeSvg.cloneNode(true));
                    headFlex.appendChild(legacyIcon);
                }
            }

            var title = document.createElement('span');
            title.className = 'cg-picker-card__title';
            title.textContent = comp ? comp.title : item.label;
            headFlex.appendChild(title);
            if (comp && comp.status) {
                var chip = document.createElement('craft-badge');
                chip.setAttribute('fill', STATUS_FILL[comp.status] || 'gray');
                chip.textContent = comp.status;
                badges.appendChild(chip);
            }
            // Where the client's review of the state on offer stands — a
            // version they signed off, one they asked to change, or one
            // changed since. The server words it; nothing when nobody has
            // reviewed it. Follows the dropdown below.
            var reviewChip = document.createElement('craft-badge');
            var paintReview = function () {
                var review = active && active.review;
                if (!review) {
                    // A class `display` beats the `hidden` attribute.
                    reviewChip.style.display = 'none';
                    return;
                }
                reviewChip.style.display = '';
                reviewChip.className = 'cg-chip--review';
                reviewChip.setAttribute('fill', REVIEW_FILL[review.state] || 'gray');
                reviewChip.textContent = review.label;
                reviewChip.title = review.note;
            };
            paintReview();
            badges.appendChild(reviewChip);
            card.appendChild(headFlex);
            card.appendChild(badges);
            if (story) {
                // Where the dropdown sits on a block's card: the state this
                // card is, named the way the story file names it.
                var storyLabel = document.createElement('span');
                storyLabel.className = 'cg-picker-card__story';
                storyLabel.setAttribute('slot', 'actions');
                storyLabel.textContent = (story.mine ? '★ ' : '') + story.title;
                storyLabel.title = story.title;
                card.appendChild(storyLabel);
            }

            // Description + thumbnail in a native card body under the titlebar.
            // Bare entry types (no matching component) collapse to the bar.
            if (comp && (description || previewUrl)) {
                // Default slot of <craft-card>; .card-content is the class
                // Craft 6 gives an element card's content, for its spacing.
                var body = document.createElement('div');
                body.className = 'card-content';

                if (description) {
                    var desc = document.createElement('div');
                    desc.className = 'cg-picker-card__desc';
                    desc.textContent = description;
                    body.appendChild(desc);
                }

                if (previewUrl) {
                    var thumb = document.createElement('span');
                    thumb.className = 'cg-picker-card__thumb';
                    var iframe = document.createElement('iframe');
                    frame = iframe;
                    iframe.src = previewUrl;
                    iframe.loading = 'lazy';
                    iframe.tabIndex = -1;
                    iframe.setAttribute('title', '');
                    iframe.addEventListener('load', function () { sizeThumb(iframe); });
                    thumb.appendChild(iframe);
                    // The zoom opens from this corner only (see bindZoom).
                    if (zoomEnabled) {
                        var zoomHint = document.createElement('span');
                        zoomHint.className = 'cg-picker-card__zoom-hint';
                        zoomHint.setAttribute('aria-hidden', 'true');
                        zoomHint.innerHTML = '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round">'
                            + '<circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5 14 14M7 5v4M5 7h4"/></svg>';
                        thumb.appendChild(zoomHint);
                    }
                    body.appendChild(thumb);
                }

                card.appendChild(body);
            }

            // A component with several reproducible states gets a switcher.
            // Built after the body so it can point at the thumbnail, appended
            // to the titlebar so it reads as part of the card's own header.
            // A dropdown rather than a row of chips: titles are written by the
            // developer and can be any length, and a wrapping row pushes the
            // card's own title around.
            if (variants.length > 1) {
                var variantWrap = document.createElement('craft-select');
                variantWrap.className = 'cg-picker-card__variants';
                variantWrap.setAttribute('slot', 'actions');
                variantWrap.setAttribute('small', '');

                var variantSelect = document.createElement('select');
                variantSelect.setAttribute('slot', 'input');
                variantSelect.setAttribute('aria-label', Craft.t('component-guide', 'Variant'));

                variants.forEach(function (story, index) {
                    var option = document.createElement('option');
                    option.value = String(index);
                    option.textContent = (story.mine ? '★ ' : '') + story.title;
                    variantSelect.appendChild(option);
                });
                variantSelect.selectedIndex = Math.max(0, variants.indexOf(active));

                // The whole card is the add button, so every interaction with
                // the dropdown — merely opening it included — has to stop here.
                // Otherwise choosing a variant would silently add a block.
                ['click', 'mousedown', 'keydown'].forEach(function (type) {
                    variantWrap.addEventListener(type, function (event) {
                        event.stopPropagation();
                    });
                });

                variantSelect.addEventListener('change', function (event) {
                    event.stopPropagation();
                    active = variants[variantSelect.selectedIndex] || active;
                    paintReview();
                    if (frame && active && active.previewUrl) {
                        frame.src = active.previewUrl;
                    }
                    if (comp && active) {
                        writeState(function (state) {
                            state.stories = state.stories || {};
                            state.stories[comp.id] = active.id;
                        });
                    }
                    card.dispatchEvent(new CustomEvent('cg-variant-change'));
                });

                variantWrap.appendChild(variantSelect);
                card.appendChild(variantWrap);
            }

            // An editor's own variant can be handed to a developer or removed,
            // right where it is shown. The row follows whichever state the card
            // is offering, so the dropdown and the split cards behave alike.
            if (variants.some(function (v) { return v.mine; })) {
                var mineRow = document.createElement('div');
                mineRow.className = 'cg-picker-card__mine';
                mineRow.setAttribute('slot', 'footer');

                var mineAction = function (labelText, handler) {
                    var a = document.createElement('a');
                    a.href = '#';
                    a.setAttribute('role', 'button');
                    a.textContent = labelText;
                    a.addEventListener('click', function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        if (active && active.mine) { handler(active); }
                    });
                    mineRow.appendChild(a);
                };

                mineAction(Craft.t('component-guide', 'Share with a developer'), function (mine) {
                    Craft.sendActionRequest('POST', 'component-guide/user-stories/share', {
                        data: { id: mine.userStoryId },
                    }).then(function (response) {
                        var snippet = response.data.snippet;
                        var file = response.data.file;
                        var shown = function () {
                            var pre = document.createElement('pre');
                            pre.className = 'cg-variant-snippet';
                            pre.textContent = snippet;
                            Craft.cp.displayNotice(
                                Craft.t('component-guide', 'Send this to your developer — it goes into {file}:', { file: file }),
                                { details: pre },
                            );
                        };
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(snippet).then(function () {
                                Craft.cp.displayNotice(Craft.t('component-guide', 'Copied. Send it to your developer — it goes into {file}.', { file: file }));
                            }, shown);
                        } else {
                            shown();
                        }
                    }).catch(function () {
                        Craft.cp.displayError(Craft.t('component-guide', 'Could not prepare the variant to share.'));
                    });
                });

                mineAction(Craft.t('component-guide', 'Delete'), function (mine) {
                    if (!window.confirm(Craft.t('component-guide', 'Delete your variant “{title}”?', { title: mine.title }))) {
                        return;
                    }
                    Craft.sendActionRequest('POST', 'component-guide/user-stories/delete', {
                        data: { id: mine.userStoryId },
                    }).then(function () {
                        mapPromise = null;
                        Craft.cp.displayNotice(Craft.t('component-guide', 'Variant deleted.'));
                        if (story) {
                            card.remove();
                            return;
                        }
                        var index = variants.indexOf(mine);
                        variants.splice(index, 1);
                        var select = card.querySelector('.cg-picker-card__variants select');
                        if (select) {
                            select.remove(index);
                            Array.prototype.forEach.call(select.options, function (o, i) { o.value = String(i); });
                            select.selectedIndex = 0;
                            select.dispatchEvent(new Event('change'));
                        }
                    }).catch(function () {
                        Craft.cp.displayError(Craft.t('component-guide', 'Could not delete the variant.'));
                    });
                });

                var syncMineRow = function () { mineRow.hidden = !(active && active.mine); };
                syncMineRow();
                card.addEventListener('cg-variant-change', syncMineRow);
                card.appendChild(mineRow);
            }

            if (blockReason) {
                var reason = document.createElement('div');
                reason.className = 'cg-picker-card__reason';
                reason.setAttribute('slot', 'footer');
                reason.textContent = blockReason;
                card.appendChild(reason);
            }

            if (!isPro) {
                card.classList.add('cg-picker-card--locked');
            }

            card.addEventListener('click', function () {
                if (card.getAttribute('aria-disabled') === 'true') { return; }
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
                // What the editor picked is what they get: the block starts
                // from the state whose preview they were looking at.
                var prefill = active ? active.prefill : (comp ? comp.prefill : null);

                // Recorded before the block exists, so the refresh that shows
                // it can be told apart (see followInPreview).
                followInPreview();

                var target = source.add(item);
                if (window.cgPickerDebug) { console.log('[component-guide] add', item.type, target, prefill); }
                if (!target) { return; }

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
                        var name = ((comp ? comp.title : item.label) || item.type)
                            + (story ? ' — ' + story.title : '');
                        // Craft 5: data-uid; Craft 6: data-id (the entry's uuid).
                        var uid = blockEl.getAttribute('data-uid') || blockEl.getAttribute('data-id');
                        if (uid) { offerUndo(name, target, uid); }
                    });
                }

                card.classList.remove('is-added');
                void card.offsetWidth; // restart the animation
                card.classList.add('is-added');
            });

            return card;
        };

        // Build the cards in native menu order — grouping only decides the
        // layout, so toggling it re-parents the same nodes. Splitting changes
        // which cards exist, so that toggle builds a fresh set.
        var buildEntries = function (split) {
            return galleryCards(items, comps, split).map(function (c) {
                return { comp: c.comp, card: buildCard(c.item, c.comp, c.story) };
            });
        };
        var entries = buildEntries(splitCheckbox.checked && !splitToggle.hidden);

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

        // renderGrid() moves the new cards in and drops the old group wrappers
        // with the old cards still inside them, so nothing is left behind.
        splitCheckbox.addEventListener('change', function () {
            setSplitEnabled(splitCheckbox.checked);
            entries = buildEntries(splitCheckbox.checked);
            renderGrid(groupCheckbox.checked);
        });

        panel.appendChild(head);
        panel.appendChild(searchWrap);
        panel.appendChild(grid);
        if (!isPro) { panel.appendChild(buildUpgradeNote()); }
        // Appended last, so at the same z-index it stacks above the overlays
        // already open (a slideout the field lives in); later ones go above it.
        document.body.appendChild(panel);
        overlaysUnderPanel = openOverlays.length;
        flagOverlays();
        search.focus();
        activePanel = { el: panel, onKey: onKey, source: source, key: key };

        // Scale thumbnails once cards have a layout size.
        requestAnimationFrame(function () {
            grid.querySelectorAll('.cg-picker-card__thumb iframe').forEach(function (iframe) {
                var w = iframe.parentElement.clientWidth;
                if (w > 0) {
                    iframe.style.transform = 'scale(' + (w / THUMB_VIEWPORT) + ')';
                }
            });
        });

        bindZoom(panel, grid);

        closeBtn.addEventListener('click', closeByEditor);
        // Capture phase: ahead of Garnish's own Escape handling (see onKey).
        document.addEventListener('keydown', onKey, true);
    };

    // Components keyed by the entry-type handle they answer to. What "answers
    // to" means — case and separators ignored — is decided on the server, which
    // ships the resulting key on both the components and the entry types. So
    // this file normalises nothing: one rule, in one language. A component the
    // server left out of the map (no story, an ambiguous name) simply isn't
    // here, and its card collapses to a bare title bar like any undocumented
    // block type.

    // Craft 6 badge fills for the guide's statuses and review states — the
    // same colours the guide's own tree uses for its dots.
    var STATUS_FILL = { stable: 'green', beta: 'blue', draft: 'amber', wip: 'amber', deprecated: 'red' };
    var REVIEW_FILL = { approved: 'green', changes: 'amber', changed: 'amber' };

    // A <craft-checkbox> with its native input and label slotted in, the
    // markup Craft 6's Checkbox component renders.
    var craftCheckbox = function (id, labelText, checked) {
        var host = document.createElement('craft-checkbox');
        var input = document.createElement('input');
        input.setAttribute('slot', 'input');
        input.type = 'checkbox';
        input.id = id;
        input.checked = !!checked;
        if (checked) { host.setAttribute('checked', ''); }
        var label = document.createElement('label');
        label.setAttribute('slot', 'label');
        label.setAttribute('for', id);
        label.textContent = labelText;
        host.appendChild(input);
        host.appendChild(label);
        return host;
    };
    var componentsByHandle = function (data) {
        var byKey = {};
        (data.components || []).forEach(function (c) { byKey[c.matchKey] = c; });

        var comps = {};
        (data.entryTypes || []).forEach(function (t) {
            if (byKey[t.matchKey]) { comps[t.handle] = byKey[t.matchKey]; }
        });

        return comps;
    };

    // --- Craft 6 Matrix ----------------------------------------------------
    // Craft 6 draws an inline Matrix field as a Vue component: the field is
    // `[data-matrix-field]` (no `.matrix-field`), the add button is a
    // <craft-button data-create-element> inside a <craft-action-menu>, and the
    // menu's <craft-action-item>s carry only the entry type's name — no
    // `data-type`. The field handle is the last step of the field's
    // `data-ui-control-path`, and the map says which names that field gives
    // its types, so a menu item still resolves to a handle.

    var vueFieldPath = function (field) {
        return field.getAttribute('data-ui-control-path') || '';
    };

    var vueFieldHandle = function (field) {
        try {
            var path = JSON.parse(vueFieldPath(field) || '[]');
            return path.length ? String(path[path.length - 1]) : null;
        } catch (e) {
            return null;
        }
    };

    // Vue may draw the field again (Live Preview, a tab switch) and give it a
    // new id; its control path stays.
    var vueLiveField = function (field) {
        if (field.isConnected) { return field; }
        var path = vueFieldPath(field);
        if (!path) { return null; }
        return document.querySelector('[data-matrix-field][data-ui-control-path="' + CSS.escape(path) + '"]');
    };

    // Only the menu under the blocks: every block has an action menu of its
    // own, and its items would insert in the middle of the list.
    var vueAddZone = function (field) {
        return field.querySelector('.matrix-add--after-blocks');
    };

    var vueTypeItems = function (field, data) {
        var zone = vueAddZone(field);
        if (!zone) { return []; }

        var handle = vueFieldHandle(field);
        var types = (data.fields && handle && data.fields[handle]) || [];
        var byName = {};
        types.forEach(function (t) { byName[t.name.trim().toLowerCase()] = t; });

        var items = [];
        var seen = {};
        zone.querySelectorAll('craft-action-item').forEach(function (el) {
            var label = el.textContent.trim();
            var t = byName[label.toLowerCase()];
            if (t && !seen[t.handle]) {
                seen[t.handle] = true;
                items.push({ type: t.handle, label: label, el: el, icon: t.icon || null, color: t.color || null });
            }
        });

        // A field with one entry type has a plain button and no menu.
        if (!items.length && types.length === 1) {
            var button = zone.querySelector('craft-button[data-create-element]');
            if (button) {
                items.push({ type: types[0].handle, label: types[0].name, el: button, icon: types[0].icon || null, color: types[0].color || null });
            }
        }

        return items;
    };

    var vueSource = function (field, data) {
        return {
            // The live field, for "Save as my variant" (picks a block in it).
            field: function () { return vueLiveField(field); },
            items: function () {
                var live = vueLiveField(field);
                return live ? vueTypeItems(live, data) : [];
            },
            add: function (item) {
                var live = vueLiveField(field);
                if (!live) { closePanel(); return null; }
                var fresh = vueTypeItems(live, data).find(function (it) { return it.type === item.type; });
                if (!fresh || !fresh.el.isConnected) { return null; }
                // Craft 6 creates the entry in the browser (a uuid id) and can
                // draw its block during this very click — so the blocks that
                // were there are counted *before* it, or the new one would be
                // mistaken for an old one and never filled.
                // By id, not by node: Vue re-keys the whole field when an entry
                // is added, so every block — old ones too — comes back as a new
                // node, and the field element itself is replaced.
                live.cgBlockIdsBefore = new Set(Array.prototype.map.call(
                    live.querySelectorAll('[data-matrix-block][data-id]'),
                    function (b) { return b.getAttribute('data-id'); }
                ));
                live.cgRelocate = function () { return vueLiveField(live); };
                // The native item, so the entry is created by Matrix's own code.
                fresh.el.click();
                // The live field, so the new block can be found and filled:
                // Craft 6 draws it as `[data-matrix-block][data-type=<handle>]`
                // once the server has created the entry.
                return live;
            },
        };
    };

    var enhanceVue = function (field) {
        var zone = vueAddZone(field);
        // Vue may drop a node it did not draw, so the button itself is the
        // flag, not a data attribute on the field.
        if (!zone || zone.querySelector('.cg-picker-open') || field.cgPickerPending) { return; }
        field.cgPickerPending = true;

        loadMap().then(function (data) {
            field.cgPickerPending = false;
            if (!field.isConnected || zone.querySelector('.cg-picker-open')) { return; }

            var comps = componentsByHandle(data);
            var items = vueTypeItems(field, data);
            if (!items.some(function (it) { return comps[it.type]; })) { return; }

            // Craft 6's own button, dashed like the native "New entry"
            // beside it.
            var btn = document.createElement('craft-button');
            btn.setAttribute('type', 'button');
            btn.setAttribute('variant', 'dashed');
            btn.setAttribute('icon', 'grid-2');
            btn.className = 'cg-picker-open cg-picker-open--craft6';
            btn.textContent = Craft.t('component-guide', 'Blocks gallery');
            var key = 'vue:' + vueFieldPath(field);
            btn.addEventListener('click', function () {
                loadMap().then(function (fresh) {
                    openPanel(vueSource(field, fresh), componentsByHandle(fresh), key);
                });
            });

            var menu = zone.querySelector('craft-action-menu') || zone.querySelector('craft-button[data-create-element]');
            if (menu) {
                menu.insertAdjacentElement('afterend', btn);
            } else {
                zone.appendChild(btn);
            }
            reopenIfRemembered(key, function () { btn.click(); });
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
            var container = manager.$container && manager.$container[0];
            var key = 'nested:' + ((container && container.id) || '');
            btn.addEventListener('click', function () {
                loadMap().then(function (fresh) {
                    openPanel(source, componentsByHandle(fresh), key);
                });
            });
            setTimeout(function () { reopenIfRemembered(key, function () { btn.click(); }); }, 0);

            if (typeof manager.addButton === 'function' && window.jQuery) {
                manager.addButton(window.jQuery(btn));
            } else if (manager.$container && manager.$container.length) {
                manager.$container[0].appendChild(btn);
            }
        });
    };

    // --- Live Preview header shortcut ------------------------------------
    // The field's own "Blocks gallery" button sits below the blocks, which on
    // long Matrix fields is below the fold; a copy in the Live Preview
    // editor pane's header keeps it in reach.

    // Craft 6: the Live Preview editor is `.lp-editor-pane`, its header
    // `header.lp-pane-header` (title, status, actions, close). The shortcut is
    // a small <craft-button> placed before the close button. Vue may redraw
    // the header, so the button itself is the flag, and it proxies the click
    // to the field's own gallery button, as on Craft 5.
    var enhanceVuePreviewHeader = function (pane) {
        var header = pane.querySelector('header.lp-pane-header');
        if (!header || header.querySelector('.cg-picker-open--header')) { return; }
        if (!pane.querySelector('.cg-picker-open--craft6')) { return; }

        var btn = document.createElement('craft-button');
        btn.setAttribute('type', 'button');
        btn.setAttribute('size', 'small');
        btn.setAttribute('variant', 'dashed');
        btn.setAttribute('icon', 'grid-2');
        btn.className = 'cg-picker-open--header';
        btn.textContent = Craft.t('component-guide', 'Blocks gallery');
        btn.addEventListener('click', function () {
            var live = pane.querySelector('.cg-picker-open--craft6');
            if (live) { live.click(); }
        });

        var close = header.lastElementChild;
        if (close && close.tagName === 'CRAFT-BUTTON') {
            header.insertBefore(btn, close);
        } else {
            header.appendChild(btn);
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

        var track = function (cls, showEvent, hideEvent) {
            if (!cls) { return; }
            Garnish.on(cls, showEvent, function (ev) {
                if (openOverlays.indexOf(ev.target) === -1) { openOverlays.push(ev.target); }
                flagOverlays();
            });
            Garnish.on(cls, hideEvent, function (ev) {
                var i = openOverlays.indexOf(ev.target);
                if (i !== -1) {
                    openOverlays.splice(i, 1);
                    // The panel was opened over this overlay — for a field
                    // inside it, the only one that could be clicked. The
                    // field is gone with it, so the panel goes too.
                    if (i < overlaysUnderPanel) {
                        closePanel();
                        return;
                    }
                }
                flagOverlays();
            });
        };

        track(Garnish.Modal, 'show', 'hide');
        track(Garnish.HUD, 'show', 'hide');
        track(window.Craft && Craft.Slideout, 'open', 'close');
    };

    var scan = function (root) {
        (root || document).querySelectorAll('[data-matrix-field]').forEach(enhanceVue);
        document.querySelectorAll('.lp-editor-pane').forEach(enhanceVuePreviewHeader);
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

    // Seam for tests/js. Filling a block is where a field type Craft renders
    // its own way goes missing without a word (1.5.1: Lightswitch), so it is
    // tested against Craft's markup. Inert unless a test asks for it.
    if (window.__componentGuideTest) {
        window.__componentGuideTest.fillBlock = fillBlock;
        window.__componentGuideTest.galleryCards = galleryCards;
        window.__componentGuideTest.preferences = {
            isGroupingEnabled: isGroupingEnabled, setGroupingEnabled: setGroupingEnabled,
            isSplitEnabled: isSplitEnabled, setSplitEnabled: setSplitEnabled,
            getCollapsedGroups: getCollapsedGroups, setCollapsedGroups: setCollapsedGroups,
        };
        window.__componentGuideTest.readBlock = readBlock;
        window.__componentGuideTest.followInPreview = followInPreview;
        window.__componentGuideTest.addedElement = function (beforeEl, afterEl) { return addedElement(shapeOf(beforeEl), shapeOf(afterEl)); };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
