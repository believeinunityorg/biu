#!/usr/bin/env bash
# Poll origin/development and deploy when HEAD changes (works even if GitHub Actions is down).
# Installed via: bash scripts/install-auto-deploy-development.sh
set -euo pipefail

if [ "$(id -un)" != "believeinunity" ]; then
  echo "Run as believeinunity (current: $(id -un))"
  exit 1
fi

REPO_URL="${REPO_URL:-https://github.com/believeinunityorg/biu.git}"
BRANCH="${BRANCH:-development}"
STATE_DIR="${STATE_DIR:-/home/believeinunity/auto-deploy-development}"
STATE_FILE="${STATE_DIR}/last-deployed.sha"
SCRIPT_DIR="${SCRIPT_DIR:-${STATE_DIR}/scripts}"
DEPLOY_SCRIPT="${SCRIPT_DIR}/manual-deploy-development.sh"

mkdir -p "${STATE_DIR}" "${SCRIPT_DIR}"

remote_sha="$(git ls-remote --heads "${REPO_URL}" "refs/heads/${BRANCH}" | awk '{print $1}')"
if [ -z "${remote_sha}" ]; then
  echo "Could not resolve ${BRANCH} on ${REPO_URL}"
  exit 1
fi

last_sha=""
if [ -f "${STATE_FILE}" ]; then
  last_sha="$(tr -d '[:space:]' < "${STATE_FILE}")"
fi

if [ "${remote_sha}" = "${last_sha}" ]; then
  echo "Up to date (${remote_sha})"
  exit 0
fi

echo "New ${BRANCH} commit: ${remote_sha} (was ${last_sha:-none}) — deploying"
curl -fsSL "https://raw.githubusercontent.com/believeinunityorg/biu/${BRANCH}/scripts/manual-deploy-development.sh" \
  -o "${DEPLOY_SCRIPT}"
chmod +x "${DEPLOY_SCRIPT}"

bash "${DEPLOY_SCRIPT}"
printf '%s\n' "${remote_sha}" > "${STATE_FILE}"
echo "Auto-deploy recorded ${remote_sha}"
