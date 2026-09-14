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
MOD_SRC="$APP_ROOT/nginx/freeradius-sqlcounter-quota"
MOD_DST="$FR_DIR/mods-available/sqlcounter_quota"
HOOK_SRC="$APP_ROOT/nginx/freeradius-accounting-quota"
SITE="$FR_DIR/sites-enabled/default"

if [[ ! -f "$MOD_SRC" ]]; then
    echo "Missing $MOD_SRC" >&2
    exit 1
fi

cp "$MOD_SRC" "$MOD_DST"
ln -sf ../mods-available/sqlcounter_quota "$FR_DIR/mods-enabled/sqlcounter_quota"

# Accounting hook (exec quota_hook) for real-time per-packet cutoff.
# The source file holds the `exec quota_hook { ... }` block; strip comment
# lines and write the block to mods-available/quota_hook.
if [[ -f "$HOOK_SRC" ]]; then
    awk '!/^#|^$/{print}' "$HOOK_SRC" > "$FR_DIR/mods-available/quota_hook"
    ln -sf ../mods-available/quota_hook "$FR_DIR/mods-enabled/quota_hook"
    echo "Installed quota_hook exec module."
else
    echo "WARNING: missing $HOOK_SRC — skipping accounting hook install." >&2
fi

# counter_name attribute for sqlcounter (stores running byte total from SQL)
DICT_LOCAL="$FR_DIR/dictionary.local"
if ! grep -q 'Max-All-Octets-Used' "$DICT_LOCAL" 2>/dev/null; then
    cat >> "$DICT_LOCAL" <<'EOF'

# Voucher data-quota sqlcounter (see nginx/freeradius-sqlcounter-quota)
ATTRIBUTE Max-All-Octets-Used 3001 integer
EOF
    echo "Added Max-All-Octets-Used to $DICT_LOCAL"
fi

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

if ! grep -q 'quota_hook' "$SITE"; then
    # Insert after the first bare "sql" line inside accounting.
    awk '
        /^[[:space:]]*accounting[[:space:]]*\{/ { in_acct=1 }
        in_acct && /^[[:space:]]*\}/ && depth==0 { in_acct=0 }
        in_acct { if ($0 ~ /^[[:space:]]*sql[[:space:]]*$/) { print; print "        quota_hook"; next } }
        { print }
    ' "$SITE" > "${SITE}.tmp"
    mv "${SITE}.tmp" "$SITE"
    echo "Added quota_hook to accounting{} in $SITE"
else
    echo "quota_hook already present in $SITE"
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
