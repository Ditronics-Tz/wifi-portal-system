<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/sales_service.php';
require_once dirname(__DIR__, 2) . '/src/user_service.php';
require_once dirname(__DIR__, 2) . '/src/package_service.php';
require_once dirname(__DIR__, 2) . '/src/voucher_service.php';
startAppSession();
requireAdmin();

$adminUserId = getCurrentUserId() ?: null;
$success = null;
$error = null;
$lastSale = null;
$db = getDB();

// ── Record a new sale (modal form) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $voucherId = intval($_POST['voucher_id'] ?? 0);
        $voucher = $voucherId > 0 ? getUnsoldVoucherById($voucherId) : null;
        if (!$voucher) { $error = 'Choose a voucher.'; }
        else {
            try {
                $targetSellerId = ($voucher['seller_id'] !== null) ? (int) $voucher['seller_id'] : (int) $adminUserId;

                $saleId = recordSale($voucher['code'], $targetSellerId, trim($_POST['buyer_phone'] ?? '') ?: null, trim($_POST['buyer_name'] ?? '') ?: null, !empty($_POST['custom_price']) ? floatval($_POST['custom_price']) : null);
                $sStmt = $db->prepare("SELECT * FROM sales WHERE id = :id");
                $sStmt->execute([':id' => $saleId]);
                $lastSale = $sStmt->fetch();
                $success = 'Sale recorded.';
            } catch (Exception $e) { $error = $e->getMessage(); }
        }
    }
}

