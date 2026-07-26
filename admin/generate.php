<?php
/**
 * Admin voucher generation page
 */

require_once '/var/www/voucher-portal/src/auth.php';
require_once '/var/www/voucher-portal/src/voucher_service.php';

session_start();
requireAdmin();

$generated = [];
$error = null;
$planKey = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $planKey = isset($_POST['plan']) ? $_POST['plan'] : '';
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;
    
    if (empty($planKey) || !isset(PLANS[$planKey])) {
        $error = 'Tafadhali chagua mpango sahihi.';
    } elseif ($quantity < 1 || $quantity > 100) {
        $error = 'Tafadhali weka idadi kati ya 1 na 100.';
    } else {
        try {
            $generated = generateVouchers($planKey, $quantity, $_SESSION['admin_username']);
        } catch (Exception $e) {
            $error = 'Hitilafu: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Vouchers - WiFi Admin</title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
</head>
<body>
    <div class="admin-wrapper">
        <!-- Header -->
        <header class="admin-header">
            <div class="admin-header-inner">
                <a href="dashboard.php" class="admin-logo">
                    <div class="admin-logo-icon">📡</div>
                    <span class="admin-logo-text">Voucher Admin</span>
                </a>
                
                <nav class="admin-nav">
                    <a href="dashboard.php">Dashboard</a>
                    <a href="generate.php" class="active">Generate</a>
                </nav>
                
                <div class="admin-user">
                    <div class="admin-user-avatar"><?php echo strtoupper(substr($_SESSION['admin_username'] ?? 'A', 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
                    <a href="logout.php" style="color: #94a3b8; text-decoration: none; font-size: 13px;">Toka →</a>
                </div>
            </div>
        </header>
        
        <!-- Content -->
        <main class="admin-content">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h2 class="admin-card-title">Tengeneza Voucher Mpya</h2>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-error" style="margin-bottom: 24px;">
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($generated)): ?>
                    <div class="alert alert-success" style="margin-bottom: 24px;">
                        <span>Umefanikiwa kutengeneza voucher <strong><?php echo count($generated); ?></strong>!</span>
                    </div>
                    
                    <div class="code-list">
                        <div class="code-list-title">
                            <span>📋</span>
                            <span>Voucher Zilizotengenezwa</span>
                        </div>
                        
                        <?php foreach ($generated as $i => $code): ?>
                            <div class="code-item">
                                <span><?php echo htmlspecialchars($code); ?></span>
                                <button class="copy-btn" onclick="copyCode('<?php echo $code; ?>', this)">Nakili</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="action-buttons">
                        <button class="btn btn-secondary btn-small" onclick="copyAll()">
                            📋 Nakili Zote
                        </button>
                        <button class="btn btn-secondary btn-small" onclick="printCodes()">
                            🖨️ Print
                        </button>
                        <button class="btn btn-secondary btn-small" onclick="downloadCSV()">
                            📥 Download CSV
                        </button>
                    </div>
                    
                    <script>
                        function copyCode(code, btn) {
                            navigator.clipboard.writeText(code).then(function() {
                                btn.textContent = 'Imenakiliwa!';
                                btn.classList.add('copied');
                                setTimeout(function() { 
                                    btn.textContent = 'Nakili';
                                    btn.classList.remove('copied');
                                }, 2000);
                            });
                        }
                        
                        function copyAll() {
                            var codes = <?php echo json_encode($generated); ?>;
                            navigator.clipboard.writeText(codes.join('\n')).then(function() {
                                alert('Codes zote zimenakiliwa!');
                            });
                        }
                        
                        function printCodes() {
                            var codes = <?php echo json_encode($generated); ?>;
                            var plan = <?php echo json_encode(PLANS[$planKey]['name'] ?? ''); ?>;
                            var html = '<!DOCTYPE html><html><head><title>Voucher Codes</title>';
                            html += '<style>';
                            html += 'body{font-family:"Courier New",monospace;padding:40px;background:#f5f5f5;}';
                            html += '.container{max-width:600px;margin:0 auto;background:white;padding:40px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.1);}';
                            html += 'h1{text-align:center;color:#1a1a1a;margin-bottom:8px;font-size:24px;}';
                            html += '.meta{text-align:center;color:#666;margin-bottom:32px;font-size:14px;}';
                            html += '.code{padding:12px 16px;margin:8px 0;background:#f8f9fa;border:2px dashed #ddd;font-size:18px;font-weight:bold;letter-spacing:2px;border-radius:8px;}';
                            html += '.footer{margin-top:32px;text-align:center;color:#999;font-size:12px;}';
                            html += '</style></head><body>';
                            html += '<div class="container">';
                            html += '<h1>WiFi Voucher Codes</h1>';
                            html += '<div class="meta">Mpango: ' + plan + ' | Idadi: ' + codes.length + '<br>Tarehe: ' + new Date().toLocaleString() + '</div>';
                            codes.forEach(function(code, i) {
                                html += '<div class="code">' + (i+1) + '. ' + code + '</div>';
                            });
                            html += '<div class="footer">WiFi Voucher Portal</div>';
                            html += '</div></body></html>';
                            
                            var w = window.open('', '_blank');
                            w.document.write(html);
                            w.document.close();
                            w.print();
                        }
                        
                        function downloadCSV() {
                            var codes = <?php echo json_encode($generated); ?>;
                            var plan = <?php echo json_encode(PLANS[$planKey]['name'] ?? ''); ?>;
                            var csv = 'Code,Plan\n';
                            codes.forEach(function(code) {
                                csv += code + ',' + plan + '\n';
                            });
                            
                            var blob = new Blob([csv], { type: 'text/csv' });
                            var url = URL.createObjectURL(blob);
                            var a = document.createElement('a');
                            a.href = url;
                            a.download = 'vouchers_<?php echo date('Y-m-d_His'); ?>.csv';
                            a.click();
                            URL.revokeObjectURL(url);
                        }
                    </script>
                    
                    <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 32px 0;">
                <?php endif; ?>
                
                <!-- Generate Form -->
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="plan">Chagua Mpango</label>
                        <select name="plan" id="plan" class="form-select" required>
                            <option value="">Chagua mpango...</option>
                            <?php foreach (PLANS as $key => $p): ?>
                                <option value="<?php echo $key; ?>" <?php echo ($planKey === $key) ? 'selected' : ''; ?>>
                                    <?php echo $p['name']; ?> — <?php echo number_format($p['price']); ?> TZS (<?php 
                                        if ($p['duration_seconds'] == 86400) echo 'siku 1';
                                        elseif ($p['duration_seconds'] == 604800) echo 'wiki 1';
                                        elseif ($p['duration_seconds'] == 2592000) echo 'mwezi 1';
                                    ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="quantity">Idadi (1-100)</label>
                        <input 
                            type="number" 
                            id="quantity" 
                            name="quantity" 
                            class="form-number"
                            min="1" 
                            max="100" 
                            value="<?php echo htmlspecialchars($_POST['quantity'] ?? '10'); ?>"
                            required
                        >
                    </div>
                    
                    <button type="submit" class="btn btn-primary" style="max-width: 300px;">
                        <span>Tengeneza Voucher</span>
                    </button>
                </form>
            </div>
            
            <!-- Plan Details -->
            <div class="admin-card">
                <div class="admin-card-header">
                    <h2 class="admin-card-title">Mipango Inayopatikana</h2>
                </div>
                
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Jina la Mpango</th>
                                <th>Muda</th>
                                <th>Bei (TZS)</th>
                                <th>Maelezo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (PLANS as $key => $p): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($p['name']); ?></td>
                                    <td>
                                        <?php 
                                        if ($p['duration_seconds'] == 86400) echo 'Saa 24';
                                        elseif ($p['duration_seconds'] == 604800) echo 'Siku 7';
                                        elseif ($p['duration_seconds'] == 2592000) echo 'Siku 30';
                                        ?>
                                    </td>
                                    <td style="font-weight: 600; color: #6366f1;"><?php echo number_format($p['price']); ?></td>
                                    <td style="color: #64748b; font-size: 13px;">
                                        <?php 
                                        if ($key == 'siku_1') echo 'Kwa matumizi ya siku moja';
                                        elseif ($key == 'wiki_1') echo 'Kwa matumizi ya wiki nzima';
                                        elseif ($key == 'mwezi_1') echo 'Kwa matumizi ya mwezi mzima';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
