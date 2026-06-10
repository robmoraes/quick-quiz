#!/usr/bin/env sh
set -eu

SERVICE_NAME="quickquiz-api"
SERVICE_USER="quickquiz"
SERVICE_GROUP="quickquiz"
APP_DIR="/opt/quickquiz/api"
ENV_FILE="/etc/quickquiz-api.env"
SYSTEMD_UNIT="/etc/systemd/system/${SERVICE_NAME}.service"
NGINX_AVAILABLE="/etc/nginx/sites-available/${SERVICE_NAME}"
NGINX_ENABLED="/etc/nginx/sites-enabled/${SERVICE_NAME}"
DOMAIN="api.example.com"
BINARY_SOURCE=""
ENABLE_NGINX="true"

usage() {
    cat <<'EOF'
Usage:
  install-service.sh --binary /path/to/quickquiz-api --domain api.example.com [options]

Options:
  --binary PATH       Built quickquiz-api binary to install.
  --domain DOMAIN    Domain used in the Nginx server_name directive.
  --no-nginx         Install and start the systemd service without Nginx config.
  --help             Show this help.
EOF
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --binary)
            BINARY_SOURCE="${2:-}"
            shift 2
            ;;
        --domain)
            DOMAIN="${2:-}"
            shift 2
            ;;
        --no-nginx)
            ENABLE_NGINX="false"
            shift
            ;;
        --help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown argument: $1" >&2
            usage >&2
            exit 2
            ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this script as root." >&2
    exit 1
fi

if [ -z "$BINARY_SOURCE" ]; then
    echo "Missing --binary PATH." >&2
    usage >&2
    exit 2
fi

if [ ! -f "$BINARY_SOURCE" ]; then
    echo "Binary not found: $BINARY_SOURCE" >&2
    exit 1
fi

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"

if ! getent group "$SERVICE_GROUP" >/dev/null; then
    groupadd --system "$SERVICE_GROUP"
fi

if ! id "$SERVICE_USER" >/dev/null 2>&1; then
    useradd --system --gid "$SERVICE_GROUP" --home /opt/quickquiz --shell /usr/sbin/nologin "$SERVICE_USER"
fi

install -d -o "$SERVICE_USER" -g "$SERVICE_GROUP" -m 0755 "$APP_DIR"
install -d -o "$SERVICE_USER" -g "$SERVICE_GROUP" -m 0755 "$APP_DIR/.local"
install -o "$SERVICE_USER" -g "$SERVICE_GROUP" -m 0755 "$BINARY_SOURCE" "$APP_DIR/quickquiz-api"

if [ ! -f "$ENV_FILE" ]; then
    install -o root -g "$SERVICE_GROUP" -m 0640 "$SCRIPT_DIR/quickquiz-api.env.example" "$ENV_FILE"
fi

install -o root -g root -m 0644 "$SCRIPT_DIR/quickquiz-api.service" "$SYSTEMD_UNIT"

systemctl daemon-reload
systemctl enable --now "$SERVICE_NAME"

if [ "$ENABLE_NGINX" = "true" ]; then
    if ! command -v nginx >/dev/null 2>&1; then
        echo "Nginx is not installed; skipping Nginx configuration." >&2
    else
        sed "s/api.example.com/${DOMAIN}/g" "$SCRIPT_DIR/nginx-quickquiz-api.conf" >"$NGINX_AVAILABLE"
        ln -sfn "$NGINX_AVAILABLE" "$NGINX_ENABLED"
        nginx -t
        systemctl reload nginx
    fi
fi

systemctl status "$SERVICE_NAME" --no-pager
