#!/bin/bash
set -eux

dnf update -y

dnf install -y docker git

systemctl enable docker
systemctl start docker

usermod -aG docker ec2-user

mkdir -p /usr/local/lib/docker/cli-plugins

case "$(uname -m)" in
  x86_64) compose_arch="x86_64" ;;
  aarch64) compose_arch="aarch64" ;;
  *) echo "unsupported architecture: $(uname -m)" >&2; exit 1 ;;
esac

curl -SL \
  "https://github.com/docker/compose/releases/download/v2.39.4/docker-compose-linux-${compose_arch}" \
  -o /usr/local/lib/docker/cli-plugins/docker-compose

chmod +x /usr/local/lib/docker/cli-plugins/docker-compose

mkdir -p /opt/quickquiz/compose
mkdir -p /opt/quickquiz/traefik/letsencrypt

touch /opt/quickquiz/traefik/letsencrypt/acme.json
chmod 600 /opt/quickquiz/traefik/letsencrypt/acme.json

chown -R ec2-user:ec2-user /opt/quickquiz

docker --version
docker compose version
