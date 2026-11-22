/**
 * Phase 3 static prototype interactions only (nav sheet + dialog drawer).
 * Not production application code.
 */
(function () {
    'use strict';

    function focusable(root) {
        return Array.from(
            root.querySelectorAll(
                'a[href], button:not([disabled]), textarea, input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
            ),
        ).filter(function (el) {
            return (
                !el.hasAttribute('disabled') &&
                el.getAttribute('aria-hidden') !== 'true'
            );
        });
    }

    function openOverlay(backdrop, panel, trigger) {
        backdrop.classList.add('open');
        panel.classList.add('open');
        document.body.classList.add('modal-open');
        backdrop.setAttribute('aria-hidden', 'false');
        panel.setAttribute('aria-hidden', 'false');
        panel.removeAttribute('inert');
        if (trigger) {
            panel.dataset.returnFocus = trigger.id || '';
            if (!trigger.id) {
                trigger.dataset.protoTrigger = '1';
            }
        }
        window.setTimeout(function () {
            var items = focusable(panel);
            (items[0] || panel).focus();
        }, 10);
    }

    function closeOverlay(backdrop, panel, fallbackTrigger) {
        backdrop.classList.remove('open');
        panel.classList.remove('open');
        document.body.classList.remove('modal-open');
        backdrop.setAttribute('aria-hidden', 'true');
        panel.setAttribute('aria-hidden', 'true');
        if (panel) {
            panel.setAttribute('inert', '');
        }
        var id = panel.dataset.returnFocus;
        var trigger =
            (id && document.getElementById(id)) ||
            fallbackTrigger ||
            document.querySelector('[data-proto-trigger="1"]');
        if (trigger) {
            trigger.focus();
            delete trigger.dataset.protoTrigger;
        }
    }

    function trapFocus(event, panel) {
        if (event.key !== 'Tab') {
            return;
        }
        var items = focusable(panel);
        if (items.length === 0) {
            event.preventDefault();
            panel.focus();
            return;
        }
        var first = items[0];
        var last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function initNav() {
        var toggle = document.getElementById('menu-toggle');
        var backdrop = document.getElementById('nav-backdrop');
        var sheet = document.getElementById('nav-sheet');
        var closeBtn = document.getElementById('nav-close');
        if (!toggle || !backdrop || !sheet) {
            return;
        }

        function openNav() {
            openOverlay(backdrop, sheet, toggle);
            toggle.setAttribute('aria-expanded', 'true');
        }

        function closeNav() {
            closeOverlay(backdrop, sheet, toggle);
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', function () {
            if (sheet.classList.contains('open')) {
                closeNav();
            } else {
                openNav();
            }
        });

        if (closeBtn) {
            closeBtn.addEventListener('click', closeNav);
        }

        backdrop.addEventListener('click', closeNav);

        document.addEventListener('keydown', function (event) {
            if (!sheet.classList.contains('open')) {
                return;
            }
            if (event.key === 'Escape') {
                closeNav();
            } else {
                trapFocus(event, sheet);
            }
        });
    }

    function initDrawer() {
        var openBtn = document.getElementById('openDocs');
        var backdrop = document.getElementById('drawer');
        var panel = document.getElementById('drawer-panel');
        var closeBtn = document.getElementById('closeDocs');
        if (!openBtn || !backdrop || !panel) {
            return;
        }

        function openDrawer() {
            backdrop.classList.add('open');
            document.body.classList.add('modal-open');
            backdrop.setAttribute('aria-hidden', 'false');
            panel.setAttribute('aria-hidden', 'false');
            panel.removeAttribute('inert');
            openBtn.setAttribute('aria-expanded', 'true');
            window.setTimeout(function () {
                var items = focusable(panel);
                (closeBtn || items[0] || panel).focus();
            }, 10);
        }

        function closeDrawer() {
            backdrop.classList.remove('open');
            document.body.classList.remove('modal-open');
            backdrop.setAttribute('aria-hidden', 'true');
            panel.setAttribute('aria-hidden', 'true');
            panel.setAttribute('inert', '');
            openBtn.setAttribute('aria-expanded', 'false');
            openBtn.focus();
        }

        openBtn.addEventListener('click', openDrawer);
        if (closeBtn) {
            closeBtn.addEventListener('click', closeDrawer);
        }

        backdrop.addEventListener('click', function (event) {
            if (event.target === backdrop) {
                closeDrawer();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (!backdrop.classList.contains('open')) {
                return;
            }
            if (event.key === 'Escape') {
                closeDrawer();
            } else {
                trapFocus(event, panel);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var sheet = document.getElementById('nav-sheet');
        if (sheet && !sheet.classList.contains('open')) {
            sheet.setAttribute('inert', '');
        }
        var drawerPanel = document.getElementById('drawer-panel');
        var drawerRoot = document.getElementById('drawer');
        if (
            drawerPanel &&
            drawerRoot &&
            !drawerRoot.classList.contains('open')
        ) {
            drawerPanel.setAttribute('inert', '');
        }
        initNav();
        initDrawer();
    });
})();
