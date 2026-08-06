#!/usr/bin/env bash
# One-time VPS setup: GitHub push to development → automatic 501c3ers.com upload.
# Does not depend on GitHub Actions job assignment.
# Run as believeinunity:
#   curl -fsSL https://raw.githubusercontent.com/believeinunityorg/biu/development/scripts/install-auto-deploy-development.sh | bash
set -euo pipefail

if [ "$(id -un)" != "believeinunity" ]; then
  echo "Run as believeinunity (current: $(id -un))"
  exit 1
fi

BRANCH="${BRANCH:-development}"
STATE_DIR="${STATE_DIR:-/home/believeinunity/auto-deploy-development}"
SCRIPT_DIR="${STATE_DIR}/scripts"
LOG_DIR="${LOG_DIR:-/home/believeinunity/logs}"
SERVICE_NAME="biu-auto-deploy-development"
UNIT_DIR="${HOME}/.config/systemd/user"

mkdir -p "${SCRIPT_DIR}" "${LOG_DIR}" "${UNIT_DIR}"

curl -fsSL "https://raw.githubusercontent.com/believeinunityorg/biu/${BRANCH}/scripts/auto-deploy-development.sh" \
  -o "${SCRIPT_DIR}/auto-deploy-development.sh"
curl -fsSL "https://raw.githubusercontent.com/believeinunityorg/biu/${BRANCH}/scripts/manual-deploy-development.sh" \
  -o "${SCRIPT_DIR}/manual-deploy-development.sh"
chmod +x "${SCRIPT_DIR}/auto-deploy-development.sh" "${SCRIPT_DIR}/manual-deploy-development.sh"

cat > "${UNIT_DIR}/${SERVICE_NAME}.service" <<EOF
[Unit]
Description=BIU auto-deploy development (501c3ers.com)
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=${SCRIPT_DIR}/auto-deploy-development.sh
WorkingDirectory=${HOME}
Environment=HOME=${HOME}
Nice=10
EOF

cat > "${UNIT_DIR}/${SERVICE_NAME}.timer" <<EOF
[Unit]
Description=Poll GitHub development branch and deploy to 501c3ers.com

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
AccuracySec=30s
Persistent=true
Unit=${SERVICE_NAME}.service

[Install]
WantedBy=timers.target
EOF

systemctl --user daemon-reload
systemctl --user enable --now "${SERVICE_NAME}.timer"
# Deploy current tip once now (if newer than last recorded sha).
systemctl --user start "${SERVICE_NAME}.service" || true

if command -v loginctl >/dev/null 2>&1; then
  loginctl enable-linger believeinunity 2>/dev/null || true
fi

echo "Installed: ${SERVICE_NAME}.timer (every ~1 minute)"
echo "Status: systemctl --user status ${SERVICE_NAME}.timer --no-pager"
echo "Logs:   journalctl --user -u ${SERVICE_NAME}.service -n 50 --no-pager"
systemctl --user --no-pager status "${SERVICE_NAME}.timer" || true
