#!/bin/sh
set -eu

json_escape() {
  sed 's/\\/\\\\/g; s/"/\\"/g'
}

api_base_url=${SPA_API_BASE_URL:-http://localhost:8080}
ads_api_base_url=${SPA_ADS_API_BASE_URL:-$api_base_url}

{
  printf 'window.__QUICKQUIZ_CONFIG__ = {\n'
  printf '  apiBaseUrl: "%s",\n' "$(printf '%s' "$api_base_url" | json_escape)"
  printf '  adsApiBaseUrl: "%s"\n' "$(printf '%s' "$ads_api_base_url" | json_escape)"
  printf '};\n'
} > /tmp/runtime-config.js

exec nginx -g 'daemon off;'
