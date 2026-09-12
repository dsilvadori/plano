# Plataforma de Cursos Online VC

Este documento guia a evolucao da plataforma atual de plano de estudos para uma plataforma unica de cursos online, materiais digitais, banco de questoes e recursos de IA. A ideia e construir com base solida para operar primeiro como plataforma propria da Vencendo Concursos e, futuramente, poder evoluir para SaaS.

## Objetivo

Unificar em uma mesma experiencia:

- Plano de estudos personalizado.
- Cursos online com videoaulas.
- Modulos de curso organizados como pastas.
- Materiais em PDF/livro digital.
- Banco de questoes interativo.
- Resumos, mapas mentais, questoes e tira-duvidas com IA.
- Integracao com provedor de video para hospedagem, player, thumbnails e importacao de aulas.
- Home customizavel pelo admin com cursos em destaque, categorias, esferas e niveis de escolaridade.
- Comunidade/rede social para progresso dos alunos, grupos de estudo, forum e atualizacoes editoriais da Vencendo Concursos.

## Principios

- A plataforma local e a fonte da verdade pedagogica: cursos, modulos, aulas, materiais, vinculos com plano, acesso dos alunos, progresso e conteudos gerados por IA.
- O provedor de video e a fonte de midia: video, player, thumbnail, duracao, status de processamento e metadados tecnicos.
- O plano de estudos deve se vincular a aulas internas (`lessons`) e nunca depender diretamente do ID do Panda.
- Aulas e modulos devem ser reutilizaveis. Cursos sao montados a partir de modulos existentes, e modulos apontam para aulas existentes. A mesma aula interna pode aparecer em varios modulos/cursos, principalmente quando vier do mesmo `panda_video_id`.
- Tudo que for importado deve ser auditavel, reversivel quando possivel e revisavel pelo admin antes de publicar.
- Preparar multi-tenant sem implementar SaaS completo no inicio: usar nomes e estruturas que permitam futura tabela `organizations` ou `tenants`.
- Nenhuma integracao externa deve bloquear a experiencia principal. Se IA/Panda falhar, curso, plano e aulas continuam acessiveis.
- Comunidade deve ter moderacao, privacidade e controles de denuncia desde o desenho inicial, pois posts, curtidas e grupos expõem dados de comportamento de estudo.

## Glossario Inicial

- **Curso**: produto pedagogico que agrupa modulos, aulas, PDFs, questoes e regras de acesso.
- **Modulo**: bloco pedagogico reutilizavel, como `Portugues`, `Matematica` ou `Legislacao`. Deve ser independente do curso para poder compor varios cursos.
- **Trilha**: playlist/sequencia de aulas dentro de um modulo, como `Classe de palavras`, `Analise sintatica` ou `Excel 2016`. A trilha precisa ter thumbnail propria.
- **Aula**: unidade assistivel/estudavel. Pode ser videoaula, PDF/livro digital, aula mista ou conteudo textual.
- **Material**: arquivo ou embed associado a uma aula ou curso, principalmente PDF.
- **Questao**: item interativo com enunciado, alternativas, gabarito, comentario e origem.
- **Plano de estudos**: agenda de tarefas que pode apontar para aulas, materiais e questoes.
- **Matricula**: relacao do aluno com curso liberado.
- **Catalogo/Home**: area publica/logada que destaca cursos, bloqueia cursos sem acesso e leva ao checkout.
- **Comunidade**: area social da plataforma com feed, posts de progresso, curtidas, comentarios, grupos de estudo, forum e atualizacoes editoriais.
- **Grupo de estudos**: espaco colaborativo vinculado a curso, concurso, disciplina ou assunto, com membros, posts e regras proprias.
- **Forum**: area estruturada por categorias/topicos para duvidas, debates e respostas mais permanentes que o feed.

## Arquitetura Recomendada

### Entidades Principais

1. `courses`
   - `id`
   - `title`
   - `slug`
   - `description`
   - `short_description`
   - `thumbnail_url`
   - `checkout_url`
   - `sphere_id`
   - `education_level_id`
   - `status`: draft, published, archived
   - `is_featured`
   - `sort_order`
   - `metadata`

2. `course_modules`
   - `id`
   - `course_id`: curso de origem/legado, mantido para compatibilidade e para telas antigas; o vinculo real com cursos deve ser feito por `course_module_course`
   - `name`
   - `description`
   - `type`: basic, specific, complementary, questions, other
   - `sort_order`
   - `panda_folder_id`
   - `is_active`
   - `metadata`

3. `course_module_tracks`
   - `id`
   - `course_module_id`
   - `name`
   - `slug`
   - `description`
   - `thumbnail_url`
   - `thumbnail_path`
   - `sort_order`
   - `status`: draft, published, archived
   - `panda_folder_id`: pasta/playlist no Panda, quando houver
   - `google_doc_url`: documento/base editorial usada para preparar a trilha, quando houver
   - `metadata`

4. `lessons`
   - `id`
   - `course_id`: curso de origem/legado, mantido para compatibilidade
   - `course_module_id`: modulo de origem/legado, mantido para compatibilidade
   - `course_module_track_id`: trilha principal/origem da aula, quando criada pela nova estrutura
   - `title`
   - `slug`
   - `description`
   - `type`: video, pdf, mixed, text, quiz
   - `thumbnail_url`
   - `duration_seconds`
   - `sort_order`
   - `status`: draft, published, archived
   - `panda_video_id`
   - `panda_embed_url`
   - `panda_player_url`
   - `panda_status`
   - `google_doc_url`
   - `source_status`: structure_only, awaiting_media, media_ready, published
   - `metadata`

5. `lesson_materials`
   - `id`
   - `lesson_id`
   - `title`
   - `type`: pdf, attachment, link, transcript
   - `file_path`
   - `external_url`
   - `is_downloadable`
   - `sort_order`
   - `metadata`

6. `enrollments`
   - `id`
   - `user_id`
   - `course_id`
   - `source`: admin, webhook, import, checkout
   - `status`: active, expired, canceled
   - `starts_at`
   - `expires_at`

7. `lesson_progress`
   - `id`
   - `user_id`
   - `lesson_id`
   - `status`: not_started, in_progress, completed
   - `progress_seconds`
   - `completed_at`

8. `course_module_track_lessons`
   - Pivot entre trilhas e aulas.
   - Permite que a mesma aula seja reutilizada em varias trilhas/modulos/cursos.
   - Guarda ordem local da aula dentro daquela trilha.
   - Guarda `status_override`, quando a aula fica rascunho em uma trilha e publicada em outra.

9. `course_module_lessons`
   - Pivot legado/compatibilidade entre modulos e aulas.
   - Pode continuar existindo para planos e telas antigas, mas a nova montagem deve priorizar `course_module_track_lessons`.

10. `course_module_course`
   - Pivot entre cursos e modulos.
   - Permite montar cursos reaproveitando modulos ja existentes.
   - Guarda ordem local do modulo dentro daquele curso.

11. `course_module_track_course`
   - Pivot entre cursos e trilhas do modulo.
   - Permite que o curso use apenas algumas trilhas de um modulo reutilizavel.
   - Permite ordem local da trilha dentro daquele curso.
   - Evita que um curso herde automaticamente todas as trilhas do modulo.

12. `study_plan_items`
   - Evoluir para permitir `lesson_id`, `material_id` e `question_set_id`.
   - A tarefa do plano continua independente do player externo.

13. `course_spheres`
   - Esferas editaveis no admin: municipal, estadual, federal, tribunais, policia, educacao, fiscal, etc.

14. `education_levels`
   - Nivel/grau de escolaridade editavel no admin: fundamental, medio, tecnico, superior.

15. `home_sections`
    - Blocos customizaveis da home.
    - Exemplo: principais cursos, ultimos adicionados, federais em destaque, cursos para nivel medio.

16. `home_section_items`
    - Cursos ligados a cada secao, com ordem e regras de exibicao.

17. `community_posts`
    - `id`
    - `user_id`
    - `course_id`
    - `study_group_id`
    - `type`: progress, text, question, editorial_share, system
    - `body`
    - `visibility`: public, enrolled_students, group, private
    - `status`: draft, published, hidden, removed
    - `metadata`

18. `community_reactions`
    - `id`
    - `user_id`
    - `reactable_type`: post, comment, forum_reply, feed_item
    - `reactable_id`
    - `type`: like

19. `community_comments`
    - `id`
    - `user_id`
    - `community_post_id`
    - `parent_id`
    - `body`
    - `status`: published, hidden, removed

20. `community_feed_items`
    - `id`
    - `source`: internal_post, progress_event, vencendo_concursos_site, system
    - `source_id`
    - `title`
    - `excerpt`
    - `url`
    - `thumbnail_url`
    - `published_at`
    - `status`
    - `metadata`

21. `study_groups`
    - `id`
    - `course_id`
    - `title`
    - `description`
    - `visibility`: public, enrolled_students, invite_only
    - `status`: active, archived
    - `created_by`
    - `metadata`

22. `study_group_members`
    - `id`
    - `study_group_id`
    - `user_id`
    - `role`: owner, moderator, member
    - `status`: active, invited, blocked, left

23. `forum_categories`
    - `id`
    - `course_id`
    - `title`
    - `description`
    - `sort_order`
    - `status`

24. `forum_topics`
    - `id`
    - `forum_category_id`
    - `user_id`
    - `title`
    - `body`
    - `status`: open, solved, closed, hidden
    - `is_pinned`
    - `last_activity_at`

25. `forum_replies`
    - `id`
    - `forum_topic_id`
    - `user_id`
    - `body`
    - `is_solution`
    - `status`: published, hidden, removed

26. `community_reports`
    - `id`
    - `reporter_user_id`
    - `target_type`: post, comment, forum_topic, forum_reply, user
    - `target_id`
    - `reason`
    - `status`: pending, reviewed, dismissed, action_taken
    - `metadata`

### Entidades de Integracao Panda

1. `panda_integrations`
   - `id`
   - `name`
   - `api_key_encrypted`
   - `workspace_id`
   - `status`
   - `metadata`

2. `panda_import_sources`
   - `id`
   - `course_id`
   - `panda_integration_id`
   - `panda_folder_id`
   - `sync_mode`: manual, scheduled
   - `status`

3. `panda_import_runs`
   - `id`
   - `panda_import_source_id`
   - `started_at`
   - `finished_at`
   - `status`
   - `summary`
   - `error_message`

4. `panda_import_items`
   - `id`
   - `panda_import_run_id`
   - `external_type`: folder, playlist, video
   - `external_id`
   - `local_type`: module, track, lesson
   - `local_id`
   - `status`: created, updated, skipped, failed
   - `payload`
   - `error_message`

## Importacao de Estrutura por Planilha

### Objetivo

