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
- Integracao com Panda Video para hospedagem, player, thumbnails e importacao de aulas.
- Home customizavel pelo admin com cursos em destaque, categorias, esferas e niveis de escolaridade.

## Principios

- A plataforma local e a fonte da verdade pedagogica: cursos, modulos, aulas, materiais, vinculos com plano, acesso dos alunos, progresso e conteudos gerados por IA.
- O Panda Video e a fonte de midia: video, player, thumbnail, duracao, status de processamento e metadados tecnicos.
- O plano de estudos deve se vincular a aulas internas (`lessons`) e nunca depender diretamente do ID do Panda.
- Tudo que for importado deve ser auditavel, reversivel quando possivel e revisavel pelo admin antes de publicar.
- Preparar multi-tenant sem implementar SaaS completo no inicio: usar nomes e estruturas que permitam futura tabela `organizations` ou `tenants`.
- Nenhuma integracao externa deve bloquear a experiencia principal. Se IA/Panda falhar, curso, plano e aulas continuam acessiveis.

## Glossario Inicial

- **Curso**: produto pedagogico que agrupa modulos, aulas, PDFs, questoes e regras de acesso.
- **Modulo**: pasta/sequencia dentro de um curso. Nao precisa ter thumbnail.
- **Aula**: unidade assistivel/estudavel. Pode ser videoaula, PDF/livro digital, aula mista ou conteudo textual.
- **Material**: arquivo ou embed associado a uma aula ou curso, principalmente PDF.
- **Questao**: item interativo com enunciado, alternativas, gabarito, comentario e origem.
- **Plano de estudos**: agenda de tarefas que pode apontar para aulas, materiais e questoes.
- **Matricula**: relacao do aluno com curso liberado.
- **Catalogo/Home**: area publica/logada que destaca cursos, bloqueia cursos sem acesso e leva ao checkout.

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
   - `course_id`
   - `title`
   - `description`
   - `sort_order`
   - `panda_folder_id`
   - `metadata`

3. `lessons`
   - `id`
   - `course_id`
   - `course_module_id`
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
   - `metadata`

4. `lesson_materials`
   - `id`
   - `lesson_id`
   - `title`
   - `type`: pdf, attachment, link, transcript
   - `file_path`
   - `external_url`
   - `is_downloadable`
   - `sort_order`
   - `metadata`

5. `enrollments`
   - `id`
   - `user_id`
   - `course_id`
   - `source`: admin, webhook, import, checkout
   - `status`: active, expired, canceled
   - `starts_at`
   - `expires_at`

6. `lesson_progress`
   - `id`
   - `user_id`
   - `lesson_id`
   - `status`: not_started, in_progress, completed
   - `progress_seconds`
   - `completed_at`

7. `study_plan_items`
   - Evoluir para permitir `lesson_id`, `material_id` e `question_set_id`.
   - A tarefa do plano continua independente do player externo.

8. `course_spheres`
   - Esferas editaveis no admin: municipal, estadual, federal, tribunais, policia, educacao, fiscal, etc.

9. `education_levels`
   - Nivel/grau de escolaridade editavel no admin: fundamental, medio, tecnico, superior.

10. `home_sections`
    - Blocos customizaveis da home.
    - Exemplo: principais cursos, ultimos adicionados, federais em destaque, cursos para nivel medio.

11. `home_section_items`
    - Cursos ligados a cada secao, com ordem e regras de exibicao.

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
   - `external_type`: folder, video
   - `external_id`
   - `local_type`: module, lesson
   - `local_id`
   - `status`: created, updated, skipped, failed
   - `payload`
   - `error_message`

## Integracao com Panda Video

### Estrategia

O admin deve poder escolher um curso e sincronizar uma pasta/projeto do Panda. Cada pasta vira modulo e cada video vira aula. A thumb da aula deve vir do Panda quando disponivel.

### Fluxo Manual Inicial

1. Admin cadastra a chave da API Panda.
2. Admin abre um curso.
3. Admin escolhe `Importar do Panda`.
4. Plataforma lista pastas/videos disponiveis, se a API permitir.
5. Admin seleciona uma pasta raiz.
6. Plataforma cria uma pre-visualizacao:
   - pasta Panda -> modulo;
   - video Panda -> aula;
   - ordem;
   - duracao;
   - thumbnail;
   - status.
