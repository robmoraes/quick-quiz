# QuickQuiz Dev

[Read in English](README.md)

QuickQuiz Dev e uma plataforma fullstack open source de quizzes para
desenvolvedores que querem testar o que ja sabem e descobrir o proximo assunto
que vale estudar.

Ele nao foi criado como curso, tutorial ou substituto para livros, aulas,
documentacao ou pratica. O objetivo e outro: apresentar desafios focados,
tornar lacunas de conhecimento visiveis e motivar o jogador a voltar para
fontes confiaveis de aprendizado antes de retornar para vencer o proximo
desafio.

O projeto tambem foi construido como um monorepo de portfolio. Ele combina uma
SPA para jogadores, uma API em Go, um manager de conteudo em Symfony, um
contrato JSON documentado para os pacotes de quiz e producao opcional de
conteudo assistida por AI em um produto coerente.

## Demo Do Produto

### Manager De Conteudo Assistido Por AI

O manager ajuda a criar, validar, localizar e publicar conteudo de quiz. Seus
fluxos assistidos por AI podem recomendar perguntas candidatas e ajudar na
localizacao do conteudo, enquanto o contrato do quiz pack permanece como fonte
da verdade.

> Espaco reservado para video: manager criando conteudo de quiz com assistencia
> de AI.
>
> Adicione aqui o link do video quando o repositorio estiver pronto para
> publicacao.

### Experiencia Do Jogador

A SPA permite que o jogador escolha um topico e uma dificuldade, responda
perguntas cronometradas e revise o resultado da run. A experiencia foi pensada
como um ciclo de desafio: tentar, falhar, estudar, voltar e vencer.

> Espaco reservado para video: jogador completando uma run de quiz.
>
> Adicione aqui o link do video quando o repositorio estiver pronto para
> publicacao.

## Por Que Este Projeto Existe

A AI esta se tornando parte do trabalho intelectual e de conhecimento. Isso
torna ainda mais importante continuar exercitando julgamento, memoria,
fundamentos e raciocinio tecnico.

QuickQuiz Dev e uma pequena resposta a essa pressao: uma ferramenta de
recordacao ativa e autoavaliacao. Quando um desafio nao e vencido, o proximo
passo esperado nao e rolar a tela passivamente nem tentar adivinhar. O proximo
passo esperado e estudar em cursos, livros, documentacao, codigo-fonte,
mentores e qualquer lugar confiavel onde o conhecimento possa ser encontrado,
para entao voltar e vencer o desafio.

## O Que Esta No Monorepo

- `apps/spa-dev/`: SPA Quasar/Vue para jogadores do theme `dev`.
- `apps/api/`: API Go para catalogos, runs, respostas e resultados.
- `apps/manager/`: app Symfony para edicao, validacao, localizacao e fluxos
  opcionais de conteudo assistidos por AI.
- `docs/`: arquitetura, notas de servico, contratos de dados, OpenAPI, specs e
  timeline.

## Standards De Engenharia

Este projeto segue convencoes influenciadas pelo meu
[Engineering Playbook](https://github.com/robmoraes/engineering-playbook):
contratos explicitos, specs pequenas de implementacao, modelagem de dados segura
para locales, baixo acoplamento entre servicos e fronteiras claras entre
conteudo de produto, comportamento da API e orquestracao da UI.

## Documentacao

Detalhes tecnicos ficam fora deste README raiz:

- [Indice da documentacao](docs/README.md)
- [Visao geral da arquitetura](docs/architecture/overview.md)
- [Mapa de servicos](docs/architecture/services.md)
- [Contrato do quiz pack](docs/quiz-pack-contract.md)
- [Documentacao da API](docs/api/README.md)
- [Documentacao da SPA](docs/spa/README.md)
- [Documentacao do manager](docs/manager/README.md)
- [Documentacao de dados](docs/data/README.md)
- [Specs de produto e implementacao](docs/specs/README.md)

Pontos de entrada dos servicos:

- [apps/api/README.md](apps/api/README.md)
- [apps/spa-dev/README.md](apps/spa-dev/README.md)
- [apps/manager/README.md](apps/manager/README.md)

## Open Source

QuickQuiz Dev esta sendo preparado para publicacao no GitHub como projeto open
source. O codigo-fonte e licenciado sob a [MIT License](LICENSE).

Bancos reais de perguntas, secrets, credenciais privadas e bancos locais
gerados nao devem ser commitados neste repositorio.
