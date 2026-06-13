# Feature: Manager de conteudo do QuickQuiz Dev

## Intent

Problem: QuickQuiz Dev usa arquivos JSON como fonte de conteudo dos quizzes.
Editar esses arquivos manualmente aumenta o risco de formato invalido,
divergencia entre locales, IDs duplicados e conteudo publicado por engano.

Users or stakeholders: administrador de conteudo do QuickQuiz Dev.

Desired outcome: disponibilizar uma aplicacao web administrativa para manter o
catalogo de linguagens e as perguntas do quiz, gerando arquivos compativeis com
o backend atual e consolidando JSON como formato canonico dos pacotes de quiz.

Non-goals:

- substituir a API publica do QuickQuiz Dev;
- alterar o contrato HTTP do quiz MVP;
- migrar pacotes de quiz para banco de dados;
- ensinar respostas, gerar explicacoes ou exibir gabarito para jogadores;
- implementar ranking, cadastro publico ou area administrativa no frontend do
  jogador;
- publicar conteudo diretamente em producao sem revisao explicita.

## Scope

In scope:

- criar um webapp manager separado do backend Go e do frontend Quasar do jogador;
- usar PHP com Symfony Framework para o manager;
- usar SQLite para autenticacao e estado administrativo necessario ao manager;
- usar Docker e Docker Compose para configurar o ambiente de desenvolvimento do
  manager;
- autenticar administradores de conteudo;
- criar o primeiro administrador por comando CLI do Symfony;
- listar, criar, editar, validar e remover arquivos JSON de perguntas;
- listar, criar, editar e validar metadados de linguagens do catalogo;
- administrar conteudo por locale BCP 47, como `en-US` e `pt-BR`;
- manter a compatibilidade com a estrutura local
  `backend/.local/<locale>/<language>/<difficulty>/<question-id>.json`;
- preparar variaveis de ambiente para integracao futura com OpenAI, sem
  implementar geracao de conteudo nesta spec.

Out of scope:

- persistir o banco de perguntas em banco relacional;
- planejar migracao dos pacotes de quiz para banco de dados;
- implementar upload ou sincronizacao com S3;
- preparar pacote de publicacao para S3;
- implementar geracao, revisao ou traducao automatica com OpenAI;
- expor APIs publicas para consumidores externos;
- exigir PHP, Composer, Symfony CLI ou extensoes PHP instalados diretamente no
  host para desenvolvimento;
- alterar regras de run, selecao de opcoes ou validacao de respostas no backend
  Go;
- criar fluxo de aprovacao multiusuario complexo;
- implementar auditoria de alteracoes de perguntas nesta entrega.

Assumptions:

- o manager roda em ambiente administrativo restrito;
- o conteudo canonico continua sendo o conjunto de arquivos JSON consumido pelo
  backend;
- JSON e o formato canonico dos pacotes de quiz por tempo indeterminado;
- uma migracao dos pacotes de quiz para banco de dados so deve ser considerada
  se uma limitacao futura concreta do formato JSON justificar a mudanca;
- `FALLBACK_LOCALE` e `SUPPORTED_LOCALES` definem os locales que o manager deve
  conhecer;
- o fallback locale padrao e `en-US`;
- o manager pode acessar o diretorio de conteudo local configurado para o
  projeto;
- o manager edita apenas conteudo local nesta entrega;
- o catalogo usa `backend/.local/index.json` como fonte central e
  `backend/.local/<locale>/index.json` como overrides localizados;
- Docker e Docker Compose sao os unicos pre-requisitos esperados no host para
  desenvolver o manager;
- a versao do PHP, extensoes PHP e dependencias do Symfony devem ser controladas
  pelos arquivos Docker e Compose do projeto;
- SQLite e suficiente para usuarios, sessoes e preferencias administrativas do
  MVP do manager.

Dependencies:

- Symfony Framework;
- PHP runtime compativel com a versao Symfony escolhida no plano, executado em
  container;
- SQLite;
- Docker;
- Docker Compose;
- estrutura de conteudo local do QuickQuiz Dev;
- contratos de conteudo definidos nas specs MVP de question data model e quiz
  pack JSONs.

## Behavior

1. O manager deve exigir login antes de permitir acesso as telas
   administrativas.
2. O manager deve fornecer um comando CLI do Symfony para criar o primeiro
   administrador.
3. O manager deve permitir que um administrador autenticado visualize os locales
   suportados e o fallback locale.
4. O manager deve permitir que um administrador visualize linguagens publicadas e
   nao publicadas.
5. O manager deve permitir criar e editar metadados de linguagem, incluindo
   chave, nome, descricao, peso, data de criacao e estado `active`.
6. O manager deve manter `active`, peso e data de criacao na fonte central
   `backend/.local/index.json`.
7. O manager deve manter nomes e descricoes localizados em
   `backend/.local/<locale>/index.json`.
8. O manager deve carregar apenas linguagens com `active: true` como publicadas
   para fins de validacao de perguntas jogaveis.
9. O manager deve permitir navegar por locale, linguagem e dificuldade numerica.
10. O manager deve permitir criar, editar e remover perguntas no formato
   `<language>/<difficulty>/<question-id>.json`.