Permitir que o admin suba uma planilha de curso antes de as aulas em video ou PDF estarem prontas. A importacao deve montar a estrutura pedagogica completa para revisao e publicacao posterior:

- curso;
- modulos reutilizaveis;
- trilhas/playlists com thumbnail;
- aulas placeholder dentro das trilhas;
- duracao planejada das aulas;
- pontos futuros de vinculo com Google Docs, Panda Video, PDF ou material interno.

### Estrutura Esperada no XLSX

A planilha de exemplo `Santos - Oficial de Administracao.xlsx` usa este formato:

- Cada aba representa um modulo ou area pedagogica. Exemplos: `Portugues`, `Matematica`, `Informatica`, `Legislacao`, `Conhecimentos Especificos`.
- A linha `Modulo - Nome do modulo` define o modulo reutilizavel. Exemplo: `Modulo - Portugues`.
- A linha `Trilha - Nome da trilha` abre uma playlist dentro do modulo. Exemplo: `Trilha - Classe de palavras`.
- A linha `Aula | Tempo de aula` e apenas cabecalho.
- As linhas seguintes, ate a proxima trilha ou linha em branco, viram aulas daquela trilha.
- A coluna A contem o titulo da aula.
- A coluna B contem a duracao planejada em minutos.

Exemplo logico:

```txt
Aba: Portugues
Modulo - Portugues
Trilha - Classe de palavras
Aula | Tempo de aula
Classes de Palavras - Substantivo e Adjetivo | 10
Classes de Palavras - Adverbio | 11

Trilha - Analise sintatica
Analise sintatica - Termos da oracao - Sujeito | 12
```

Resultado esperado:

- Curso: `Oficial de Administracao`, vindo de aba dedicada `Nome do curso` quando existir ou do nome do arquivo quando nao existir.
- Modulo reutilizavel: `Portugues`.
- Trilhas do modulo: `Classe de palavras`, `Analise sintatica`.
- Aulas placeholder vinculadas a cada trilha, com duracao e status inicial.

### Regra de Modelagem

O importador nao deve transformar cada trilha em modulo. O modelo correto e:

```txt
Curso
  Modulo reutilizavel selecionado pelo curso
    Trilha / playlist selecionada pelo curso
      Aula
      Aula
```

Isso corrige o comportamento atual herdado do primeiro importador, que achata `Modulo + Trilha` em um unico `course_modules.name`, como `Portugues - Classe de palavras`. A nova implementacao deve preservar o modulo `Portugues` como entidade reaproveitavel e criar `Classe de palavras` como trilha/playlist dentro dele.

Um curso nao deve herdar automaticamente todas as trilhas de um modulo. O curso vincula o modulo e tambem vincula explicitamente quais trilhas daquele modulo fazem parte da sua estrutura. Assim, dois cursos podem usar o mesmo modulo `Portugues`, mas cada um pode ter um conjunto diferente de trilhas.

### Aulas Placeholder

Ao importar por planilha, a aula pode nascer sem video, sem PDF e sem Panda ID. Nesse caso:

- `lessons.status` deve iniciar como `draft`, salvo escolha explicita do admin.
- `lessons.source_status` deve iniciar como `structure_only` ou `awaiting_media`.
- `duration_seconds` deve vir da coluna de minutos.
- `type` deve iniciar como `video`, `pdf`, `mixed` ou `text` quando a planilha trouxer essa informacao; sem coluna extra, usar `video` como padrao planejado.
- `panda_video_id`, `panda_embed_url` e `panda_player_url` ficam nulos.
- A aula deve aparecer no admin como pendente de midia.

Quando o video ou PDF for enviado depois, o admin deve poder vincular a midia a uma aula placeholder existente, sem recriar a aula e sem quebrar plano de estudos/progresso.

### Thumbnails de Trilhas

Trilhas precisam ter thumbnail propria porque funcionam como playlists visuais para o aluno. A origem pode ser:

- coluna opcional na planilha, como `thumbnail_url` ou `thumbnail`;
- upload manual no admin depois da importacao;
- thumbnail de uma pasta/playlist do Panda, quando houver integracao;
- fallback visual padrao do modulo/curso enquanto o admin nao cadastrar a imagem.

Thumb de aula continua existindo separadamente e pode vir do Panda quando a aula tiver video.

### Reaproveitamento

Modulos devem ser independentes dos cursos:

- o match primario de modulo deve ser por nome normalizado e, futuramente, por uma chave canonica (`canonical_key`);
- se `Portugues` ja existir, o novo curso deve vincular o modulo existente por `course_module_course`;
- trilhas tambem devem ser reaproveitaveis dentro do modulo quando o nome normalizado bater, mas a exposicao da trilha no curso depende do vinculo em `course_module_track_course`;
- o mesmo modulo pode aparecer em varios cursos com conjuntos diferentes de trilhas;
- aulas devem ser reaproveitadas por `panda_video_id` quando existir, ou por titulo normalizado dentro da trilha/modulo quando ainda forem placeholders;
- o curso guarda a ordem local dos modulos e das trilhas selecionadas, nao uma copia isolada deles.

### Reimportacao

Reimportar a mesma planilha deve ser idempotente:

- atualizar nomes, ordem, duracao planejada e thumbnails quando a planilha trouxer dados novos;
- criar novas trilhas e aulas que aparecerem na planilha;
- nao apagar automaticamente trilhas/aulas removidas da planilha;
- marcar itens ausentes como `archived` ou `missing_from_spreadsheet`, conforme decisao do admin;
- preservar videos, PDFs, Panda IDs, progresso de aluno e vinculos com planos.

### Fluxo Administrativo

1. Admin escolhe ou cria o curso.
2. Admin envia a planilha XLSX/CSV.
3. Sistema mostra preview com curso, modulos, trilhas, aulas, duracao total, itens novos, itens atualizados e conflitos.
4. Admin revisa nomes e thumbnails de trilhas.
5. Admin confirma a importacao.
6. Sistema cria/atualiza a estrutura em rascunho.
7. Admin envia/vincula Google Docs, PDFs ou videos Panda depois.
8. Admin publica trilhas/aulas quando a midia estiver pronta.

### Google Docs e Panda

O Google Docs deve ser tratado como fonte editorial/material de preparacao, nao como identificador pedagogico principal. A aula local continua sendo a fonte da verdade.

- Documento do modulo pode alimentar descricao, PDFs, resumo ou roteiro.
- Documento da trilha pode alimentar uma playlist/conjunto de aulas.
- Documento da aula pode ser convertido em PDF/material ou usado para IA.
- Panda entra depois como fonte de video, player, thumbnail tecnica, duracao real e status de processamento.
- Se uma pasta do Panda representar uma trilha, salvar o ID em `course_module_tracks.panda_folder_id`.
- Se um video do Panda representar uma aula, salvar o ID em `lessons.panda_video_id`.

## Integracao com Google Drive

### Objetivo

Permitir que o admin informe uma pasta do Google Drive e use seu conteudo como origem editorial para montar ou atualizar uma trilha dentro de um modulo reutilizavel.

Caso de uso inicial:

```txt
Modulo: Informatica
Trilha: Windows 10
Pasta Drive: https://drive.google.com/drive/folders/...
Destino de midia: Panda Videos
```

O Google Drive deve criar/atualizar estrutura e referencias editoriais. O Panda continua sendo a fonte de video/player/thumbnail tecnica.

### Autenticacao

Usar Google Drive API com credenciais de servidor.

Opcoes:

- **Service account**: recomendada para operacao administrativa controlada. A pasta do Drive precisa ser compartilhada com o e-mail da service account.
- **OAuth por usuario admin**: util quando cada admin acessa seu proprio Drive, mas adiciona fluxo de consentimento e refresh token.

Para a primeira versao, usar service account:

- criar projeto no Google Cloud;
- habilitar Google Drive API;
- criar service account;
- gerar chave JSON;
- guardar a chave fora do Git, em storage seguro;
- configurar no `.env` o caminho da chave ou JSON criptografado;
- compartilhar a pasta Drive com o e-mail da service account.

Variaveis previstas:

```env
GOOGLE_DRIVE_ENABLED=true
GOOGLE_DRIVE_CREDENTIALS_PATH=/caminho/seguro/google-drive-service-account.json
GOOGLE_DRIVE_SCOPES=https://www.googleapis.com/auth/drive.readonly
```

### Escopos

Usar o menor escopo possivel:

- `https://www.googleapis.com/auth/drive.readonly` para listar e ler metadados/arquivos.

Nao usar escopo amplo de escrita na primeira versao.

### Leitura da Pasta

A URL da pasta deve ser convertida para `folder_id`.

Exemplo:

```txt
https://drive.google.com/drive/folders/1S9xtCHYpxl69Tz1ACIUwpdAaIsOtTDCu?usp=sharing
folder_id = 1S9xtCHYpxl69Tz1ACIUwpdAaIsOtTDCu
```

O sistema deve chamar `files.list` usando query:

```txt
'{folder_id}' in parents and trashed = false
```

Campos minimos esperados:

- `id`
- `name`
- `mimeType`
- `webViewLink`
- `webContentLink`
- `thumbnailLink`
- `modifiedTime`
- `size`

### Mapeamento para Curso

No painel admin, a acao inicial deve ficar na trilha ou no modulo:

1. Admin cria ou seleciona modulo `Informatica`.
2. Admin cria ou seleciona trilha `Windows 10`.
3. Admin informa URL da pasta do Google Drive.
4. Sistema mostra preview dos arquivos.
5. Admin escolhe o tratamento:
   - Google Docs -> aula textual/material editorial;
   - PDF -> aula PDF ou material da aula;
   - video bruto -> referencia para upload/processamento futuro no Panda;
   - imagem -> thumbnail da trilha ou material;
   - subpasta -> nova trilha ou agrupamento, conforme escolha.
6. Admin confirma.
7. Se habilitado, sistema cria/garante pasta correspondente no Panda para o modulo.
8. Se habilitado, sistema cria/garante uma pasta correspondente no Panda para cada trilha/subpasta do Drive.
9. Sistema salva `panda_folder_id` no modulo e nas trilhas.
10. Sistema cria/atualiza aulas placeholder na trilha.
11. Aulas ficam `draft` e `awaiting_media`, salvo quando o arquivo for PDF/material ja utilizavel.
12. O curso selecionado recebe vinculo explicito com o modulo e com a trilha em `course_module_track_course`.

### Relacao com Panda Videos

Fluxo recomendado para `Informatica > Windows 10`:

1. Drive cria a estrutura editorial da trilha e aulas.
2. Plataforma cria/garante a pasta `Informatica` no Panda quando o modulo ainda nao tiver `panda_folder_id`.
3. Plataforma cria/garante a pasta `Windows 10` no Panda dentro da pasta do modulo, quando a API permitir hierarquia.
4. Panda importa ou sincroniza videos da pasta correspondente.
5. Admin faz match dos videos Panda com as aulas placeholder:
   - por nome normalizado;
   - por ordem;
   - manualmente quando houver divergencia.
6. Ao vincular video Panda, a aula muda para `media_ready`.
7. Admin publica a trilha/aulas quando revisar tudo.

### Entidades Sugeridas

1. `google_drive_import_runs`
   - `id`
   - `course_id`
   - `course_module_id`
   - `course_module_track_id`
   - `folder_id`
   - `folder_url`
   - `status`: preview, running, finished, failed
   - `summary`
   - `error_message`
   - `started_at`
   - `finished_at`
   - `created_by`
   - `metadata`

2. `google_drive_import_items`
   - `id`
   - `google_drive_import_run_id`
   - `drive_file_id`
   - `name`
   - `mime_type`
   - `local_type`: track, lesson, material, ignored
   - `local_id`
   - `status`: created, updated, matched, skipped, failed
   - `payload`
   - `error_message`

### Regras

- A importacao do Drive nunca deve apagar aulas/trilhas automaticamente.
- Arquivos removidos do Drive devem gerar alerta `missing_on_drive`.
- O Drive nao substitui o Panda como fonte de video publicado.
- O Drive nao deve ser usado como armazenamento final de video para aluno.
- Criacao automatica de pastas Panda deve ser opcional, pois depende das permissoes e do payload aceito pela API Panda.
- Quando a API Panda nao aceitar hierarquia, salvar ao menos a pasta da trilha criada e manter a relacao local pelo modulo.
- Cada importacao deve ter preview antes de gravar.
- O admin deve poder ajustar nomes antes de confirmar.
- A pasta pode estar publica por link, mas o modo mais estavel e compartilhar com a service account.

### Campos Opcionais para CSV ou Planilha Evoluida

Para importacoes futuras, a planilha pode aceitar colunas opcionais:

- `course_name`
- `module_name`
- `module_type`
- `module_sort_order`
- `track_name`
- `track_sort_order`
- `track_thumbnail_url`
- `track_google_doc_url`
- `lesson_title`
- `lesson_minutes`
- `lesson_type`
- `lesson_status`
- `lesson_sort_order`
- `lesson_google_doc_url`
- `panda_folder_id`
- `panda_video_id`
- `panda_embed_url`
- `panda_player_url`

O formato atual por abas deve continuar suportado porque e simples para producao editorial.

## Integracao com Provedor de Video

### Estrategia

O admin deve poder escolher um curso, modulo ou trilha e sincronizar uma pasta/projeto do Panda. A importacao do Panda deve preencher midia e metadados tecnicos sem sobrescrever a organizacao pedagogica local.

- Se a pasta Panda representar um modulo inteiro, ela pode sugerir/criar `course_modules`.
- Se a pasta Panda representar uma playlist de aulas, ela deve sugerir/criar `course_module_tracks`.
- Cada video Panda deve virar ou atualizar uma `lesson`.
- A thumbnail da trilha pode vir da pasta/playlist Panda quando disponivel.
- A thumbnail da aula deve vir do video Panda quando disponivel.

### Fluxo Manual Inicial

1. Admin cadastra a chave da API Panda.
2. Admin abre um curso.
3. Admin escolhe `Importar do Panda`.
4. Plataforma lista pastas/videos disponiveis, se a API permitir.
5. Admin seleciona uma pasta raiz.
6. Plataforma cria uma pre-visualizacao:
   - pasta Panda -> modulo ou trilha, conforme mapeamento escolhido pelo admin;
   - video Panda -> aula;
   - ordem;
   - duracao;
   - thumbnail;
   - status.
7. Admin confirma importacao.
8. Sistema cria/atualiza modulos, trilhas e aulas.
9. Sistema registra `panda_import_run` e `panda_import_items`.

### Sincronizacao Incremental

- Videos novos no Panda criam novas aulas em rascunho ou publicadas, conforme configuracao do curso.
- Videos alterados atualizam titulo, thumb, duracao e status.
- Videos removidos no Panda nao devem apagar aulas automaticamente. Marcar como `missing_on_panda` ou exibir alerta ao admin.
- Modulos locais podem ter nome ajustado sem perder vinculo com `panda_folder_id`.
- Trilhas locais podem ter nome e thumbnail ajustados sem perder vinculo com `course_module_tracks.panda_folder_id`.

### Webhooks

Se o Panda oferecer webhooks, criar endpoint:

```txt
POST /webhooks/panda
```

Eventos esperados:

- video criado.
- video processado.
- video atualizado.
- thumbnail pronta.
- video removido.

Cada evento deve atualizar apenas metadados tecnicos da aula. A organizacao pedagogica continua sendo local.

### IA do Panda

Se o Panda disponibilizar transcricao, resumo ou questoes por IA:

- Salvar o resultado bruto em `ai_artifacts`.
- Marcar origem como `panda`.
- Permitir revisao do admin antes de publicar para o aluno.
- Se a funcionalidade nao estiver disponivel, usar nossa propria fila de IA com transcricao/material local.

## Integracao com API de IA

### Objetivos

- Gerar resumo da aula.
- Gerar mapa mental em HTML/CSS.
- Gerar questoes objetivas.
- Gerar comentarios de questoes.
- Tirar duvidas do aluno com base no conteudo do curso.
- Gerar resumo de PDFs/livros digitais.

### Entidades

1. `ai_artifacts`
   - `id`
   - `source_type`: lesson, material, question, course, module
   - `source_id`
   - `artifact_type`: summary, mindmap, quiz, explanation, transcript, embeddings
   - `provider`: panda, manual, other
   - `status`: pending, processing, ready, failed, approved
   - `content`
   - `metadata`

2. `ai_jobs`
   - `id`
   - `ai_artifact_id`
   - `provider`
   - `prompt_version`
   - `status`
   - `error_message`

3. `student_ai_messages`
   - `id`
   - `user_id`
   - `course_id`
   - `lesson_id`
   - `question`
   - `answer`
   - `sources`
   - `metadata`

### Regras

- IA sempre deve citar a origem interna usada: aula, PDF, questao ou modulo.
- Aluno so pode consultar IA de cursos nos quais esta matriculado.
- Conteudo gerado por IA deve ter estado de revisao quando for conteudo publico do curso.
- Tira-duvidas pode responder em tempo real, mas deve limitar escopo ao curso/aula.

## PDF, Livro Digital e Materiais

### Requisitos

- Aula pode ser do tipo PDF/livro digital.
- PDF pode ser embedado na aula.
- Admin escolhe se o PDF pode ser baixado.
- PDF pode ser associado a videoaula.
- PDF deve poder alimentar IA para resumo, mapa mental, questoes e tira-duvidas.

### Fluxo

1. Admin cria aula do tipo PDF ou adiciona material PDF a uma aula existente.
2. Sistema armazena arquivo em disco/cloud.
3. Sistema cria preview/embed.
4. Sistema extrai texto em job assíncrono.
5. Sistema gera artefatos de IA opcionalmente.
6. Aluno acessa o PDF dentro da aula e baixa se permitido.

## Banco de Questoes

Objetivo: criar uma area propria de banco de questoes, capaz de importar questoes a partir de PDFs e transformar o conteudo em exercicios interativos, organizados por curso, disciplina, assunto, banca, ano, prova/concurso e relacao com as aulas estudadas.

### Fontes

- PDF.
- XLS/XLSX.
- CSV.
- Entrada manual no admin.
- Geracao por IA a partir de aula/PDF.
- Comentarios gerados por IA, inicialmente com API do Gemini.

### Entidades

1. `question_banks`
   - `id`
   - `course_id`
   - `exam_board_id`
   - `exam_id`
   - `title`
   - `source_type`
   - `source_file_path`
   - `year`
   - `status`
   - `metadata`

2. `questions`
   - `id`
   - `question_bank_id`
   - `course_id`
   - `course_module_id`
   - `lesson_id`
   - `exam_board_id`
   - `exam_id`
   - `exam_year`
   - `exam_name`
   - `institution`
   - `position`
   - `education_level_id`
   - `exam_area`
   - `source_question_number`
   - `subject`
   - `topic`
   - `subtopic`
   - `statement`
   - `type`: multiple_choice, true_false, discursive
   - `answer_key`
   - `commentary`
   - `source_reference`
   - `difficulty`
   - `status`: draft, review, published, archived
   - `metadata`

3. `question_options`
   - `id`
   - `question_id`
   - `label`
   - `text`
   - `is_correct`

4. `question_attempts`
   - `id`
   - `user_id`
   - `question_id`
   - `selected_option_id`
   - `is_correct`
   - `answered_at`

5. `question_import_batches`
   - `id`
   - `course_id`
   - `question_bank_id`
   - `source_type`: pdf, xlsx, csv, manual, ai
   - `file_path`
   - `status`: uploaded, extracting, parsed, review, imported, failed
   - `summary`
   - `error_message`
   - `created_by`
   - `metadata`

6. `question_import_rows`
   - `id`
   - `question_import_batch_id`
   - `raw_text`
   - `parsed_payload`
   - `status`: pending, parsed, needs_review, imported, skipped, failed
   - `error_message`
   - `metadata`

7. `question_topics`
   - `id`
   - `course_id`
   - `course_module_id`
   - `lesson_id`
   - `name`
   - `normalized_name`
   - `parent_id`
   - `metadata`

8. `exam_boards`
   - `id`
   - `name`: Vunesp, Cebraspe/Cespe, FGV, FCC, Instituto AOCP, Quadrix, etc.
   - `normalized_name`
   - `acronym`
   - `aliases`
   - `status`
   - `metadata`

9. `exams`
   - `id`
   - `exam_board_id`
   - `title`
   - `year`
   - `institution`
   - `position`
   - `exam_area`
   - `notice_reference`
   - `sphere_id`
   - `education_level_id`
   - `status`
   - `metadata`

10. `question_sets`
   - `id`
   - `course_id`
   - `title`
   - `type`: manual, lesson_related, study_plan_task, review, simulation
   - `exam_board_id`
   - `exam_id`
   - `year`
   - `status`
   - `metadata`

11. `question_set_items`
   - `id`
   - `question_set_id`
   - `question_id`
   - `sort_order`

### Importacao

