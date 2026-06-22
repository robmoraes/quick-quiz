# Deploy Local

[en-US](./README.md)

Empacotamento Docker para executar e testar o QuickQuiz Dev localmente com uma estrutura de runtime pensada para produção.

## Serviços

- `api`: API Go compilada estaticamente em imagem multi-stage, rodando sem root.
- `spa-dev`: build estático Quasar/Vue servido por Nginx sem root.
- `spa-dslab`: build estático Quasar/Vue com tema DSLab servido por Nginx sem root.
- `manager-fpm`: app Symfony manager com dependências Composer de produção, PHP-FPM, OPcache e SQLite.
- `manager-web`: Nginx leve para servir o manager via FastCGI.

## Execução Local

Copie o arquivo de ambiente de exemplo quando quiser sobrescrever portas, secrets, tags de imagem ou caminho de conteúdo:

```sh
cp deploy/compose/.env.example deploy/compose/.env
```

Suba a stack:

```sh
docker compose --env-file deploy/compose/.env -f deploy/compose/docker-compose.yml up -d --build
```

URLs locais:

- Health da API: `http://localhost:8080/healthz`
- SPA Dev: `http://localhost:8082`
- Manager: `http://localhost:8081`

Por padrão, o Compose monta `deploy/content-demo` como conteúdo local de demonstração. A API monta esse conteúdo como somente leitura em `/app/.local`; o manager monta a mesma pasta em `/content` com escrita para testes locais. Para usar outra pasta de conteúdo, ajuste `QUICKQUIZ_CONTENT_ROOT` em `deploy/compose/.env`.

O banco SQLite do manager fica, por padrão, dentro da pasta de conteúdo montada:

```text
<QUICKQUIZ_CONTENT_ROOT>/.manager/manager.sqlite
```

O manager cria `.manager/manager.sqlite` quando o repositório de admin é usado pela primeira vez, por exemplo em uma tentativa de login ou ao criar um usuário admin.

Crie um admin local do manager:

```sh
docker compose --env-file deploy/compose/.env -f deploy/compose/docker-compose.yml exec manager-fpm \
  php bin/console manager:admin:create admin@example.com 'change-this-password'
```

O container PHP-FPM roda com `QUICKQUIZ_RUNTIME_UID` e `QUICKQUIZ_RUNTIME_GID`. Ajuste esses valores se seu usuário local não for `1000:1000`.

## Repositórios de Imagem

O Makefile de deploy usa estes repositórios Docker Hub por padrão:

```text
robmoraes/quick-quiz-api
robmoraes/quick-quiz-dev
robmoraes/quick-quiz-dslab
robmoraes/quick-quiz-manager-fpm
robmoraes/quick-quiz-manager-web
```

Você pode sobrescrever cada repositório com:

```sh
API_REPOSITORY=example/api
SPA_DEV_REPOSITORY=example/spa
SPA_DSLAB_REPOSITORY=example/spa-dslab
MANAGER_FPM_REPOSITORY=example/manager-fpm
MANAGER_WEB_REPOSITORY=example/manager-web
```

## Builds de Imagens

O Makefile usa `docker buildx` e constrói imagens `linux/amd64` por padrão.

Para o fluxo opcional de publicação em nuvem usando AWS EC2, Docker Compose, Traefik e os domínios do projeto, consulte [Cloud Publishing](./CLOUD-PUBLISHING.md).

Exporte as cinco imagens como artefatos OCI em `deploy/dist`:

```sh
make -C deploy build-images \
  TAG=v0.1.0-beta \
  SPA_DEV_API_BASE_URL=https://dev.quickquiz.com.br \
  SPA_DSLAB_API_BASE_URL=https://dslab.quickquiz.com.br \
  OUTPUT=oci
```

Publique as cinco imagens com a mesma tag:

```sh
make -C deploy build-images \
  TAG=v0.1.0-beta \
  SPA_DEV_API_BASE_URL=https://dev.quickquiz.com.br \
  SPA_DSLAB_API_BASE_URL=https://dslab.quickquiz.com.br \
  OUTPUT=push
```

Quando `OUTPUT=push` é usado, o Makefile também marca e publica a mesma imagem como `latest` em cada repositório. Por exemplo, `TAG=v0.1.0-beta` publica `robmoraes/quick-quiz-api:v0.1.0-beta` e `robmoraes/quick-quiz-api:latest`.

Builds de imagem SPA exigem uma URL base de API; use `SPA_DEV_API_BASE_URL` ou
`SPA_DSLAB_API_BASE_URL` para valores específicos por app. `VITE_API_BASE_URL`
continua disponível como fallback compartilhado para builds locais pontuais. Não
há valor padrão de propósito, porque esse valor é compilado no bundle estático
do frontend.

Construa ou publique apenas uma imagem com tag individual:

```sh
make -C deploy api API_TAG=v0.1.1-beta OUTPUT=push
make -C deploy spa-dev SPA_DEV_TAG=v0.1.1-beta SPA_DEV_API_BASE_URL=https://dev.quickquiz.com.br OUTPUT=push
make -C deploy spa-dslab SPA_DSLAB_TAG=v0.1.1-beta SPA_DSLAB_API_BASE_URL=https://dslab.quickquiz.com.br OUTPUT=push
make -C deploy manager-fpm MANAGER_FPM_TAG=v0.1.1-beta OUTPUT=push
make -C deploy manager-web MANAGER_WEB_TAG=v0.1.1-beta OUTPUT=push
```

Construa as cinco imagens com tags diferentes:

```sh
make -C deploy build-images \
  API_TAG=v0.1.1-api \
  SPA_DEV_TAG=v0.1.0-dev \
  SPA_DSLAB_TAG=v0.1.0-dslab \
  MANAGER_FPM_TAG=v0.1.2-fpm \
  MANAGER_WEB_TAG=v0.1.2-web \
  SPA_DEV_API_BASE_URL=https://dev.quickquiz.com.br \
  SPA_DSLAB_API_BASE_URL=https://dslab.quickquiz.com.br \
  OUTPUT=push
```

Carregue imagens no Docker local:

```sh
make -C deploy build-images \
  SPA_DEV_API_BASE_URL=https://dev.quickquiz.com.br \
  SPA_DSLAB_API_BASE_URL=https://dslab.quickquiz.com.br \
  OUTPUT=load
```

O workflow de release do GitHub Actions publica imagens quando tags de release
suportadas são enviadas. O workflow usa o GitHub Environment `production` e lê
os repositórios Docker Hub e as URLs da API das SPAs de variáveis do
environment:

- `DOCKERHUB_API_IMAGE`
- `DOCKERHUB_SPA_DEV_IMAGE`
- `DOCKERHUB_SPA_DSLAB_IMAGE`
- `DOCKERHUB_MANAGER_FPM_IMAGE`
- `DOCKERHUB_MANAGER_WEB_IMAGE`
- `SPA_DEV_API_BASE_URL`
- `SPA_DSLAB_API_BASE_URL`
