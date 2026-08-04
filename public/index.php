<?php
/**
 * Customer-facing voucher entry — Two-step flow
 * Step A: Read AP redirect params, show voucher form
 * Step B: Prepare voucher in DB, auto-submit to AP /portal/auth
 */

require_once dirname(__DIR__) . '/src/voucher_service.php';

session_start();

// --- Read AP redirect params ---
$target    = isset($_GET['target'])    ? preg_replace('/[^a-fA-F0-9.:]/', '', $_GET['target'])    : null;
$clientMac = isset($_GET['clientMac']) ? preg_replace('/[^a-fA-F0-9:]/', '', $_GET['clientMac'])   : null;

// Store in session for POST back
if ($target)    $_SESSION['target']    = $target;
if ($clientMac) $_SESSION['clientMac'] = $clientMac;

// Recover from session if GET is empty (POST back)
$target    = $target    ?? ($_SESSION['target']    ?? null);
$clientMac = $clientMac ?? ($_SESSION['clientMac'] ?? null);

// --- State ---
$error   = null;
$code    = '';
$ready   = false;   // true = show auto-submit form to AP
$hasParams = ($target && $clientMac);

// --- Handle POST (Step B) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['voucher_code'])) {
    $code = strtoupper(trim($_POST['voucher_code']));

    if (!$hasParams) {
        $error = 'Tafadhali unganisha kwenye WiFi kwanza.';
    } elseif (empty($code)) {
        $error = 'Tafadhali weka msimbo wa voucher.';
    } else {
        $result = prepareVoucherForAuth($code);

        if ($result['status'] === 'ok') {
            // Ready to auto-submit to AP
            $ready = true;
        } elseif ($result['status'] === 'expired') {
            $error = 'Voucher imekwisha muda.';
        } else {
            $error = 'Msimbo si sahihi. Hakikisha umefanya vizuri.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>WiFi Portal - Ingia Mtandaoni</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <div class="brand-icon"><img src="/assets/ditronics-logo.png" alt="Ditronics"></div>
                <h1>WiFi Portal</h1>
                <p class="subtitle">Weka voucher number ili kutumia mtandao</p>
            </div>

            <?php if (!$hasParams): ?>
                <!-- No AP redirect params — not connected via WiFi -->
                <div class="alert alert-error">
                    <span>Tafadhali unganisha kwenye WiFi kwanza.</span>
                </div>

            <?php elseif ($ready): ?>
                <!-- Step B: Auto-submit to AP -->
                <div class="alert alert-success">
                    <span>Inaunganisha... tafadhali subiri.</span>
                </div>

                <form id="radiusForm" method="post">
                    <input type="hidden" name="username"  value="<?= htmlspecialchars($code) ?>">
                    <input type="hidden" name="password"  value="<?= htmlspecialchars($code) ?>">
                    <input type="hidden" id="cid" name="clientMac" value="<?= htmlspecialchars($clientMac) ?>">
                </form>
                <script>
                    document.getElementById("radiusForm").action =
                        "http://<?= htmlspecialchars($target) ?>/portal/auth";
                    document.getElementById("radiusForm").submit();
                </script>

            <?php else: ?>
                <!-- Step A: Show voucher form -->
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="voucher-form">
                    <div class="form-group">
                        <label for="code">Msimbo wa Voucher</label>
                        <div class="input-wrapper">
                            <input
                                type="text"
                                id="code"
                                name="voucher_code"
                                placeholder="Mfano: ABC12345XY"
                                maxlength="10"
                                pattern="[A-Za-z0-9]{8,10}"
                                required
                                autofocus
                                autocomplete="off"
                                spellcheck="false"
                            >
                            <span class="input-icon">🔑</span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <span>Endelea</span>
                    </button>
                </form>

                <div class="info-section">
                    <p>Kama huna voucher, fika ofisini kwa manunuzi.</p>
                    <a href="status.php" class="info-link">Angalia muda uliobaki</a>
                </div>

                <?php if ($clientMac): ?>
                    <div style="text-align: center; margin-top: 12px;">
                        <span class="mac-badge"><?= htmlspecialchars($clientMac) ?></span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p>&copy; <?= date('Y') ?> WiFi Portal &middot; Huduma ya Mtandao</p>
        </div>
    </div>

    <script>
        document.getElementById('code').addEventListener('input', function() {
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        });
    </script>
</body>
</html>
