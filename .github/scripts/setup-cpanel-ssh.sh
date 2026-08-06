#!/usr/bin/env bash
# Reliable cPanel SSH for GitHub Actions (IPv4-only, minimal noise).
# Required env: SSH_PRIVATE_KEY, DEPLOY_USER, SSH_DIR
# Optional env: DEPLOY_HOST, SSH_CONNECT_HOST, SSH_PORT (22), SSH_MAX_ATTEMPTS (6), SSH_RETRY_SLEEP (5),
#   SSH_USE_TCP_PROBE (false), SSH_USE_DEPLOY_HOST (false), SSH_RELAY_TO_C3ERS (false)
set -euo pipefail

DEPLOY_USER="${DEPLOY_USER:-c3ers}"
DEPLOY_HOST="${DEPLOY_HOST:-501c3ers.com}"
SSH_PORT="${SSH_PORT:-22}"
SSH_CONNECT_HOST="${SSH_CONNECT_HOST:-72.60.226.88}"
SSH_RELAY_TO_C3ERS="${SSH_RELAY_TO_C3ERS:-false}"
SSH_RELAY_USER="${SSH_RELAY_USER:-believeinunity}"
SSH_RELAY_HOST="${SSH_RELAY_HOST:-72.60.226.88}"
SERVER_INNER_KEY="${SERVER_INNER_KEY:-/home/believeinunity/.local/share/.gconf/deploy_key}"
SSH_CONNECT_TIMEOUT="${SSH_CONNECT_TIMEOUT:-30}"
SSH_MAX_ATTEMPTS="${SSH_MAX_ATTEMPTS:-10}"
SSH_RETRY_SLEEP="${SSH_RETRY_SLEEP:-8}"
SSH_USE_TCP_PROBE="${SSH_USE_TCP_PROBE:-false}"
SSH_USE_DEPLOY_HOST="${SSH_USE_DEPLOY_HOST:-true}"
SSH_SELF_HOSTED="${SSH_SELF_HOSTED:-false}"
SSH_ALLOW_IPV6="${SSH_ALLOW_IPV6:-false}"

if [ "${SSH_SELF_HOSTED}" = "true" ]; then
  mkdir -p "${SSH_DIR}"
  chmod 700 "${SSH_DIR}"

  # Prefer the VPS-local inner key for same-machine hops (127.0.0.1).
  # GitHub secrets often hold a different key that is not in the target user's authorized_keys.
  identity_file=""
  if [ -f "${SERVER_INNER_KEY}" ]; then
    identity_file="${SERVER_INNER_KEY}"
    echo "Self-hosted VPS runner -> ${DEPLOY_USER}@127.0.0.1:${SSH_PORT} (inner key ${SERVER_INNER_KEY})"
  elif [ -n "${SSH_PRIVATE_KEY:-}" ]; then
    if printf '%s' "${SSH_PRIVATE_KEY}" | grep -q '\\n'; then
      printf '%b\n' "${SSH_PRIVATE_KEY}" | tr -d '\r' > "${SSH_DIR}/cpanel_deploy"
    else
      printf '%s\n' "${SSH_PRIVATE_KEY}" | tr -d '\r' > "${SSH_DIR}/cpanel_deploy"
    fi
    chmod 600 "${SSH_DIR}/cpanel_deploy"
    if ! grep -q 'BEGIN.*PRIVATE KEY' "${SSH_DIR}/cpanel_deploy"; then
      echo "::error::CPANEL SSH key does not look like an OpenSSH private key."
      exit 1
    fi
    identity_file="${SSH_DIR}/cpanel_deploy"
    echo "Self-hosted VPS runner -> ${DEPLOY_USER}@127.0.0.1:${SSH_PORT} (deploy secret key)"
  else
    echo "::error::Missing deploy key on VPS: ${SERVER_INNER_KEY} (and no SSH_PRIVATE_KEY secret)."
    exit 1
  fi

  printf '%s\n' \
    'Host cpanel-deploy' \
    '  HostName 127.0.0.1' \
    "  User ${DEPLOY_USER}" \
    "  Port ${SSH_PORT}" \
    "  IdentityFile ${identity_file}" \
    '  IdentitiesOnly yes' \
    '  StrictHostKeyChecking accept-new' \
    '  ServerAliveInterval 15' \
    '  ServerAliveCountMax 4' \
    > "${SSH_DIR}/config"
  chmod 600 "${SSH_DIR}/config"
  if [ -n "${GITHUB_ENV:-}" ]; then
    {
      echo "SSH_DIR=${SSH_DIR}"
      echo "SSH_CMD=ssh -F ${SSH_DIR}/config -o BatchMode=yes -o ConnectTimeout=${SSH_CONNECT_TIMEOUT} -4"
      echo "RSYNC_RSH=ssh -F ${SSH_DIR}/config -o BatchMode=yes -o ConnectTimeout=${SSH_CONNECT_TIMEOUT} -4"
    } >> "${GITHUB_ENV}"
  fi
  if out="$(ssh -4 -F "${SSH_DIR}/config" -o BatchMode=yes cpanel-deploy "echo SSH_OK && whoami && hostname" 2>&1)"; then
    echo "${out}"
    echo "SSH connected (self-hosted local hop)."
    exit 0
  fi
  echo "${out}" | tail -8
  echo "::error::Self-hosted SSH to ${DEPLOY_USER}@127.0.0.1 failed."
  exit 1