1. Admin sobe PDF ou XLS.
2. Sistema cria lote de importacao.
3. PDF fica salvo como fonte original para auditoria.
4. Sistema extrai texto do PDF em background.
5. Parser identifica questoes, alternativas, gabarito e comentarios quando houver.
6. Quando comentario nao existir, sistema pode gerar comentario com IA usando Gemini.
7. Parser/IA tenta identificar banca, ano, concurso/prova, orgao, cargo, disciplina, assunto, subassunto, modulo e possivel aula relacionada.
8. Admin revisa uma tela de pre-importacao.
9. Admin ajusta enunciado, alternativas, gabarito, comentario, banca, ano, concurso/prova, orgao, cargo e assunto quando necessario.
10. Admin pode editar comentarios gerados por IA antes e depois da publicacao.
11. Sistema salva questoes revisadas.
12. Questoes podem ser vinculadas a curso, modulo, aula, plano, prova/concurso ou conjunto de questoes.

### Taxonomia, Prova e Trilha

- Cada questao deve ter assunto normalizado para permitir busca, filtro e recomendacao.
- Cada questao deve poder guardar banca e ano. Concurso/prova, orgao e cargo sao recomendados, mas podem ficar vazios quando a origem nao trouxer esses dados.
- Banca deve ser entidade normalizada para evitar duplicidade entre nomes como `Cespe`, `Cebraspe` e `CESPE/CEBRASPE`, ou erros comuns de digitacao como `Venesp` para `Vunesp`.
- Prova/concurso deve ser entidade separada quando houver informacao suficiente: exemplo `TJ SP - Escrevente Tecnico Judiciario - 2023 - Vunesp`.
- Campos de prova relevantes: banca, ano, orgao/instituicao, cargo, esfera, escolaridade, edital/prova e area.
- O banco de questoes pode ter banca/ano/prova padrao, mas cada questao deve poder sobrescrever esses dados quando um arquivo trouxer questoes de origens diferentes.
- A IA deve comparar o texto da questao com titulos de aulas, resumos, transcricoes, mapas mentais e metadados do modulo.
- O sistema deve sugerir vinculo com aula/modulo, mas o admin pode revisar.
- Questoes exibidas no plano de estudos devem ter relacao com o conteudo estudado naquele dia ou naquela semana.
- Tarefas de questoes podem ser geradas por:
  - aula concluida;
  - modulo em andamento;
  - assunto do bloco do plano;
  - revisao programada;
  - desempenho do aluno.
- Quando nao houver confianca suficiente na classificacao por IA, a questao deve ficar em revisao.

### Experiencia do Aluno

- Menu separado para `Banco de Questoes`.
- Filtros por curso, disciplina, assunto, banca, ano, concurso/prova, orgao, cargo, dificuldade, status e questoes erradas.
- Resolucao interativa com clique na alternativa.
- Exibicao de gabarito e comentario apos resposta.
- Historico de tentativas.
- Percentual de acertos por assunto, banca, ano e prova.
- Recomendacao de novas questoes com base nas aulas estudadas e erros anteriores.
- Possibilidade de abrir um conjunto de questoes a partir da tarefa do plano.

### IA e Cache

- Comentarios gerados por IA devem ser salvos em cache no campo `commentary` ou em `ai_artifacts`.
- Uma questao ja comentada nao deve gerar nova chamada de IA sem acao explicita do admin.
- Guardar provider, prompt version, data da geracao e payload resumido em metadata.
- Usar fila para importacao e comentario em lote.
- Registrar falhas por questao sem interromper o lote inteiro.
- Permitir edicao manual do comentario gerado por IA no admin.
- Permitir regerar comentario apenas para questoes selecionadas.

## Comunidade, Rede Social e Forum

Objetivo: criar uma camada social dentro da plataforma para aumentar engajamento, pertencimento e troca entre alunos, sem misturar a organizacao pedagogica dos cursos com conversas livres.

### Componentes

- Feed da comunidade com posts de alunos, progresso de estudo e atualizacoes editoriais.
- Posts de progresso gerados pelo aluno ao concluir aulas, bater metas, finalizar modulos ou resolver questoes.
- Curtidas em posts, comentarios, respostas de forum e atualizacoes editoriais.
- Comentarios em posts do feed.
- Compartilhamento interno de novas postagens do site `vencendoconcursos.com.br`.
- Grupos de estudo por curso, concurso, disciplina, assunto ou turma.
- Forum estruturado por categorias, topicos e respostas.
- Marcacao de resposta como solucao em topicos de duvida.
- Denuncia de conteudo e fila de moderacao.

### Feed da Comunidade

- Feed deve combinar:
  - posts manuais dos alunos;
  - eventos de progresso permitidos pelo aluno;
  - posts de grupos dos quais o aluno participa;
  - topicos recentes ou populares do forum;
  - novas postagens editoriais do site `vencendoconcursos.com.br`.
- Aluno deve poder publicar texto curto sobre seu progresso, duvida, meta ou conquista.
- Ao concluir uma aula, modulo, simulado ou bloco do plano, o sistema pode sugerir um post de progresso, mas nao deve publicar automaticamente sem consentimento do aluno.
- Feed deve respeitar permissao de acesso: aluno nao deve ver posts de grupo privado nem conteudo de curso ao qual nao tem acesso.
- Atualizacoes editoriais do site devem entrar como itens curtiveis e clicaveis, levando ao post original quando necessario.

### Grupos de Estudo

- Grupo pode ser criado pelo admin, professor/moderador ou aluno, conforme regra definida.
- Grupo pode ser publico, restrito a alunos matriculados ou por convite.
- Grupo deve permitir posts, comentarios, membros, moderadores e regras de entrada.
- Grupos podem ser vinculados a curso, concurso, disciplina, assunto ou turma.
- Aluno pode encontrar grupos recomendados com base nos cursos em que esta matriculado e assuntos do plano.

### Forum

- Forum deve ser mais organizado que o feed, com categorias, topicos e respostas.
- Categorias podem ser globais ou vinculadas a cursos.
- Topico pode ser marcado como resolvido.
- Respostas podem receber curtidas.
- Admin/moderador pode fixar, fechar, ocultar ou remover topicos.
- Futuramente, IA pode sugerir topicos relacionados, mas nao deve responder como autoridade sem revisao quando a resposta for publica.

### Moderacao e Privacidade

- Todo conteudo publicado por aluno deve ter status moderavel.
- Alunos devem poder denunciar posts, comentarios, topicos, respostas e usuarios.
- Admin/moderador deve ter fila de denuncias.
- Remocao deve ser logica, preservando auditoria.
- Dados de progresso compartilhados devem depender de consentimento do aluno.
- Perfis podem exibir apenas informacoes essenciais: nome, avatar, cursos em comum e conquistas que o aluno autorizou compartilhar.

### Integracao com `vencendoconcursos.com.br`

- Criar fonte editorial para importar ou sincronizar novas postagens do site.
- Priorizar RSS/feed oficial, API ou WordPress REST API se disponivel.
- Cada item importado deve gerar `community_feed_item` com titulo, resumo, URL, thumb e data.
- Evitar duplicidade por URL/canonical ID.
- Se a integracao falhar, o feed social continua funcionando normalmente.
- Admin pode destacar, ocultar ou fixar atualizacoes editoriais.

## Planilha do Plano de Estudos

### Objetivo

Usar a mesma planilha do plano para montar cursos, modulos e aulas.

### Colunas Recomendadas

- curso
- esfera
- escolaridade
- modulo
- aula
- tipo_aula
- panda_video_id
- panda_video_url
- duracao
- thumbnail_url
- pdf_url
- bloco_plano
- materia
- assunto
- ordem_modulo
- ordem_aula
- publicado

### Comportamento

- Se curso nao existir, criar como rascunho.
- Se modulo nao existir, criar.
- Se aula nao existir, criar.
- Se aula existir, atualizar campos permitidos.
- Se `panda_video_id` estiver presente, vincular aula ao Panda.
- Se houver PDF, criar material.
- Se houver materia/assunto, usar para vinculo com plano de estudos.

## Experiencia do Aluno

### Home Logada

- Ver ultimos cursos acessados.
- Ver principais cursos disponiveis.
- Ver cursos por esfera.
- Ver cursos por escolaridade.
- Mostrar cadeado em cursos nao matriculados.
- Curso bloqueado deve exibir CTA para checkout.
- Curso liberado deve levar para pagina do curso.

### Meus Cursos

- Lista de cursos matriculados.
- Progresso por curso.
- Ultima aula assistida.
- Botao continuar.
- Filtros por esfera, escolaridade e status.

### Pagina do Curso

- Header com thumbnail, titulo, descricao e progresso.
- Modulos exibidos em acordeon, fechados por padrao para facilitar a leitura da pagina.
- Cada modulo mostra suas trilhas em carrossel horizontal.
- Cada trilha aparece como card com thumbnail, titulo, progresso e quantidade de aulas.
- Ao abrir uma trilha, o aluno ve as aulas daquela playlist em ordem.
- Status da aula: nao iniciada, em andamento, concluida.
- Materiais disponiveis.
- Resumos/mapas/questoes quando publicados.

### Pagina da Aula

- Player Panda quando for video.
- PDF embedado quando houver.
- Lista de materiais.
- Resumo da aula.
- Mapa mental.
- Questoes da aula.
- Tira-duvidas com IA.
- Botao concluir aula.
- Navegacao aula anterior/proxima.

### Plano de Estudos

- Ao exibir tarefa do dia, mostrar aulas vinculadas.
- Aula deve ter link direto para player/aula interna.
- Tarefa pode agrupar:
  - videoaulas;
  - leitura de PDF;
  - questoes;
  - revisao.
- Ao concluir aula, avaliar se tarefa do plano pode ser marcada como concluida ou parcialmente concluida.

### Comunidade

- Feed com posts de alunos, progresso compartilhado, grupos, topicos recentes e novidades da Vencendo Concursos.
- Botao para postar progresso a partir de aula concluida, modulo concluido, meta batida ou simulado finalizado.
- Curtir posts, comentarios, respostas e atualizacoes editoriais.
- Comentar posts do feed.
- Entrar em grupos de estudo recomendados.
- Criar ou participar de topicos no forum.
- Denunciar conteudo inadequado.
- Controlar privacidade do progresso compartilhado.

## Admin

### Cursos

- CRUD de cursos.
- Thumbnail do curso.
- Checkout URL.
- Esfera.
- Escolaridade.
- Destaque na home.
- Status.
- Ordenacao.

### Modulos

- CRUD de modulos dentro do curso.
- Ordenacao por drag-and-drop futuramente.
- Vinculo com pasta Panda.
- Configuracao de exibicao como acordeon na pagina do curso, fechado por padrao.

### Trilhas

- CRUD de trilhas dentro do modulo.
- Thumbnail da trilha.
- Ordenacao das trilhas dentro do modulo.
- Exibicao em carrossel na pagina do curso.
- Vinculo opcional com pasta/playlist Panda.
- Vinculo opcional com Google Docs.

### Aulas

