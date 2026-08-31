<?php
/**
 * Shared admin shell: sidebar + topbar.
 * Caller must set $activePage and $pageTitle before including, and
 * must already have called requireAdmin() / startAppSession().
 */
require_once __DIR__ . '/icons.php';
$adminUsername = getCurrentUsername();
// Ordered by daily-use priority: overview first, then the day-to-day
// operational pages (sales, vouchers), then setup/config, then reports.
$navItems = [
    'dashboard'    => ['href' => '/admin/dashboard.php',    'label' => 'Dashboard',    'icon' => 'DashboardSquare01Icon'],
    'sales'        => ['href' => '/admin/sales.php',        'label' => 'Sales',        'icon' => 'Coins01Icon'],
    'generate'     => ['href' => '/admin/generate.php',     'label' => 'Vouchers',     'icon' => 'Ticket01Icon'],
    'sessions'     => ['href' => '/admin/sessions.php',     'label' => 'Sessions',     'icon' => 'Wifi01Icon'],
    'usage'        => ['href' => '/admin/usage.php',        'label' => 'Data Usage',   'icon' => 'ChartLineData01Icon'],
    'security'     => ['href' => '/admin/security.php',     'label' => 'Security',     'icon' => 'Alert02Icon'],
    'packages'     => ['href' => '/admin/packages.php',     'label' => 'Packages',     'icon' => 'PackageIcon'],
    'sellers'      => ['href' => '/admin/sellers.php',      'label' => 'Staff',        'icon' => 'UserGroupIcon'],
    'analytics'    => ['href' => '/admin/analytics.php',    'label' => 'Analytics',    'icon' => 'AnalyticsUpIcon'],
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
                    <span class="sidebar-icon"><?php echo hi($item['icon'], 18); ?></span>
                    <span><?php echo $item['label']; ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-menu user-menu-sidebar">
                <button type="button" class="user-menu-trigger user-menu-trigger-sidebar" id="userMenuTrigger" aria-haspopup="true" aria-expanded="false">
                    <span class="admin-user-avatar"><?php echo strtoupper(substr($adminUsername, 0, 1)); ?></span>
                    <span class="user-menu-name"><?php echo htmlspecialchars($adminUsername); ?></span>
                    <?php echo hi('ArrowDown01Icon', 12, 'user-menu-caret'); ?>
                </button>
                <div class="user-menu-dropdown user-menu-dropdown-sidebar" id="userMenuDropdown">
                    <div class="user-menu-header">
                        <span class="admin-user-avatar admin-user-avatar-lg"><?php echo strtoupper(substr($adminUsername, 0, 1)); ?></span>
                        <div>
                            <div class="user-menu-fullname"><?php echo htmlspecialchars($adminUsername); ?></div>
                            <div class="user-menu-role">Administrator</div>
                        </div>
                    </div>
                    <div class="user-menu-divider"></div>
                    <a href="/admin/profile.php" class="user-menu-item"><?php echo hi('UserIcon', 16); ?> Profile</a>
                    <a href="/admin/settings.php" class="user-menu-item"><?php echo hi('Settings02Icon', 16); ?> Settings</a>
                    <div class="user-menu-divider"></div>
                    <a href="/admin/logout.php" class="user-menu-item user-menu-item-danger"><?php echo hi('Logout03Icon', 16); ?> Logout</a>
                </div>
            </div>
        </div>
    </aside>

    <div class="app-main">
        <header class="topbar">
            <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Open menu" aria-expanded="false">
                <?php echo hi('Menu01Icon', 18); ?>
            </button>
            <button type="button" class="sidebar-collapse-toggle" id="sidebarCollapseToggle" aria-label="Toggle sidebar" aria-expanded="true">
                <?php echo hi('PanelLeftIcon', 18); ?>
            </button>
            <h1 class="topbar-title"><?php echo htmlspecialchars($pageTitle ?? ''); ?></h1>
            <div class="topbar-actions">
                <button type="button" class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <span class="theme-icon theme-icon-sun"><?php echo hi('Sun03Icon', 17); ?></span>
                    <span class="theme-icon theme-icon-moon"><?php echo hi('Moon02Icon', 17); ?></span>
                </button>
            </div>
        </header>
        <div class="sidebar-scrim" id="sidebarScrim"></div>
        <main class="admin-content">
