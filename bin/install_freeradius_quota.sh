#!/bin/bash
# Install FreeRADIUS sqlcounter_quota for per-voucher data caps.
# Must run as root on the portal/RADIUS host:
#   sudo bash /var/www/voucher-portal/bin/install_freeradius_quota.sh
set -euo pipefail

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    echo "Run as root: sudo bash $0" >&2
    exit 1
fi

APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FR_DIR="/etc/freeradius/3.0"
MOD_SRC="$APP_ROOT/freeradius/sqlcounter_quota"
MOD_DST="$FR_DIR/mods-available/sqlcounter_quota"
SITE="$FR_DIR/sites-enabled/default"

if [[ ! -f "$MOD_SRC" ]]; then
    echo "Missing $MOD_SRC" >&2
    exit 1
fi

cp "$MOD_SRC" "$MOD_DST"
ln -sf ../mods-available/sqlcounter_quota "$FR_DIR/mods-enabled/sqlcounter_quota"

if [[ ! -f "$SITE" ]]; then
    echo "Missing $SITE" >&2
    exit 1
fi

if ! grep -q 'sqlcounter_quota' "$SITE"; then
    # Insert after the first bare "sql" line inside authorize (module call, not sql { block).
    awk '
        /^[[:space:]]*authorize[[:space:]]*\{/ { in_auth=1 }
        in_auth && /^[[:space:]]*\}/ && depth==0 { in_auth=0 }
        in_auth { if ($0 ~ /^[[:space:]]*sql[[:space:]]*$/) { print; print "        sqlcounter_quota"; next } }
        { print }
    ' "$SITE" > "${SITE}.tmp"
    mv "${SITE}.tmp" "$SITE"
    echo "Added sqlcounter_quota to authorize{} in $SITE"
else
    echo "sqlcounter_quota already present in $SITE"
fi

echo "Testing FreeRADIUS configuration..."
freeradius -XC 2>&1 | tee /tmp/freeradius-test.log | tail -20

if grep -qi 'error' /tmp/freeradius-test.log && ! grep -qi 'Configuration appears to be OK' /tmp/freeradius-test.log; then
    echo "FreeRADIUS config test reported errors — not restarting." >&2
    exit 1
fi

systemctl restart freeradius
systemctl is-active freeradius
echo "Done. sqlcounter_quota is enabled."
