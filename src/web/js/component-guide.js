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

    // --- Detail: iframe loading state + refresh ---
    var iframe = document.querySelector('[data-cg-iframe]');
    if (iframe) {
        var frame = iframe.closest('.cg-preview__frame');
        iframe.addEventListener('load', function () {
            if (frame) { frame.classList.add('is-loaded'); }
        });

        var refresh = document.querySelector('[data-cg-refresh]');
        if (refresh) {
            refresh.addEventListener('click', function () {
                if (frame) { frame.classList.remove('is-loaded'); }
                // Cache-bust so a re-render always happens.
                var base = iframe.getAttribute('src').split('#')[0].split('?')[0];
                iframe.setAttribute('src', base + '?t=' + Date.now());
            });
        }
    }
})();
