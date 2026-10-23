/* Component Guide — minimal vanilla JS: search, copy, iframe controls. */
(function () {
    'use strict';

    // --- Index: client-side search and filters ---
    // Three controls, one pass: a card is visible when it survives all of them.
    // Each control is optional — the group select is not drawn on a one-group
    // project, the status select on a project where every component is in the
    // same state — so every read is guarded rather than assumed.
    var search = document.getElementById('cg-search');
    var groupFilter = document.getElementById('cg-filter-group');
    var statusFilter = document.getElementById('cg-filter-status');

    // Assigned below when the index is on screen; the status toggle calls it
    // after a component changes state, from further down this file.
    var refreshStatusFilter = null;

    // A search term matches at the start of a word, not anywhere: “grid”
    // found “upcomin-g-rid-es” inside the handle upcomingRides. Every word of
    // the term has to match (so “hero dark” narrows rather than widens), and
    // handles are split at their capitals where the haystack is written.
    var termMatcher = function (term) {
        var words = term.trim().toLowerCase().split(/\s+/).filter(Boolean);
        var patterns = words.map(function (w) {
            return new RegExp('(^|[^\\p{L}\\p{N}])' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'u');
        });
        return function (haystack) {
            haystack = haystack || '';
            return patterns.every(function (p) { return p.test(haystack); });
        };
    };

    // The device preview and the controls below it are written as two
    // sections; this is where they meet. The device section sets reload(),
    // the controls set post() — which renders changed values, and says
    // whether there were any.
    var cgPreview = { reload: null, post: null, frameName: 'cg-preview-frame' };

    if (search || groupFilter || statusFilter) {
        // Two pages, one set of filters. On the index they narrow the cards and
        // the tree follows the cards; on a component page there are no cards,
        // so they narrow the tree itself. Either way the same three questions
        // are asked of each subject: its words, its group, its status.
        var cards = Array.prototype.slice.call(document.querySelectorAll('[data-card]'));
        var groups = Array.prototype.slice.call(document.querySelectorAll('[data-group]'));
        var noResults = document.getElementById('cg-no-results');
        var treeItems = Array.prototype.slice.call(document.querySelectorAll('[data-cg-tree-item]'));
        var treeGroups = Array.prototype.slice.call(document.querySelectorAll('[data-cg-tree-group]'));
        var onIndex = cards.length > 0;

        var subjects = onIndex ? cards : treeItems;
        var groupOf = function (el) {
            if (!onIndex) { return el.getAttribute('data-tree-group') || ''; }
            var section = el.closest('[data-group]');
            return section ? section.getAttribute('data-group') : '';
        };
        var statusOf = function (el) {
            return el.getAttribute(onIndex ? 'data-card-status' : 'data-tree-status') || '__nostatus';
        };

        var filteredNotes = Array.prototype.slice.call(document.querySelectorAll('[data-cg-filtered]'));
        var clearButtons = Array.prototype.slice.call(document.querySelectorAll('[data-cg-filters-clear]'));

        // Kept for the browser tab, so narrowing the library to a few blocks
        // and opening one of them does not throw the narrowing away. Session
        // storage: a new tab starts from the whole library again.
        var STORE = 'componentGuide.filters';
        var save = function (state) {
            try { window.sessionStorage.setItem(STORE, JSON.stringify(state)); } catch (e) { /* no storage — filters just do not carry over */ }
        };
        var load = function () {
            try { return JSON.parse(window.sessionStorage.getItem(STORE) || 'null') || {}; } catch (e) { return {}; }
        };

        var filter = function () {
            var term = search ? search.value.trim().toLowerCase() : '';
            var termMatches = termMatcher(term);
            var wantGroup = groupFilter ? groupFilter.value : '';
            var wantStatus = statusFilter ? statusFilter.value : '';
            var active = term !== '' || wantGroup !== '' || wantStatus !== '';
            var shown = 0;

            save({ term: search ? search.value : '', group: wantGroup, status: wantStatus });

            subjects.forEach(function (el) {
                var match = termMatches(el.getAttribute('data-search'))
                    && (wantGroup === '' || groupOf(el) === wantGroup)
                    && (wantStatus === '' || statusOf(el) === wantStatus);
                if (match) { shown++; }
                if (onIndex) {
                    el.classList.toggle('hidden', !match);
                } else {
                    // The page you are on stays in the tree, filtered or not.
                    el.hidden = !match && !el.hasAttribute('data-tree-current');
                }
            });

            if (onIndex) {
                groups.forEach(function (group) {
                    group.classList.toggle('hidden', !group.querySelector('[data-card]:not(.hidden)'));
                });
                if (noResults) { noResults.classList.toggle('hidden', shown > 0); }

                // The tree shows exactly the cards that are left.
                treeItems.forEach(function (item) {
                    var card = document.getElementById('cg-card-' + item.getAttribute('data-component-id'));
                    item.hidden = !card || card.classList.contains('hidden');
                });
            }

            treeGroups.forEach(function (group) {
                group.hidden = !group.querySelector('[data-cg-tree-item]:not([hidden])');
            });

            filteredNotes.forEach(function (note) {
                note.hidden = !active;
                var count = note.querySelector('[data-cg-filtered-count]');
                if (count) { count.textContent = shown; }
            });
        };

        if (search) { search.addEventListener('input', filter); }
        if (groupFilter) { groupFilter.addEventListener('change', filter); }
        if (statusFilter) { statusFilter.addEventListener('change', filter); }
        clearButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (search) { search.value = ''; }
                if (groupFilter) { groupFilter.value = ''; }
                if (statusFilter) { statusFilter.value = ''; }
                filter();
                if (search) { search.focus(); }
            });
        });

        if (statusFilter) {
            // The statuses on offer are the statuses on the page — never a list
            // the plugin invents, and never one fixed at render time, because
            // “Mark stable” moves a component between them without a reload.
            var labels = statusFilter.closest('.cg-filters');
            var allLabel = statusFilter.options.length ? statusFilter.options[0].textContent : '';
            var labelFor = function (bucket) {
                if (bucket === '__nostory') { return (labels && labels.getAttribute('data-label-nostory')) || bucket; }
                if (bucket === '__nostatus') { return (labels && labels.getAttribute('data-label-nostatus')) || bucket; }
                return bucket;
            };

            refreshStatusFilter = function () {
                var seen = {};
                subjects.forEach(function (el) { seen[statusOf(el)] = true; });
                // Written statuses first, alphabetically; the two buckets that
                // stand for something missing belong at the end of the list.
                var values = Object.keys(seen).sort(function (a, b) {
                    var sa = a.indexOf('__') === 0 ? 1 : 0;
                    var sb = b.indexOf('__') === 0 ? 1 : 0;
                    return sa !== sb ? sa - sb : (a < b ? -1 : (a > b ? 1 : 0));
                });

                var wanted = statusFilter.value;
                statusFilter.textContent = '';
                statusFilter.appendChild(new Option(allLabel, ''));
                values.forEach(function (value) {
                    statusFilter.appendChild(new Option(labelFor(value), value));
                });
                // A selection whose status no longer exists anywhere would
                // leave the page filtered to nothing, with no way to read why.
                statusFilter.value = values.indexOf(wanted) !== -1 ? wanted : '';

                // One status is not a choice.
                var control = statusFilter.parentNode;
                if (control) { control.classList.toggle('hidden', values.length < 2); }
            };

            refreshStatusFilter();
        }

        // Put back what was set before — only values that still exist, so a
        // renamed group or a status nobody has any more cannot filter to
        // nothing — then apply it.
        var saved = load();
        var hasOption = function (select, value) {
            return Array.prototype.some.call(select.options, function (o) { return o.value === value; });
        };
        if (search && typeof saved.term === 'string') { search.value = saved.term; }
        if (groupFilter && saved.group && hasOption(groupFilter, saved.group)) { groupFilter.value = saved.group; }
        if (statusFilter && saved.status && hasOption(statusFilter, saved.status)) { statusFilter.value = saved.status; }
        filter();
    }

    // --- Index: scale card thumbnails ---
    // Each thumb iframe renders at a real 1280px viewport; scale it down to the
    // thumb box width (the box's 16:10 aspect matches 1280×800, so it fills).
    var thumbs = Array.prototype.slice.call(document.querySelectorAll('[data-cg-thumb]'));
    if (thumbs.length) {
        var scaleThumbs = function () {
            thumbs.forEach(function (thumb) {
                var iframe = thumb.querySelector('iframe');
                if (iframe && thumb.clientWidth > 0) {
                    iframe.style.transform = 'scale(' + (thumb.clientWidth / 1280) + ')';
                }
            });
        };
        var thumbRaf = null;
        window.addEventListener('resize', function () {
            if (thumbRaf) { return; }
            thumbRaf = requestAnimationFrame(function () { thumbRaf = null; scaleThumbs(); });
        });
        scaleThumbs();
    }

    // --- Detail: copy Twig snippet ---
    var copyBtn = document.querySelector('[data-cg-copy]');
    var snippet = document.querySelector('[data-cg-snippet]');
    if (copyBtn && snippet) {
        copyBtn.addEventListener('click', function () {
            var text = snippet.textContent || '';
            var done = function () {
                var original = copyBtn.textContent;
                copyBtn.textContent = 'Copied!';
                setTimeout(function () { copyBtn.textContent = original; }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () {});
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                document.body.removeChild(ta);
            }
        });
    }

    // --- Detail: device preview (mirrors Craft's own Live Preview) ---
    // The iframe holder is sized to EXACT device pixels; the whole holder is
    // transform:scale()'d to fit the stage. The iframe itself always stays
    // width/height:100% — the one layout Chrome composites reliably at any zoom.
    // Switching device only resizes/scales the holder, so the iframe never reloads.
    var frame = document.querySelector('.cg-preview__frame');
    var stage = document.querySelector('[data-cg-stage]');
    if (frame && stage) {
        var src = frame.getAttribute('data-cg-src');
        var title = frame.getAttribute('data-cg-title') || '';
        var devButtons = Array.prototype.slice.call(document.querySelectorAll('[data-cg-device]'));
        var rotateBtn = document.querySelector('[data-cg-rotate]');
        var refreshBtn = document.querySelector('[data-cg-refresh]');
        var widthLabel = document.querySelector('[data-cg-width]');

        // Portrait mask dimensions for phone/tablet match Craft's own presets;
        // desktop simulates a full-HD monitor (scaled to fit, no bezel).
        var DEVICES = {
            desktop: { width: 1920, height: 1080 },
            phone:  { width: 375, height: 753 },
            tablet: { width: 768, height: 1110 }
        };
        var NAMED = {
            mobile: 'phone', phone: 'phone',
            tablet: 'tablet', ipad: 'tablet',
            desktop: 'desktop', full: 'desktop', laptop: 'desktop'
        };

        var currentDevice = 'desktop';
        var orientation = 'portrait';
        var container = null; // .cg-device-preview-container (holds the iframe)
        var mask = null;      // .cg-device-mask (SVG bezel)
        var iframe = null;

        // A 300px strip shouldn't sit at the top of a 1080px white screen. The
        // device stays whole — same size, same scale — and the stage is cropped
        // to the component, so the screen runs on past both edges. The
        // height is measured in device px (it holds across window resizes) and
        // re-measured whenever the device or orientation changes. null = no
        // crop: a tall component, or a frame we can't read.
        var contentH = null;
        var MIN_CROP_H = 200;
        var FRAME_H = frame.clientHeight || 640;

        // Desktop: width only — it is what the breakpoints answer to, and the
        // monitor's height is never the point. Phone and tablet: width × height,
        // so Rotate visibly swaps the two.
        var showWidth = function () {
            if (!widthLabel) { return; }
            var d = DEVICES[currentDevice];
            var w = orientation === 'landscape' ? d.height : d.width;
            var h = orientation === 'landscape' ? d.width : d.height;
            widthLabel.textContent = currentDevice === 'desktop' ? w + 'px' : w + '×' + h;
        };

        // Port of Craft's updateDevicePreview(): fit the device into the stage with
        // a single scale, size the holder to device px, and scale it into place.
        // Desktop goes through the same path (a 1920×1080 "monitor"), just with no
        // bezel mask and no notch offset.
        var layout = function () {
            stage.classList.toggle('is-desktop', currentDevice === 'desktop');
            stage.classList.toggle('is-phone', currentDevice === 'phone');
            stage.classList.toggle('is-tablet', currentDevice === 'tablet');

            var d = DEVICES[currentDevice];
            var hasMask = currentDevice !== 'desktop';
            var availH = FRAME_H - 32;
            var availW = stage.clientWidth - 32;

            var t = 1, e = 1;
            if (orientation === 'landscape') {
                if (availW < d.height) { t = availW / d.height; }
                if (availH < d.width)  { e = availH / d.width; }
            } else {
                if (availH < d.height) { t = availH / d.height; }
                if (availW < d.width)  { e = availW / d.width; }
            }
            var n = Math.min(t, e);
            var o = -100 / n / 2; // pre-scale translate to re-center after scale()
            var rot = orientation === 'landscape' ? '-90deg' : '0deg';

            // Top-anchor: the translate() math centres the device on its anchor
            // point, so put that anchor at 16px + half the scaled visual height —
            // the device then hugs the top of the stage instead of floating mid-air.
            var visualH = n * (orientation === 'landscape' ? d.width : d.height);
            var centerY = 16 + visualH / 2;

            // The 12px offset re-centres the screen inside the bezel (top chrome
            // is 31px, bottom 55px) — desktop has no bezel, so no offset.
            var off = hasMask ? 12 * n : 0;

            // Cropped to a short component: the screen starts at the stage's top
            // edge and ends at its bottom one, so the component reads as a slice
            // of a page rather than the section that opens it.
            if (contentH !== null) {
                centerY -= 16 - (orientation === 'landscape' ? 0 : off);
            }

            // Mask always uses portrait dims; landscape is achieved by rotating it.
            if (hasMask) {
                mask.style.top = centerY + 'px';
                mask.style.width = d.width + 'px';
                mask.style.height = d.height + 'px';
                mask.style.transform = 'scale(' + n + ') translate(' + o + '%, ' + o + '%) rotate(' + rot + ')';
            }

            // The screen holder swaps its dims for landscape (so the iframe's own
            // viewport is landscape) but is NOT rotated — content stays upright.
            container.style.top = centerY + 'px';
            container.style.width = (orientation === 'landscape' ? d.height : d.width) + 'px';
            container.style.height = (orientation === 'landscape' ? d.width : d.height) + 'px';
            container.style.transform = 'scale(' + n + ') translate(' + o + '%, ' + o + '%)';
            container.style.marginTop = orientation === 'landscape' ? '0' : ('-' + off + 'px');
            container.style.marginLeft = orientation === 'landscape' ? ('-' + off + 'px') : '0';

            // Exactly the component's height when cropped. Otherwise the whole
            // device plus 16px above and below — a landscape phone or a monitor
            // scaled by width is shorter than the stylesheet's stage, and the
            // rest of it was empty.
            frame.style.height = Math.min(FRAME_H, Math.ceil(
                contentH === null ? visualH + 32 : n * contentH
            )) + 'px';

            showWidth();
        };

        // Measure the component on the device as it is, then crop the stage to
        // it. The device itself never changes size, so content sized by the
        // viewport is measured honestly. Same-origin only (as the gallery's
        // thumbnails): a control panel on another origin can't read the frame
        // and keeps the full stage.
        var fitDevice = function () {
            if (!iframe) { return; }
            contentH = null;
            try {
                var doc = iframe.contentDocument;
                if (doc && doc.body && doc.location.href !== 'about:blank') {
                    var d = DEVICES[currentDevice];
                    var full = orientation === 'landscape' ? d.width : d.height;
                    var h = Math.ceil(doc.body.scrollHeight);
                    if (h && h < full) { contentH = Math.max(h, MIN_CROP_H); }
                }
            } catch (e) { /* cross-origin — no crop */ }
            layout();
        };

        var build = function () {
            frame.classList.remove('is-loaded');

            mask = document.createElement('div');
            mask.className = 'cg-device-mask';

            container = document.createElement('div');
            container.className = 'cg-device-preview-container';

            iframe = document.createElement('iframe');
            iframe.setAttribute('title', title);
            iframe.setAttribute('name', cgPreview.frameName);
            iframe.addEventListener('load', function () {
                frame.classList.add('is-loaded');
                fitDevice();
            });
            container.appendChild(iframe);

            // Attach to the DOM first, THEN set src — setting src on a detached
            // iframe lets Chrome "load" about:blank before insertion.
            stage.replaceChildren(mask, container);
            iframe.src = src;
            layout();
        };

        var reload = function () {
            frame.classList.remove('is-loaded');
            // Changed values are posted, never put in the frame's URL.
            if (cgPreview.post && cgPreview.post()) { return; }
            iframe.contentWindow.location.replace(src + (src.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now());
        };
        cgPreview.reload = reload;

        var selectDevice = function (device, btn) {
            currentDevice = DEVICES[device] ? device : 'desktop';
            orientation = 'portrait';
            devButtons.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
            if (rotateBtn) { rotateBtn.hidden = currentDevice === 'desktop'; }
            layout();
            fitDevice();
        };

        devButtons.forEach(function (b) {
            b.addEventListener('click', function () {
                selectDevice(b.getAttribute('data-cg-device'), b);
            });
        });

        if (rotateBtn) {
            rotateBtn.addEventListener('click', function () {
                if (currentDevice === 'desktop') { return; }
                orientation = orientation === 'portrait' ? 'landscape' : 'portrait';
                layout();
                fitDevice();
            });
        }
        if (refreshBtn) {
            refreshBtn.addEventListener('click', reload);
        }

        // Re-fit on resize (rAF-debounced).
        var raf = null;
        window.addEventListener('resize', function () {
            if (raf) { return; }
            raf = requestAnimationFrame(function () { raf = null; layout(); });
        });

        build();

        // Default device from the story `viewport` (named), else Desktop.
        var raw = (frame.getAttribute('data-cg-default-device') || '').trim().toLowerCase();
        var defaultDevice = NAMED[raw] || 'desktop';
        var defaultBtn = devButtons.filter(function (b) {
            return b.getAttribute('data-cg-device') === defaultDevice;
        })[0] || devButtons[0];
        selectDevice(defaultDevice, defaultBtn);
    }

    // --- Detail: controls ---
    // Change a story's values and watch the component answer. The values are
    // POSTed with the CSRF token of whoever typed them; the frame's own URL
    // never carries them (see _controls.twig for why).
    //
    // Rendered through fetch and handed to the frame as a document of its own
    // with location.replace(): a form posted into the frame would add a history
    // entry per keystroke, and Back would walk through every one of them.
    (function () {
        var root = document.querySelector('[data-cg-controls]');
        var frameEl = document.querySelector('.cg-preview__frame');
        var form = root && root.querySelector('[data-cg-args-form]');
        if (!root || !frameEl || !form) { return; }

        var rows = Array.prototype.slice.call(root.querySelectorAll('[data-cg-control]'));
        var argsInput = form.querySelector('input[name="args"]');
        var changedNote = root.querySelector('[data-cg-controls-changed]');
        var resetBtn = root.querySelector('[data-cg-controls-reset]');
        var copyBtn = root.querySelector('[data-cg-controls-copy]');
        var linked = root.querySelector('[data-cg-controls-linked]');
        var openTab = document.querySelector('[data-cg-open-tab]');
        var src = frameEl.getAttribute('data-cg-src');

        // Craft draws a disabled button from the `disabled` class, not the
        // attribute — with the attribute alone it looks clickable and is not.
        var enable = function (button, on) {
            if (!button) { return; }
            button.disabled = !on;
            button.classList.toggle('disabled', !on);
        };

        var lastValid = {};
        var current = null;
        var sequence = 0;

        var nameOf = function (row) { return row.getAttribute('data-cg-control'); };
        var kindOf = function (row) { return row.getAttribute('data-kind'); };
        var isSet = function (row) { return row.getAttribute('data-set') === '1'; };
        var inputOf = function (row) { return row.querySelector('input:not([type="hidden"]), select, textarea'); };
        var original = function (row) {
            try { return JSON.parse(row.getAttribute('data-original')); } catch (e) { return null; }
        };

        var showError = function (row, message) {
            var error = row.querySelector('[data-cg-control-error]');
            if (!error) { return; }
            error.textContent = message || '';
            error.hidden = !message;
        };

        // The value a control holds, in the type the story would give it.
        var read = function (row) {
            var el = inputOf(row);
            var name = nameOf(row);
            switch (kindOf(row)) {
                case 'bool':
                    return el.checked;
                case 'number':
                    if (el.value.trim() === '') { return null; }
                    var n = Number(el.value);
                    return isNaN(n) ? null : n;
                case 'json':
                    var raw = el.value.trim();
                    if (raw === '') { showError(row, ''); return null; }
                    try {
                        var parsed = JSON.parse(raw);
                        showError(row, '');
                        lastValid[name] = parsed;
                        return parsed;
                    } catch (e) {
                        showError(row, root.getAttribute('data-msg-json'));
                        return Object.prototype.hasOwnProperty.call(lastValid, name) ? lastValid[name] : original(row);
                    }
                default:
                    return el.value;
            }
        };

        var write = function (row, value) {
            var el = inputOf(row);
            switch (kindOf(row)) {
                case 'bool':
                    el.checked = !!value;
                    break;
                case 'json':
                    el.value = value === null || value === undefined ? '' : JSON.stringify(value, null, 2);
                    showError(row, '');
                    break;
                case 'select':
                    var wanted = value === null || value === undefined ? '' : String(value);
                    var has = Array.prototype.some.call(el.options, function (o) { return o.value === wanted; });
                    if (!has) {
                        var option = document.createElement('option');
                        option.value = wanted;
                        option.textContent = wanted === '' ? '—' : wanted;
                        el.appendChild(option);
                    }
                    el.value = wanted;
                    break;
                default:
                    el.value = value === null || value === undefined ? '' : String(value);
            }
        };

        var unchanged = function (row, value) {
            var before = original(row);
            if (!isSet(row)) {
                // Leaving an argument empty is not setting it.
                return value === '' || value === null || value === false;
            }
            if (before !== null && typeof before === 'object') {
                return JSON.stringify(before) === JSON.stringify(value);
            }
            if (kindOf(row) === 'bool' || kindOf(row) === 'number') {
                return before === value;
            }
            return String(before === null ? '' : before) === String(value === null ? '' : value);
        };

        // Argument name => value, for the controls that differ from the
        // story; null when none do.
        var collect = function () {
            var out = {};
            var any = false;
            rows.forEach(function (row) {
                var value = read(row);
                if (!unchanged(row, value)) {
                    out[nameOf(row)] = value;
                    any = true;
                }
            });
            return any ? out : null;
        };

        var encode = function (obj) {
            var bytes = new TextEncoder().encode(JSON.stringify(obj));
            var bin = '';
            for (var i = 0; i < bytes.length; i++) { bin += String.fromCharCode(bytes[i]); }
            return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        };
        var decode = function (text) {
            var b64 = text.replace(/-/g, '+').replace(/_/g, '/');
            while (b64.length % 4) { b64 += '='; }
            var bin = atob(b64);
            var bytes = new Uint8Array(bin.length);
            for (var i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
            return JSON.parse(new TextDecoder().decode(bytes));
        };

        // The page's own address keeps the changes, so “this is what I see”
        // can be sent as a link. Replaced, not pushed: Back leaves the page.
        var remember = function (values) {
            try {
                var url = new URL(window.location.href);
                if (values) { url.searchParams.set('args', encode(values)); } else { url.searchParams.delete('args'); }
                window.history.replaceState(window.history.state, '', url.toString());
            } catch (e) { /* an address we cannot rewrite — the controls still work */ }
        };

        var iframe = function () { return frameEl.querySelector('iframe'); };

        // The preview document is served from the preview route; a <base>
        // keeps its relative URLs (styles, scripts, images) pointing there.
        var withBase = function (html, base) {
            var tag = '<base href="' + base.replace(/"/g, '&quot;') + '">';
            return /<head[^>]*>/i.test(html) ? html.replace(/<head[^>]*>/i, function (m) { return m + tag; }) : tag + html;
        };

        var postToFrame = function (values) {
            var frameNow = iframe();
            if (!frameNow) { return; }
            argsInput.value = JSON.stringify(values);

            var body = new FormData(form);
            var mine = ++sequence;
            fetch(form.action, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (response) { return response.text(); })
                .then(function (html) {
                    // Typing outruns rendering; only the latest answer is shown.
                    if (mine !== sequence) { return; }
                    var base = new URL(form.action, window.location.href).toString();
                    var blob = new Blob([withBase(html, base)], { type: 'text/html' });
                    var url = URL.createObjectURL(blob);
                    var target = iframe();
                    target.addEventListener('load', function revoke() {
                        target.removeEventListener('load', revoke);
                        URL.revokeObjectURL(url);
                    });
                    target.contentWindow.location.replace(url);
                })
                .catch(function () {
                    // A preview on another origin cannot be read back; post
                    // into the frame instead, history entry and all.
                    if (mine !== sequence) { return; }
                    form.target = cgPreview.frameName;
                    form.submit();
                });
        };

        cgPreview.post = function () {
            if (!current) { return false; }
            postToFrame(current);
            return true;
        };

        var refresh = function () {
            current = collect();
            if (changedNote) { changedNote.hidden = !current; }
            enable(resetBtn, !!current);
            enable(copyBtn, !!current);
            remember(current);

            if (current) {
                postToFrame(current);
            } else {
                sequence++;
                var frameNow = iframe();
                if (frameNow) { frameNow.contentWindow.location.replace(src); }
            }
        };

        var timer = null;
        var later = function () {
            clearTimeout(timer);
            timer = setTimeout(refresh, 350);
        };

        rows.forEach(function (row) {
            var el = inputOf(row);
            if (!el) { return; }
            var kind = kindOf(row);
            if (kind === 'bool' || kind === 'select') {
                el.addEventListener('change', refresh);
            } else {
                el.addEventListener('input', later);
                el.addEventListener('change', function () { clearTimeout(timer); refresh(); });
            }
        });

        // An asset on the site the preview is served from is written as a
        // root-relative path: the story it ends up in is committed and run on
        // local, staging and production, and only the path is the same on all
        // of them.
        var portable = function (url) {
            try {
                var asset = new URL(url, window.location.href);
                var site = new URL(form.action, window.location.href);
                return asset.origin === site.origin ? asset.pathname + asset.search : url;
            } catch (e) {
                return url;
            }
        };

        // An image argument takes a URL; the site's own assets are one click
        // away, through Craft's own asset modal — uploading included, where
        // the person may upload. Nothing is attached to anything: the preview
        // just gets the asset's URL.
        rows.forEach(function (row) {
            var pick = row.querySelector('[data-cg-control-image]');
            if (!pick || !window.Craft || !Craft.createElementSelectorModal) {
                if (pick) { pick.hidden = true; }
                return;
            }
            pick.addEventListener('click', function () {
                Craft.createElementSelectorModal('craft\\elements\\Asset', {
                    multiSelect: false,
                    criteria: { kind: ['image'] },
                    onSelect: function (elements) {
                        var element = elements && elements[0];
                        if (!element) { return; }
                        var url = element.url || (element.$element && element.$element.data('url')) || '';
                        if (!url) {
                            if (Craft.cp && Craft.cp.displayError) { Craft.cp.displayError(root.getAttribute('data-msg-no-url')); }
                            return;
                        }
                        write(row, portable(url));
                        clearTimeout(timer);
                        refresh();
                    }
                });
            });
        });

        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                lastValid = {};
                rows.forEach(function (row) { write(row, isSet(row) ? original(row) : null); });
                refresh();
            });
        }

        if (openTab) {
            openTab.addEventListener('click', function (event) {
                if (!current) { return; }
                event.preventDefault();
                argsInput.value = JSON.stringify(current);
                form.target = '_blank';
                form.submit();
                form.target = cgPreview.frameName;
            });
        }

        // The story is always shown under the controls as well: the clipboard
        // can refuse (a browser without the permission, focus lost while the
        // request was out), and a refusal must not look like nothing happened.
        var shown = root.querySelector('[data-cg-controls-snippet]');
        var shownText = shown && shown.querySelector('textarea');

        var execCopy = function (text) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(ta);
            return ok;
        };

        var copyText = function (text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(text).then(
                    function () { return true; },
                    function () { return execCopy(text); }
                );
            }
            return Promise.resolve(execCopy(text));
        };

        if (copyBtn && window.Craft && Craft.sendActionRequest) {
            copyBtn.addEventListener('click', function () {
                enable(copyBtn, false);
                Craft.sendActionRequest('POST', 'component-guide/components/story-snippet', {
                    data: {
                        componentId: root.getAttribute('data-component-id'),
                        storyId: root.getAttribute('data-story-id'),
                        args: JSON.stringify(current || {})
                    }
                }).then(function (response) {
                    var text = ((response && response.data) || {}).snippet || '';
                    if (shown && shownText) {
                        shownText.value = text;
                        shownText.rows = Math.min(16, text.split('\n').length + 1);
                        shown.hidden = false;
                    }
                    return copyText(text).then(function (copied) {
                        if (copied) {
                            if (Craft.cp && Craft.cp.displayNotice) { Craft.cp.displayNotice(root.getAttribute('data-msg-copied')); }
                        } else if (shownText) {
                            shownText.focus();
                            shownText.select();
                            if (Craft.cp && Craft.cp.displayError) { Craft.cp.displayError(root.getAttribute('data-msg-copy-manual')); }
                        }
                    });
                }).catch(function (error) {
                    var body = error && error.response && error.response.data;
                    if (Craft.cp && Craft.cp.displayError) {
                        Craft.cp.displayError((body && body.message) || root.getAttribute('data-msg-copy-error'));
                    }
                    if (window.console) { console.error('[component-guide] Copy as story:', error); }
                }).then(function () {
                    enable(copyBtn, !!current);
                });
            });
        }

        // Opened from a link that carries changes: say so, and wait. Applying
        // them is one click — but it is the reader's click, not the link's.
        var linkedValues = null;
        try {
            var param = new URL(window.location.href).searchParams.get('args');
            if (param) {
                var decoded = decode(param);
                if (decoded && typeof decoded === 'object' && !Array.isArray(decoded)) {
                    linkedValues = {};
                    rows.forEach(function (row) {
                        if (Object.prototype.hasOwnProperty.call(decoded, nameOf(row))) {
                            linkedValues[nameOf(row)] = decoded[nameOf(row)];
                        }
                    });
                    if (!Object.keys(linkedValues).length) { linkedValues = null; }
                }
            }
        } catch (e) { linkedValues = null; }

        if (linkedValues && linked) {
            linked.hidden = false;
            linked.querySelector('[data-cg-controls-apply]').addEventListener('click', function () {
                rows.forEach(function (row) {
                    if (Object.prototype.hasOwnProperty.call(linkedValues, nameOf(row))) {
                        write(row, linkedValues[nameOf(row)]);
                    }
                });
                linked.hidden = true;
                refresh();
            });
            linked.querySelector('[data-cg-controls-ignore]').addEventListener('click', function () {
                linked.hidden = true;
                remember(null);
            });
        } else if (new URL(window.location.href).searchParams.has('args')) {
            remember(null);
        }
    })();

    // --- Status toggle: draft <-> stable, without leaving the page ---
    // A reviewer working through forty generated drafts is somewhere in the
    // middle of a long list. A form post would reload and send them back to
    // the top after every decision, so this posts over fetch and repaints only
    // what changed: the chip, the button, the "in gallery" flag, the counter,
    // and the list of files the control panel has written.
    //
    // Nothing here is translated in JS. Every label and message was rendered
    // server-side into a data attribute, because Craft.t() only knows a
    // category that has been registered for the page and would otherwise
    // silently fall back to English.
    var statusToggles = Array.prototype.slice.call(document.querySelectorAll('[data-cg-status-toggle]'));
    if (statusToggles.length && window.Craft && Craft.sendActionRequest) {
        var STATUS_CLASSES = ['cg-chip--stable', 'cg-chip--beta', 'cg-chip--draft', 'cg-chip--deprecated'];
        var banner = document.querySelector('[data-cg-writes]');

        var notice = function (message) {
            if (message && Craft.cp && Craft.cp.displayNotice) { Craft.cp.displayNotice(message); }
        };
        var failure = function (message) {
            if (message && Craft.cp && Craft.cp.displayError) { Craft.cp.displayError(message); }
        };

        var repaintWrites = function (writes) {
            if (!banner) { return; }
            var list = banner.querySelector('[data-cg-writes-list]');
            if (list) {
                list.textContent = '';
                writes.forEach(function (write) {
                    var li = document.createElement('li');
                    var code = document.createElement('code');
                    code.textContent = write.file;
                    var note = document.createElement('span');
                    note.className = 'light';
                    note.textContent = write.action === 'scaffold'
                        ? banner.getAttribute('data-word-scaffold')
                        : banner.getAttribute('data-word-status');
                    li.appendChild(code);
                    li.appendChild(note);
                    list.appendChild(li);
                });
            }
            banner.hidden = writes.length === 0;
        };

        var repaint = function (scope, data) {
            var chip = scope.querySelector('[data-cg-status-chip]');
            if (chip && data.status) {
                STATUS_CLASSES.forEach(function (c) { chip.classList.remove(c); });
                chip.classList.add('cg-chip--' + data.status);
                chip.textContent = data.status;
            }

            var gallery = scope.querySelector('[data-cg-gallery-chip]');
            if (gallery) { gallery.hidden = !data.inGallery; }

            // The status filter reads this attribute, so it has to move with
            // the status — otherwise filtering right after a toggle answers
            // from the page as it was rendered, not as it is.
            var card = scope.closest ? scope.closest('[data-card]') : null;
            if (card && data.status) { card.setAttribute('data-card-status', data.status); }
        };

        statusToggles.forEach(function (button) {
            button.addEventListener('click', function (event) {
                // The card is one big overlay link; a click on the button must
                // not reach it.
                event.preventDefault();
                event.stopPropagation();

                var next = button.getAttribute('data-status') === 'draft' ? 'stable' : 'draft';
                button.disabled = true;

                Craft.sendActionRequest('POST', 'component-guide/components/set-status', {
                    data: {
                        componentId: button.getAttribute('data-component-id'),
                        status: next
                    }
                }).then(function (response) {
                    var data = (response && response.data) || {};
                    var status = data.status || next;

                    button.setAttribute('data-status', status);
                    button.textContent = status === 'draft'
                        ? button.getAttribute('data-label-stable')
                        : button.getAttribute('data-label-draft');

                    // The card on the index, or the whole header on a detail page.
                    repaint(button.closest('.cg-card') || document, data);

                    // The sidebar's status filter reads the tree on a
                    // component page, so the tree has to move with it too.
                    var treeItem = document.querySelector('[data-cg-tree-item][data-component-id="'
                        + CSS.escape(button.getAttribute('data-component-id') || '') + '"]');
                    if (treeItem) {
                        treeItem.setAttribute('data-tree-status', status);
                        var dot = treeItem.querySelector('[data-cg-tree-dot]');
                        if (dot) {
                            var colors = { stable: 'green', beta: 'blue', draft: 'orange', deprecated: 'red' };
                            dot.className = 'status ' + (colors[status] || 'cg-status--none');
                            dot.title = status;
                        }
                    }

                    var readyCount = document.querySelector('[data-cg-ready-count]');
                    if (readyCount && typeof data.galleryReadyCount === 'number') {
                        readyCount.textContent = data.galleryReadyCount;
                    }

                    if (refreshStatusFilter) {
                        refreshStatusFilter();
                        filter();
                    }

                    if (data.writes) { repaintWrites(data.writes); }
                    notice(data.message);
                }).catch(function (error) {
                    var body = error && error.response && error.response.data;
                    failure((body && body.message) || button.getAttribute('data-error-status'));
                }).then(function () {
                    button.disabled = false;
                });
            });
        });

        var reviewed = document.querySelector('[data-cg-reviewed]');
        if (reviewed && banner) {
            reviewed.addEventListener('click', function () {
                reviewed.disabled = true;
                Craft.sendActionRequest('POST', 'component-guide/components/mark-reviewed')
                    .then(function (response) {
                        repaintWrites(((response && response.data) || {}).writes || []);
                    })
                    .catch(function () {
                        failure(banner.getAttribute('data-error-reviewed'));
                    })
                    .then(function () { reviewed.disabled = false; });
            });
        }
    }

    // --- Render errors reported by preview frames ---------------------------

    (function () {
        var badges = document.querySelectorAll('[data-cg-render-badge]');
        if (!badges.length) { return; }

        window.addEventListener('message', function (event) {
            var data = event.data;
            if (!data || data.source !== 'component-guide' || data.state !== 'error') { return; }

            // Trust our own frames only, whatever origin they were served from:
            // matching the source window is exact, and needs no origin plumbing.
            var frames = document.querySelectorAll('[data-cg-thumb] iframe');
            var known = false;
            for (var i = 0; i < frames.length; i++) {
                if (frames[i].contentWindow === event.source) { known = true; break; }
            }
            if (!known) { return; }

            var card = document.getElementById('cg-card-' + data.componentId);
            var badge = card && card.querySelector('[data-cg-render-badge]');
            if (!badge) { return; }

            // The message is rendered by the server and passed through as-is;
            // nothing is composed here (see the note on translations above).
            badge.title = data.message || '';
            badge.hidden = false;
        });
    })();

})();
