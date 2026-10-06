/* Agents: WIP page. Loaded only by Template/wip/index.php. No inline JS (CSP). */
(function () {
    'use strict';
    var KEY = 'agents-wip-unflagged';

    function init() {
        var root = document.querySelector('.agents-wip');
        if (!root) {
            return;
        }
        var box = document.getElementById('agents-wip-unflagged');
        if (box) {
            try { box.checked = window.localStorage.getItem(KEY) === '1'; } catch (e) {}
            var apply = function () {
                root.classList.toggle('agents-wip-show-unflagged', box.checked);
                try { window.localStorage.setItem(KEY, box.checked ? '1' : '0'); } catch (e) {}
            };
            box.addEventListener('change', apply);
            apply();
        }
        root.addEventListener('click', function (ev) {
            var btn = ev.target.closest('.agents-wip-copy');
            if (!btn) {
                return;
            }
            if (navigator.clipboard) {
                navigator.clipboard.writeText(btn.getAttribute('data-copy'));
            }
            btn.textContent = '✓';
        });
        // One post per fix: a double click must not race two submits past the server-side guards.
        root.addEventListener('submit', function (ev) {
            var form = ev.target;
            if (!form.classList || !form.classList.contains('agents-wip-fix')) {
                return;
            }
            if (form.getAttribute('data-submitted') === '1') {
                ev.preventDefault();
                return;
            }
            form.setAttribute('data-submitted', '1');
            form.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
            // Selects carry assignee_id: disabling them now would drop it from this post, so lock them next tick.
            setTimeout(function () {
                form.querySelectorAll('select').forEach(function (s) { s.disabled = true; });
            }, 0);
        });
        // Back/forward cache restores the page as left: unlock the fix forms again.
        window.addEventListener('pageshow', function (ev) {
            if (!ev.persisted) {
                return;
            }
            root.querySelectorAll('form.agents-wip-fix').forEach(function (form) {
                form.removeAttribute('data-submitted');
                form.querySelectorAll('button, select').forEach(function (c) { c.disabled = false; });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
