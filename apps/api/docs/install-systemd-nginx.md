# Install on a Server with systemd and Nginx

This guide installs the QuickQuiz Dev API directly on a Linux server, runs it
as a `systemd` service, and exposes it through Nginx as a reverse proxy.

Reusable distribution resources are available in
`apps/api/deploy/systemd`.

## Assumptions

- The server runs a recent Linux distribution with `systemd`.
- Nginx is installed and can listen on ports `80` and `443`.
- The API will run as user `quickquiz`.
- The application directory is `/opt/quickquiz/api`.
- The API listens locally on `127.0.0.1:8080`.
- Replace `api.example.com` with the real API domain.

## Build the Binary

Build the API on a machine with Go installed:

```sh
cd apps/api
mkdir -p bin
go build -o bin/quickquiz-api ./cmd/api
```

Copy the binary to the server:

```sh
ssh root@api.example.com 'mkdir -p /opt/quickquiz/api'
scp bin/quickquiz-api root@api.example.com:/opt/quickquiz/api/
```

## Prepare the Server

Create the service user and application directories:

```sh
sudo useradd --system --home /opt/quickquiz --shell /usr/sbin/nologin quickquiz
sudo mkdir -p /opt/quickquiz/api/.local
sudo chown -R quickquiz:quickquiz /opt/quickquiz
sudo chmod 0755 /opt/quickquiz /opt/quickquiz/api
```

Copy local question content to `/opt/quickquiz/api/.local` when using the
local storage provider. The expected layout is:

```text
/opt/quickquiz/api/.local/themes.json
/opt/quickquiz/api/.local/<theme>/index.json
/opt/quickquiz/api/.local/<theme>/en-US/index.json
/opt/quickquiz/api/.local/<theme>/en-US/<topic>/<difficulty>/<question-id>.json
```

## Configure Environment

Create `/etc/quickquiz-api.env`:

```sh
sudo tee /etc/quickquiz-api.env >/dev/null <<'EOF'
HTTP_ADDR=127.0.0.1:8080
QUESTION_STORAGE_PROVIDER=local
QUESTION_SOURCE=/opt/quickquiz/api/.local
FALLBACK_LOCALE=en-US
SUPPORTED_LOCALES=en-US,pt-BR
RUN_QUESTION_LIMIT=10
SESSION_TTL=30m
SHUTDOWN_TIMEOUT=10s
EOF
sudo chmod 0640 /etc/quickquiz-api.env
sudo chown root:quickquiz /etc/quickquiz-api.env
```

If question content is loaded from S3, set `QUESTION_STORAGE_PROVIDER=s3` and add
the required AWS and S3 variables documented in `apps/api/README.md`.

## Create the systemd Service

The ready-to-use unit file is available at
`apps/api/deploy/systemd/quickquiz-api.service`.

Create `/etc/systemd/system/quickquiz-api.service`:

```ini
[Unit]
Description=QuickQuiz Dev API
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=quickquiz
Group=quickquiz
WorkingDirectory=/opt/quickquiz/api
EnvironmentFile=/etc/quickquiz-api.env
ExecStart=/opt/quickquiz/api/quickquiz-api
Restart=on-failure
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ProtectHome=true
ReadWritePaths=/opt/quickquiz/api

[Install]
WantedBy=multi-user.target
```

Enable and start the service:

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now quickquiz-api
sudo systemctl status quickquiz-api
```

Check logs:

```sh
journalctl -u quickquiz-api -f
```

## Configure Nginx

The ready-to-use Nginx site template is available at
`apps/api/deploy/systemd/nginx-quickquiz-api.conf`.

Create `/etc/nginx/sites-available/quickquiz-api`:

```nginx
server {
    listen 80;
    server_name api.example.com;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Enable the site and reload Nginx:

```sh
sudo ln -s /etc/nginx/sites-available/quickquiz-api /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

For production, configure TLS with your certificate provider, for example
Certbot:

```sh
sudo certbot --nginx -d api.example.com
```

## Verify

Call the health endpoint:

```sh
curl -fsS http://api.example.com/healthz
```

Expected response:

```json
{"status":"ok"}
```

## Upgrade

Build and copy a new binary, then restart the service:

```sh
sudo install -o quickquiz -g quickquiz -m 0755 quickquiz-api /opt/quickquiz/api/quickquiz-api
sudo systemctl restart quickquiz-api
sudo systemctl status quickquiz-api
```

## Scripted Install

The distribution package also includes a script that installs the binary,
enables the service, and configures Nginx:

```sh
sudo deploy/systemd/install-service.sh \
  --binary bin/quickquiz-api \
  --domain api.example.com
```