fi

if [ -z "${SSH_PRIVATE_KEY:-}" ]; then
  echo "::error::SSH_PRIVATE_KEY is empty (set CPANEL_SSH_KEY secret)."
  exit 1
fi

mkdir -p "${SSH_DIR}"
chmod 700 "${SSH_DIR}"

# Normalize private key (strip CR; support literal \n in GitHub secret).
if printf '%s' "${SSH_PRIVATE_KEY}" | grep -q '\\n'; then
  printf '%b\n' "${SSH_PRIVATE_KEY}" | tr -d '\r' > "${SSH_DIR}/cpanel_deploy"
else
  printf '%s\n' "${SSH_PRIVATE_KEY}" | tr -d '\r' > "${SSH_DIR}/cpanel_deploy"
fi
chmod 600 "${SSH_DIR}/cpanel_deploy"

if ! grep -q 'BEGIN.*PRIVATE KEY' "${SSH_DIR}/cpanel_deploy"; then
  echo "::error::CPANEL_SSH_KEY does not look like an OpenSSH private key (missing BEGIN PRIVATE KEY)."
  exit 1
fi

RUNNER_IP="$(curl -4 -fsS --max-time 8 https://api.ipify.org 2>/dev/null || echo unknown)"
if [ "${SSH_RELAY_TO_C3ERS}" = "true" ]; then
  echo "Runner IPv4: ${RUNNER_IP} -> ${SSH_RELAY_USER}@${SSH_RELAY_HOST}:${SSH_PORT} -> ${DEPLOY_USER}@127.0.0.1 (c3ers deploy)"
else
  echo "Runner IPv4: ${RUNNER_IP} -> ${DEPLOY_USER}@${SSH_CONNECT_HOST}:${SSH_PORT}"
fi

request_runner_allow() {
  local token="${DEPLOY_RUNNER_ALLOW_TOKEN:-}"
  local app_url="${APP_URL:-https://501c3ers.com}"
  if [ -z "${token}" ] || [ "${RUNNER_IP}" = "unknown" ]; then
    return 1
  fi
  echo "Requesting CSF allow for ${RUNNER_IP} before SSH..."
  local http_code
  http_code="$(
    curl -4 -sS --max-time 30 -o /tmp/deploy-allow-response.txt -w '%{http_code}' \
      -X POST "${app_url%/}/internal/deploy/allow-runner-ip" \
      -H "Authorization: Bearer ${token}" \
      -H "Content-Type: application/json" \
      -H "Accept: application/json" \
      -d "{\"ip\":\"${RUNNER_IP}\"}" || echo "000"
  )"
  if [ "${http_code}" = "200" ]; then
    cat /tmp/deploy-allow-response.txt 2>/dev/null || true
    echo ""
    sleep 3
    return 0
  fi
  echo "::warning::Runner allow request returned HTTP ${http_code}"
  cat /tmp/deploy-allow-response.txt 2>/dev/null || true
  echo ""
  return 1
}

if [ "${SSH_RELAY_TO_C3ERS}" != "true" ]; then
  request_runner_allow || true
fi

ssh-keyscan -T 8 -p "${SSH_PORT}" -H "${SSH_CONNECT_HOST}" >> "${SSH_DIR}/known_hosts" 2>/dev/null || true
if [ "${SSH_RELAY_TO_C3ERS}" = "true" ]; then
  ssh-keyscan -T 8 -p "${SSH_PORT}" -H "${SSH_RELAY_HOST}" >> "${SSH_DIR}/known_hosts" 2>/dev/null || true
fi
if [ "${SSH_USE_DEPLOY_HOST}" = "true" ] && [ -n "${DEPLOY_HOST}" ] && [ "${DEPLOY_HOST}" != "${SSH_CONNECT_HOST}" ]; then
  ssh-keyscan -T 8 -p "${SSH_PORT}" -H "${DEPLOY_HOST}" >> "${SSH_DIR}/known_hosts" 2>/dev/null || true
