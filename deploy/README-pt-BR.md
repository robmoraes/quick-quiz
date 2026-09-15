# Deploy Local

[en-US](./README.md)

Empacotamento Docker para executar e testar o QuickQuiz Dev localmente com uma estrutura de runtime pensada para produção.

## Serviços

- `api`: API Go compilada estaticamente em imagem multi-stage, rodando sem root.
- `redis`: armazenamento efêmero de runs/sessões e cache de soluções geradas, limitado a 256 MiB de dados e 384 MiB de memória do container.
- `manager-db`: PostgreSQL para administradores e prompts de IA do Manager.
- `ads-api`: API Go de publicidade para entrega e gerenciamento de anúncios.
- `spa-dev`: build estático Quasar/Vue servido por Nginx sem root.
- `spa-dslab`: build estático Quasar/Vue com tema DSLab servido por Nginx sem root.
- `manager-fpm`: app Symfony manager com dependências Composer de produção, PHP-FPM, OPcache e PDO PostgreSQL.
- `manager-web`: Nginx leve para servir o manager via FastCGI.

## Execução Local

Copie o arquivo de ambiente de exemplo quando quiser sobrescrever portas, secrets, tags de imagem ou caminho de conteúdo:

```sh
cp deploy/compose.local/.env-example deploy/compose.local/.env
```

Suba a stack:

```sh
docker compose --env-file deploy/compose.local/.env -f deploy/compose.local/docker-compose.yml up -d --build
```

URLs locais:

- Health da API: `http://localhost:8080/healthz`
- SPA Dev: `http://localhost:8082`
- Manager: `http://localhost:8081`

O Compose local inicia o Redis junto com a stack e configura os runs da API, as soluções geradas e as sessões do Manager para usá-lo pela rede interna do Docker. O Redis não é instalado no host, não expõe porta no host e não possui volume persistente; reiniciá-lo invalida os runs de quiz ativos e as sessões de login do Manager, enquanto as soluções são recriadas sob demanda. A imagem oficial oferece suporte a `linux/amd64` e `linux/arm64`. A API não grava estado de runtime no próprio sistema de arquivos.

Por padrão, o Compose monta `deploy/content-demo` como conteúdo local de demonstração. A API monta esse conteúdo como somente leitura em `/app/.local`; o Manager monta a mesma pasta em `/content` com escrita para testes locais. Para usar outra pasta de conteúdo, ajuste `QUICKQUIZ_CONTENT_ROOT` em `deploy/compose.local/.env`. Para testar armazenamento S3 compatível compartilhado, defina `QUESTION_STORAGE_PROVIDER=s3`, `ADS_STORAGE_PROVIDER=s3` e `MANAGER_CONTENT_STORAGE_PROVIDER=s3`, depois configure os mesmos valores de `AWS_REGION`, `S3_BUCKET`, `S3_PREFIX`, `S3_ENDPOINT_URL` e `S3_FORCE_PATH_STYLE`.

O Manager guarda administradores e prompts de IA no PostgreSQL. O banco local
fica disponível somente na rede interna do Docker e persiste no volume
`manager-db-data`. Configure-o com `MANAGER_DATABASE_URL` e as variáveis
`MANAGER_DB_*`.

Crie um admin local do manager:

```sh
docker compose --env-file deploy/compose.local/.env -f deploy/compose.local/docker-compose.yml exec manager-fpm \
  php bin/console manager:admin:create admin@example.com 'change-this-password'
```

O container PHP-FPM roda com `QUICKQUIZ_RUNTIME_UID` e `QUICKQUIZ_RUNTIME_GID`. Ajuste esses valores se seu usuário local não for `1000:1000`.

## Repositórios de Imagem

O Makefile de deploy usa estes repositórios Docker Hub por padrão:

```text
robmoraes/quick-quiz-api
robmoraes/quick-quiz-ads-api
robmoraes/quick-quiz-dev
robmoraes/quick-quiz-dslab
robmoraes/quick-quiz-manager-fpm
robmoraes/quick-quiz-manager-web
```

Você pode sobrescrever cada repositório com:

```sh
API_REPOSITORY=example/api
ADS_API_REPOSITORY=example/ads-api
SPA_DEV_REPOSITORY=example/spa
SPA_DSLAB_REPOSITORY=example/spa-dslab
MANAGER_FPM_REPOSITORY=example/manager-fpm
MANAGER_WEB_REPOSITORY=example/manager-web
```

## Builds de Imagens

O Makefile usa `docker buildx` e constrói imagens `linux/amd64` por padrão.

Para o fluxo opcional de publicação em nuvem usando AWS EC2, Docker Compose, Traefik e os domínios do projeto, consulte [Cloud Publishing](./CLOUD-PUBLISHING.md).

Exporte as seis imagens como artefatos OCI em `deploy/dist`:

```sh
make -C deploy build-images \
  TAG=v0.1.0-beta \
  OUTPUT=oci
```

Publique as seis imagens com a mesma tag:

```sh
make -C deploy build-images \
  TAG=v0.1.0-beta \
  OUTPUT=push
```

Quando `OUTPUT=push` é usado, o Makefile também marca e publica a mesma imagem como `latest` em cada repositório. Por exemplo, `TAG=v0.1.0-beta` publica `robmoraes/quick-quiz-api:v0.1.0-beta` e `robmoraes/quick-quiz-api:latest`.

As imagens das SPAs são independentes do ambiente. Na inicialização do
contêiner, o Compose mapeia os valores `SPA_*_API_BASE_URL` para
`SPA_API_BASE_URL` e `SPA_ADS_API_BASE_URL`; a troca de endpoint exige apenas a
recriação do contêiner.

Construa ou publique apenas uma imagem com tag individual:

```sh
make -C deploy api API_TAG=v0.1.1-beta OUTPUT=push
make -C deploy ads-api ADS_API_TAG=v0.1.1-beta OUTPUT=push
make -C deploy spa-dev SPA_DEV_TAG=v0.1.1-beta OUTPUT=push
make -C deploy spa-dslab SPA_DSLAB_TAG=v0.1.1-beta OUTPUT=push
make -C deploy manager-fpm MANAGER_FPM_TAG=v0.1.1-beta OUTPUT=push
make -C deploy manager-web MANAGER_WEB_TAG=v0.1.1-beta OUTPUT=push
```

Construa as seis imagens com tags diferentes:

```sh
make -C deploy build-images \
  API_TAG=v0.1.1-api \
  ADS_API_TAG=v0.1.1-ads-api \
  SPA_DEV_TAG=v0.1.0-dev \
  SPA_DSLAB_TAG=v0.1.0-dslab \
  MANAGER_FPM_TAG=v0.1.2-fpm \
  MANAGER_WEB_TAG=v0.1.2-web \
  OUTPUT=push
```

Carregue imagens no Docker local:

```sh
make -C deploy build-images \
  OUTPUT=load
```

O workflow de release do GitHub Actions publica imagens quando tags de release
suportadas são enviadas. O workflow usa o GitHub Environment `production` e lê os repositórios Docker
Hub das variáveis do environment:

- `DOCKERHUB_API_IMAGE`
- `DOCKERHUB_ADS_API_IMAGE`
- `DOCKERHUB_SPA_DEV_IMAGE`
- `DOCKERHUB_SPA_DSLAB_IMAGE`
- `DOCKERHUB_MANAGER_FPM_IMAGE`
- `DOCKERHUB_MANAGER_WEB_IMAGE`