- CRUD de aulas.
- Thumbnail da aula.
- Tipo de aula.
- Vinculo com trilha.
- Vinculo com Panda.
- Materiais.
- Status.
- Ordenacao.
- Gerar/resincronizar IA.

### Banco de Questoes

- CRUD de bancos de questoes.
- Cadastro e normalizacao de bancas.
- Cadastro opcional de provas/concursos.
- Campos de ano, orgao, cargo, esfera e escolaridade.
- Revisao de banca/ano/prova sugeridos por parser ou IA.
- Mesclagem de bancas duplicadas ou equivalentes.
- Filtros administrativos por banca, ano, prova, orgao, cargo, disciplina e assunto.

### Home

- CRUD de secoes.
- Tipo da secao:
  - cursos manuais;
  - cursos em destaque;
  - ultimos cursos;
  - por esfera;
  - por escolaridade.
- Ordenacao.
- Publicar/despublicar secao.

### Comunidade

- Moderar posts, comentarios, topicos e respostas.
- Gerenciar denuncias.
- Criar e destacar grupos de estudo.
- Definir quem pode criar grupos: admin, moderador, professor ou aluno.
- Criar categorias do forum.
- Fixar, fechar, ocultar ou remover topicos.
- Configurar fonte editorial do site `vencendoconcursos.com.br`.
- Destacar ou ocultar atualizacoes editoriais no feed.

### Integracoes

- Configurar Panda.
- Testar conexao.
- Importar pastas/videos.
- Ver historico de importacao.
- Configurar IA.
- Testar provider de IA.

## Fases de Implementacao

### Fase 0 - Fundacao e Decisoes

Objetivo: alinhar arquitetura antes de criar telas.

- Definir nomes finais das tabelas.
- Confirmar driver de banco em dev/producao.
- Confirmar como os cursos atuais do plano se relacionam com cursos online.
- Confirmar campos da planilha principal.
- Definir primeira versao da integracao Panda: manual primeiro, webhook depois.
- Definir se thumbnails serao URL externa inicialmente ou upload local.

Criterios de aceite:

- Documento revisado.
- Modelo de dados aprovado.
- Ordem de implementacao aprovada.

### Fase 1 - Catalogo Basico de Cursos

Objetivo: criar estrutura interna de cursos, modulos e aulas.

- Migrations para cursos, esferas e escolaridade.
- Migrations para modulos e aulas.
- Relacionamentos Eloquent.
- Seeds basicos de esferas e escolaridades.
- Admin CRUD simples de cursos.
- Admin CRUD simples de modulos.
- Admin CRUD simples de aulas.
- Thumbnail por URL.
- Status draft/published.

Criterios de aceite:

- Admin cria curso com thumbnail.
- Admin cria modulos dentro do curso.
- Admin cria aulas dentro dos modulos.
- Curso publicado aparece para aluno.

### Fase 2 - Home e Meus Cursos

Objetivo: criar experiencia inicial do aluno.

- Home logada customizavel por secoes simples.
- Secao de cursos em destaque.
- Secao de ultimos cursos.
- Secao por esfera/escolaridade.
- Tela Meus Cursos.
- Cards com cadeado para cursos bloqueados.
- Link de checkout em curso bloqueado.
- Matricula simples via admin.

Criterios de aceite:

- Aluno ve cursos liberados e bloqueados.
- Curso bloqueado leva ao checkout.
- Curso liberado abre pagina do curso.
- Admin consegue destacar cursos na home.

### Fase 3 - Player de Aula e Progresso

Objetivo: permitir consumo real das aulas.

Status: em implementacao inicial.

- Pagina do curso com modulos e aulas.
- Modulos da pagina do curso em acordeon fechado por padrao.
- Trilhas exibidas dentro de cada modulo em carrossel com thumbnail.
- Pagina da aula.
- Player via embed/URL Panda.
- Progresso de aula.
- Botao concluir aula.
- Continuar de onde parou.
- Progresso do curso.

Criterios de aceite:

- Aluno assiste aula Panda dentro da plataforma.
- Aluno conclui aula.
- Curso mostra progresso atualizado.
- Aluno continua a ultima aula.

Implementacao inicial:

- Tabela `lesson_progress` registra andamento por aluno, curso e aula.
- Curso exibe progresso geral e aulas concluidas.
- Aula publicada abre em pagina propria com embed Panda quando houver URL cadastrada.
- Botao de conclusao atualiza o progresso do curso.
- Cards de curso mostram progresso para alunos com acesso.

### Fase 4 - Vinculo com Plano de Estudos

Objetivo: ligar aulas ao plano atual.

Status: em implementacao inicial.

- Adicionar `lesson_id` em itens do plano ou tabela pivot se uma tarefa tiver varias aulas.
- Atualizar gerador/importador do plano para associar aulas por materia/assunto/planilha.
- Exibir aulas vinculadas na tarefa do dia.
- Link direto da tarefa para aula.
- Opcional: concluir aula atualiza progresso da tarefa.

Criterios de aceite:

- Tarefa do plano mostra aulas reais.
- Clique abre aula.
- Plano continua funcionando se aula nao existir.

Implementacao inicial:

- Tabela `study_plan_item_lessons` permite vincular uma ou mais aulas a uma tarefa do plano.
- Gerador do plano tenta associar aulas publicadas do modulo aos blocos teoricos criados.
- Visualizacao do plano mostra link direto para o player quando ha aula real vinculada.
- Quando nao existe aula vinculada, o plano continua exibindo a aula textual planejada.
- Concluir aula pelo player pode marcar a tarefa do plano como concluida quando todas as aulas vinculadas foram finalizadas.

### Fase 5 - Importacao por Planilha

Objetivo: montar cursos pela mesma planilha do plano.

Status: em implementacao inicial.

- Definir template oficial.
- Parser de XLS/CSV.
- Tela de pre-visualizacao.
- Criar/atualizar cursos, modulos, aulas e materiais.
- Relatorio de erros.
- Modo dry-run.

Criterios de aceite:

- Admin sobe planilha.
- Sistema mostra o que sera criado/atualizado.
- Admin confirma.
- Cursos e aulas aparecem corretamente.

Implementacao inicial:

- Importador aceita `.xlsx` no formato atual do plano e `.csv` no template oficial simples.
- Preview em modo dry-run mostra curso, modulos, trilhas, aulas, carga total e quantos itens serao criados ou atualizados.
- Importacao cria/atualiza curso, modulos reutilizaveis, trilhas/playlists e registros reais em `lessons`.
- Antes de criar novos registros, a importacao procura modulos, trilhas e aulas existentes por nome normalizado. Se encontrar, vincula o curso ao modulo existente, a trilha ao modulo e a aula a trilha.
- Aulas importadas por planilha ficam em rascunho/pendentes de midia por padrao quando o arquivo nao informar status.
- Reimportacao atualiza modulos, trilhas e aulas existentes sem apagar automaticamente itens removidos da planilha.

Template CSV inicial:

```csv
course_name,module_name,module_type,module_sort_order,track_name,track_sort_order,track_thumbnail_url,lesson_title,lesson_minutes,lesson_type,lesson_status,panda_video_id,panda_embed_url,panda_player_url,thumbnail_url
Curso Exemplo,Portugues,basic,1,Classes de palavras,1,https://example.com/trilha.jpg,Substantivo e adjetivo,30,video,draft,video_123,https://player.example.com/video_123,,https://example.com/aula.jpg
```

### Fase 6 - Integracao Panda Manual

Objetivo: importar aulas e thumbnails do Panda.

Status: em implementacao inicial.

- Configurar credenciais Panda.
- Testar conexao.
- Listar pastas/videos se API permitir.
- Importar pasta como modulo.
- Importar videos como aulas.
- Salvar `panda_video_id`, duracao, thumbnail e status.
- Historico de importacao.

Criterios de aceite:

- Admin importa videos do Panda para um curso.
- Aulas criadas usam thumb do Panda.
- Player funciona na aula.
- Nova sincronizacao atualiza metadados sem duplicar aulas.

Implementacao inicial:

- Configuracao por `PANDA_API_KEY`, `PANDA_API_BASE_URL`, `PANDA_AUTH_HEADER`, `PANDA_AUTH_SCHEME`, `PANDA_VIDEOS_PATH`, `PANDA_FOLDER_QUERY_PARAM` e `PANDA_EMBED_BASE_URL`.
- Admin importa uma pasta do Panda dentro da edicao do modulo, nao diretamente dentro do curso.
- Na tela de `course-modules`, a importacao por nome procura modulo existente pelo nome normalizado e substitui/atualiza esse modulo, sem duplicar.
- O curso deve ser montado a partir de modulos existentes/reutilizaveis.
- Videos viram aulas internas com `panda_video_id`, duracao, thumbnail, status e URLs de player/embed.
- Aulas sao reutilizadas por `panda_video_id`: se o mesmo video for importado em outro curso, o registro da aula e reaproveitado e apenas um novo vinculo modulo-aula e criado.
- Modulos tambem podem ser reutilizados em varios cursos pela pivot `course_module_course`.
- Historico basico fica em `panda_import_runs` e `panda_import_items`.

### Fase 7 - Integracao Google Drive para Trilhas

Objetivo: permitir que o admin use uma pasta do Google Drive como fonte editorial para montar uma trilha dentro de um modulo reutilizavel, preparando as aulas para receber videos/PDFs depois.

Status: proxima fase de desenvolvimento.

Escopo principal:

- Configurar credenciais da Google Drive API.
- Criar client de leitura do Drive.
- Criar acao no admin para importar pasta Drive em uma trilha.
- Aceitar URL de pasta Drive e extrair `folder_id`.
- Listar arquivos da pasta com preview.
- Criar/atualizar trilha existente, como `Informatica > Windows 10`.
- Criar aulas placeholder a partir dos arquivos.
- Guardar `google_doc_url`/link Drive na trilha, aula ou material.
- Vincular explicitamente a trilha ao curso selecionado.
- Registrar historico em `google_drive_import_runs` e `google_drive_import_items`.

Criterios de aceite:

- Admin seleciona curso, modulo `Informatica` e trilha `Windows 10`.
- Admin informa URL da pasta do Drive.
- Sistema mostra preview dos arquivos.
- Admin confirma.
- Sistema cria aulas em rascunho/aguardando midia.
- Curso passa a exibir somente a trilha selecionada.
- Nada e apagado automaticamente ao reimportar.

Implementacao inicial:

- Usar service account com escopo `drive.readonly`.
- Exigir que a pasta seja compartilhada com o e-mail da service account.
- Tratar Google Docs, PDFs, imagens, videos e subpastas de forma diferente no preview.
- Nao baixar videos para servir ao aluno.
- O match com Panda Videos sera feito por etapa separada, usando nome normalizado e ordem.

### Fase 8 - Banco de Questoes