fi

if [ -n "${GITHUB_ENV:-}" ]; then
  {
    echo "SSH_DIR=${SSH_DIR}"
    echo "SSH_CMD=ssh -F ${SSH_DIR}/config -o BatchMode=yes -o ConnectTimeout=${SSH_CONNECT_TIMEOUT} -4"
    echo "RSYNC_RSH=ssh -F ${SSH_DIR}/config -o BatchMode=yes -o ConnectTimeout=${SSH_CONNECT_TIMEOUT} -4"
  } >> "${GITHUB_ENV}"
fi

format_ssh_hostname() {
  local host="$1"
  if [[ "${host}" == *:* && "${host}" != \[*\] ]]; then
    printf '[%s]' "${host}"
  else
    printf '%s' "${host}"
  fi
}

write_relay_ssh_config() {
  local relay_host inner_key runner_inner_key
  relay_host="$(format_ssh_hostname "${SSH_RELAY_HOST}")"
  runner_inner_key="${SSH_DIR}/c3ers_inner"

  printf '%s\n' \
    'Host believeinunity-vps' \
    "  HostName ${relay_host}" \
    "  User ${SSH_RELAY_USER}" \
    "  Port ${SSH_PORT}" \
    "  IdentityFile ${SSH_DIR}/cpanel_deploy" \
    '  IdentitiesOnly yes' \
    '  StrictHostKeyChecking accept-new' \
    '  ServerAliveInterval 15' \
    '  ServerAliveCountMax 4' \
    '  AddressFamily inet' \
    '  IPQoS throughput' \
    '  TCPKeepAlive yes' \
    > "${SSH_DIR}/config"

  printf '%s\n' \
    'Host cpanel-deploy' \
    '  HostName 127.0.0.1' \
    "  User ${DEPLOY_USER}" \
    "  Port ${SSH_PORT}" \
    "  IdentityFile ${runner_inner_key}" \
    '  IdentitiesOnly yes' \
    "  ProxyCommand ssh -F ${SSH_DIR}/config -o BatchMode=yes -o ConnectTimeout=${SSH_CONNECT_TIMEOUT} believeinunity-vps -W 127.0.0.1:${SSH_PORT}" \
    '  StrictHostKeyChecking accept-new' \
    '  ServerAliveInterval 15' \
    '  ServerAliveCountMax 4' \
    '  AddressFamily inet' \
    >> "${SSH_DIR}/config"
  chmod 600 "${SSH_DIR}/config"
}

bootstrap_c3ers_relay() {
  write_relay_ssh_config

  if ! ssh -4 -F "${SSH_DIR}/config" -o BatchMode=yes -o ConnectTimeout="${SSH_CONNECT_TIMEOUT}" \
    believeinunity-vps "echo RELAY_OK" >/dev/null 2>&1; then
    echo "::error::Cannot SSH to relay host ${SSH_RELAY_USER}@${SSH_RELAY_HOST}"
    return 1
  fi

  if ! ssh -4 -F "${SSH_DIR}/config" -o BatchMode=yes believeinunity-vps \
    "test -f ${SERVER_INNER_KEY} && ssh -i ${SERVER_INNER_KEY} -o BatchMode=yes -o StrictHostKeyChecking=no ${DEPLOY_USER}@127.0.0.1 whoami" 2>/dev/null \
    | grep -qx "${DEPLOY_USER}"; then
    echo "::error::Inner hop failed (${SERVER_INNER_KEY} -> ${DEPLOY_USER}@127.0.0.1)."
    return 1
  fi

  scp -F "${SSH_DIR}/config" -o BatchMode=yes "believeinunity-vps:${SERVER_INNER_KEY}" "${SSH_DIR}/c3ers_inner"
  chmod 600 "${SSH_DIR}/c3ers_inner"
  write_relay_ssh_config
  echo "Relay SSH ready (${SSH_RELAY_USER} -> ${DEPLOY_USER}@127.0.0.1)."
}

write_ssh_host() {
  local host="$1"
  local formatted
  formatted="$(format_ssh_hostname "${host}")"
  printf '%s\n' \
    'Host cpanel-deploy' \
    "  HostName ${formatted}" \
    "  User ${DEPLOY_USER}" \
    "  Port ${SSH_PORT}" \
    "  IdentityFile ${SSH_DIR}/cpanel_deploy" \
    '  IdentitiesOnly yes' \
    '  StrictHostKeyChecking accept-new' \
    '  ServerAliveInterval 15' \
    '  ServerAliveCountMax 4' \
    '  AddressFamily inet' \
    '  IPQoS throughput' \
    '  TCPKeepAlive yes' \
    > "${SSH_DIR}/config"
  chmod 600 "${SSH_DIR}/config"
}