7. Admin confirma importacao.
8. Sistema cria/atualiza modulos e aulas.
9. Sistema registra `panda_import_run` e `panda_import_items`.

### Sincronizacao Incremental

- Videos novos no Panda criam novas aulas em rascunho ou publicadas, conforme configuracao do curso.
- Videos alterados atualizam titulo, thumb, duracao e status.
- Videos removidos no Panda nao devem apagar aulas automaticamente. Marcar como `missing_on_panda` ou exibir alerta ao admin.
- Modulos locais podem ter nome ajustado sem perder vinculo com `panda_folder_id`.

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
   - `provider`: panda, openai, manual, other
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

### Fontes

- PDF.
- XLS/XLSX.
- CSV.
- Entrada manual no admin.
- Geracao por IA a partir de aula/PDF.

### Entidades

1. `question_banks`
   - `id`
   - `course_id`
   - `title`
   - `source_type`
   - `status`

2. `questions`
   - `id`
   - `question_bank_id`
   - `course_id`
   - `lesson_id`
   - `statement`
   - `type`: multiple_choice, true_false, discursive
   - `answer_key`
   - `commentary`
   - `source_reference`
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

### Importacao

1. Admin sobe PDF ou XLS.
2. Sistema cria lote de importacao.
3. Parser identifica questoes, alternativas, gabarito e comentarios quando houver.
4. Admin revisa uma tela de pre-importacao.
5. Sistema salva questoes.
6. Questoes podem ser vinculadas a curso, modulo, aula ou plano.

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
- Lista de modulos.
- Lista de aulas por modulo.
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

### Aulas

- CRUD de aulas.
- Thumbnail da aula.
- Tipo de aula.
- Vinculo com Panda.
- Materiais.
- Status.
- Ordenacao.
- Gerar/resincronizar IA.

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

- Pagina do curso com modulos e aulas.
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

### Fase 4 - Vinculo com Plano de Estudos

Objetivo: ligar aulas ao plano atual.

- Adicionar `lesson_id` em itens do plano ou tabela pivot se uma tarefa tiver varias aulas.
- Atualizar gerador/importador do plano para associar aulas por materia/assunto/planilha.
- Exibir aulas vinculadas na tarefa do dia.
- Link direto da tarefa para aula.
- Opcional: concluir aula atualiza progresso da tarefa.

Criterios de aceite:

- Tarefa do plano mostra aulas reais.
- Clique abre aula.
- Plano continua funcionando se aula nao existir.

### Fase 5 - Importacao por Planilha

Objetivo: montar cursos pela mesma planilha do plano.

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

### Fase 6 - Integracao Panda Manual

Objetivo: importar aulas e thumbnails do Panda.

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

### Fase 7 - Webhooks e Sincronizacao Avancada Panda

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

### Fase 8 - PDF e Livro Digital

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

### Fase 9 - IA para Aulas e PDFs

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

### Fase 10 - Banco de Questoes

Objetivo: transformar PDFs/XLS em questoes interativas.

- Upload de XLS/CSV/PDF.
- Parser inicial de XLS.
- Parser assistido para PDF.
- Tela de revisao.
- Questoes com alternativas, gabarito e comentario.
- Tentativas dos alunos.
- Estatisticas por questao.

Criterios de aceite:

- Admin importa questoes de XLS.
- Aluno responde questoes.
- Sistema mostra gabarito e comentario.
- Resultado fica salvo no historico.

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

## Checklist de Decisoes Pendentes

- [ ] Confirmar nomes finais das tabelas.
- [ ] Confirmar se `courses` atual do plano pode ser reaproveitada ou se precisa ser separada de `online_courses`.
- [ ] Confirmar formato oficial da planilha.
- [ ] Confirmar provider inicial de IA.
- [ ] Confirmar endpoints reais do Panda Video.
- [ ] Confirmar onde thumbnails e PDFs serao armazenados.
- [ ] Confirmar regra de matricula vinda de checkout/webhook.
- [ ] Confirmar se curso bloqueado aparece para todos ou apenas por regras de catalogo.
- [ ] Confirmar se aulas importadas do Panda entram publicadas ou como rascunho.
- [ ] Confirmar gateway inicial para checkout transparente.
- [ ] Confirmar regra comercial do assinante com acesso total.
- [ ] Confirmar formato dos arquivos de importacao Tutory e Hotmart.
- [ ] Confirmar quais cursos entram ou nao no plano de assinatura.