Objetivo: iniciar uma nova frente de desenvolvimento antes da preparacao SaaS, criando um banco de questoes capaz de receber PDFs por upload, extrair questoes, organizar por assunto, banca, ano e prova/concurso, e transformar o conteudo em exercicios interativos com gabarito comentado.

Status: proxima fase de desenvolvimento.

Escopo principal:

- Criar menu separado `Banco de Questoes`.
- Criar estrutura de bancos, lotes de importacao, questoes, alternativas, assuntos, bancas, provas/concursos, conjuntos e tentativas.
- Upload de PDF pelo admin.
- Armazenar PDF original como fonte/auditoria.
- Extrair texto do PDF em background.
- Parser inicial para identificar enunciado, alternativas e gabarito.
- Cadastro/normalizacao de bancas como Vunesp, Cebraspe/Cespe, FGV, FCC, Instituto AOCP e Quadrix.
- Campos de ano, concurso/prova, orgao e cargo no banco e/ou na questao.
- Tela de revisao antes de publicar questoes.
- Questoes interativas para o aluno, com clique na alternativa.
- Exibir gabarito e comentario apos resposta.
- Salvar tentativas e historico do aluno.
- Estatisticas por questao, assunto, banca, ano, prova e aluno.

IA e Gemini:

- Usar API do Gemini para gerar comentario quando o PDF nao trouxer comentario.
- Usar IA para sugerir banca, ano, concurso/prova, orgao, cargo, disciplina, assunto, subassunto, modulo e aula relacionada.
- Guardar comentarios gerados em cache para nao repetir chamadas de IA.
- Permitir que o admin edite comentarios gerados por IA antes e depois da publicacao.
- Permitir regerar comentario apenas por acao explicita do admin.
- Registrar provider, prompt version, data e status da geracao.
- Questao com classificacao incerta deve ficar em revisao.

Organizacao por prova, assunto e plano:

- Questoes devem ser organizadas por assunto normalizado.
- Questoes devem permitir filtros por banca, ano, prova/concurso, orgao e cargo.
- Concurso/prova deve ser opcional para nao travar importacoes incompletas.
- Sistema deve relacionar questoes com aulas, modulos e resumos/transcricoes quando disponiveis.
- Plano de estudos deve poder incluir tarefas de questoes.
- Questoes da trilha devem ter relacao com o conteudo estudado pelo aluno.
- A tarefa de questoes pode vir apos aula, modulo, revisao ou bloco de assunto.
- Aluno deve conseguir resolver questoes dentro do plano e tambem pelo menu separado.

Fluxo inicial:

1. Admin cria ou escolhe um banco de questoes.
2. Admin sobe um PDF.
3. Sistema cria `question_import_batch`.
4. Sistema extrai texto e tenta separar as questoes.
5. Sistema identifica alternativas e gabarito quando possivel.
6. Sistema gera comentario com IA quando necessario.
7. Sistema sugere banca, ano, prova/concurso, assunto e vinculo com aula/modulo.
8. Admin revisa e corrige.
9. Admin publica questoes.
10. Aluno resolve questoes interativas.
11. Sistema salva resultado e atualiza estatisticas.

Criterios de aceite:

- Admin sobe PDF com questoes.
- Sistema cria lote de importacao.
- Sistema extrai e apresenta questoes em tela de revisao.
- Admin informa ou corrige banca, ano, prova/concurso, orgao e cargo.
- Sistema normaliza bancas e evita duplicar nomes equivalentes.
- Admin publica questoes revisadas.
- Aluno responde questoes.
- Sistema mostra gabarito e comentario.
- Resultado fica salvo no historico.
- Questoes aparecem em menu separado.
- Aluno filtra questoes por banca, ano e concurso/prova.
- Plano de estudos pode apontar para conjunto de questoes relacionado ao assunto estudado.
- Comentario gerado por IA fica cacheado e nao gera nova chamada automaticamente.
- Admin consegue editar o comentario gerado por IA sem perder historico da origem.

### Fase 8 - Webhooks e Sincronizacao Avancada Panda

Objetivo: manter dados atualizados automaticamente.

- Endpoint `/webhooks/panda`.
- Validacao de assinatura/token.
- Atualizar status de video.
- Atualizar thumbnail quando pronta.
- Alertar videos removidos.
- Job agendado de reconciliacao.

Criterios de aceite:

- Evento Panda atualiza aula local.
- Falhas ficam registradas.
- Admin enxerga inconsistencias.

### Fase 9 - PDF e Livro Digital

Objetivo: suportar aulas e materiais em PDF.

- Upload de PDF.
- Embed de PDF na aula.
- Controle de download.
- Extração de texto em background.
- Associar PDF a aula/curso.

Criterios de aceite:

- Admin sobe PDF.
- Aluno visualiza PDF embedado.
- Download respeita configuracao.
- Texto extraido fica disponivel para IA.

### Fase 10 - IA para Aulas e PDFs

Objetivo: enriquecer conteudo com IA.

- Estrutura `ai_artifacts`.
- Jobs de resumo.
- Jobs de mapa mental HTML/CSS.
- Jobs de questoes.
- Revisao/aprovacao no admin.
- Exibicao na aula.
- Tira-duvidas por aula/curso.

Criterios de aceite:

- Admin gera resumo de uma aula.
- Admin aprova conteudo.
- Aluno visualiza resumo/mapa/questoes.
- Aluno tira duvida limitada ao conteudo do curso.

### Fase 11 - Preparacao SaaS

Objetivo: deixar arquitetura pronta para multiplas organizacoes.

- Mapear tabelas que receberiam `organization_id`.
- Separar configuracoes por organizacao.
- Preparar integracoes por organizacao.
- Criar politicas de acesso por tenant.
- Avaliar billing/planos futuramente.

Criterios de aceite:

- Documento de migracao para SaaS.
- Entidades novas ja nascem com baixo acoplamento.
- Integracoes nao ficam hardcoded para uma unica conta.

## Ordem Recomendada Agora

1. Revisar este documento.
2. Fechar modelo de dados da Fase 1.
3. Criar migrations de cursos, modulos e aulas.
4. Criar admin CRUD basico.
5. Criar telas aluno: home, meus cursos, pagina do curso e aula.
6. So depois entrar em Panda/IA/planilha, para nao misturar fundacao com integracao.

## Riscos e Cuidados

- Panda: confirmar oficialmente endpoints disponiveis para listar pastas, videos, thumbnails, transcricoes e webhooks.
- IA: custos podem crescer rapido; usar filas, cache, aprovacao e limites por aluno.
- PDF: extracao de texto pode falhar em PDF escaneado; prever OCR futuramente.
- Questoes em PDF: parsing automatico pode ser imperfeito; sempre ter revisao humana.
- Plano de estudos: manter compatibilidade com planos ja criados.
- Progresso: separar progresso de aula, progresso de curso e progresso do plano.
- Checkout: curso bloqueado deve ter URL de compra configuravel.
- LGPD: conversas de IA e progresso do aluno sao dados sensiveis de comportamento de estudo.
- Comunidade: exigir moderacao, denuncia, remocao logica e consentimento antes de compartilhar progresso publicamente.

## Pagamentos, Assinaturas e Matriculas

Esta etapa deve entrar depois que catalogo, aulas, progresso, home e matriculas basicas estiverem estaveis. A ideia e permitir venda direta com checkout transparente, matricula automatica e suporte a alunos vindos de plataformas anteriores.

### Objetivos

- Integrar gateway de pagamento para checkout transparente dentro da plataforma.
- Criar produtos/ofertas vinculados a cursos ou planos de acesso.
- Matricular automaticamente alunos apos pagamento aprovado.
- Criar perfil de assinante com acesso a todos os cursos, semelhante ao comportamento de assinante nos Planos de Estudos.
- Permitir matricula manual pelo admin.
- Permitir importacao de alunos e matriculas vindas de Tutory, Hotmart e outras plataformas.
- Manter historico de origem da matricula para auditoria e suporte.

### Conceitos

- **Produto**: item comercial vendido, que pode liberar um curso especifico, um pacote de cursos ou uma assinatura.
- **Oferta**: variacao comercial de um produto, com preco, periodo, parcelas, cupom ou campanha.
- **Assinatura**: acesso recorrente que pode liberar todos os cursos ou um conjunto de cursos.
- **Perfil assinante**: status do usuario que concede acesso amplo aos cursos publicados, enquanto a assinatura estiver ativa.
- **Matricula automatica**: matricula criada por evento de pagamento aprovado.
- **Matricula manual**: matricula criada pelo admin para casos internos, cortesia, suporte ou migracao.
- **Matricula importada**: matricula criada por arquivo ou integracao com plataforma anterior.

### Entidades Recomendadas

1. `commerce_products`
   - `id`
   - `title`
   - `description`
   - `type`: course, bundle, subscription
   - `status`: draft, active, archived
   - `metadata`

2. `commerce_offers`
   - `id`
   - `commerce_product_id`
   - `title`
   - `price_cents`
   - `currency`
   - `billing_type`: one_time, recurring
   - `billing_interval`: monthly, quarterly, yearly, lifetime
   - `checkout_mode`: transparent, external
   - `external_checkout_url`
   - `status`
   - `metadata`

3. `product_course_access`
   - `id`
   - `commerce_product_id`
   - `course_id`
   - `access_type`: included, bonus

4. `subscriptions`
   - `id`
   - `user_id`
   - `commerce_product_id`
   - `commerce_offer_id`
   - `gateway`
   - `gateway_customer_id`
   - `gateway_subscription_id`
   - `status`: trialing, active, past_due, canceled, expired
   - `starts_at`
   - `renews_at`
   - `ends_at`
   - `metadata`

5. `orders`
   - `id`
   - `user_id`
   - `commerce_product_id`
   - `commerce_offer_id`
   - `gateway`
   - `gateway_order_id`
   - `status`: pending, paid, refused, refunded, chargeback, canceled
   - `amount_cents`
   - `currency`
   - `paid_at`
   - `metadata`

6. `payment_transactions`
   - `id`
   - `order_id`
   - `gateway`
   - `gateway_transaction_id`
   - `payment_method`: pix, credit_card, boleto
   - `status`
   - `amount_cents`
   - `payload`

7. `gateway_webhook_events`
   - `id`
   - `gateway`
   - `event_id`
   - `event_type`
   - `payload`
   - `processed_at`
   - `status`
   - `error_message`

8. `student_import_batches`
   - `id`
   - `source`: tutory, hotmart, manual_csv, other
   - `file_path`
   - `status`
   - `summary`
   - `created_by`

9. `student_import_rows`
   - `id`
   - `student_import_batch_id`
   - `email`
   - `name`
   - `course_reference`
   - `external_user_id`
   - `external_purchase_id`
   - `status`: pending, imported, skipped, failed
   - `payload`
   - `error_message`

