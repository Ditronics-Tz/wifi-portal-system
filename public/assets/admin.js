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
            e.stopPropagation();
            var isOpen = userMenuDropdown.classList.toggle('open');
            userMenuTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
        document.addEventListener('click', function (e) {
            if (!userMenuDropdown.contains(e.target) && e.target !== userMenuTrigger) closeMenu();
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
})();