11. O manager deve preservar JSON como artefato canonico de colaboracao e
    publicacao dos pacotes de quiz.
12. O manager deve derivar `locale`, `language`, `difficulty` e `question-id` do
   caminho do arquivo, nao do corpo JSON.
13. O manager deve salvar arquivos de pergunta contendo somente `prompt`,
   `correctOptions` e `wrongOptions`.
14. O manager deve rejeitar perguntas sem `prompt`, sem opcoes corretas ou sem
    opcoes erradas.
15. O manager deve rejeitar perguntas com IDs duplicados dentro do mesmo
    conjunto carregado.
16. O manager deve impedir que locales nao fallback adicionem ou removam
    pacotes de pergunta exclusivos.
17. O manager deve validar que cada locale suportado replica os mesmos caminhos
    `<language>/<difficulty>/<question-id>.json` do fallback locale para
    linguagens ativas.
18. O manager deve permitir editar a traducao do `prompt`, `correctOptions` e
    `wrongOptions` de um locale sem alterar o ID canonico da pergunta.
19. O manager deve avisar quando uma linguagem ativa nao possui perguntas em uma
    dificuldade esperada.
20. O manager deve impedir salvar conteudo que o backend Go rejeitaria ao
    carregar a fonte de perguntas.
21. O manager deve registrar erros de validacao com mensagens compreensiveis
    para o administrador.
22. O manager deve manter codigos, IDs, chaves de linguagem, dificuldade e
    locale como valores de maquina, sem traduzi-los.
23. O ambiente de desenvolvimento do manager deve ser iniciado por Docker
    Compose.
24. O ambiente de desenvolvimento do manager deve permitir executar comandos do
    Symfony, Composer e testes dentro de containers.
25. O projeto deve fixar a versao de PHP e extensoes usadas pelo manager nos
    arquivos Docker.
26. O manager deve incluir variaveis de ambiente no `.env-example` para futura
    integracao OpenAI, mantendo-as opcionais e sem uso funcional nesta entrega.

## Acceptance Examples

### Scenario: criar pergunta valida no fallback locale

Given o administrador esta autenticado

And `FALLBACK_LOCALE` e `en-US`

And a linguagem `php` esta ativa

When o administrador cria a pergunta `php/1/php-1-001.json` em `en-US`

And informa `prompt`, pelo menos uma `correctOptions` e pelo menos uma
`wrongOptions`

Then o manager salva o arquivo em
`backend/.local/en-US/php/1/php-1-001.json`

And o corpo JSON contem somente `prompt`, `correctOptions` e `wrongOptions`

### Scenario: criar primeiro administrador por CLI

Given o banco SQLite do manager esta inicializado

And nao existe administrador cadastrado

When um operador executa o comando CLI do Symfony para criar administrador

Then o manager cria um usuario administrativo

And a senha e armazenada com hash seguro

And o administrador consegue fazer login no manager

### Scenario: iniciar ambiente dev em containers

Given o host possui Docker e Docker Compose instalados

And nao possui PHP, Composer ou Symfony CLI instalados localmente

When o desenvolvedor inicia o ambiente dev do manager com Docker Compose

Then o manager fica disponivel para desenvolvimento local

And comandos Symfony, Composer e testes podem ser executados dentro dos
containers

And a versao do PHP usada e a definida pelos arquivos Docker do projeto

### Scenario: bloquear pergunta exclusiva em locale traduzido

Given o fallback locale `en-US` nao possui `go/2/go-2-999.json`

And o locale `pt-BR` e suportado

When o administrador tenta criar `backend/.local/pt-BR/go/2/go-2-999.json`

Then o manager rejeita a operacao

And informa que locales traduzidos devem replicar os pacotes canonicos do
fallback locale

### Scenario: editar traducao sem alterar ID canonico

Given existe `backend/.local/en-US/php/1/php-1-001.json`

And existe `backend/.local/pt-BR/php/1/php-1-001.json`

When o administrador edita o `prompt` da pergunta em `pt-BR`

Then o manager atualiza apenas o arquivo do locale `pt-BR`

And mantem o caminho `php/1/php-1-001.json`

And nao altera linguagem, dificuldade ou ID da pergunta

### Scenario: bloquear conteudo invalido

Given o administrador esta editando uma pergunta

When o administrador remove todas as opcoes corretas

Then o manager impede o salvamento

And informa que `correctOptions` deve conter ao menos uma opcao

### Scenario: despublicar linguagem

Given a linguagem `xgh` existe no catalogo

When o administrador altera `active` para `false`

Then o manager mantem os arquivos da linguagem no conteudo local

And marca a linguagem como nao publicada

And nao considera suas perguntas como jogaveis nas validacoes de publicacao

## Data and Contracts

Inputs:

- diretorio de conteudo local configurado para o manager;
- `FALLBACK_LOCALE`;
- `SUPPORTED_LOCALES`;
- arquivos `index.json` do catalogo;
- arquivos de pergunta em
  `<locale>/<language>/<difficulty>/<question-id>.json`;
- credenciais de administrador mantidas em SQLite.

Outputs:

