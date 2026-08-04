<?php
/**
 * Status page — look up remaining time by voucher code.
 * Accessible at: status.php?code=XXXX
 */

require_once dirname(__DIR__) . '/src/voucher_service.php';

$code   = isset($_GET['code']) ? strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $_GET['code']))) : '';
$voucher = null;
$error   = null;

if (!empty($code)) {
    $voucher = getVoucherByCode($code);
    if (!$voucher) {
        $error = 'Voucher haikupatikana.';
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
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Hali ya Voucher - WiFi Portal</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <div class="container">
        <div class="card status-card">
            <div class="header">
                <div class="brand-icon"><img src="/assets/ditronics-logo.png" alt="Ditronics"></div>
                <h1>Hali ya Voucher</h1>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <span><?= htmlspecialchars($error) ?></span>
                </div>

            <?php elseif (!$voucher): ?>
                <!-- No code entered — show search form -->
                <form method="GET" action="" class="voucher-form">
                    <div class="form-group">
                        <label for="code">Weka msimbo wa voucher</label>
                        <div class="input-wrapper">
                            <input
                                type="text"
                                id="code"
                                name="code"
                                placeholder="Mfano: ABC12345XY"
                                maxlength="10"
                                pattern="[A-Za-z0-9]{8,10}"
                                required
                                autofocus
                                autocomplete="off"
                                spellcheck="false"
                            >
                            <span class="input-icon">🔍</span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <span>Tafuta</span>
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
                    'unused'  => ['Haijatumika',  '#6366f1'],
                    'active'  => ['Inafanya kazi', '#10b981'],
                    'expired' => ['Imekwisha',     '#ef4444'],
                    default   => ['--',            '#94a3b8'],
                };
            ?>

                <div class="countdown-display">
                    <div class="countdown-label">Muda Uliobaki</div>
                    <div class="countdown-time" id="countdown">
                        <?= $status === 'active' ? formatTime($remaining) : '—' ?>
                    </div>
                </div>

                <div class="status-info">
                    <div class="status-row">
                        <span class="status-label">Msimbo</span>
                        <span class="status-value" style="font-family:monospace;"><?= htmlspecialchars($voucher['code']) ?></span>
                    </div>
                    <div class="status-row">
                        <span class="status-label">Mpango</span>
                        <span class="status-value"><?= htmlspecialchars($voucher['plan_name']) ?></span>
                    </div>
                    <div class="status-row">
                        <span class="status-label">Hali</span>
                        <span class="status-value" style="color:<?= $statusLabel[1] ?>; font-weight:700;"><?= $statusLabel[0] ?></span>
                    </div>
                    <?php if ($voucher['first_used_at']): ?>
                    <div class="status-row">
                        <span class="status-label">Ilianza</span>
                        <span class="status-value"><?= date('d/m/Y H:i', strtotime($voucher['first_used_at'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($expiresAt): ?>
                    <div class="status-row">
                        <span class="status-label">Inakwisha</span>
                        <span class="status-value"><?= date('d/m/Y H:i', $expiresAt) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($status === 'active'): ?>
                <!-- Progress bar -->
                <div class="progress-section">
                    <div class="progress-track">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                    <div class="progress-text">
                        <span id="progressPercent"><?= round(($remaining / max(1, $remaining + (time() - strtotime($voucher['first_used_at'])))) * 100) ?>%</span>
                        <span>ya muda umebaki</span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="tips-box">
                    <h3>📋 Taarifa</h3>
                    <ul>
                        <?php if ($status === 'active'): ?>
                        <li>Unaweza kutumia mtandao kwa muda wote wa voucher</li>
                        <li>Kuondoka mapema, tembelea: <code>http://portal.tplink.net/portal/logout</code></li>
                        <?php elseif ($status === 'expired'): ?>
                        <li>Voucher hii imekwisha muda — nunua mpya ili kuendelea</li>
                        <?php else: ?>
                        <li>Weka voucher kwenye portal ili kuanza kutumia mtandao</li>
                        <?php endif; ?>
                    </ul>
                </div>

            <?php endif; ?>

            <div style="text-align:center; margin-top:16px;">
                <a href="/" class="info-link">← Rudi kwenye Voucher Portal</a>
            </div>
        </div>

        <div class="footer">
            <p>&copy; <?= date('Y') ?> WiFi Portal &middot; Huduma ya Mtandao</p>
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
                countdownEl.textContent = 'Muda umekwisha';
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
