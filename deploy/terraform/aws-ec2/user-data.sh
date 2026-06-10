#!/bin/bash
set -eux

dnf update -y

dnf install -y docker git

systemctl enable docker
systemctl start docker

usermod -aG docker ec2-user

mkdir -p /usr/local/lib/docker/cli-plugins

curl -SL \
  https://github.com/docker/compose/releases/download/v2.39.4/docker-compose-linux-x86_64 \
  -o /usr/local/lib/docker/cli-plugins/docker-compose

chmod +x /usr/local/lib/docker/cli-plugins/docker-compose

mkdir -p /opt/quickquiz/compose
mkdir -p /opt/quickquiz/traefik/letsencrypt

touch /opt/quickquiz/traefik/letsencrypt/acme.json
chmod 600 /opt/quickquiz/traefik/letsencrypt/acme.json

chown -R ec2-user:ec2-user /opt/quickquiz

docker --version
docker compose version
