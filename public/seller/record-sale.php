<?php
require_once dirname(__DIR__, 2) . '/src/auth.php';
require_once dirname(__DIR__, 2) . '/src/voucher_service.php';
require_once dirname(__DIR__, 2) . '/src/sales_service.php';
require_once dirname(__DIR__, 2) . '/src/package_service.php';
startAppSession();
requireSellerOrAdmin();

$sellerId = getCurrentUserId();
$sellerUsername = getCurrentUsername();
$success = null;
$error = null;
$lastSale = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { $error = 'Invalid request.'; }
    else {
        $voucherId = intval($_POST['voucher_id'] ?? 0);
        $voucher = ($voucherId > 0 && $sellerId) ? getUnsoldVoucherById($voucherId, $sellerId) : null;
        if (!$voucher) { $error = 'Choose a voucher.'; }
        elseif (!$sellerId) { $error = 'Seller ID not found.'; }
        else {
            try {
                $saleId = recordSale($voucher['code'], $sellerId, trim($_POST['buyer_phone'] ?? '') ?: null, trim($_POST['buyer_name'] ?? '') ?: null, !empty($_POST['custom_price']) ? floatval($_POST['custom_price']) : null);
                $db = getDB(); $stmt = $db->prepare("SELECT * FROM sales WHERE id = :id"); $stmt->execute([':id' => $saleId]); $lastSale = $stmt->fetch();
                $success = 'Sale recorded. Give this code to the customer.';
            } catch (Exception $e) { $error = $e->getMessage(); }
        }
    }
}

if ($sellerId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT v.id, v.code, v.plan_name, v.price FROM vouchers v WHERE v.seller_id = :sid AND v.status = 'unused' AND v.code NOT IN (SELECT voucher_code FROM sales WHERE voucher_code = v.code) ORDER BY v.plan_name, v.created_at DESC");
    $stmt->execute([':sid' => $sellerId]);
    $stockVouchers = $stmt->fetchAll();
} else { $stockVouchers = []; }
$csrf = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Sale - Seller</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('/assets/style.css')); ?>">
</head>
<body>
    <div class="admin-wrapper">
        <header class="admin-header">
            <div class="admin-header-inner">
                <a href="/seller/dashboard.php" class="admin-logo"><img src="/assets/DITRONICS-COMPANY-LOGO.png" alt="Ditronics" style="height:28px;width:auto;"><span class="admin-logo-text">WiFi Voucher Seller</span></a>
                <nav class="admin-nav">
                    <a href="/seller/dashboard.php">Dashboard</a>
                    <a href="/seller/generate.php">Generate</a>
                    <a href="/seller/record-sale.php" class="active">Record Sale</a>
                    <a href="/seller/my-sales.php">My Sales</a>
                </nav>
                <div class="admin-user">
                    <div class="admin-user-avatar"><?php echo strtoupper(substr($sellerUsername, 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($sellerUsername); ?></span>
                    <a href="/seller/logout.php" style="color: var(--text-tertiary); text-decoration: none; font-size: var(--text-xs);">Logout</a>
                </div>
            </div>
        </header>
        <main class="admin-content">
            <div class="section-header">
                <h1>Record Sale</h1>
                <p>Record a voucher sale you made to a customer.</p>
            </div>

            <?php if ($success && $lastSale): ?>
                <div class="confirmation-card">
                    <div class="confirm-icon">✓</div>
                    <div class="confirm-title"><?php echo htmlspecialchars($success); ?></div>
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

            <div class="admin-card">
                <div class="admin-card-header">
                    <div class="admin-card-header-text">
                        <h2 class="admin-card-title">Sale Form</h2>
                        <p class="admin-card-subtitle">Choose a voucher from your stock and enter customer details</p>
                    </div>
                </div>
                <?php if (empty($stockVouchers) && !$success): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📦</div>
                        <div class="empty-state-title">No vouchers to sell</div>
                        <div class="empty-state-text"><a href="/seller/generate.php">Generate vouchers first</a> before recording a sale.</div>
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
                                    <?php foreach ($vs as $v): ?><option value="<?php echo (int) $v['id']; ?>" data-price="<?php echo $v['price']; ?>" data-plan="<?php echo htmlspecialchars($pn); ?>"><?php echo htmlspecialchars(maskVoucherCode($v['code'])); ?></option><?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint"><?php echo count($stockVouchers); ?> voucher(s) available</div>
                    </div>
                    <div class="form-group"><label for="buyer_name">Customer Name (optional)</label><input type="text" id="buyer_name" name="buyer_name" class="form-input" placeholder="Customer's name" value="<?php echo htmlspecialchars($_POST['buyer_name'] ?? ''); ?>"></div>
                    <div class="form-group"><label for="buyer_phone">Phone Number (optional)</label><input type="text" id="buyer_phone" name="buyer_phone" class="form-input" placeholder="0712345678" value="<?php echo htmlspecialchars($_POST['buyer_phone'] ?? ''); ?>"></div>
                    <div class="form-group">
                        <label for="custom_price">Price (TZS) — <span id="priceHint" style="color: var(--text-tertiary);">choose a voucher</span></label>
                        <input type="number" id="custom_price" name="custom_price" class="form-number" min="0" step="50" placeholder="Package price" value="<?php echo htmlspecialchars($_POST['custom_price'] ?? ''); ?>">
                        <div class="form-hint">Leave blank to use the package price. Enter a new price for a discount.</div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="max-width: 250px;">Record Sale</button>
                </form>
                <script>function updatePrice(s){var o=s.options[s.selectedIndex];var h=document.getElementById('priceHint');if(o&&o.value){h.textContent='Price: '+Number(o.getAttribute('data-price')).toLocaleString()+' TZS ('+o.getAttribute('data-plan')+')';}else{h.textContent='choose a voucher';}}</script>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="<?php echo htmlspecialchars(assetUrl('/assets/admin.js')); ?>"></script>
</body>
</html>
