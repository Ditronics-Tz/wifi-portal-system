<?php
/**
 * Shared admin shell: sidebar + topbar.
 * Caller must set $activePage and $pageTitle before including, and
 * must already have called requireAdmin() / startAppSession().
 */
$adminUsername = getCurrentUsername();
$navItems = [
    'dashboard' => ['href' => '/admin/dashboard.php', 'label' => 'Dashboard', 'icon' => '&#9679;'],
    'sellers'   => ['href' => '/admin/sellers.php',   'label' => 'Sellers',   'icon' => '&#128101;'],
    'packages'  => ['href' => '/admin/packages.php',  'label' => 'Packages',  'icon' => '&#128230;'],
    'analytics' => ['href' => '/admin/analytics.php', 'label' => 'Analytics', 'icon' => '&#128202;'],
    'generate'  => ['href' => '/admin/generate.php',  'label' => 'Generate',  'icon' => '&#127915;'],
];
?>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a href="/admin/dashboard.php" class="sidebar-brand">
            <img src="/assets/DITRONICS-COMPANY-LOGO.png" alt="Ditronics">
            <span class="sidebar-brand-text">WiFi Voucher</span>
        </a>
        <nav class="sidebar-nav">
            <?php foreach ($navItems as $key => $item): ?>
                <a href="<?php echo $item['href']; ?>" class="sidebar-link<?php echo $activePage === $key ? ' active' : ''; ?>">
                    <span class="sidebar-icon"><?php echo $item['icon']; ?></span>
                    <span><?php echo $item['label']; ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <span>WiFi Voucher Admin</span>
        </div>
    </aside>

    <div class="app-main">
        <header class="topbar">
            <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Fungua menyu" aria-expanded="false">
                <span class="icon-bar"></span><span class="icon-bar"></span><span class="icon-bar"></span>
            </button>
            <h1 class="topbar-title"><?php echo htmlspecialchars($pageTitle ?? ''); ?></h1>
            <div class="topbar-actions">
                <button type="button" class="theme-toggle" id="themeToggle" aria-label="Badili mandhari">
                    <span class="theme-icon theme-icon-sun">&#9728;</span>
                    <span class="theme-icon theme-icon-moon">&#9789;</span>
                </button>
                <div class="user-menu">
                    <button type="button" class="user-menu-trigger" id="userMenuTrigger" aria-haspopup="true" aria-expanded="false">
                        <span class="admin-user-avatar"><?php echo strtoupper(substr($adminUsername, 0, 1)); ?></span>
                        <span class="user-menu-name"><?php echo htmlspecialchars($adminUsername); ?></span>
                        <svg class="user-menu-caret" width="10" height="6" viewBox="0 0 10 6" fill="none"><path d="M1 1L5 5L9 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <div class="user-menu-dropdown" id="userMenuDropdown">
                        <div class="user-menu-header">
                            <span class="admin-user-avatar admin-user-avatar-lg"><?php echo strtoupper(substr($adminUsername, 0, 1)); ?></span>
                            <div>
                                <div class="user-menu-fullname"><?php echo htmlspecialchars($adminUsername); ?></div>
                                <div class="user-menu-role">Msimamizi</div>
                            </div>
                        </div>
                        <div class="user-menu-divider"></div>
                        <a href="/admin/profile.php" class="user-menu-item"><span>&#128100;</span> Profile</a>
                        <a href="/admin/settings.php" class="user-menu-item"><span>&#9881;</span> Settings</a>
                        <div class="user-menu-divider"></div>
                        <a href="/admin/logout.php" class="user-menu-item user-menu-item-danger"><span>&#8618;</span> Logout</a>
                    </div>
                </div>
            </div>
        </header>
        <div class="sidebar-scrim" id="sidebarScrim"></div>
        <main class="admin-content">
