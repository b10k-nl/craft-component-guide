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
    var reviewFilter = document.getElementById('cg-filter-review');
    var usageFilter = document.getElementById('cg-filter-usage');

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

    if (search || groupFilter || statusFilter || reviewFilter || usageFilter) {
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
        // The client's verdict, beside the developer's status (see _tree).
        var reviewOf = function (el) {
            return el.getAttribute(onIndex ? 'data-card-review' : 'data-tree-review') || 'review';
        };
        // On a page or not; empty for a component no entry type matches.
        var usageOf = function (el) {
            return el.getAttribute(onIndex ? 'data-card-usage' : 'data-tree-usage') || '';
        };

        // Card size: how wide a card may get — S, M (the default) or L. The
        // grid fills each row with as many as the window holds, so it is a
        // size, not a count. Remembered in this browser.
        var SIZE_STORE = 'componentGuide.cardSize';
        var sizeControl = document.querySelector('[data-cg-card-size]');
        if (sizeControl) {
            var applySize = function (size) {
                if (size === 'm') {
                    document.documentElement.removeAttribute('data-cg-card-size-value');
                } else {
                    document.documentElement.setAttribute('data-cg-card-size-value', size);
                }
                Array.prototype.forEach.call(sizeControl.querySelectorAll('[data-size]'), function (btn) {
                    btn.setAttribute('aria-pressed', btn.getAttribute('data-size') === size ? 'true' : 'false');
                });
            };
            var savedSize = 'm';
            try { savedSize = window.localStorage.getItem(SIZE_STORE) || 'm'; } catch (e) { /* no storage */ }
            applySize(['s', 'm', 'l'].indexOf(savedSize) === -1 ? 'm' : savedSize);
            sizeControl.hidden = false;
            sizeControl.addEventListener('click', function (event) {
                var btn = event.target.closest('[data-size]');
                if (!btn) { return; }
                var size = btn.getAttribute('data-size');
                applySize(size);
                try { window.localStorage.setItem(SIZE_STORE, size); } catch (e) { /* not remembered */ }
            });
        }

        // “Variants as cards”: the index shows either the block cards or the
        // story cards, and only those count. Remembered in this browser.
        var MODE_STORE = 'componentGuide.storyCards';
        var modeToggle = document.getElementById('cg-story-cards');
        var storyMode = false;
        if (modeToggle) {
            try { storyMode = window.localStorage.getItem(MODE_STORE) === '1'; } catch (e) { /* no storage */ }
            modeToggle.checked = storyMode;
        }
        var inMode = function (el) {
            return !onIndex || el.hasAttribute('data-story-card') === storyMode;
        };
        var applyMode = function () {
            var mode = storyMode ? 'stories' : 'blocks';
            Array.prototype.forEach.call(document.querySelectorAll('[data-cg-cards], [data-cg-counts]'), function (el) {
                el.hidden = (el.getAttribute('data-cg-cards') || el.getAttribute('data-cg-counts')) !== mode;
            });
        };
        applyMode();

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
            var wantReview = reviewFilter ? reviewFilter.value : '';
            var wantUsage = usageFilter ? usageFilter.value : '';
            var active = term !== '' || wantGroup !== '' || wantStatus !== '' || wantReview !== '' || wantUsage !== '';
            var shown = 0;

            save({ term: search ? search.value : '', group: wantGroup, status: wantStatus, review: wantReview, usage: wantUsage });

            var visibleIds = {};
            subjects.forEach(function (el) {
                var match = termMatches(el.getAttribute('data-search'))
                    && (wantGroup === '' || groupOf(el) === wantGroup)
                    && (wantStatus === '' || statusOf(el) === wantStatus)
                    && (wantReview === '' || reviewOf(el) === wantReview)
                    && (wantUsage === '' || usageOf(el) === wantUsage);
                if (match && inMode(el)) {
                    shown++;
                    if (onIndex) { visibleIds[el.getAttribute('data-component-id')] = true; }
                }
                if (onIndex) {
                    el.classList.toggle('hidden', !match);
                } else {
                    // Filters mean what they say, the page you are on included:
                    // “Approved · 0” with an unapproved block in the list read
                    // as a filter that does not work.
                    el.hidden = !match;
                }
            });

            if (onIndex) {
                groups.forEach(function (group) {
                    group.classList.toggle('hidden', !group.querySelector('[data-cg-cards]:not([hidden]) [data-card]:not(.hidden)'));
                });
                if (noResults) { noResults.classList.toggle('hidden', shown > 0); }

                // The tree shows exactly the blocks whose cards are left.
                treeItems.forEach(function (item) {
                    item.hidden = !visibleIds[item.getAttribute('data-component-id')];
                });
            }

            treeGroups.forEach(function (group) {
                group.hidden = !group.querySelector('[data-cg-tree-item]:not([hidden])');
            });
            var treeNone = document.querySelector('[data-cg-tree-none]');
            if (treeNone) {
                treeNone.hidden = !treeItems.length || treeItems.some(function (item) { return !item.hidden; });
            }

            filteredNotes.forEach(function (note) {
                note.hidden = !active;
                var count = note.querySelector('[data-cg-filtered-count]');
                if (count) { count.textContent = shown; }
            });
        };

        // On a component page, a filter the editor just set that leaves this
        // component out means they are looking for something else: take them
        // to the list, with the filters as they set them. Only on their own
        // change — a link to a block always opens it, whatever filters were
        // left set in this tab — and, while typing, once they pause.
        var current = onIndex ? null : document.querySelector('[data-tree-current]');
        var tree = document.querySelector('[data-cg-index-url]');
        var leaveTimer = null;
        var leaveIfFilteredOut = function (delay) {
            clearTimeout(leaveTimer);
            if (!current || !tree || !current.hidden) { return; }
            leaveTimer = setTimeout(function () {
                if (current.hidden) { window.location.href = tree.getAttribute('data-cg-index-url'); }
            }, delay);
        };
        var filterByUser = function (event) {
            filter();
            leaveIfFilteredOut(event && event.type === 'input' ? 900 : 0);
        };

        if (modeToggle) {
            modeToggle.addEventListener('change', function () {
                storyMode = modeToggle.checked;
                try { window.localStorage.setItem(MODE_STORE, storyMode ? '1' : '0'); } catch (e) { /* no storage */ }
                applyMode();
                filter();
            });
        }
        if (search) { search.addEventListener('input', filterByUser); }
        if (groupFilter) { groupFilter.addEventListener('change', filterByUser); }
        if (statusFilter) { statusFilter.addEventListener('change', filterByUser); }
        if (reviewFilter) { reviewFilter.addEventListener('change', filterByUser); }
        if (usageFilter) { usageFilter.addEventListener('change', filterByUser); }
        // The review counts on the index set the same filter.
        Array.prototype.forEach.call(document.querySelectorAll('[data-cg-review-stat]'), function (button) {
            button.addEventListener('click', function () {
                if (!reviewFilter) { return; }
                var value = button.getAttribute('data-cg-review-stat');
                reviewFilter.value = reviewFilter.value === value ? '' : value;
                filter();
            });
        });
        clearButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (search) { search.value = ''; }
                if (groupFilter) { groupFilter.value = ''; }
                if (statusFilter) { statusFilter.value = ''; }
                if (reviewFilter) { reviewFilter.value = ''; }
                if (usageFilter) { usageFilter.value = ''; }
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
        if (search && typeof saved.term === 'string') {
            search.value = saved.term;
            // Arrived here mid-search: keep typing where it left off.
            if (document.activeElement === search && search.setSelectionRange) {
                search.setSelectionRange(search.value.length, search.value.length);
            }
        }
        if (groupFilter && saved.group && hasOption(groupFilter, saved.group)) { groupFilter.value = saved.group; }
        if (statusFilter && saved.status && hasOption(statusFilter, saved.status)) { statusFilter.value = saved.status; }
        if (reviewFilter && saved.review && hasOption(reviewFilter, saved.review)) { reviewFilter.value = saved.review; }
        if (usageFilter && saved.usage && hasOption(usageFilter, saved.usage)) { usageFilter.value = saved.usage; }
        filter();
    }

    // --- Index: scale card thumbnails ---
    // Each thumb iframe renders at a real 1280px viewport; scale it down to the
    // thumb box width (the box's 16:10 aspect matches 1280×800, so it fills).
    var thumbs = Array.prototype.slice.call(document.querySelectorAll('[data-cg-thumb]'));
    if (thumbs.length) {
        // Each thumbnail is a request that renders a story in Craft. Only the
        // cards near the screen load: `loading="lazy"` loads hidden frames
        // anyway (the other set of cards, cards a filter hides) and reaches
        // far below the fold, which made 80 requests out of 15 cards.
        var loadThumb = function (thumb) {
            var iframe = thumb.querySelector('iframe[data-src]');
            if (iframe && !iframe.getAttribute('src')) { iframe.setAttribute('src', iframe.getAttribute('data-src')); }
        };
        if (window.IntersectionObserver) {
            var thumbLoader = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        loadThumb(entry.target);
                        thumbLoader.unobserve(entry.target);
                    }
                });
            }, { rootMargin: '300px 0px' });
            thumbs.forEach(function (thumb) { thumbLoader.observe(thumb); });
        } else {
            thumbs.forEach(loadThumb);
        }

        var scaleThumb = function (thumb) {
            var iframe = thumb.querySelector('iframe');
            if (iframe && thumb.clientWidth > 0) {
                iframe.style.transform = 'scale(' + (thumb.clientWidth / 1280) + ')';
            }
        };
        // Watched one by one: a card hidden by a filter has no width to
        // scale to, and the grid reflows when the filter changes — so each
        // thumb is scaled whenever its own box changes size, including the
        // moment a filter shows it again.
        if (window.ResizeObserver) {
            var thumbObserver = new ResizeObserver(function (entries) {
                entries.forEach(function (entry) { scaleThumb(entry.target); });
            });
            thumbs.forEach(function (thumb) { thumbObserver.observe(thumb); });
        } else {
            var thumbRaf = null;
            var scaleAll = function () { thumbs.forEach(scaleThumb); };
            window.addEventListener('resize', function () {
                if (thumbRaf) { return; }
                thumbRaf = requestAnimationFrame(function () { thumbRaf = null; scaleAll(); });
            });
            document.addEventListener('change', function () { requestAnimationFrame(scaleAll); });
            document.addEventListener('input', function () { requestAnimationFrame(scaleAll); });
        }
        thumbs.forEach(scaleThumb);

        // Zoom: rest the mouse on a thumbnail and a larger copy of it opens
        // beside the card, so small cards can still be read. The same address
        // as the thumbnail, so the browser has it already (versioned, cached).
        // Only for a mouse: on a touch screen the tap opens the block anyway.
        var canHover = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        if (canHover) {
            var zoom = document.createElement('div');
            zoom.className = 'cg-zoom';
            zoom.hidden = true;
            zoom.setAttribute('aria-hidden', 'true');
            var zoomFrame = document.createElement('iframe');
            zoomFrame.tabIndex = -1;
            zoomFrame.title = '';
            zoom.appendChild(zoomFrame);
            document.body.appendChild(zoom);

            var ZOOM_DELAY = 350;
            var zoomTimer = null;
            var zoomedThumb = null;

            // The cursor says it before the zoom opens: a magnifier over the
            // preview, the usual pointer on the rest of the card.
            var setZoomCursor = function (thumb, on) {
                var card = thumb && thumb.closest('.cg-card__link');
                if (card) { card.classList.toggle('cg-card__link--zoom', on); }
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
                if (!src) { return; }
                var rect = thumb.getBoundingClientRect();
                var gap = 12;
                // About half the window, never larger than the preview itself.
                var width = Math.round(Math.min(640, window.innerWidth * 0.45));
                var height = Math.round(width * 10 / 16);
                var spaceRight = window.innerWidth - rect.right - gap;
                var left = spaceRight >= width + 8 ? rect.right + gap : rect.left - gap - width;
                left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
                var top = Math.max(8, Math.min(rect.top, window.innerHeight - height - 8));
                if (zoomFrame.getAttribute('src') !== src) { zoomFrame.setAttribute('src', src); }
                zoom.style.width = width + 'px';
                zoom.style.height = height + 'px';
                zoom.style.left = left + 'px';
                zoom.style.top = top + 'px';
                zoomFrame.style.transform = 'scale(' + (width / 1280) + ')';
                zoom.hidden = false;
            };

            // The thumbnail lets the pointer through to the card's link, so
            // which one is under the mouse is read from the card and its box.
            var thumbAt = function (event) {
                var card = event.target.closest && event.target.closest('.cg-card__link');
                var thumb = card && card.querySelector('[data-cg-thumb]');
                if (!thumb) { return null; }
                var r = thumb.getBoundingClientRect();
                return event.clientX >= r.left && event.clientX <= r.right && event.clientY >= r.top && event.clientY <= r.bottom
                    ? thumb : null;
            };

            document.addEventListener('mousemove', function (event) {
                var thumb = thumbAt(event);
                if (thumb === zoomedThumb) { return; }
                hideZoom();
                if (!thumb) { return; }
                zoomedThumb = thumb;
                setZoomCursor(thumb, true);
                zoomTimer = setTimeout(function () { showZoom(thumb); }, ZOOM_DELAY);
            });
            document.documentElement.addEventListener('mouseleave', hideZoom);
            window.addEventListener('scroll', hideZoom, true);
            window.addEventListener('blur', hideZoom);
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') { hideZoom(); }
            });
        }
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

        // For the client review below: what is on screen, and a way to put
        // a reviewed version back on screen.
        cgPreview.overrides = function () { return current; };
        cgPreview.apply = function (values) {
            lastValid = {};
            rows.forEach(function (row) {
                var name = nameOf(row);
                write(row, Object.prototype.hasOwnProperty.call(values, name) ? values[name] : (isSet(row) ? original(row) : null));
            });
            refresh();
        };

        var refresh = function () {
            current = collect();
            if (changedNote) { changedNote.hidden = !current; }
            // The client review below only approves the story as written.
            document.dispatchEvent(new CustomEvent('cg-controls-change', { detail: { changed: !!current } }));
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

    // --- Detail: the story's tabs ---
    // Craft's own tab manager (Craft.Tabs) does the tabs — selection, focus,
    // arrow keys, the overflow menu; this shows the matching pane and
    // remembers the last tab chosen in this browser.
    (function () {
        var root = document.querySelector('[data-cg-tabs]');
        if (!root) { return; }
        var KEY = 'componentGuide.tab';
        var paneOf = function (tab) { return tab ? document.getElementById(tab.getAttribute('data-id')) : null; };
        var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));
        var show = function (tab) {
            tabs.forEach(function (t) {
                var pane = paneOf(t);
                if (pane) { pane.classList.toggle('hidden', t !== tab); }
            });
            try { window.localStorage.setItem(KEY, tab.getAttribute('data-id')); } catch (e) { /* not remembered */ }
        };

        var remembered = null;
        try { remembered = window.localStorage.getItem(KEY); } catch (e) { remembered = null; }
        var start = tabs.filter(function (t) { return t.getAttribute('data-id') === remembered; })[0] || null;

        var container = root.querySelector('.pane-tabs');
        if (window.Craft && Craft.Tabs && container && window.jQuery) {
            var manager = new Craft.Tabs(container);
            manager.on('selectTab', function (event) { show(event.$tab[0]); });
            if (start) { manager.selectTab(start, false); }
        } else {
            // Without Craft's manager: the same, by hand.
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function (event) {
                    event.preventDefault();
                    tabs.forEach(function (t) {
                        t.classList.toggle('sel', t === tab);
                        t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                    });
                    show(tab);
                });
            });
            if (start) { start.click(); }
        }

        // Fold the panes away (the tabs stay), remembered in this browser;
        // choosing a tab unfolds them.
        var FOLD_KEY = 'componentGuide.tabsFolded';
        var toggle = root.querySelector('[data-cg-tabs-toggle]');
        var fold = function (folded, remember) {
            root.classList.toggle('is-folded', folded);
            if (toggle) {
                toggle.setAttribute('aria-expanded', folded ? 'false' : 'true');
                var label = toggle.getAttribute(folded ? 'data-label-show' : 'data-label-hide');
                toggle.setAttribute('title', label);
                toggle.setAttribute('aria-label', label);
            }
            if (remember) {
                try { window.localStorage.setItem(FOLD_KEY, folded ? '1' : '0'); } catch (e) { /* not remembered */ }
            }
        };
        var folded = false;
        try { folded = window.localStorage.getItem(FOLD_KEY) === '1'; } catch (e) { folded = false; }
        fold(folded, false);
        if (toggle) {
            toggle.addEventListener('click', function () {
                fold(!root.classList.contains('is-folded'), true);
            });
        }
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                if (root.classList.contains('is-folded')) { fold(false, true); }
            });
        });

        // Controls changed: the tab says so, wherever you are.
        var controlsTab = tabs.filter(function (t) { return t.getAttribute('data-id') === 'cg-tab-controls'; })[0];
        var label = controlsTab ? controlsTab.querySelector('.tab-label') : null;
        if (label) {
            var dot = document.createElement('span');
            dot.className = 'cg-tab-changed';
            dot.textContent = ' · ' + (root.getAttribute('data-msg-changed') || 'changed');
            dot.hidden = true;
            label.appendChild(dot);
            document.addEventListener('cg-controls-change', function (event) {
                dot.hidden = !(event.detail && event.detail.changed);
            });
        }
    })();

    // --- Client review: export ---
    // The file comes as a download, which leaves the page as it was; once
    // the browser has it, say so instead of offering “N new” again.
    (function () {
        var box = document.querySelector('[data-cg-export]');
        if (!box) { return; }
        var pending = box.querySelector('[data-cg-export-pending]');
        var done = box.querySelector('[data-cg-export-done]');
        var text = box.querySelector('[data-cg-export-text]');
        Array.prototype.forEach.call(box.querySelectorAll('[data-cg-export-link]'), function (link) {
            link.addEventListener('click', function () {
                // A failed export would have taken the page away with its
                // error; still here a moment later means the file came.
                window.setTimeout(function () {
                    if (pending) { pending.hidden = true; }
                    if (done) { done.hidden = false; }
                    if (text) { text.textContent = text.getAttribute('data-just-now') || text.textContent; }
                }, 800);
            });
        });
    })();

    // --- Client review: an admin clears the history (one block, or all) ---
    (function () {
        if (!window.Craft || !Craft.sendActionRequest) { return; }
        Array.prototype.forEach.call(document.querySelectorAll('[data-cg-review-clear]'), function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                if (link.getAttribute('aria-disabled') === 'true') { return; }
                if (!window.confirm(link.getAttribute('data-confirm'))) { return; }
                link.setAttribute('aria-disabled', 'true');
                var data = {};
                if (link.getAttribute('data-component-id')) { data.componentId = link.getAttribute('data-component-id'); }
                Craft.sendActionRequest('POST', 'component-guide/reviews/clear', { data: data })
                    .then(function (response) {
                        var message = ((response && response.data) || {}).message;
                        if (message && Craft.cp) { Craft.cp.displayNotice(message); }
                        window.location.reload();
                    })
                    .catch(function () {
                        link.removeAttribute('aria-disabled');
                        if (Craft.cp) { Craft.cp.displayError(); }
                    });
            });
        });
    })();

    // --- Detail: client review ---
    // Approve the story as written, or ask for changes; see _review.twig.
    (function () {
        var root = document.querySelector('[data-cg-review]');
        if (!root || !window.Craft || !Craft.sendActionRequest) { return; }

        var componentId = root.getAttribute('data-component-id');
        var storyId = root.getAttribute('data-story-id');
        var comment = root.querySelector('[data-cg-review-comment]');
        var approveBtn = root.querySelector('[data-cg-review-decide="approved"]');
        var valuesNote = root.querySelector('[data-cg-review-values]');
        var notice = function (m) { if (m && Craft.cp && Craft.cp.displayNotice) { Craft.cp.displayNotice(m); } };
        var failure = function (m) { if (m && Craft.cp && Craft.cp.displayError) { Craft.cp.displayError(m); } };
        var messageOf = function (error) {
            var body = error && error.response && error.response.data;
            return (body && body.message) || root.getAttribute('data-msg-error');
        };

        // Back to this page without changed values in its address — the
        // decision is recorded, and the page now shows it.
        var reload = function () {
            try {
                var url = new URL(window.location.href);
                url.searchParams.delete('args');
                window.location.replace(url.toString());
            } catch (e) {
                window.location.reload();
            }
        };

        // Only the story as written is approved: with values changed in
        // Controls, Approve waits and says why.
        var syncApprove = function (changed) {
            if (valuesNote) { valuesNote.hidden = !changed; }
            if (approveBtn) {
                approveBtn.disabled = changed;
                approveBtn.classList.toggle('disabled', changed);
            }
        };
        document.addEventListener('cg-controls-change', function (event) {
            syncApprove(!!(event.detail && event.detail.changed));
        });
        syncApprove(!!(cgPreview.overrides && cgPreview.overrides()));

        // “Mark stable” / “Back to draft” in the header: Approve comes and
        // goes with it, and so does the note saying why it is not there.
        document.addEventListener('cg-status-change', function (event) {
            var detail = event.detail || {};
            if (detail.componentId !== componentId) { return; }
            Array.prototype.forEach.call(root.querySelectorAll('[data-cg-review-unapprovable]'), function (note) {
                note.hidden = note.getAttribute('data-cg-review-unapprovable') !== detail.status;
            });
            if (approveBtn) { approveBtn.hidden = detail.status === 'draft' || detail.status === 'deprecated'; }
        });

        Array.prototype.forEach.call(root.querySelectorAll('[data-cg-review-decide]'), function (button) {
            button.addEventListener('click', function () {
                var decision = button.getAttribute('data-cg-review-decide');
                var confirmText = button.getAttribute('data-cg-review-confirm');
                if (confirmText && !window.confirm(confirmText)) { return; }
                var text = comment ? comment.value.trim() : '';
                if (decision === 'changes' && text === '') {
                    failure(root.getAttribute('data-msg-need-comment'));
                    if (comment) { comment.focus(); }
                    return;
                }
                var values = cgPreview.overrides ? cgPreview.overrides() : null;
                button.disabled = true;
                Craft.sendActionRequest('POST', 'component-guide/reviews/decide', {
                    data: {
                        componentId: componentId,
                        storyId: storyId,
                        decision: decision,
                        comment: text,
                        args: JSON.stringify(decision === 'changes' ? (values || {}) : {})
                    }
                }).then(function (response) {
                    var data = (response && response.data) || {};
                    if (data.review && data.review.id) {
                        // A slip of the mouse can be undone — for a few seconds,
                        // on the page that reloads with the decision on it.
                        offerUndo(data.review.id, decision, text);
                    } else {
                        notice(data.message);
                    }
                    reload();
                }).catch(function (error) {
                    failure(messageOf(error));
                    button.disabled = false;
                });
            });
        });

        // --- Undo a decision, right after the click ---
        // In Craft's own notification, as the gallery's Undo is (picker.js):
        // a link in its details, not a button, so it does not pull focus.
        // The server allows it for ClientReviews::UNDO_SECONDS; the offer
        // only has to survive the reload that shows the approval.
        var UNDO_KEY = 'componentGuide.undo';
        var UNDO_MS = 15000;
        var DRAFT_KEY = 'componentGuide.reviewDraft';
        var offerUndo = function (id, decision, text) {
            try {
                window.sessionStorage.setItem(UNDO_KEY, JSON.stringify({
                    id: id, decision: decision, comment: text || '',
                    componentId: componentId, storyId: storyId, until: Date.now() + UNDO_MS
                }));
            } catch (e) { /* no storage: the decision stands, as it would anyway */ }
        };
        var showUndo = function (offer) {
            if (!Craft.cp || !Craft.cp.displayNotice) { return; }
            var details = document.createElement('div');
            details.className = 'cg-undo';
            var link = document.createElement('a');
            link.className = 'cg-undo__btn';
            link.href = '#';
            link.textContent = root.getAttribute('data-msg-undo');
            details.appendChild(link);

            var notification = Craft.cp.displayNotice(root.getAttribute(offer.decision === 'changes' ? 'data-msg-sent' : 'data-msg-approved'), {
                details: details, class: 'cg-undo-notification'
            });
            link.addEventListener('click', function (event) {
                event.preventDefault();
                if (link.getAttribute('aria-disabled') === 'true') { return; }
                link.setAttribute('aria-disabled', 'true');
                Craft.sendActionRequest('POST', 'component-guide/reviews/undo', { data: { id: offer.id } })
                    .then(function (response) {
                        if (notification && notification.close) { notification.close(); }
                        notice(((response && response.data) || {}).message);
                        // The comment comes back into the box, to fix and send again.
                        if (offer.comment) {
                            try {
                                window.sessionStorage.setItem(DRAFT_KEY, JSON.stringify({
                                    componentId: componentId, storyId: storyId, comment: offer.comment
                                }));
                            } catch (e) { /* the comment is lost with the decision */ }
                        }
                        reload();
                    })
                    .catch(function (error) {
                        if (notification && notification.close) { notification.close(); }
                        failure(messageOf(error));
                    });
            });
        };
        try {
            var draft = JSON.parse(window.sessionStorage.getItem(DRAFT_KEY) || 'null');
            window.sessionStorage.removeItem(DRAFT_KEY);
            if (draft && comment && draft.componentId === componentId && draft.storyId === storyId) {
                comment.value = draft.comment;
                comment.focus();
            }
        } catch (e) { /* nothing to put back */ }
        try {
            var offer = JSON.parse(window.sessionStorage.getItem(UNDO_KEY) || 'null');
            window.sessionStorage.removeItem(UNDO_KEY);
            if (offer && offer.componentId === componentId && offer.storyId === storyId && offer.until > Date.now()) {
                showUndo(offer);
            }
        } catch (e) { /* no storage, nothing offered */ }

        Array.prototype.forEach.call(root.querySelectorAll('[data-cg-review-item]'), function (item) {
            // The values a request carried, back on screen in Controls.
            var showBtn = item.querySelector('[data-cg-review-show]');
            if (showBtn) {
                showBtn.addEventListener('click', function () {
                    var values = {};
                    try { values = JSON.parse(item.getAttribute('data-review-args') || '{}') || {}; } catch (e) { values = {}; }
                    if (cgPreview.apply) { cgPreview.apply(values); }
                    var frame = document.querySelector('.cg-preview');
                    if (frame && frame.scrollIntoView) { frame.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
                });
            }

            // The developer's mark on a request for changes: fixed (outside
            // the template, where it is not noticed by itself), or not yet.
            var fixedBtn = item.querySelector('[data-cg-review-fixed]');
            if (fixedBtn) {
                fixedBtn.addEventListener('click', function () {
                    var fixed = fixedBtn.getAttribute('data-cg-review-fixed') === '1';
                    if (fixed && !window.confirm(root.getAttribute('data-msg-fixed'))) { return; }
                    fixedBtn.disabled = true;
                    Craft.sendActionRequest('POST', 'component-guide/reviews/mark-fixed', {
                        data: { id: item.getAttribute('data-review-id'), fixed: fixed ? 1 : 0 }
                    }).then(function (response) {
                        notice(((response && response.data) || {}).message);
                        reload();
                    }).catch(function (error) {
                        failure(messageOf(error));
                        fixedBtn.disabled = false;
                    });
                });
            }
        });
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

            // On a detail page: what editors see of it, in one sentence.
            var ready = scope.querySelector('[data-cg-editors-ready]');
            var unavailable = scope.querySelector('[data-cg-editors-unavailable]');
            if (ready) { ready.hidden = !data.inGallery; }
            if (unavailable && data.status) {
                unavailable.hidden = !!data.inGallery || data.status === 'stable';
                unavailable.textContent = (unavailable.getAttribute('data-template') || '').replace('{status}', data.status);
            }
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

                    var idSelector = '[data-component-id="' + CSS.escape(button.getAttribute('data-component-id') || '') + '"]';

                    // Every toggle of this block — its card and each of its
                    // story cards (“Variants as cards”) has one.
                    Array.prototype.forEach.call(document.querySelectorAll('[data-cg-status-toggle]' + idSelector), function (toggle) {
                        toggle.setAttribute('data-status', status);
                        toggle.textContent = status === 'draft'
                            ? toggle.getAttribute('data-label-stable')
                            : toggle.getAttribute('data-label-draft');
                    });

                    // Its cards on the index, or the whole header on a detail page.
                    var blockCards = document.querySelectorAll('[data-card]' + idSelector);
                    if (blockCards.length) {
                        Array.prototype.forEach.call(blockCards, function (card) { repaint(card, data); });
                    } else {
                        repaint(document, data);
                    }

                    // The sidebar's status filter reads the tree on a
                    // component page, so the tree has to move with it too.
                    var treeItem = document.querySelector('[data-cg-tree-item][data-component-id="'
                        + CSS.escape(button.getAttribute('data-component-id') || '') + '"]');
                    if (treeItem) {
                        treeItem.setAttribute('data-tree-status', status);
                        var dot = treeItem.querySelector('[data-cg-tree-dot]');
                        if (dot) {
                            // The same classes as _tree.twig: colour, and Craft's shape.
                            var colors = { stable: 'green', beta: 'blue', draft: 'orange pending', deprecated: 'red expired' };
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
                    // For the client review below, which can be approved only
                    // on a stable block.
                    document.dispatchEvent(new CustomEvent('cg-status-change', {
                        detail: { componentId: button.getAttribute('data-component-id'), status: status }
                    }));
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

    // --- “Used on”: open the page in Craft's side panel ----------------------
    // A plain click opens the element where the editor would edit it, without
    // leaving the guide; ⌘/Ctrl/Shift-click and the middle button keep the
    // link's own behaviour (a new tab).
    (function () {
        var pane = document.getElementById('cg-tab-used-on');
        if (!pane || !window.Craft || !Craft.createElementEditor) { return; }

        // After a save in the side panel the list may be out of date — a block
        // disabled, removed, added. Read this page again and take its “Used
        // on”, rather than reloading: Controls and the scroll stay as they are.
        var refresh = function () {
            if (!window.fetch || !window.DOMParser) { return; }
            fetch(window.location.href, { credentials: 'same-origin' })
                .then(function (response) { return response.ok ? response.text() : Promise.reject(response); })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var fresh = doc.getElementById('cg-tab-used-on');
                    if (fresh) { pane.innerHTML = fresh.innerHTML; }
                    var tab = document.getElementById('tab-cg-tab-used-on');
                    var freshTab = doc.getElementById('tab-cg-tab-used-on');
                    if (tab && freshTab) { tab.innerHTML = freshTab.innerHTML; }
                })
                .catch(function () { /* the list stays as it was; a reload brings it up to date */ });
        };

        // Delegated on the pane, which stays: the list inside is replaced.
        pane.addEventListener('click', function (event) {
            var link = event.target.closest('[data-cg-open-element]');
            if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }
            event.preventDefault();
            try {
                var slideout = Craft.createElementEditor(link.getAttribute('data-element-type'), {
                    elementId: parseInt(link.getAttribute('data-element-id'), 10),
                    siteId: parseInt(link.getAttribute('data-site-id'), 10)
                });
                if (slideout && slideout.on) { slideout.on('submit', refresh); }
            } catch (e) {
                window.location.href = link.href;
            }
        });
    })();

    // --- Render errors reported by preview frames ---------------------------

    (function () {
        // Remembered for the browser tab, so the sidebar can show a broken
        // component on every page — not only on the index, where the frames
        // render. A later render of the same story that works clears it.
        var STORE = 'componentGuide.renderErrors';
        var load = function () {
            try { return JSON.parse(window.sessionStorage.getItem(STORE) || '{}') || {}; } catch (e) { return {}; }
        };
        var save = function (map) {
            try { window.sessionStorage.setItem(STORE, JSON.stringify(map)); } catch (e) { /* no storage — the cards still show it */ }
        };
        var paintTree = function (map) {
            Array.prototype.forEach.call(document.querySelectorAll('[data-cg-tree-item]'), function (item) {
                var badge = item.querySelector('[data-cg-tree-error]');
                if (!badge) { return; }
                var stories = map[item.getAttribute('data-component-id')] || {};
                var messages = Object.keys(stories).map(function (id) { return stories[id]; });
                badge.hidden = messages.length === 0;
                // Messages rendered by the server, passed through as-is.
                badge.title = messages.join('\n');
            });
        };
        paintTree(load());

        window.addEventListener('message', function (event) {
            var data = event.data;
            if (!data || data.source !== 'component-guide' || !data.componentId) { return; }

            // Trust our own frames only, whatever origin they were served from:
            // matching the source window is exact, and needs no origin plumbing.
            var frames = document.querySelectorAll('[data-cg-thumb] iframe');
            var frame = null;
            for (var i = 0; i < frames.length; i++) {
                if (frames[i].contentWindow === event.source) { frame = frames[i]; break; }
            }
            if (!frame) { return; }

            var map = load();
            var componentId = String(data.componentId);
            var storyId = String(data.storyId || '');
            if (data.state === 'error') {
                map[componentId] = map[componentId] || {};
                map[componentId][storyId] = data.message || '';
            } else if (map[componentId]) {
                delete map[componentId][storyId];
                if (!Object.keys(map[componentId]).length) { delete map[componentId]; }
            }
            save(map);
            paintTree(map);

            if (data.state !== 'error') { return; }

            // The card the frame is in: a block's, or one story's.
            var card = frame.closest('[data-card]');
            var badge = card && card.querySelector('[data-cg-render-badge]');
            if (!badge) { return; }

            // The message is rendered by the server and passed through as-is;
            // nothing is composed here (see the note on translations above).
            badge.title = data.message || '';
            badge.hidden = false;
        });
    })();

})();
