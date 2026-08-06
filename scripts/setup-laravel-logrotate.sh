#!/bin/bash
# Install logrotate rules for Laravel storage/logs on both cPanel sites.
# Run as root: bash scripts/setup-laravel-logrotate.sh
set -euo pipefail

cat >/etc/logrotate.d/biu-laravel <<'EOF'
/home/believeinunity/public_html/storage/logs/*.log
/home/c3ers/public_html/storage/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    copytruncate
    su root root
}
EOF

echo "Wrote /etc/logrotate.d/biu-laravel"
logrotate -d /etc/logrotate.d/biu-laravel 2>&1 | tail -n 20 || true
echo "Done. logrotate will rotate daily on schedule."
