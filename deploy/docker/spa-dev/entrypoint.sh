#!/bin/sh
set -eu

invalid_tag() {
  echo 'SPA_THEME_TAG must be one tag of at most 50 ASCII characters.' >&2
  exit 1
}

raw_tag=${SPA_THEME_TAG-dev}
[ "${#raw_tag}" -le 50 ] || invalid_tag
[ -z "$(printf '%s' "$raw_tag" | LC_ALL=C tr -d '[:space:]A-Za-z0-9-')" ] || invalid_tag
theme_tag=$(printf '%s' "$raw_tag" | LC_ALL=C awk 'BEGIN { RS="\034"; ORS="" } { gsub(/^[[:space:]]+|[[:space:]]+$/, ""); print tolower($0) }')
case "$theme_tag" in
  ''|*[!a-z0-9-]*|-*|*-|*--*) invalid_tag ;;
esac

json_escape() {
  sed 's/\\/\\\\/g; s/"/\\"/g'
}

api_base_url=${SPA_API_BASE_URL:-http://localhost:8080}
ads_api_base_url=${SPA_ADS_API_BASE_URL:-$api_base_url}
case "$api_base_url$ads_api_base_url" in
  *[[:cntrl:]]*) echo 'SPA API URLs must not contain control characters.' >&2; exit 1 ;;
esac

{
  printf 'window.__QUICKQUIZ_CONFIG__ = {\n'
  printf '  apiBaseUrl: "%s",\n' "$(printf '%s' "$api_base_url" | json_escape)"
  printf '  adsApiBaseUrl: "%s",\n' "$(printf '%s' "$ads_api_base_url" | json_escape)"
  printf '  themeTag: "%s"\n' "$theme_tag"
  printf '};\n'
} > /tmp/runtime-config.js

exec nginx -g 'daemon off;'
