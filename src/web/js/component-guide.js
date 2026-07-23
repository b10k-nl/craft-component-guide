/* Component Guide — minimal vanilla JS: search, copy, iframe controls. */
(function () {
    'use strict';

    // --- Index: client-side search filter ---
    var search = document.getElementById('cg-search');
    if (search) {
        var cards = Array.prototype.slice.call(document.querySelectorAll('[data-card]'));
        var groups = Array.prototype.slice.call(document.querySelectorAll('[data-group]'));
        var noResults = document.getElementById('cg-no-results');

        var filter = function () {
            var term = search.value.trim().toLowerCase();
            var anyVisible = false;

            cards.forEach(function (card) {
                var match = term === '' || (card.getAttribute('data-search') || '').indexOf(term) !== -1;
                card.classList.toggle('hidden', !match);
                if (match) { anyVisible = true; }
            });

            // Hide groups with no visible cards.
            groups.forEach(function (group) {
                var visible = group.querySelectorAll('[data-card]:not(.hidden)').length;
                group.classList.toggle('hidden', visible === 0);
            });

            if (noResults) { noResults.classList.toggle('hidden', anyVisible); }
        };

        search.addEventListener('input', filter);
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

        var showWidth = function () {
            if (!widthLabel) { return; }
            var d = DEVICES[currentDevice];
            var w = orientation === 'landscape' ? d.height : d.width;
            var h = orientation === 'landscape' ? d.width : d.height;
            widthLabel.textContent = w + '×' + h;
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
            var availH = stage.clientHeight - 32;
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

            // Mask always uses portrait dims; landscape is achieved by rotating it.
            if (hasMask) {
                mask.style.top = centerY + 'px';
                mask.style.width = d.width + 'px';
                mask.style.height = d.height + 'px';
                mask.style.transform = 'scale(' + n + ') translate(' + o + '%, ' + o + '%) rotate(' + rot + ')';
            }

            // The screen holder swaps its dims for landscape (so the iframe's own
            // viewport is landscape) but is NOT rotated — content stays upright.
            // The 12px offset re-centres the screen inside the bezel (top chrome is
            // 31px, bottom 55px) — desktop has no bezel, so no offset.
            var off = hasMask ? 12 * n : 0;
            container.style.top = centerY + 'px';
            container.style.width = (orientation === 'landscape' ? d.height : d.width) + 'px';
            container.style.height = (orientation === 'landscape' ? d.width : d.height) + 'px';
            container.style.transform = 'scale(' + n + ') translate(' + o + '%, ' + o + '%)';
            container.style.marginTop = orientation === 'landscape' ? '0' : ('-' + off + 'px');
            container.style.marginLeft = orientation === 'landscape' ? ('-' + off + 'px') : '0';

            showWidth();
        };

        var build = function () {
            frame.classList.remove('is-loaded');

            mask = document.createElement('div');
            mask.className = 'cg-device-mask';

            container = document.createElement('div');
            container.className = 'cg-device-preview-container';

            iframe = document.createElement('iframe');
            iframe.setAttribute('title', title);
            iframe.setAttribute('sandbox', 'allow-same-origin allow-scripts allow-popups allow-forms');
            iframe.addEventListener('load', function () { frame.classList.add('is-loaded'); });
            container.appendChild(iframe);

            // Attach to the DOM first, THEN set src — setting src on a detached
            // iframe lets Chrome "load" about:blank before insertion.
            stage.replaceChildren(mask, container);
            iframe.src = src;
            layout();
        };

        var reload = function () {
            frame.classList.remove('is-loaded');
            iframe.src = src + (src.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
        };

        var selectDevice = function (device, btn) {
            currentDevice = DEVICES[device] ? device : 'desktop';
            orientation = 'portrait';
            devButtons.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
            if (rotateBtn) { rotateBtn.hidden = currentDevice === 'desktop'; }
            layout();
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
})();
