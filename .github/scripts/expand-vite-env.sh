#!/usr/bin/env bash
# Resolve VITE_* for production Vite builds (Vite does not expand ${VAR} from .env).
# Usage: expand-vite-env.sh < server.env.lines > ci.env
set -euo pipefail

declare -A vars=()
while IFS= read -r line || [[ -n "$line" ]]; do
  line="${line%%#*}"
  line="$(echo "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  [[ -z "$line" ]] && continue
  [[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]] || continue
  key="${BASH_REMATCH[1]}"
  val="${BASH_REMATCH[2]}"
  val="${val%\"}"; val="${val#\"}"
  val="${val%\'}"; val="${val#\'}"
  vars["$key"]="$val"
done

strip_quotes() {
  local v="$1"
  v="${v%\"}"; v="${v#\"}"
  v="${v%\'}"; v="${v#\'}"
  echo "$v"
}

# dotenv requires quotes when a value contains whitespace or shell-ish chars.
quote_env_value() {
  local v="$1"
  if [[ "$v" =~ [[:space:]#\"'=] ]] || [[ "$v" == *'$'* ]]; then
    v="${v//\\/\\\\}"
    v="${v//\"/\\\"}"
    printf '"%s"' "$v"
  else
    printf '%s' "$v"
  fi
}

resolve() {
  local v="$1"
  if [[ "$v" =~ \$\{([A-Za-z_][A-Za-z0-9_]*)\} ]]; then
    local ref="${BASH_REMATCH[1]}"
    if [[ -n "${vars[$ref]:-}" ]]; then
      v="${vars[$ref]}"
    fi
  fi
  strip_quotes "$v"
}

app_url="${vars[APP_URL]:-}"
app_name="${vars[APP_NAME]:-}"
reverb_key="$(strip_quotes "${vars[REVERB_APP_KEY]:-${vars[VITE_REVERB_APP_KEY]:-}}")"
reverb_host="$(resolve "${vars[VITE_REVERB_HOST]:-${vars[REVERB_HOST]:-}}")"
reverb_port="$(resolve "${vars[VITE_REVERB_PORT]:-${vars[REVERB_PORT]:-443}}")"
reverb_scheme="$(resolve "${vars[VITE_REVERB_SCHEME]:-${vars[REVERB_SCHEME]:-https}}")"

[[ -n "$app_url" ]] && echo "APP_URL=$(quote_env_value "$app_url")"
[[ -n "$app_name" ]] && echo "VITE_APP_NAME=$(quote_env_value "$app_name")"
[[ -n "${vars[APP_VERSION]:-}" ]] && echo "VITE_APP_VERSION=$(quote_env_value "${vars[APP_VERSION]}")"

if [[ -z "$reverb_key" ]]; then
  echo "::error::REVERB_APP_KEY missing — cannot build Echo/Reverb client" >&2
  exit 1
fi

echo "VITE_REVERB_APP_KEY=$(quote_env_value "$reverb_key")"
echo "VITE_REVERB_HOST=$(quote_env_value "$reverb_host")"
echo "VITE_REVERB_PORT=$(quote_env_value "$reverb_port")"
echo "VITE_REVERB_SCHEME=$(quote_env_value "$reverb_scheme")"

for key in VITE_YOUTUBE_API_KEY VITE_GOOGLE_API_KEY VITE_GOOGLE_MAPS_API_KEY VITE_STRIPE_PUBLIC_KEY VITE_MERCHANT_DOMAIN VITE_LIVESTOCK_DOMAIN; do
  [[ -n "${vars[$key]:-}" ]] && echo "$key=$(quote_env_value "$(resolve "${vars[$key]}")")"
done
