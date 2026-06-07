# Plano de Estudos Vencendo Concursos

Dashboard web em Laravel para geração de planos de estudo personalizados para alunos da Vencendo Concursos. O projeto usa Blade, Livewire, Tailwind, Filament Admin e PWA básico.

## Stack

- Laravel 13
- PHP 8.3+
- SQLite no desenvolvimento local
- MySQL/MariaDB em produção
- Blade
- Livewire
- Tailwind CSS
- Filament Admin
- Vite
- PHPUnit

## Requisitos locais

- PHP 8.3+ com `pdo_sqlite` e `sqlite3`
- Composer
- Node.js 20+
- NPM

No Ubuntu, se o SQLite do PHP ainda não estiver habilitado:

```bash
sudo apt install php8.4-sqlite3
```

## Instalação local

```bash
git clone https://github.com/dsilvadori/plano.git
cd plano
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm run dev
php artisan serve
```

Acesse em:

```text
http://127.0.0.1:8000
```

## Configuração do `.env`

O `.env.example` já vem preparado com:

```env
APP_NAME="Plano Vencendo Concursos"
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=sqlite

MAIL_MAILER=log

QUEUE_CONNECTION=sync

TUTORY_WEBHOOK_SECRET=secret-local
TUTORY_WEBHOOK_URL="${APP_URL}/webhooks/tutory"
```

Para o banco SQLite local:

```bash
touch database/database.sqlite
```

Se necessário, aponte explicitamente:

```env
DB_DATABASE=/caminho/absoluto/para/database/database.sqlite
```

## Migrations e seeders

```bash
php artisan migrate --seed
```

Seeders incluídos:

- Admin padrão
- Curso demo `Gabaritando Prefeitura de Santos`
- Módulos demo
- Trilha demo `Trilha Equilibrada`

## Acesso admin local

```text
URL: http://127.0.0.1:8000/admin
E-mail: admin@vencendoconcursos.com.br
Senha: password
```

## Fluxo do aluno

- Login em `/login`
- Dashboard em `/dashboard`
- Criação de plano em `/dashboard/plano/novo`
- Visualização do plano em `/dashboard/plano/{id}`

## Webhook da Tutory

Endpoint local:

```text
POST http://127.0.0.1:8000/webhooks/tutory
```

Endpoint de produção:

```text
POST https://plano.vencendoconcursos.com.br/webhooks/tutory
```

Autenticação:

- `Authorization: Bearer secret-local`
- ou `X-Tutory-Secret: secret-local`

Exemplo de cURL:

```bash
curl -X POST http://127.0.0.1:8000/webhooks/tutory \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer secret-local" \
  -d '{
    "event_id": "evt_123",
    "event_type": "purchase.approved",
    "purchase": {
      "id": "purchase_123",
      "status": "approved",
      "product_id": "gabaritando-prefeitura-santos",
      "product_name": "Gabaritando Prefeitura de Santos",
      "student": {
        "name": "João da Silva",
        "email": "joao@email.com",
        "phone": "11999999999"
      }
    }
  }'
```

Quando o curso existir pelo `tutory_product_id`, o sistema:

- registra o evento
- cria ou atualiza o aluno
- vincula o curso ao aluno
- envia e-mail de criação de senha usando o fluxo nativo de reset

## Testes

Arquivo `.env.testing` incluído com SQLite em memória.

```bash
php artisan test
```

Cobertura mínima implementada:

- acesso admin
- bloqueio de aluno no `/admin`
- redirect de convidado para login
- segurança do webhook
- criação e idempotência do webhook
- acesso do aluno ao dashboard
- geração de plano
- proteção de plano por owner
- marcação de tarefa concluída
- geração de ciclos semanais

## Build de produção

```bash
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Deploy em Plesk / Napoleon

- Aponte o document root para `public`
- Nunca aponte para a raiz do Laravel
- Configure `APP_ENV=production`
- Configure `APP_DEBUG=false`
- Troque `DB_CONNECTION=sqlite` por `mysql`
- Configure SMTP real
- Garanta permissões de escrita em `storage` e `bootstrap/cache`

Exemplo de variáveis de produção:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://plano.vencendoconcursos.com.br
TUTORY_WEBHOOK_URL=https://plano.vencendoconcursos.com.br/webhooks/tutory

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="Vencendo Concursos"

QUEUE_CONNECTION=sync

TUTORY_WEBHOOK_SECRET=
```

## PWA

O projeto inclui:

- `public/manifest.webmanifest`
- `public/service-worker.js`

O app abre normalmente no navegador e pode ser instalado como PWA básico.
