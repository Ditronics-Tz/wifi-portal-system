<?php
/**
 * Database connection — PostgreSQL
 */

require_once dirname(__DIR__) . '/config.php';

function getDB() {
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', DB_HOST, DB_PORT, DB_NAME);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // Auto-create tables
            createTables($pdo);

        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            die('System unavailable. Please try again later.');
        }
    }

    return $pdo;
}

function createTables(PDO $db): void {
    // ── Vouchers ─────────────────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS vouchers (
            id SERIAL PRIMARY KEY,
            code VARCHAR(32) UNIQUE NOT NULL,
            plan_name VARCHAR(64) NOT NULL,
            duration_seconds INT NOT NULL,
            price NUMERIC(10,2) DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'unused' CHECK (status IN ('unused','active','expired')),
            created_at TIMESTAMP DEFAULT NOW(),
            first_used_at TIMESTAMP NULL,
            expires_at TIMESTAMP NULL,
            created_by VARCHAR(64),
            seller_id INTEGER NULL
        )
    ");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_vouchers_code ON vouchers(code)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_vouchers_status ON vouchers(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_vouchers_seller_id ON vouchers(seller_id)");

    // ── FreeRADIUS tables ────────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS radcheck (
            id SERIAL PRIMARY KEY,
            username VARCHAR(64) NOT NULL,
            attribute VARCHAR(64) NOT NULL,
            op VARCHAR(2) NOT NULL DEFAULT ':=',
            value VARCHAR(253) NOT NULL
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS radreply (
            id SERIAL PRIMARY KEY,
            username VARCHAR(64) NOT NULL,
            attribute VARCHAR(64) NOT NULL,
            op VARCHAR(2) NOT NULL DEFAULT ':=',
            value VARCHAR(253) NOT NULL
        )
    ");

    // ── Users ────────────────────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            role VARCHAR(16) NOT NULL CHECK (role IN ('admin', 'seller', 'buyer')),
            username VARCHAR(64) UNIQUE NOT NULL,
            phone VARCHAR(20) UNIQUE,
            full_name VARCHAR(128),
            password_hash VARCHAR(255) NOT NULL,
            is_active BOOLEAN DEFAULT true,
            is_deleted BOOLEAN DEFAULT false,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW(),
            created_by INT NULL REFERENCES users(id)
        )
    ");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_users_username ON users(username)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_users_is_active ON users(is_active)");

    // ── Sales ────────────────────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS sales (
            id SERIAL PRIMARY KEY,
            voucher_code VARCHAR(32) NOT NULL,
            seller_id INT NULL REFERENCES users(id),
            buyer_id INT NULL REFERENCES users(id),
            buyer_phone VARCHAR(20) NULL,
            buyer_name VARCHAR(128) NULL,
            plan_name VARCHAR(64) NOT NULL,
            price NUMERIC(10,2) NOT NULL,
            payment_method VARCHAR(16) DEFAULT 'cash',
            payment_reference VARCHAR(64) NULL,
            sold_at TIMESTAMP DEFAULT NOW()
        )
    ");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_sales_seller_id ON sales(seller_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sales_voucher_code ON sales(voucher_code)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sales_sold_at ON sales(sold_at)");

    // ── Packages ─────────────────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS packages (
            id SERIAL PRIMARY KEY,
            name VARCHAR(64) NOT NULL,
            slug VARCHAR(64) UNIQUE NOT NULL,
            duration_seconds INT NOT NULL,
            price NUMERIC(10,2) NOT NULL,
            bandwidth_mbps INT NULL,
            data_quota_mb INT NULL,
            description TEXT NULL,
            is_active BOOLEAN DEFAULT true,
            is_deleted BOOLEAN DEFAULT false,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW(),
            created_by INT NULL REFERENCES users(id)
        )
    ");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_packages_slug ON packages(slug)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_packages_is_active ON packages(is_active)");

    // ── Audit Log ────────────────────────────────────────────────
    $db->exec("
        CREATE TABLE IF NOT EXISTS audit_log (
            id SERIAL PRIMARY KEY,
            user_id INT NULL REFERENCES users(id),
            action VARCHAR(64) NOT NULL,
            entity_type VARCHAR(32),
            entity_id VARCHAR(64),
            details JSONB,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT NOW()
        )
    ");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_user_id ON audit_log(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_action ON audit_log(action)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at)");

    // ── Seed default packages if empty ───────────────────────────
    $count = $db->query("SELECT COUNT(*) FROM packages")->fetchColumn();
    if ($count == 0) {
        $defaults = [
            ['siku_1', 'Siku 1', 86400, 500, null, null, 'Mtandao kwa saa 24', 1],
            ['wiki_1', 'Wiki 1', 604800, 3000, null, null, 'Mtandao kwa siku 7', 2],
            ['mwezi_1', 'Mwezi 1', 2592000, 10000, null, null, 'Mtandao kwa siku 30', 3],
        ];
        $stmt = $db->prepare("
            INSERT INTO packages (slug, name, duration_seconds, price, bandwidth_mbps, data_quota_mb, description, sort_order)
            VALUES (:slug, :name, :duration, :price, :bw, :quota, :desc, :sort)
        ");
        foreach ($defaults as $d) {
            $stmt->execute([
                ':slug' => $d[0], ':name' => $d[1], ':duration' => $d[2],
                ':price' => $d[3], ':bw' => $d[4], ':quota' => $d[5],
                ':desc' => $d[6], ':sort' => $d[7],
            ]);
        }
    }
}

/**
 * Generate a random voucher code
 */
function generateVoucherCode($length = 10) {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $code;
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCSRFToken($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Write an entry to the audit log
 */
function writeAuditLog(string $action, ?int $userId = null, ?string $entityType = null, ?string $entityId = null, ?array $details = null): void {
    $db = getDB();
    try {
        $validUserId = null;
        if ($userId && $userId > 0) {
            $check = $db->prepare("SELECT id FROM users WHERE id = :id");
            $check->execute([':id' => $userId]);
            if ($check->fetch()) {
                $validUserId = $userId;
            }
        }

        $stmt = $db->prepare("
            INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address)
            VALUES (:user_id, :action, :entity_type, :entity_id, :details::jsonb, :ip_address)
        ");
        $stmt->execute([
            ':user_id'     => $validUserId,
            ':action'      => $action,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':details'     => $details ? json_encode($details) : null,
            ':ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e) {
        error_log('Audit log write failed: ' . $e->getMessage());
    }
}
