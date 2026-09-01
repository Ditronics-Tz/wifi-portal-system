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
$clientIp  = isset($_GET['clientIp'])  ? filter_var($_GET['clientIp'], FILTER_VALIDATE_IP) : null;

if ($target)    $_SESSION['target']    = $target;
if ($clientMac) $_SESSION['clientMac'] = $clientMac;
if ($clientIp)  $_SESSION['clientIp']  = $clientIp;

$target    = $target    ?? ($_SESSION['target']    ?? null);
$clientMac = $clientMac ?? ($_SESSION['clientMac'] ?? null);
$clientIp  = $clientIp  ?? ($_SESSION['clientIp']  ?? ($_SERVER['REMOTE_ADDR'] ?? null));

// --- State ---
$error   = null;
$code    = '';
$ready   = false;   // true = show auto-submit form to AP
$hasParams = ($target && $clientMac);

// --- Handle POST (Step B) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['voucher_code'])) {
    $code = strtoupper(trim($_POST['voucher_code']));

    if (!$hasParams) {
        $error = 'Please connect to WiFi first.';
    } elseif (empty($code)) {
        $error = 'Please enter a voucher code.';
    } else {
        $result = prepareVoucherForAuth($code, $clientMac, is_string($clientIp) ? $clientIp : null);

        if ($result['status'] === 'ok') {
            // Ready to auto-submit to AP
            $ready = true;
            $statusUrl = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/status.php?code=' . urlencode($code);
        } elseif ($result['status'] === 'expired') {
            $error = 'This voucher has expired.';
        } elseif ($result['status'] === 'in_use') {
            $error = 'This voucher is already in use on another device.';
        } else {
            $error = 'Invalid code. Please check and try again.';
        }
    }
}

// --- Auto-resume: this device already has an active voucher — skip the form ---
if (!$ready && !$error && $hasParams && $clientMac && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $existingCode = getActiveVoucherForMac($clientMac);
    if ($existingCode) {
        $result = prepareVoucherForAuth($existingCode, $clientMac, is_string($clientIp) ? $clientIp : null);
        if ($result['status'] === 'ok') {
            $code = $existingCode;
            $ready = true;
            $statusUrl = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/status.php?code=' . urlencode($code);
        } elseif ($result['status'] === 'expired') {
            $error = 'This voucher has expired (time or data quota reached).';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>WiFi Portal - Sign In</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <div class="brand-icon"><img src="/assets/ditronics-logo.png" alt="Ditronics"></div>
                <h1>WiFi Portal</h1>
                <p class="subtitle">Enter your voucher number to get online</p>
            </div>

            <?php if (!$hasParams): ?>
                <!-- No AP redirect params — not connected via WiFi -->
                <div class="alert alert-error">
                    <span>Please connect to WiFi first.</span>
                </div>

            <?php elseif ($ready): ?>
                <!-- Step B: Auto-submit to AP -->
                <div class="connecting-panel">
                    <svg class="connecting-spinner" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10"></circle>
                    </svg>
                    <div class="connecting-text">Connecting...</div>
                    <div class="connecting-sub">Please wait</div>
                </div>

                <div class="save-link-card">
                    <div class="save-link-title">Save this link to check remaining time later</div>
                    <div class="save-link-row">
                        <span class="save-link-url" id="statusLinkText"><?= htmlspecialchars($statusUrl) ?></span>
                        <button type="button" class="save-link-copy" id="copyStatusLink">Copy</button>
                    </div>
                </div>

                <form id="radiusForm" method="post">
                    <input type="hidden" name="username"  value="<?= htmlspecialchars($code) ?>">
                    <input type="hidden" name="password"  value="<?= htmlspecialchars($code) ?>">
                    <input type="hidden" id="cid" name="clientMac" value="<?= htmlspecialchars($clientMac) ?>">
                </form>
                <script>
                    (function () {
                        var copyBtn = document.getElementById("copyStatusLink");
                        var statusUrl = <?= json_encode($statusUrl) ?>;
                        copyBtn.addEventListener("click", function () {
                            navigator.clipboard.writeText(statusUrl).then(function () {
                                copyBtn.textContent = "Copied";
                                copyBtn.classList.add("copied");
                            }).catch(function () {
                                window.prompt("Copy link:", statusUrl);
                            });
                        });

                        var form = document.getElementById("radiusForm");
                        form.action = "http://<?= htmlspecialchars($target) ?>/portal/auth";
                        setTimeout(function () { form.submit(); }, 2800);
                    })();
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
                        <label for="code">Voucher Code</label>
                        <div class="input-wrapper">
                            <input
                                type="text"
                                inputmode="numeric"
                                id="code"
                                name="voucher_code"
                                placeholder="Example: 0123456789"
                                maxlength="10"
                                pattern="[0-9]{10}"
                                required
                                autofocus
                                autocomplete="off"
                                spellcheck="false"
                            >
                            <span class="input-icon">🔑</span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <span>Continue</span>
                    </button>
                </form>

                <div class="info-section">
                    <p>Don't have a voucher? Visit the office to purchase one.</p>
                    <a href="status.php" class="info-link">Check remaining time</a>
                </div>

                <?php if ($clientMac): ?>
                    <div style="text-align: center; margin-top: 12px;">
                        <span class="mac-badge"><?= htmlspecialchars($clientMac) ?></span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p>&copy; <?= date('Y') ?> WiFi Portal &middot; Network Service</p>
        </div>
    </div>

    <script>
        document.getElementById('code').addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
    </script>
</body>
</html>
