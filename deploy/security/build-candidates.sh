#!/bin/sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
output=${1:?Usage: build-candidates.sh OUTPUT_DIRECTORY}
mkdir -p "$output"
output=$(CDPATH= cd -- "$output" && pwd)
layout=$(mktemp -d)
trap 'rm -rf "$layout"' EXIT HUP INT TERM
cd "$root"

# Only local security rehearsals build the base. CI pulls the manually published
# base through the Dockerfile default; it never builds or publishes that base.
if [ "${2:-}" = --published-base ]; then
  set --
else
  docker buildx build --platform linux/amd64,linux/arm64 \
    --file deploy/docker/php-base/Dockerfile \
    --output "type=oci,dest=$output/php-base.oci.tar" .
  tar -xf "$output/php-base.oci.tar" -C "$layout"
  set -- --build-arg PHP_BASE_IMAGE=security-base \
    --build-context "security-base=oci-layout://$layout"
fi
for target in fpm nginx; do
  case "$target" in fpm) kind=manager-fpm ;; nginx) kind=manager-web ;; esac
  docker buildx build --platform linux/amd64,linux/arm64 \
    --file deploy/docker/manager/Dockerfile --target "$target" \
    "$@" \
    --output "type=oci,dest=$output/$kind.oci.tar" .
done
