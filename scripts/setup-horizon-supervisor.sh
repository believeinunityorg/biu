#!/bin/bash
# Switch Supervisor from queue:work → Laravel Horizon (Production Roadmap).
# Run as root on the VPS for both sites after deploy contains laravel/horizon.
#
# Usage:
#   bash scripts/setup-horizon-supervisor.sh
set -euo pipefail

PHP_BIN="${PHP_BIN:-/opt/cpanel/ea-php84/root/usr/bin/php}"

write_horizon_ini() {
  local site="$1"   # believeinunity | c3ers
  local path="$2"
  local user="$3"
  local ini="/etc/supervisord.d/laravel-horizon-${site}.ini"

  cat >"$ini" <<EOF
[program:laravel-horizon-${site}]
process_name=%(program_name)s
command=${PHP_BIN} ${path}/artisan horizon
directory=${path}
autostart=true
autorestart=true
user=${user}
redirect_stderr=true
stdout_logfile=/home/${user}/logs/horizon.log
stopwaitsecs=3600
stopasgroup=true
killasgroup=true
EOF
  echo "Wrote $ini"
}

mkdir -p /home/believeinunity/logs /home/c3ers/logs
chown believeinunity:believeinunity /home/believeinunity/logs || true
chown c3ers:c3ers /home/c3ers/logs || true

write_horizon_ini believeinunity /home/believeinunity/public_html believeinunity
write_horizon_ini c3ers /home/c3ers/public_html c3ers

# Stop legacy queue:work programs if present (keep ini files but stop processes)
for prog in laravel-queue-believeinunity laravel-queue-c3ers; do
  supervisorctl stop "${prog}:*" 2>/dev/null || true
done

supervisorctl reread
supervisorctl update
supervisorctl start laravel-horizon-believeinunity laravel-horizon-c3ers || true
supervisorctl status | grep -E 'horizon|queue' || true

echo "Horizon Supervisor programs installed. Legacy queue workers stopped (inis retained as backup)."
echo "Verify: sudo -u believeinunity ${PHP_BIN} /home/believeinunity/public_html/artisan horizon:status"
echo "Verify: sudo -u c3ers ${PHP_BIN} /home/c3ers/public_html/artisan horizon:status"
