# systemd Distribution

This directory contains the server distribution resources for running the
QuickQuiz Dev API as a `systemd` service behind Nginx.

## Files

- `quickquiz-api.service`: `systemd` unit for the API.
- `quickquiz-api.env.example`: environment template installed as
  `/etc/quickquiz-api.env`.
- `nginx-quickquiz-api.conf`: Nginx reverse proxy site template.
- `install-service.sh`: installation script that installs the binary, enables
  the service, and optionally configures Nginx.

## Build the Binary

Run from the apps/api directory:

```sh
mkdir -p bin
go build -o bin/quickquiz-api ./cmd/api
```

## Install and Launch the Service

Copy the API directory or at least `deploy/systemd` and `bin/quickquiz-api`
to the server. Then run:

```sh
sudo deploy/systemd/install-service.sh \
  --binary bin/quickquiz-api \
  --domain api.example.com
```

The script:

- creates the `quickquiz` system user and group when missing;
- installs the binary at `/opt/quickquiz/api/quickquiz-api`;
- creates `/opt/quickquiz/api/.local`;
- installs `/etc/quickquiz-api.env` from the example if it does not exist;
- installs and starts `quickquiz-api.service`;
- installs the Nginx site and reloads Nginx when Nginx is available.

To launch the service without configuring Nginx:

```sh
sudo deploy/systemd/install-service.sh \
  --binary bin/quickquiz-api \
  --no-nginx
```

## Local Question Content

When using `QUESTION_STORAGE_PROVIDER=local`, copy question content to:

```text
/opt/quickquiz/api/.local/themes.json
/opt/quickquiz/api/.local/<theme>/index.json
/opt/quickquiz/api/.local/<theme>/en-US/index.json
/opt/quickquiz/api/.local/<theme>/en-US/<topic>/<difficulty>/<question-id>.json
```

## Service Operations

Check status:

```sh
sudo systemctl status quickquiz-api
```

Follow logs:

```sh
journalctl -u quickquiz-api -f
```

Restart after changing environment or content:

```sh
sudo systemctl restart quickquiz-api
```

Validate Nginx:

```sh
sudo nginx -t
sudo systemctl reload nginx
```

## TLS

The Nginx template listens on port `80`. For production, configure TLS with your
certificate provider, for example:

```sh
sudo certbot --nginx -d api.example.com
```
