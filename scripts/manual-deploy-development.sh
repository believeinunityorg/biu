#!/usr/bin/env bash
# Development upload engine (501c3ers.com). Used by auto-deploy watcher / emergency runs.
# Prefer: install-auto-deploy-development.sh so GitHub pushes deploy without Actions.
set -euo pipefail

if [ "$(id -un)" != "believeinunity" ]; then
  echo "Run as believeinunity (current: $(id -un))"
  exit 1
fi

LOCK_FILE="${LOCK_FILE:-/home/believeinunity/logs/deploy-development.lock}"
mkdir -p "$(dirname "${LOCK_FILE}")"
exec 9>"${LOCK_FILE}"
if ! flock -n 9; then
  echo "Another development deploy is already running — skip."
  exit 0
fi

REPO_URL="${REPO_URL:-https://github.com/believeinunityorg/biu.git}"
BRANCH="${BRANCH:-development}"
WORK_DIR="${WORK_DIR:-/home/believeinunity/manual-deploy-dev}"
DEPLOY_USER="${DEPLOY_USER:-c3ers}"
DEPLOY_PATH="${DEPLOY_PATH:-/home/c3ers/public_html}"
APP_URL="${APP_URL:-https://501c3ers.com}"
INNER_KEY="${INNER_KEY:-/home/believeinunity/.local/share/.gconf/deploy_key}"
PHP_VERSION="${PHP_VERSION:-8.4}"
SSH_DIR="${SSH_DIR:-${HOME}/.ssh-manual-deploy}"
PHP_CLI="/opt/cpanel/ea-php$(echo "${PHP_VERSION}" | tr -d '.')/root/usr/bin/php"

if [ ! -x "${PHP_CLI}" ]; then
  echo "Missing EA PHP CLI at ${PHP_CLI}"
  exit 1
fi
if [ ! -f "${INNER_KEY}" ]; then
  echo "Missing deploy key: ${INNER_KEY}"
  exit 1
fi

mkdir -p "${HOME}/bin" "${WORK_DIR}" "${SSH_DIR}"
chmod 700 "${SSH_DIR}"
ln -sfn "${PHP_CLI}" "${HOME}/bin/php"
export PATH="${HOME}/bin:$(dirname "${PHP_CLI}"):${PATH}"

if [ ! -f "${HOME}/bin/composer.phar" ]; then
  curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o "${HOME}/bin/composer.phar"
fi
printf '%s\n' '#!/usr/bin/env bash' "exec \"${PHP_CLI}\" \"\${HOME}/bin/composer.phar\" \"\$@\"" > "${HOME}/bin/composer"
chmod +x "${HOME}/bin/composer"

if [ -s "${HOME}/.nvm/nvm.sh" ]; then
  # shellcheck source=/dev/null
  . "${HOME}/.nvm/nvm.sh"
  nvm use 24 >/dev/null 2>&1 || nvm install 24
fi

cat > "${SSH_DIR}/config" <<EOF
Host cpanel-deploy
  HostName 127.0.0.1
  User ${DEPLOY_USER}
  Port 22
  IdentityFile ${INNER_KEY}
  IdentitiesOnly yes
  StrictHostKeyChecking accept-new
EOF
chmod 600 "${SSH_DIR}/config"
SSH_CMD=(ssh -F "${SSH_DIR}/config" -o BatchMode=yes -o ConnectTimeout=30 -4)
RSYNC_RSH="ssh -F ${SSH_DIR}/config -o BatchMode=yes -o ConnectTimeout=30 -4"

"${SSH_CMD[@]}" cpanel-deploy "echo SSH_OK && whoami && test -d '${DEPLOY_PATH}'"

if [ -d "${WORK_DIR}/.git" ]; then
  git -C "${WORK_DIR}" fetch --depth 1 origin "${BRANCH}"
  # Discard leftover edits from previous failed runs before switching.
  git -C "${WORK_DIR}" reset --hard HEAD
  git -C "${WORK_DIR}" clean -fdx
  git -C "${WORK_DIR}" checkout -f -B "${BRANCH}" "origin/${BRANCH}"
  git -C "${WORK_DIR}" reset --hard "origin/${BRANCH}"
  git -C "${WORK_DIR}" clean -fdx
else
  rm -rf "${WORK_DIR}"
  git clone --depth 1 --branch "${BRANCH}" "${REPO_URL}" "${WORK_DIR}"
fi

cd "${WORK_DIR}"
rm -f .env .env.server
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-ansi --ignore-platform-req=ext-sodium
mkdir -p resources/views storage/framework/views

"${SSH_CMD[@]}" cpanel-deploy "grep -E '^(APP_NAME|APP_URL|APP_VERSION)=|^REVERB_|^VITE_' '${DEPLOY_PATH}/.env' | grep -v '^#'" > .env.server
chmod +x .github/scripts/expand-vite-env.sh
{
  ./.github/scripts/expand-vite-env.sh < .env.server | grep -v '^APP_URL='
  echo "APP_URL=\"${APP_URL}\""
} > .env
grep -qE '^VITE_REVERB_APP_KEY=.+' .env || { echo "VITE_REVERB_APP_KEY empty after resolve"; exit 1; }

npm ci --legacy-peer-deps --no-audit --no-fund
npm run build
test -f public/build/manifest.json

RSYNC_EXCLUDES=(
  --exclude=.git/
  --exclude=.github/
  --exclude=node_modules/
  --exclude=tests/
  --exclude=storage/
  --exclude=.env
  --exclude=.env.*
  --exclude=.htaccess
  --exclude=docs/
  --exclude=deploy/
  --exclude=forgestack/
  --exclude=/build/
  --exclude=resources/js/
  --exclude=*.md
  --exclude=phpunit.xml
  --exclude=package.json
  --exclude=package-lock.json
  --exclude=vite.config.ts
  --exclude=vendor/
)

rsync -avz --delete -e "${RSYNC_RSH}" "${RSYNC_EXCLUDES[@]}" ./ "cpanel-deploy:${DEPLOY_PATH}/"
rsync -avz --delete -e "${RSYNC_RSH}" ./vendor/ "cpanel-deploy:${DEPLOY_PATH}/vendor/"

"${SSH_CMD[@]}" cpanel-deploy bash -s <<REMOTE
set -euo pipefail
cd "${DEPLOY_PATH}"
mkdir -p storage/app/public storage/framework/{sessions,views,cache/data} storage/logs
chmod -R 775 storage bootstrap/cache 2>/dev/null || true
chmod -R 755 public/build 2>/dev/null || true
PHP_BIN="${PHP_CLI}"
if [ ! -x "\${PHP_BIN}" ]; then PHP_BIN="php"; fi
"\${PHP_BIN}" artisan migrate --force --no-interaction
"\${PHP_BIN}" artisan optimize:clear
"\${PHP_BIN}" artisan config:cache
"\${PHP_BIN}" artisan route:cache
"\${PHP_BIN}" artisan view:cache
"\${PHP_BIN}" artisan queue:restart 2>/dev/null || true
echo MANUAL_DEPLOY_OK
REMOTE

echo "Done: ${BRANCH} -> ${DEPLOY_USER}@127.0.0.1:${DEPLOY_PATH} (${APP_URL})"