tcp_probe() {
  local host="$1"
  timeout 6 bash -c "cat < /dev/null > /dev/tcp/${host}/${SSH_PORT}" 2>/dev/null
}

run_ssh_test() {
  ssh -4 -F "${SSH_DIR}/config" \
    -o BatchMode=yes \
    -o ConnectTimeout="${SSH_CONNECT_TIMEOUT}" \
    cpanel-deploy "echo SSH_OK && whoami && hostname"
}

SSH_HOSTS=("${SSH_CONNECT_HOST}")
if [ "${SSH_USE_DEPLOY_HOST}" = "true" ] && [ -n "${DEPLOY_HOST}" ]; then
  if [ "${DEPLOY_HOST}" != "${SSH_CONNECT_HOST}" ]; then
    SSH_HOSTS+=("${DEPLOY_HOST}")
  fi
fi
if [ "${SSH_ALLOW_IPV6}" = "true" ] && [ -n "${SSH_IPV6_HOST:-}" ]; then
  SSH_HOSTS+=("${SSH_IPV6_HOST}")
fi

print_firewall_help() {
  echo "::error::SSH failed after ${SSH_MAX_ATTEMPTS} attempts."
  if [ "${SSH_RELAY_TO_C3ERS}" = "true" ]; then
    echo "::error::Could not reach ${DEPLOY_USER} via ${SSH_RELAY_USER}@${SSH_RELAY_HOST} (runner ${RUNNER_IP})."
  else
    echo "::error::Could not reach ${DEPLOY_USER}@${SSH_CONNECT_HOST}:${SSH_PORT} (runner ${RUNNER_IP})."
  fi
  echo "::error::ONE-TIME fix (WHM -> Terminal as root):"
  echo "::error::  bash ${DEPLOY_PATH:-/home/believeinunity/public_html}/scripts/allow-github-actions-csf.sh"
}

connected=0
last_err=""

for attempt in $(seq 1 "${SSH_MAX_ATTEMPTS}"); do
  if [ "${SSH_RELAY_TO_C3ERS}" = "true" ]; then
    host="${SSH_RELAY_HOST}"
    if ! bootstrap_c3ers_relay; then
      last_err="relay_bootstrap_failed"
      if [ "${attempt}" -eq "${SSH_MAX_ATTEMPTS}" ] || [ "${attempt}" -eq 1 ]; then
        echo "Attempt ${attempt}/${SSH_MAX_ATTEMPTS}: relay bootstrap failed"
      fi
      sleep "${SSH_RETRY_SLEEP}"
      continue
    fi
  else
    host="${SSH_HOSTS[$(( (attempt - 1) % ${#SSH_HOSTS[@]} ))]}"
    write_ssh_host "${host}"
  fi

  probe_host="${host}"
  if [[ "${host}" == \[*\] ]]; then
    probe_host="${host#[}"
    probe_host="${probe_host%]}"
  fi

  if [ "${SSH_RELAY_TO_C3ERS}" != "true" ] && { [ "${attempt}" -eq 3 ] || [ "${attempt}" -eq 6 ]; }; then
    request_runner_allow || true
  fi

  if [ "${SSH_USE_TCP_PROBE}" = "true" ] && ! tcp_probe "${probe_host}"; then
    last_err="tcp_unreachable"
    if [ "${attempt}" -eq "${SSH_MAX_ATTEMPTS}" ] || [ "${attempt}" -eq 1 ]; then
      echo "Attempt ${attempt}/${SSH_MAX_ATTEMPTS}: port ${SSH_PORT} not reachable on ${host}"
    fi
    sleep "${SSH_RETRY_SLEEP}"
    continue
  fi

  if out="$(run_ssh_test 2>&1)"; then
    echo "${out}"
    echo "SSH connected on attempt ${attempt} (${host})"
    connected=1
    break
  fi
  last_err="${out}"
  if [ "${attempt}" -eq "${SSH_MAX_ATTEMPTS}" ] || [ "${attempt}" -eq 1 ]; then
    echo "Attempt ${attempt}/${SSH_MAX_ATTEMPTS} failed for ${host}"
    echo "${out}" | tail -8
  fi
  sleep "${SSH_RETRY_SLEEP}"
done

if [ "${connected}" -ne 1 ]; then
  if [ -n "${last_err}" ] && [ "${last_err}" != "relay_bootstrap_failed" ] && [ "${last_err}" != "tcp_unreachable" ]; then
    echo "${last_err}" | tail -8
  fi
  print_firewall_help
  exit 1
fi
