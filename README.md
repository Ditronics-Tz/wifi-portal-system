# WiFi Voucher Portal

A PHP-based captive portal for WiFi voucher management with FreeRADIUS integration.

## Overview

This application provides:
- **Public Portal**: Customer-facing page for voucher code entry
- **Admin Panel**: Password-protected dashboard for voucher management

## Prerequisites

- Ubuntu server with FreeRADIUS + PostgreSQL already configured
- PHP 8.1+ with FPM
- Nginx
- PostgreSQL database named `radius`

## Installation Steps

### 1. Copy Application Files

```bash
sudo cp -r voucher-portal /var/www/voucher-portal
sudo chown -R www-data:www-data /var/www/voucher-portal
sudo chmod -R 755 /var/www/voucher-portal
sudo chmod 600 /var/www/voucher-portal/config.php
```

### 2. Create Database Table

```bash
sudo -u postgres psql radius < /var/www/voucher-portal/migrations/001_add_vouchers_table.sql
```

### 3. Configure Application

Edit `/var/www/voucher-portal/config.php`:

```php
// Set your database credentials
define('DB_USER', 'radius');
define('DB_PASS', 'your_db_password');

// Set RADIUS secret (must match clients.conf entry for 127.0.0.1)
define('RADIUS_SECRET', 'your_radius_secret');

// Generate admin password hash
// Run: php -r "echo password_hash('your_password', PASSWORD_ARGON2ID);"
define('ADMIN_PASSWORD_HASH', '$argon2id$v=19$m=65536,t=4,p=1$...');
```

### 4. Add FreeRADIUS Client for Local Portal

Edit `/etc/freeradius/3.0/clients.conf` and add:

```conf
client localhost_portal {
    ipaddr = 127.0.0.1
    secret = testing123  # Must match RADIUS_SECRET in config.php
    nastype = other
}
```

Restart FreeRADIUS:
```bash
sudo systemctl restart freeradius
```

### 5. Configure Nginx

```bash
sudo cp /var/www/voucher-portal/nginx/voucher-portal.conf /etc/nginx/sites-available/
sudo ln -s /etc/nginx/sites-available/voucher-portal.conf /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### 6. Configure PHP-FPM Pool (Optional)

```bash
sudo cp /var/www/voucher-portal/nginx/voucher-portal-pool.conf /etc/php/8.1/fpm/pool.d/
sudo mkdir -p /var/lib/php/sessions/voucher-portal
sudo chown www-data:www-data /var/lib/php/sessions/voucher-portal
sudo systemctl restart php8.1-fpm
```

### 7. Open Firewall Port

```bash
sudo ufw allow 8090/tcp
```

## Access Points

- **Public Portal**: http://192.168.100.100:8090/
- **Admin Panel**: http://192.168.100.100:8090/admin/login.php
- **Default Admin**: username `admin`, password as set in config.php

## Testing with radtest

Before configuring the AP, test a voucher end-to-end:

### 1. Generate a Test Voucher

1. Login to admin panel
2. Generate 1 voucher with "Siku 1" plan
3. Copy the generated code

### 2. Test RADIUS Authentication

```bash
# Replace VOUCHER_CODE with your actual code
# The code is used as both username and password

# Test with radtest (from freeradius-utils)
radtest VOUCHER_CODE VOUCHER_CODE 127.0.0.1 0 testing123

# Expected output for unused voucher:
# Received Access-Accept Id xxx from 127.0.0.1:1812 to 0.0.0.0:0 length 36
# Session-Timeout = 86400
```

### 3. Test via Portal

```bash
# Test the PHP portal directly
curl -X POST http://192.168.100.100:8090/ \
  -d "code=VOUCHER_CODE&csrf_token=test" \
  -v
```

### 4. Verify Database State

```bash
sudo -u postgres psql radius

SELECT code, status, first_used_at, expires_at FROM vouchers;
```

## TP-Link AP Configuration

Configure the EAP650 standalone portal:

1. Access AP web interface at http://192.168.100.101
2. Go to **Wireless Control** → **Portal**
3. Enable Portal
4. Portal Type: **External Portal Server**
5. Portal URL: `http://192.168.100.100:8090/`
6. Authentication Type: **Portal**
7. Redirect URL parameters: Check "Client MAC" option

The AP will redirect clients like:
```
http://192.168.100.100:8090/?client_mac=AA:BB:CC:DD:EE:FF&ap=11:22:33:44:55:66
```

## Voucher Business Rules

- **Duration starts from first use**, not from creation
- Unused vouchers remain valid indefinitely
- Reconnecting mid-session uses remaining time (not full reset)
- Expired vouchers show "Voucher imekwisha muda."

## Security Notes

- All database queries use PDO prepared statements
- RADIUS calls use proc_open (no shell injection)
- CSRF protection on all forms
- Admin login rate limiting
- Password hashed with Argon2id

## File Structure

```
/var/www/voucher-portal/
├── public/                     # Nginx document root
│   ├── index.php              # Customer voucher form
│   ├── status.php             # Connection status page
│   └── assets/style.css      # Styles
├── admin/
│   ├── login.php              # Admin login
│   ├── dashboard.php          # Voucher list/management
│   ├── generate.php           # Generate new vouchers
│   └── logout.php
├── src/
│   ├── db.php                 # Database connection
│   ├── radius_client.php      # RADIUS integration
│   ├── voucher_service.php    # Business logic
│   └── auth.php               # Admin authentication
├── config.php                 # Configuration (DO NOT expose!)
├── migrations/
│   └── 001_add_vouchers_table.sql
├── nginx/
│   ├── voucher-portal.conf    # Nginx site config
│   └── voucher-portal-pool.conf  # PHP-FPM pool config
├── .htaccess                  # Apache fallback protection
└── README.md                  # This file
```

## Troubleshooting

### Portal shows "System unavailable"

Check FreeRADIUS is running:
```bash
sudo systemctl status freeradius
sudo tail -f /var/log/freeradius/radius.log
```

### Voucher rejected but should be valid

Check radreply table:
```bash
sudo -u postgres psql radius
SELECT * FROM radreply WHERE username = 'VOUCHER_CODE';
```

### PHP errors

Check logs:
```bash
sudo tail -f /var/log/nginx/voucher-portal-error.log
sudo tail -f /var/log/php/voucher-portal-error.log
```

### Permission issues

```bash
sudo chown -R www-data:www-data /var/www/voucher-portal
sudo chmod 600 /var/www/voucher-portal/config.php
```

## Optional: Stretch Goals (Not Implemented)

- **CoA Disconnect**: Force-disconnect active users on manual revoke
- **M-Pesa Integration**: Automated voucher sales
- **HTTPS**: Self-signed certificate for internal use

## Support

For issues or questions, check:
1. FreeRADIUS logs: `/var/log/freeradius/radius.log`
2. Nginx logs: `/var/log/nginx/voucher-portal-*.log`
3. PHP logs: `/var/log/php/voucher-portal-error.log`
4. PostgreSQL logs: `/var/log/postgresql/*.log`