### Evolucao de `enrollments`

Adicionar ou prever:

- `source`: admin, checkout, subscription, tutory, hotmart, import, webhook.
- `source_id`: ID da ordem, assinatura, importacao ou evento externo.
- `granted_by`: admin que concedeu acesso manualmente.
- `access_scope`: course, all_courses, bundle.
- `metadata`.

### Checkout Transparente

Fluxo desejado:

1. Aluno acessa curso bloqueado.
2. Plataforma mostra CTA de compra.
3. Aluno faz pagamento em checkout transparente.
4. Plataforma cria `order` pendente.
5. Gateway processa pagamento.
6. Webhook confirma pagamento.
7. Plataforma marca pedido como pago.
8. Plataforma cria usuario se necessario.
9. Plataforma cria matricula no curso/pacote.
10. Aluno recebe email de acesso.
11. Aluno passa a ver o curso em Meus Cursos.

### Assinante com Acesso Total

Regras:

- Usuario com assinatura ativa deve acessar todos os cursos publicados elegiveis.
- Curso pode ter flag `included_in_subscription`.
- Admin pode excluir cursos especificos da assinatura, se necessario.
- Quando assinatura expira/cancela, acesso amplo deve ser removido ou marcado como expirado.
- Matriculas individuais compradas continuam ativas mesmo se assinatura for cancelada.

### Gateway de Pagamento

O gateway inicial ainda precisa ser escolhido. A arquitetura deve permitir trocar ou adicionar provedores.

Possiveis providers:

- Mercado Pago.
- Asaas.
- Pagar.me.
- Stripe.
- Hotmart como fonte externa de venda, quando nao for checkout transparente interno.

Requisitos tecnicos:

- Webhook com validacao de assinatura.
- Idempotencia por `event_id` e `gateway_transaction_id`.
- Logs completos de payload.
- Job de reprocessamento de evento.
- Tela admin para consultar pedidos, pagamentos e falhas.

### Importacao Tutory e Hotmart

Fluxo:

1. Admin sobe CSV/XLS exportado da plataforma anterior.
2. Sistema detecta origem: Tutory, Hotmart ou manual.
3. Sistema mapeia colunas:
   - nome;
   - email;
   - curso/produto;
   - data de compra;
   - status;
   - telefone, se houver;
   - ID externo.
4. Sistema mostra pre-visualizacao.
5. Admin escolhe criar usuarios ausentes ou apenas matricular usuarios existentes.
6. Sistema cria/atualiza usuarios.
7. Sistema cria matriculas com `source`.
8. Sistema gera relatorio de importacao.

Cuidados:

- Nao duplicar usuario pelo mesmo email.
- Nao duplicar matricula ativa no mesmo curso.
- Preservar ID externo para suporte.
- Permitir rollback logico: expirar matriculas importadas de um lote.

### Fase 12 - Commerce, Checkout e Migracao de Alunos

Objetivo: transformar a plataforma em ambiente completo de venda/acesso.

- Criar produtos e ofertas.
- Criar vinculo produto -> curso/pacote/assinatura.
- Criar pedidos e transacoes.
- Integrar primeiro gateway de pagamento.
- Implementar checkout transparente.
- Implementar webhooks de pagamento.
- Criar matricula automatica apos pagamento aprovado.
- Criar perfil de assinante com acesso a todos os cursos elegiveis.
- Criar matricula manual pelo admin.
- Criar importacao Tutory/Hotmart/manual CSV.
- Criar tela admin de pedidos, assinaturas, matriculas e importacoes.

Criterios de aceite:

- Aluno compra curso bloqueado e recebe acesso automaticamente.
- Pagamento aprovado cria pedido pago e matricula ativa.
- Assinante ativo enxerga todos os cursos elegiveis.
- Cancelamento/expiracao remove acesso de assinatura sem apagar historico.
- Admin matricula aluno manualmente.
- Admin importa alunos da Tutory/Hotmart sem duplicar usuarios/matriculas.

## Comunidade e Rede Social

Esta etapa deve entrar depois que cursos, progresso, matriculas e experiencia principal do aluno estiverem estaveis. A comunidade depende dessas bases para gerar posts de progresso confiaveis, controlar acesso por curso e recomendar grupos relevantes.

### Fase 13 - Comunidade, Grupos de Estudo e Forum

Objetivo: criar uma rede social interna para alunos acompanharem progresso, interagirem com novidades da Vencendo Concursos, formarem grupos de estudo e discutirem duvidas em forum.

Escopo principal:

- Criar feed da comunidade.
- Criar posts de texto e posts de progresso.
- Permitir curtidas em posts, comentarios, respostas e atualizacoes editoriais.
- Permitir comentarios em posts.
- Criar grupos de estudo com membros, regras de visibilidade e moderadores.
- Criar forum com categorias, topicos, respostas e marcacao de solucao.
- Criar denuncias e fila basica de moderacao.
- Criar privacidade/consentimento para compartilhamento de progresso.
- Integrar novas postagens do site `vencendoconcursos.com.br` ao feed.
- Permitir que admin destaque, oculte ou fixe itens editoriais.

Fluxo inicial:

1. Aluno conclui uma aula, modulo ou meta.
2. Plataforma sugere compartilhar progresso no feed.
3. Aluno revisa texto e escolhe visibilidade.
4. Post aparece para usuarios autorizados.
5. Outros alunos podem curtir e comentar.
6. Aluno entra em grupo de estudo recomendado.
7. Aluno cria topico no forum para duvida mais estruturada.
8. Moderador/admin acompanha denuncias e conteudos sinalizados.
9. Novas postagens do site aparecem no feed como atualizacoes curtiveis e clicaveis.

Criterios de aceite:

- Aluno cria post manual no feed.
- Aluno compartilha progresso apenas apos confirmar.
- Alunos autorizados conseguem curtir e comentar.
- Feed exibe novidades importadas do site `vencendoconcursos.com.br`.
- Aluno cria ou participa de grupo de estudo conforme regras de acesso.
- Aluno cria topico e responde no forum.
- Topico pode ser marcado como resolvido.
- Conteudo pode ser denunciado.
- Admin/moderador consegue ocultar ou remover conteudo sem apagar historico.
- Privacidade impede acesso a posts de grupos privados ou cursos nao liberados.

## Estado Implementado Ate Agora

Esta secao registra o que ja foi aplicado na plataforma ate a fase atual, para separar a visao futura do estado funcional do produto.

### Marca e posicionamento

- A plataforma deixou de ser tratada apenas como `Plano Vencendo Concursos`.
- O nome recomendado e aplicado nos pontos principais de interface e comunicacao e `Plataforma Vencendo Concursos`.
- O nome curto usado no PWA e `Plataforma VC`.
- O plano de estudos continua existindo como funcionalidade central, mas nao e mais o nome principal do produto.

### Cursos, modulos, trilhas e aulas

- Cursos podem ser montados com modulos, trilhas e aulas.
- Modulos foram tratados como entidades reutilizaveis, independentes de um curso especifico.
- Cursos acessam modulos e trilhas por vinculos, permitindo que o mesmo modulo seja usado em cursos diferentes.
- Trilhas podem variar conforme o curso, mesmo quando usam o mesmo modulo.
- Aulas podem ser vinculadas a varios cursos, modulos e trilhas.
- A estrutura de exibicao do curso no painel do aluno usa modulos em acordeon, fechados por padrao.
- Trilhas sao exibidas como cards com thumbnail em carrossel.
- O clique na trilha leva para a aula em que o aluno parou ou para a primeira aula da trilha.
- Na lista administrativa de aulas, as colunas podem ser exibidas ou ocultadas pelo seletor de colunas.
- `Curso`, `Modulo`, `Trilha` e `ID do provedor` ficam ocultos por padrao na listagem de aulas.

### Importacao por planilha

- O importador por planilha foi ajustado para montar cursos, modulos, trilhas e aulas.
- A importacao por planilha e capaz de vincular aulas existentes pelo nome, priorizando aulas que ja possuem midia.
- A correspondencia de nomes de aulas passou a ser aproximada e ignora numeracao quando necessario.
- A normalizacao de nomes remove extensoes, caracteres tecnicos de arquivo e padroniza numeracao com duas casas quando aplicavel.
- Existe comando para normalizar titulos e reorganizar vinculos:

```bash
php artisan lessons:normalize-titles --dry-run
php artisan lessons:normalize-titles
```

### Google Drive

- A integracao com Google Drive usa credenciais de service account.
- O caminho das credenciais e configuravel por `.env`, usando caminho relativo para ser reproduzivel no servidor.
- A area de aulas possui importacao pelo Drive para criar aulas avulsas, sem obrigar curso, modulo ou trilha.
- A importacao por Drive aceita curso, modulo e trilha opcionais.
- Subpastas do Drive sao processadas de forma recursiva para importar aulas dentro delas.
- Quando uma subpasta e identificada, o sistema pode criar pasta equivalente no Panda com o mesmo nome.
- A importacao do Drive roda em background via fila.
- A tela de importacoes exibe progresso, status, ultima mensagem, enviados e erros.
- Importacoes podem ser interrompidas e reprocessadas.

### Panda Video

- A plataforma cria ou reutiliza pastas no Panda, evitando duplicacao quando o nome normalizado ja existe.
- A importacao deve reaproveitar aulas e videos existentes no Panda quando possivel.
- Uploads na raiz do Panda foram bloqueados quando a pasta esperada nao foi resolvida.
- O fluxo passou a evitar duplicar aulas com midia quando ja existe aula equivalente.
- O upload de videos do Drive para o Panda roda em background.
- Foram adicionados atrasos, tentativas e backoff para reduzir gargalos e limites de upload/conversao do Panda.
- A fila de upload pode ser controlada por variaveis como:

```env
PANDA_QUEUE_DRIVE_UPLOADS=true
PANDA_VIDEO_UPLOAD_JOB_DELAY_SECONDS=120
PANDA_VIDEO_UPLOAD_JOB_BACKOFF_SECONDS=300,600,1200,2400
PANDA_VIDEO_UPLOAD_RETRY_ATTEMPTS=1
```

- O sistema registra `source_status` das aulas para indicar estrutura, upload, processamento, falha ou midia pronta.
- O status de video no Panda pode ser sincronizado para atualizar minutagem e disponibilidade da midia.
- O worker recomendado para processamento manual e:

```bash
php artisan queue:restart
php artisan queue:work --queue=default --tries=1 --timeout=900
```

### Diagnostico de falhas na importacao Panda