- arquivos JSON de catalogo e perguntas compativeis com o backend Go;
- pacotes de quiz em JSON que podem ser revisados e colaborados fora do manager;
- mensagens de validacao para o administrador;
- registros administrativos em SQLite para login e sessoes;
- arquivos Docker e Compose para ambiente dev do manager;
- `.env-example` com configuracoes do manager e variaveis opcionais para OpenAI.

Question file contract:

```json
{
  "prompt": "Question displayed to the player",
  "correctOptions": ["Correct option"],
  "wrongOptions": ["Wrong option"]
}
```

Path contract:

```text
backend/.local/<locale>/<language>/<difficulty>/<question-id>.json
```

Catalog contract:

- `backend/.local/index.json` e a fonte central do catalogo;
- `backend/.local/<locale>/index.json` contem overrides localizados;
- entradas de linguagem devem ter chave estavel em `key`;
- `active: true` publica a linguagem;
- `active: false` mantem o pacote presente, mas nao publicado;
- `active`, `weight` e `created_at` devem vir da fonte central;
- `name` e `description` podem vir de overrides localizados;
- codigos de locale devem usar BCP 47.

API, schema, event, or CLI changes:

- nenhuma mudanca na API publica do quiz MVP;
- APIs internas do manager podem ser definidas no plano tecnico.

Persistence changes:

- SQLite deve armazenar apenas usuarios, sessoes e estado administrativo do
  manager;
- perguntas e metadados de quiz continuam persistidos como arquivos JSON.
- nao ha objetivo atual de migrar perguntas ou metadados de quiz para banco de
  dados.

Machine-readable contract:

- o contrato principal e a estrutura de arquivos JSON consumida pelo backend;
- JSON e o formato canonico de pacote de quiz para colaboracao, validacao e
  publicacao;
- OpenAPI do quiz MVP nao deve mudar por causa desta spec.

## Quality Attributes

Security:

- o manager deve exigir autenticacao;
- sessoes administrativas devem expirar;
- formularios que alteram conteudo devem ter protecao contra CSRF;
- senhas de administradores devem ser armazenadas com hash seguro;
- segredos e chaves OpenAI nao devem ser gravados em arquivos de conteudo.

Privacy:

- o manager nao deve coletar dados pessoais de jogadores;
- dados de administradores devem se limitar ao necessario para login.

Accessibility:

- telas administrativas devem ser navegaveis por teclado;
- campos obrigatorios e erros de validacao devem ser identificaveis sem depender
  apenas de cor.

Performance:

- listagens devem ser usaveis com centenas de perguntas por linguagem;
- validacoes de conteudo devem executar antes do salvamento e sem depender do
  backend Go estar em execucao.

Reliability:

- salvamentos devem evitar arquivos parcialmente escritos;
- o manager deve preservar arquivos existentes quando uma validacao falhar;
- erros de leitura ou escrita no diretorio de conteudo devem ser exibidos ao
  administrador.

Observability:

- login, logout, falhas de autenticacao, falhas de validacao e falhas de escrita
  devem gerar log administrativo;
- logs nao devem incluir respostas completas, senhas ou chaves secretas.

## Rollout and Operations

Migration:

- nenhuma migracao de perguntas e obrigatoria para esta spec;
- migracao dos pacotes de quiz para banco de dados nao esta planejada;
- o manager deve operar sobre a estrutura de arquivos ja esperada pelo backend.

Feature flag or configuration:

- `MANAGER_HTTP_ADDR`;
- `MANAGER_DATABASE_URL`;
- `MANAGER_CONTENT_ROOT`;
- `MANAGER_DOCKER_PHP_VERSION`;
- `FALLBACK_LOCALE`;
- `SUPPORTED_LOCALES`;
- `OPENAI_API_KEY`;
- `OPENAI_MODEL`;
- `OPENAI_PROJECT`;
- `OPENAI_ORG_ID`.

Rollback:

- desativar o processo do manager;
- manter o backend Go usando os arquivos JSON existentes;
- restaurar arquivos de conteudo a partir de backup ou controle externo quando
  necessario.

Monitoring:

- falhas de login;
- falhas de validacao;
- falhas de leitura/escrita no diretorio de conteudo;
- quantidade de perguntas por locale, linguagem e dificuldade.

## Verification

Planned checks:

- testes unitarios para validacao de paths e corpo JSON;
- teste do comando CLI de criacao do primeiro administrador;
- teste de inicializacao do ambiente dev por Docker Compose;
- testes de integracao para login e protecao de rotas administrativas;
- testes de integracao para criar, editar e remover perguntas em diretorio
  temporario;
- teste de validacao de paridade entre fallback locale e locales traduzidos;
- teste manual de criacao de pergunta valida e bloqueio de pergunta invalida;
- executar o carregamento do backend Go contra uma amostra gerada pelo manager.

Evidence to record:

- comandos de teste executados;
- comando Docker Compose usado para subir o ambiente dev;
- exemplo de arquivo JSON gerado;
- captura ou nota de revisao das telas principais;
- resultado do backend Go carregando a amostra sem erro.

## Open Questions

- Nenhuma pergunta aberta nesta versao.
