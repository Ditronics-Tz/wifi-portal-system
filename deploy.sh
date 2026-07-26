#!/bin/bash
# WiFi Voucher Portal Deployment Script
# Run this script on the Ubuntu server (192.168.100.100)

set -e  # Exit on error

echo "=========================================="
echo "WiFi Voucher Portal Deployment"
echo "=========================================="

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "Please run as root (sudo ./deploy.sh)"
    exit 1
fi

# Variables
APP_DIR="/var/www/voucher-portal"
PHP_VERSION="8.1"  # Change if different
DB_NAME="radius"
DB_USER="radius"

# Step 1: Check prerequisites
echo ""
echo "[1/8] Checking prerequisites..."

command -v nginx >/dev/null 2>&1 || { echo "Nginx not found. Install: apt install nginx"; exit 1; }
command -v php-fpm${PHP_VERSION} >/dev/null 2>&1 || { echo "PHP-FPM not found. Install: apt install php${PHP_VERSION}-fpm"; exit 1; }
command -v psql >/dev/null 2>&1 || { echo "PostgreSQL not found. Install: apt install postgresql"; exit 1; }
command -v freeradius >/dev/null 2>&1 || { echo "FreeRADIUS not found. Install: apt install freeradius"; exit 1; }

echo "✓ All prerequisites found"

# Step 2: Copy files
echo ""
echo "[2/8] Copying application files..."

if [ -d "$APP_DIR" ]; then
    echo "Warning: $APP_DIR already exists. Backing up..."
    mv "$APP_DIR" "${APP_DIR}.bak.$(date +%Y%m%d%H%M%S)"
fi

cp -r "$(dirname "$0")" "$APP_DIR"
chown -R www-data:www-data "$APP_DIR"
chmod -R 755 "$APP_DIR"
chmod 600 "$APP_DIR/config.php"

echo "✓ Files copied to $APP_DIR"

# Step 3: Database migration
echo ""
echo "[3/8] Running database migration..."

echo "Please enter PostgreSQL password for user '$DB_USER':"
read -s PGPASSWORD

PGPASSWORD="$PGPASSWORD" psql -U "$DB_USER" -d "$DB_NAME" -f "$APP_DIR/migrations/001_add_vouchers_table.sql" || {
    echo "Migration failed. Please check database credentials."
    exit 1
}

echo "✓ Database tables created"

# Step 4: Check FreeRADIUS client configuration
echo ""
echo "[4/8] Checking FreeRADIUS configuration..."

if grep -q "client localhost_portal" /etc/freeradius/3.0/clients.conf 2>/dev/null; then
    echo "✓ FreeRADIUS client already configured"
else
    echo "Adding FreeRADIUS client for portal..."
    cat >> /etc/freeradius/3.0/clients.conf << 'EOF'

# Voucher Portal local client
client localhost_portal {
    ipaddr = 127.0.0.1
    secret = testing123
    nastype = other
}
EOF
    echo "✓ FreeRADIUS client added"
    echo "Restarting FreeRADIUS..."
    systemctl restart freeradius
fi

# Step 5: Configure Nginx
echo ""
echo "[5/8] Configuring Nginx..."

cp "$APP_DIR/nginx/voucher-portal.conf" /etc/nginx/sites-available/
ln -sf /etc/nginx/sites-available/voucher-portal.conf /etc/nginx/sites-enabled/

# Check if port 8090 is available
if netstat -tlnp 2>/dev/null | grep -q ":8090 "; then
    echo "Warning: Port 8090 is already in use!"
    echo "Please edit /etc/nginx/sites-available/voucher-portal.conf"
    echo "and change the port number."
else
    echo "✓ Port 8090 is available"
fi

# Test nginx configuration
nginx -t || {
    echo "Nginx configuration test failed!"
    exit 1
}

echo "✓ Nginx configured"

# Step 6: Configure PHP-FPM (optional)
echo ""
echo "[6/8] Configuring PHP-FPM..."

POOL_CONF="/etc/php/${PHP_VERSION}/fpm/pool.d/voucher-portal.conf"
if [ ! -f "$POOL_CONF" ]; then
    cp "$APP_DIR/nginx/voucher-portal-pool.conf" "$POOL_CONF"
    mkdir -p /var/lib/php/sessions/voucher-portal
    chown www-data:www-data /var/lib/php/sessions/voucher-portal
    echo "✓ PHP-FPM pool configured"
else
    echo "✓ PHP-FPM pool already exists"
fi

# Step 7: Restart services
echo ""
echo "[7/8] Restarting services..."

systemctl reload nginx
systemctl restart php${PHP_VERSION}-fpm

echo "✓ Services restarted"

# Step 8: Configure firewall
echo ""
echo "[8/8] Configuring firewall..."

if command -v ufw >/dev/null 2>&1; then
    ufw allow 8090/tcp comment "WiFi Voucher Portal" 2>/dev/null || true
    echo "✓ Firewall rule added"
else
    echo "Note: ufw not found. Please manually open port 8090."
fi

# Generate admin password hash
echo ""
echo "=========================================="
echo "SETUP COMPLETE!"
echo "=========================================="
echo ""
echo "Next steps:"
echo ""
echo "1. Edit the config file:"
echo "   sudo nano /var/www/voucher-portal/config.php"
echo ""
echo "2. Set your admin password:"
echo "   php -r \"echo password_hash('YOUR_PASSWORD', PASSWORD_ARGON2ID);\""
echo "   Then update ADMIN_PASSWORD_HASH in config.php"
echo ""
echo "3. Access the portal:"
echo "   Public: http://192.168.100.100:8090/"
echo "   Admin:  http://192.168.100.100:8090/admin/login.php"
echo ""
echo "4. Configure your AP portal URL to:"
echo "   http://192.168.100.100:8090/"
echo ""
echo "5. Test with radtest:"
echo "   Generate a voucher in admin panel, then:"
echo "   radtest CODE CODE 127.0.0.1 0 testing123"
echo ""
echo "=========================================="
