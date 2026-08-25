(function () {
    'use strict';

    // ── Theme toggle ─────────────────────────────
    var themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            var current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            var next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
        });
    }

    // ── User avatar dropdown ─────────────────────
    var userMenuTrigger = document.getElementById('userMenuTrigger');
    var userMenuDropdown = document.getElementById('userMenuDropdown');
    if (userMenuTrigger && userMenuDropdown) {
        var closeMenu = function () {
            userMenuDropdown.classList.remove('open');
            userMenuTrigger.setAttribute('aria-expanded', 'false');
        };
        userMenuTrigger.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var isOpen = userMenuDropdown.classList.toggle('open');
            userMenuTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
        document.addEventListener('click', function (e) {
            var menu = e.target && e.target.closest ? e.target.closest('.user-menu') : null;
            if (!menu) closeMenu();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeMenu();
        });
    }

    // ── Mobile sidebar toggle ────────────────────
    var sidebar = document.getElementById('sidebar');
    var sidebarToggle = document.getElementById('sidebarToggle');
    var sidebarScrim = document.getElementById('sidebarScrim');
    if (sidebar && sidebarToggle && sidebarScrim) {
        var closeSidebar = function () {
            sidebar.classList.remove('open');
            sidebarScrim.classList.remove('open');
            sidebarToggle.setAttribute('aria-expanded', 'false');
        };
        sidebarToggle.addEventListener('click', function () {
            var isOpen = sidebar.classList.toggle('open');
            sidebarScrim.classList.toggle('open', isOpen);
            sidebarToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
        sidebarScrim.addEventListener('click', closeSidebar);
    }

    // ── Desktop sidebar collapse ──────────────────
    var sidebarCollapseToggle = document.getElementById('sidebarCollapseToggle');
    if (sidebarCollapseToggle) {
        sidebarCollapseToggle.setAttribute('aria-expanded', document.documentElement.classList.contains('sidebar-collapsed') ? 'false' : 'true');
        sidebarCollapseToggle.addEventListener('click', function () {
            var collapsed = document.documentElement.classList.toggle('sidebar-collapsed');
            localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
            sidebarCollapseToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        });
    }

    // ── Confirm dialog (replaces window.confirm) ──
    // Usage: add data-confirm="Message" to a <form>. On submit, the form is
    // intercepted, a styled dialog is shown, and the form only submits if
    // the user confirms. Add data-confirm-tone="neutral" for non-destructive actions.
    var confirmOverlay, confirmIconEl, confirmMessageEl, confirmOkBtn, confirmCancelBtn, confirmPendingForm;

    function ensureConfirmDialog() {
        if (confirmOverlay) return;
        confirmOverlay = document.createElement('div');
        confirmOverlay.className = 'modal-overlay confirm-overlay';
        confirmOverlay.innerHTML =
            '<div class="modal confirm-modal">' +
                '<div class="confirm-modal-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M13.9248 21H10.0752C5.44476 21 3.12955 21 2.27636 19.4939C1.42317 17.9879 2.60736 15.9914 4.97574 11.9985L6.90057 8.75333C9.17559 4.91778 10.3131 3 12 3C13.6869 3 14.8244 4.91777 17.0994 8.75332L19.0243 11.9985C21.3926 15.9914 22.5768 17.9879 21.7236 19.4939C20.8704 21 18.5552 21 13.9248 21Z" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"/><path d="M12 9V13" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"/><path d="M12.125 16.75H12M12.25 16.75C12.25 16.8881 12.1381 17 12 17C11.8619 17 11.75 16.8881 11.75 16.75C11.75 16.6119 11.8619 16.5 12 16.5C12.1381 16.5 12.25 16.6119 12.25 16.75Z" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"/></svg></div>' +
                '<h3 class="modal-title confirm-modal-title">Are you sure?</h3>' +
                '<p class="confirm-modal-message"></p>' +
                '<div class="modal-actions">' +
                    '<button type="button" class="btn btn-secondary btn-small confirm-cancel-btn" style="flex:1;">Cancel</button>' +
                    '<button type="button" class="btn btn-danger btn-small confirm-ok-btn" style="flex:1;">Confirm</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(confirmOverlay);
        confirmIconEl = confirmOverlay.querySelector('.confirm-modal-icon');
        confirmMessageEl = confirmOverlay.querySelector('.confirm-modal-message');
        confirmOkBtn = confirmOverlay.querySelector('.confirm-ok-btn');
        confirmCancelBtn = confirmOverlay.querySelector('.confirm-cancel-btn');
        confirmCancelBtn.addEventListener('click', closeConfirmDialog);
        confirmOverlay.addEventListener('click', function (e) { if (e.target === confirmOverlay) closeConfirmDialog(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && confirmOverlay.classList.contains('open')) closeConfirmDialog();
        });
        confirmOkBtn.addEventListener('click', function () {
            var form = confirmPendingForm;
            closeConfirmDialog();
            if (form) form.submit(); // HTMLFormElement.submit() does not re-fire the 'submit' event
        });
    }

    function closeConfirmDialog() {
        confirmPendingForm = null;
        if (confirmOverlay) confirmOverlay.classList.remove('open');
    }

    function showConfirmDialog(message, tone, form) {
        ensureConfirmDialog();
        confirmMessageEl.textContent = message;
        confirmIconEl.classList.toggle('icon-neutral', tone === 'neutral');
        confirmOkBtn.className = 'btn btn-small confirm-ok-btn ' + (tone === 'neutral' ? 'btn-primary' : 'btn-danger');
        confirmOkBtn.style.flex = '1';
        confirmPendingForm = form || null;
        confirmOverlay.classList.add('open');
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form && form.hasAttribute && form.hasAttribute('data-confirm')) {
            e.preventDefault();
            showConfirmDialog(form.getAttribute('data-confirm'), form.getAttribute('data-confirm-tone'), form);
        }
    }, true);
})();