Ao investigar falhas de importacao de aulas pelo Panda, primeiro confirmar a origem do log. Logs do dominio principal em `httpdocs`, especialmente com caminhos de WordPress, Elementor, `xmlrpc.php` ou `wp-content/plugins`, nao pertencem a plataforma Laravel de cursos e nao devem ser tratados como falha da importacao Panda da plataforma.

Fontes corretas para diagnostico da plataforma:

- `storage/logs/laravel.log` da aplicacao `app.vencendoconcursos.com.br`.
- Registros em `panda_import_runs` e `panda_import_items`.
- Registros em `google_drive_import_runs`, quando o fluxo for Drive -> Panda.
- Status das aulas em `lessons.source_status`, `lessons.panda_status`, `lessons.panda_video_id`, `lessons.panda_embed_url`, `lessons.panda_player_url` e `lessons.metadata`.
- Saida do worker de fila responsavel por `UploadLessonToPanda` e `SyncPandaVideoStatus`.

Procedimento seguro:

1. Identificar se o erro vem da plataforma Laravel ou do site institucional WordPress.
2. Conferir se existe `panda_import_run` recente com `status`, `latest_message`, enviados, falhas e itens associados.
3. Para erro de upload/processamento, verificar `source_status` da aula:
   - `upload_queued`: aguardando worker.
   - `uploading`: envio em andamento.
   - `panda_processing`: video enviado e aguardando processamento/status.
   - `media_ready`: video pronto para aluno.
   - `upload_failed` ou status equivalente: falha que exige reprocessamento ou nova tentativa.
4. Confirmar que o worker esta rodando antes de reprocessar:

```bash
php artisan queue:restart
php artisan queue:work --queue=default --tries=1 --timeout=900
```

5. Se houver limite, timeout ou instabilidade do Panda, manter o registro local da aula e usar nova tentativa com backoff. Nao apagar aula, modulo, trilha, progresso ou vinculo com plano.
6. Se o video existir no Panda mas a aula nao estiver pronta, sincronizar status/duracao em vez de recriar a aula.
7. Se a falha for do WordPress/Elementor no dominio principal, tratar separadamente na manutencao do site institucional; isso nao deve bloquear importacao, aulas, planos ou player da plataforma.

Falha conhecida corrigida:

- Logs Apache/PHP-FPM como `AH01075: Error dispatching request to : (polling)` com referer `/admin/lessons` indicam timeout da requisicao web do admin.
- A tela `Admin > Aulas > Importar Panda` nao deve importar a pasta inteira durante a requisicao HTTP.
- O comportamento correto e criar um `panda_import_run` com status `pending`, enfileirar `ImportPandaLessons` e responder rapidamente ao admin.
- O worker deve processar a pasta Panda em segundo plano e atualizar o mesmo `panda_import_run` para `running`, `finished` ou `failed`.
- Para importacoes grandes, aumentar timeout do worker e nao do painel web. A requisicao do admin deve continuar curta.

Regras que nao podem ser quebradas durante correcao:

- A importacao Panda deve preservar a organizacao pedagogica local.
- Aulas devem ser reutilizadas por `panda_video_id` quando existir.
- Se nao houver `panda_video_id`, o match por titulo normalizado dentro do modulo/trilha pode reaproveitar placeholder sem midia.
- Reimportacao nao apaga automaticamente aulas, trilhas ou modulos ausentes no Panda.
- Duracao, thumbnail, status e URLs do Panda sao metadados tecnicos e podem ser atualizados sem duplicar aula.
- Planos de estudo devem continuar apontando para `lessons` internas, nunca diretamente para IDs externos do Panda.

Comandos operacionais recomendados apos deploy de ajustes em importacao Panda:

```bash
php artisan queue:restart
php artisan queue:work --queue=default --tries=1 --timeout=900
```

No Laravel Toolkit, usar os mesmos comandos sem o prefixo `php artisan`:

```bash
queue:restart
queue:work --queue=default --tries=1 --timeout=900
```

### Recursos de IA do Panda

- O admin pode gerar recursos de IA do Panda em massa na lista de aulas.
- O admin tambem pode gerar recursos de IA na tela de edicao de uma aula.
- O texto da acao foi padronizado como `Gerar Recursos de IA`.
- As notificacoes da lista e da edicao foram alinhadas para mostrar a mesma mensagem.
- O sistema solicita IA em `pt-BR`.
- Quando os recursos ja estao prontos, o sistema nao apaga nem reprocessa desnecessariamente.
- O sistema limpa cache/sincroniza artefatos ao gerar recursos, evitando exibir resumo antigo.
- A plataforma salva artefatos de IA como resumo, questoes, mapa mental e payload Panda.
- A pagina da aula so exibe o bloco de IA quando ha ao menos um recurso pronto: resumo, questoes, mapa mental ou Tutor disponivel.
- As abas do bloco de IA mostram apenas recursos realmente disponiveis.
- O resumo nao exibe mais minutagem inline, para preservar leitura.
- O aluno pode baixar o resumo em PDF quando o resumo de IA esta disponivel.
- O PDF do resumo contem capa, nome da plataforma, curso, aula, conteudo formatado e logo/fallback visual da Vencendo Concursos.
- O gerador de PDF foi ajustado para tratar acentos e caracteres especiais em portugues.
- Titulos longos no PDF quebram dentro da margem.

### Tutor IA do Panda

- O admin pode ativar Tutor IA em massa pela lista de aulas.
- O admin tambem pode ativar Tutor IA pela tela de edicao da aula.
- A mensagem padrao configurada e:

```txt
Converse com a tutora LilIA
```

- A ativacao usa endpoints do Panda Assist/Tutor configuraveis por `.env`.
- A plataforma verifica o assistente do Tutor e a visibilidade do chat.
- O Tutor so e marcado como disponivel quando o Panda indica que o assistente e o video estao prontos.
- Se o Panda retorna `assistant_id`, mas o video do Tutor ainda esta `processing`, a aba `Tirar duvidas` fica oculta para o aluno.
- Isso evita exibir chat que ainda nao consegue responder.

### Indicadores administrativos

- A lista de aulas possui flags visuais para recursos de IA:
  - `Completa`
  - `Parcial`
  - `Gerando`
  - `Sem IA`
- A lista de aulas possui flags visuais para Tutor:
  - `Ativo`
  - `Processando`
  - `Solicitado`
  - `Falhou`
  - `Sem Tutor`
- Essas flags facilitam auditoria editorial e operacional do que ja esta liberado para alunos.

### Experiencia do aluno

- O bloco de IA da aula fica oculto quando nao ha recurso gerado.
- O aluno nao ve abas vazias de resumo, questoes, mapa mental ou Tutor.
- A trilha no curso funciona como entrada para continuidade de estudos.
- O painel do aluno integra video, progresso, material de estudo, IA e Tutor quando disponiveis.
- O progresso do plano de estudos pode ser atualizado conforme aulas publicadas e vinculadas.

### Registro de ajuste - plano, trilha e minutagem

Data: 2026-09-09.

- A fonte confiavel para a trilha lateral da pagina da aula e a pivot `study_plan_item_lessons`.
- Quando um item do plano tiver aulas reais vinculadas, a fonte confiavel de minutagem exibida no plano e `lessons.duration_seconds`, ou seja, a duracao real do video/aula.
- O plano nao deve mostrar aulas parciais nem criar entradas de `Continuacao:`. A aula e indivisivel: se nao couber inteira no bloco atual, deve ficar para o proximo bloco da mesma materia, e os minutos restantes do dia devem ir para resolucao de questoes e revisao.
- Quando a aula for aberta a partir do plano, a navegacao precisa preservar `plan_id` e `plan_item_id`, pois a mesma aula pode aparecer em mais de um dia. A lateral da aula deve usar esse item como fonte da verdade e espelhar exatamente o bloco/dia clicado.
- A tela do plano pode calcular pre-visualizacao por modulo/trilha para itens ainda nao vinculados, mas deve preferir a aula real assim que houver vinculo.
- A sincronizacao de plano deve preferir `lesson_id` quando disponivel e usar comparacao por nome apenas como fallback.
- A edicao manual de um dia do plano deve atualizar tambem `study_plan_item_lessons`, nao apenas `study_plan_items`.
- Para planos ativos antigos com cronograma incorreto, usar `php artisan study-plans:refresh-active --from-date=YYYY-MM-DD` para regenerar os itens nao concluidos a partir da data afetada. Use `--dry-run` antes em producao.
- Ao mexer em geracao, reequilibrio ou edicao manual do plano, validar em conjunto:
  - `/dashboard/plano/{id}`
  - `/dashboard/cursos/{course:slug}/aulas/{lesson}`

### Cache, deploy e operacao

- Apos alteracoes de configuracao, rotas, views ou nome da aplicacao, usar:

```bash
php artisan optimize:clear
```

- Em ambiente de testes/producao, apos deploy:

```bash
php artisan migrate --force
php artisan queue:restart
php artisan optimize:clear
```

- Quando houver build de frontend:

```bash
npm install
npm run build
php artisan optimize
```

## Checklist de Decisoes Pendentes

- [ ] Confirmar nomes finais das tabelas.
- [ ] Confirmar se `courses` atual do plano pode ser reaproveitada ou se precisa ser separada de `online_courses`.
- [ ] Confirmar formato oficial da planilha.
- [ ] Confirmar provider inicial de IA.
- [ ] Confirmar endpoints reais do provedor de video.
- [ ] Confirmar onde thumbnails e PDFs serao armazenados.
- [ ] Confirmar taxonomia oficial do banco de questoes: banca, ano, prova/concurso, orgao, cargo, esfera e escolaridade.
- [ ] Confirmar lista inicial de bancas e aliases: Vunesp, Cebraspe/Cespe, FGV, FCC, Instituto AOCP, Quadrix, etc.
- [ ] Confirmar se concurso/prova sera obrigatorio apenas em simulados ou sempre opcional.
- [ ] Confirmar regra de matricula vinda de checkout/webhook.
- [ ] Confirmar se curso bloqueado aparece para todos ou apenas por regras de catalogo.
- [ ] Confirmar se aulas importadas do Panda entram publicadas ou como rascunho.
- [ ] Confirmar gateway inicial para checkout transparente.
- [ ] Confirmar regra comercial do assinante com acesso total.
- [ ] Confirmar formato dos arquivos de importacao Tutory e Hotmart.
- [ ] Confirmar quais cursos entram ou nao no plano de assinatura.
- [ ] Confirmar regras de privacidade para compartilhamento de progresso na comunidade.
- [ ] Confirmar quem pode criar grupos de estudo.
- [ ] Confirmar se o forum sera global, por curso ou hibrido.
- [ ] Confirmar fonte tecnica para novidades do `vencendoconcursos.com.br`: RSS, API, WordPress REST API ou cadastro manual.
- [ ] Confirmar politica de moderacao, denuncia e remocao logica de conteudo social.