// ── Voucher stock available for a new sale ──────────────────────
$stockStmt = $db->prepare("
    SELECT v.id, v.code, v.plan_name, v.price, v.seller_id, u.username AS seller_username
    FROM vouchers v
    LEFT JOIN users u ON u.id = v.seller_id
    WHERE v.status = 'unused' AND v.code NOT IN (SELECT voucher_code FROM sales WHERE voucher_code = v.code)
    ORDER BY v.plan_name, v.created_at DESC
");
$stockStmt->execute();
$stockVouchers = $stockStmt->fetchAll();

// ── Statistics ────────────────────────────────────────────────
$salesStats = getSystemSalesStats();

// ── Filters + sales list ─────────────────────────────────────────
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$sellerFilter = isset($_GET['seller_id']) && $_GET['seller_id'] !== '' ? intval($_GET['seller_id']) : null;
$planFilter = $_GET['plan_name'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$sales = getAllSales($sellerFilter, $dateFrom ?: null, $dateTo ?: null, $planFilter ?: null, $search ?: null, $perPage, $offset);
$totalResults = countAllSales($sellerFilter, $dateFrom ?: null, $dateTo ?: null, $planFilter ?: null, $search ?: null);
$totalPages = ceil($totalResults / $perPage);

$sellerList = getSellers(null, true, 100, 0);
$packages = getAllPackages();

$csrf = generateCSRFToken();
$activePage = 'sales';
$pageTitle = 'Sales';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales - Admin</title>
    <?php require dirname(__DIR__, 2) . '/src/theme_init.php'; ?>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php require dirname(__DIR__, 2) . '/src/admin_header.php'; ?>
            <div class="section-header">
                <h1>Sales</h1>
                <p>Track sales activity and record new sales.</p>
            </div>

            <?php if ($success && $lastSale): ?>
                <div class="confirmation-card">
                    <div class="confirm-icon">✓</div>
                    <div class="confirm-title"><?php echo htmlspecialchars($success); ?> Give this code to the customer.</div>
                    <?php echo renderVoucherCode($lastSale['voucher_code'], true, 'reveal'); ?>
                    <div class="confirm-details">
                        <div class="detail-grid">
                            <span class="detail-label">Package:</span><span class="detail-value"><?php echo htmlspecialchars($lastSale['plan_name']); ?></span>
                            <span class="detail-label">Price:</span><span class="detail-value" style="color: var(--color-secondary); font-weight: 600;"><?php echo number_format($lastSale['price']); ?> TZS</span>
                            <?php if ($lastSale['buyer_name']): ?><span class="detail-label">Customer:</span><span class="detail-value"><?php echo htmlspecialchars($lastSale['buyer_name']); ?></span><?php endif; ?>
                            <?php if ($lastSale['buyer_phone']): ?><span class="detail-label">Phone:</span><span class="detail-value"><?php echo htmlspecialchars($lastSale['buyer_phone']); ?></span><?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($error): ?><div class="alert alert-error"><span><?php echo htmlspecialchars($error); ?></span></div><?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-header">
                        <div class="stat-icon-wrap icon-secondary"><?php echo hi('Coins01Icon', 20); ?></div>
                        <span class="stat-badge badge-up">Today</span>
                    </div>
                    <div class="stat-value"><?php echo number_format($salesStats['today_revenue'] ?? 0); ?></div>
                    <div class="stat-label">Revenue Today</div>
                    <div class="stat-description">From <?php echo number_format($salesStats['today_sales'] ?? 0); ?> sales today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header"><div class="stat-icon-wrap icon-accent"><?php echo hi('ChartLineData01Icon', 20); ?></div></div>
                    <div class="stat-value"><?php echo number_format($salesStats['month_revenue'] ?? 0); ?></div>
                    <div class="stat-label">Revenue This Month</div>
                    <div class="stat-description">From <?php echo number_format($salesStats['month_sales'] ?? 0); ?> sales</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header"><div class="stat-icon-wrap icon-primary"><?php echo hi('Ticket01Icon', 20); ?></div></div>
                    <div class="stat-value"><?php echo number_format($salesStats['total_sales'] ?? 0); ?></div>
                    <div class="stat-label">Total Sales</div>
                    <div class="stat-description">All-time sales count</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-header"><div class="stat-icon-wrap icon-success"><?php echo hi('AnalyticsUpIcon', 20); ?></div></div>
                    <div class="stat-value"><?php echo number_format($salesStats['total_revenue'] ?? 0); ?></div>
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-description">All-time, TZS</div>
                </div>
            </div>

            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">Sales List</h2>
                        <p class="admin-card-subtitle">All recorded sales</p>
                    </div>
                    <button type="button" class="btn btn-primary btn-small" onclick="openRecordModal()"><?php echo hi('Coins01Icon', 16); ?> Record Sale</button>
                </div>

                <form method="GET" action="" class="filters-bar">
                    <input type="text" name="search" class="filter-input" placeholder="Search code, buyer, staff..." value="<?php echo htmlspecialchars($search); ?>">
                    <select name="seller_id" class="filter-select">
                        <option value="">All Staff</option>
                        <?php foreach ($sellerList as $seller): ?>
                            <option value="<?php echo $seller['id']; ?>" <?php echo $sellerFilter === (int)$seller['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($seller['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="plan_name" class="filter-select">
                        <option value="">All Packages</option>
                        <?php foreach ($packages as $pkg): ?>
                            <option value="<?php echo htmlspecialchars($pkg['name']); ?>" <?php echo $planFilter === $pkg['name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($pkg['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="date_from" class="filter-input" style="min-width: 140px;" value="<?php echo htmlspecialchars($dateFrom); ?>">
                    <input type="date" name="date_to" class="filter-input" style="min-width: 140px;" value="<?php echo htmlspecialchars($dateTo); ?>">
                    <button type="submit" class="btn btn-secondary btn-small">Search</button>
                    <?php if ($dateFrom || $dateTo || $sellerFilter || $planFilter || $search): ?><a href="/admin/sales.php" class="btn btn-secondary btn-small" style="text-decoration: none;">Clear</a><?php endif; ?>
                </form>

                <div class="table-wrapper">
                    <table class="data-table">
                        <thead><tr><th>Voucher</th><th>Package</th><th>Price</th><th>Buyer</th><th>Phone</th><th>Staff</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php if (empty($sales)): ?>
                                <tr><td colspan="7" style="text-align: center; color: var(--text-tertiary); padding: var(--space-8);">No sales</td></tr>
                            <?php else: foreach ($sales as $sale): ?>
                            <tr>
                                <td><?php echo renderVoucherCode($sale['voucher_code'], true); ?></td>
                                <td><?php echo htmlspecialchars($sale['plan_name']); ?></td>
                                <td style="font-weight: 600;"><?php echo number_format($sale['price']); ?></td>
                                <td><?php echo htmlspecialchars($sale['buyer_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($sale['buyer_phone'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($sale['seller_username'] ?? '—'); ?></td>
                                <td style="font-size: var(--text-sm);"><?php echo date('d/m/Y H:i', strtotime($sale['sold_at'])); ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&seller_id=<?php echo urlencode($sellerFilter ?? ''); ?>&plan_name=<?php echo urlencode($planFilter); ?>&search=<?php echo urlencode($search); ?>">Back</a><?php endif; ?>
                    <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?><a href="?page=<?php echo $i; ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&seller_id=<?php echo urlencode($sellerFilter ?? ''); ?>&plan_name=<?php echo urlencode($planFilter); ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a><?php endfor; ?>
                    <?php if ($page < $totalPages): ?><a href="?page=<?php echo $page+1; ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&seller_id=<?php echo urlencode($sellerFilter ?? ''); ?>&plan_name=<?php echo urlencode($planFilter); ?>&search=<?php echo urlencode($search); ?>">Next</a><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
<?php require dirname(__DIR__, 2) . '/src/admin_footer.php'; ?>

    <!-- Record Sale Modal -->
    <div id="recordModal" class="modal-overlay <?php echo $error ? 'open' : ''; ?>">
        <div class="modal">
            <h3 class="modal-title">Record Sale</h3>
            <?php if (empty($stockVouchers)): ?>
                <p style="color: var(--text-tertiary);">No unsold vouchers in stock. <a href="/admin/generate.php">Generate vouchers first</a>.</p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary btn-small" style="flex: 1;" onclick="closeRecordModal()">Close</button>
                </div>
            <?php else: ?>
            <form method="POST" action="" data-confirm="Record this sale?" data-confirm-tone="neutral">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <div class="form-group">
                    <label for="voucher_id">Choose Voucher</label>
                    <select name="voucher_id" id="voucher_id" class="form-select" required onchange="updatePrice(this)">
                        <option value="">Select a voucher...</option>
                        <?php $grouped = []; foreach ($stockVouchers as $v) { $grouped[$v['plan_name']][] = $v; } foreach ($grouped as $pn => $vs): ?>
                            <optgroup label="<?php echo htmlspecialchars($pn); ?> (<?php echo count($vs); ?>)">
                                <?php foreach ($vs as $v): ?><option value="<?php echo (int) $v['id']; ?>" data-price="<?php echo $v['price']; ?>" data-plan="<?php echo htmlspecialchars($pn); ?>"><?php echo htmlspecialchars(maskVoucherCode($v['code'])); ?><?php echo $v['seller_username'] ? ' — ' . htmlspecialchars($v['seller_username']) : ''; ?></option><?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint"><?php echo count($stockVouchers); ?> voucher(s) available</div>
                </div>
                <div class="form-group"><label for="buyer_name">Customer Name (optional)</label><input type="text" id="buyer_name" name="buyer_name" class="form-input" placeholder="Customer's name"></div>
                <div class="form-group"><label for="buyer_phone">Phone Number (optional)</label><input type="text" id="buyer_phone" name="buyer_phone" class="form-input" placeholder="0712345678"></div>
                <div class="form-group">
                    <label for="custom_price">Price (TZS) — <span id="priceHint" style="color: var(--text-tertiary);">choose a voucher</span></label>
                    <input type="number" id="custom_price" name="custom_price" class="form-number" min="0" step="50" placeholder="Package price">
                    <div class="form-hint">Leave blank to use the package price. Enter a new price for a discount.</div>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary btn-small" style="flex: 1;">Record Sale</button>
                    <button type="button" class="btn btn-secondary btn-small" style="flex: 1;" onclick="closeRecordModal()">Cancel</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function openRecordModal() { document.getElementById('recordModal').classList.add('open'); }
        function closeRecordModal() { document.getElementById('recordModal').classList.remove('open'); }
        document.getElementById('recordModal').addEventListener('click', function(e) { if (e.target === this) closeRecordModal(); });
        function updatePrice(s){var o=s.options[s.selectedIndex];var h=document.getElementById('priceHint');if(o&&o.value){h.textContent='Price: '+Number(o.getAttribute('data-price')).toLocaleString()+' TZS ('+o.getAttribute('data-plan')+')';}else{h.textContent='choose a voucher';}}
    </script>
</body>
</html>
