<?php
/**
 * Status page — remaining time and data usage for a voucher code.
 * Accessible at: status.php?code=XXXX
 */

require_once dirname(__DIR__) . '/src/voucher_service.php';
require_once dirname(__DIR__) . '/src/quota_service.php';

$code   = isset($_GET['code']) ? strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $_GET['code']))) : '';
$voucher = null;
$error   = null;

if (!empty($code)) {
    $voucher = getVoucherByCode($code);
    if (!$voucher) {
        $error = 'Voucher not found.';
    }
}

function formatTime(int $seconds): string {
    if ($seconds <= 0) return '0:00';
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    if ($h > 0) {
        return $h . ':' . str_pad($m, 2, '0', STR_PAD_LEFT) . ':' . str_pad($s, 2, '0', STR_PAD_LEFT);
    }
    return $m . ':' . str_pad($s, 2, '0', STR_PAD_LEFT);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Voucher Status - WiFi Portal</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <div class="container">
        <div class="card status-card">
            <div class="header">
                <div class="brand-icon"><img src="/assets/ditronics-logo.png" alt="Ditronics"></div>
                <h1>Voucher Status</h1>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <span><?= htmlspecialchars($error) ?></span>
                </div>

            <?php elseif (!$voucher): ?>
                <!-- No code entered — show search form -->
                <form method="GET" action="" class="voucher-form">
                    <div class="form-group">
                        <label for="code">Enter your voucher code</label>
                        <div class="input-wrapper">
                            <input
                                type="text"
                                inputmode="numeric"
                                id="code"
                                name="code"
                                placeholder="Example: 0123456789"
                                maxlength="10"
                                pattern="[0-9]{10}"
                                required
                                autofocus
                                autocomplete="off"
                                spellcheck="false"
                            >
                            <span class="input-icon">🔍</span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <span>Search</span>
                    </button>
                </form>

            <?php else:
                $now       = time();
                $expiresAt = $voucher['expires_at'] ? strtotime($voucher['expires_at']) : null;
                $status    = $voucher['status'];

                // Auto-expire if past due
                if ($status === 'active' && $expiresAt && $expiresAt <= $now) {
                    $status = 'expired';
                }

                $remaining = 0;
                if ($status === 'active' && $expiresAt) {
                    $remaining = max(0, $expiresAt - $now);
                }

                $statusLabel = match($status) {
                    'unused'  => ['Unused',  '#6366f1'],
                    'active'  => ['Active', '#10b981'],
                    'expired' => ['Expired',     '#ef4444'],
                    default   => ['--',            '#94a3b8'],
                };
            ?>

                <div class="countdown-display">
                    <div class="countdown-label">Time Remaining</div>
                    <div class="countdown-time" id="countdown">
                        <?= $status === 'active' ? formatTime($remaining) : '—' ?>
                    </div>
                </div>

                <div class="status-info">
                    <div class="status-row">
                        <span class="status-label">Code</span>
                        <span class="status-value" style="font-family:monospace;"><?= htmlspecialchars($voucher['code']) ?></span>
                    </div>
                    <div class="status-row">
                        <span class="status-label">Plan</span>
                        <span class="status-value"><?= htmlspecialchars($voucher['plan_name']) ?></span>
                    </div>
                    <div class="status-row">
                        <span class="status-label">Status</span>
                        <span class="status-value" style="color:<?= $statusLabel[1] ?>; font-weight:700;"><?= $statusLabel[0] ?></span>
                    </div>
                    <?php if ($voucher['first_used_at']): ?>
                    <div class="status-row">
                        <span class="status-label">Started</span>
                        <span class="status-value"><?= date('d/m/Y H:i', strtotime($voucher['first_used_at'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($expiresAt): ?>
                    <div class="status-row">
                        <span class="status-label">Expires</span>
                        <span class="status-value"><?= date('d/m/Y H:i', $expiresAt) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

            <?php if ($status === 'active'): ?>
                <?php
                    // Fetch data quota status (graceful if radacct is unavailable)
                    $quotaStatus = getVoucherQuotaStatus($voucher['code'], $voucher['plan_name']);
                ?>
                <!-- Time progress bar -->
                <div class="progress-section">
                    <div class="progress-label-row" style="display:flex;justify-content:space-between;font-size:.75rem;color:var(--text-tertiary);margin-bottom:4px;">
                        <span>Time remaining</span>
                        <span id="progressPercent"><?= round(($remaining / max(1, $remaining + (time() - strtotime($voucher['first_used_at'])))) * 100) ?>%</span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                </div>

                <?php if ($quotaStatus['has_quota']): ?>
                <!-- Data quota progress bar -->
                <div class="progress-section" style="margin-top:12px;">
                    <div class="progress-label-row" style="display:flex;justify-content:space-between;font-size:.75rem;color:var(--text-tertiary);margin-bottom:4px;">
                        <span>Data used</span>
                        <span><?= $quotaStatus['used_mb'] ?> MB / <?= $quotaStatus['quota_mb'] ?> MB</span>
                    </div>
                    <div class="progress-track">
                        <?php
                            $dataPct = $quotaStatus['percent_used'];
                            $dataClass = 'progress-fill';
                            if ($dataPct >= 90)      $dataClass .= ' danger';
                            elseif ($dataPct >= 70)  $dataClass .= ' warning';
                        ?>
                        <div class="<?= $dataClass ?>" style="width:<?= $dataPct ?>%"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:.72rem;color:var(--text-tertiary);margin-top:4px;">
                        <span><?= $quotaStatus['remaining_mb'] ?> MB remaining</span>
                        <span><?= $dataPct ?>% used</span>
                    </div>
                    <?php if ($quotaStatus['exceeded']): ?>
                    <div class="alert alert-error" style="margin-top:8px;padding:8px 12px;font-size:.8rem;">
                        ⚠️ Data quota exceeded — your session will be terminated shortly.
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            <?php endif; ?>

                <div class="tips-box">
                    <h3>📋 Info</h3>
                    <ul>
                        <?php if ($status === 'active'): ?>
                        <li>You can use the internet for the full duration of the voucher</li>
                        <?php if (!empty($quotaStatus['has_quota'])): ?>
                        <li>This plan includes <strong><?= $quotaStatus['quota_mb'] ?> MB</strong> of data — your session ends when the data or time limit is reached, whichever comes first</li>
                        <?php endif; ?>
                        <li>To disconnect early, visit: <code>http://portal.tplink.net/portal/logout</code></li>
                        <?php elseif ($status === 'expired'): ?>
                        <li>This voucher has expired — buy a new one to continue</li>
                        <?php else: ?>
                        <li>Enter the voucher on the portal to start using the internet</li>
                        <?php endif; ?>
                    </ul>
                </div>

            <?php endif; ?>

            <div style="text-align:center; margin-top:16px;">
                <a href="/" class="info-link">← Back to Voucher Portal</a>
            </div>
        </div>

        <div class="footer">
            <p>&copy; <?= date('Y') ?> WiFi Portal &middot; Network Service</p>
        </div>
    </div>

    <?php if ($voucher && $status === 'active'): ?>
    <script>
    (function() {
        var remaining = <?= (int) $remaining ?>;
        var total     = remaining;
        var countdownEl    = document.getElementById('countdown');
        var progressFill   = document.getElementById('progressFill');
        var progressPct    = document.getElementById('progressPercent');

        function fmt(s) {
            if (s <= 0) return '0:00';
            var h = Math.floor(s / 3600);
            var m = Math.floor((s % 3600) / 60);
            var sec = s % 60;
            if (h > 0) return h + ':' + (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
            return m + ':' + (sec < 10 ? '0' : '') + sec;
        }

        function tick() {
            if (remaining <= 0) {
                countdownEl.textContent = 'Time expired';
                countdownEl.style.opacity = '0.6';
                progressFill.style.width = '0%';
                if (progressPct) progressPct.textContent = '0%';
                return;
            }
            countdownEl.textContent = fmt(remaining);
            var pct = Math.round((remaining / total) * 100);
            progressFill.style.width = pct + '%';
            if (progressPct) progressPct.textContent = pct + '%';

            if (pct > 50)      progressFill.className = 'progress-fill';
            else if (pct > 20) progressFill.className = 'progress-fill warning';
            else               progressFill.className = 'progress-fill danger';
        }

        tick();
        setInterval(function() { remaining--; tick(); }, 1000);
    })();
    </script>
    <?php endif; ?>
</body>
</html>
