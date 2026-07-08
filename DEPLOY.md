# Deploy da API (Hostinger)

A API é PHP puro, **sem Composer e sem build**. Por isso o deploy usa o
**Git nativo da Hostinger + Webhook do GitHub**: cada push na `main` faz a
Hostinger dar `git pull` na pasta de produção. Não precisa de GitHub Actions.

Pasta de produção: **`public_html/api`** (combina com `API_BASE_PATH=/api` e com o
`.htaccess` da raiz). A API responde em `https://seu-dominio.com/api/health`.

## Parte GitHub (já pronta)
- `.gitignore` isola segredos: `.env`, `.mysql-data/`, `tools/`, logs e cache **não** vão para o Git.
- `.htaccess` força HTTPS + HSTS e bloqueia `.git/`, `.env`, `src/`, `database/`, etc.
- `database/migrate.php` — runner de migrations (ver abaixo).
- Fluxo: `feat/*` → PR → `developer` → `main`. Só o que entra na `main` é publicado.

## Parte Hostinger (fazer uma vez)

### 1. Conectar o repositório (hPanel → Avançado → GIT)
- **Repository:** `git@github.com:pavarinekristen/ClinicaPsicoApi.git`
- **Branch:** `main`
- **Directory:** `public_html/api`
- Repositório é **privado**: a Hostinger mostra uma **chave SSH**. Copie e adicione no
  GitHub em **repo → Settings → Deploy keys → Add deploy key** (read-only).

### 2. Auto-deploy + Webhook
- No hPanel, ative **Auto-Deployment** e copie a **URL do Webhook**.
- GitHub → repo → **Settings → Webhooks → Add webhook**:
  - Payload URL: a URL da Hostinger
  - Content type: `application/json`
  - Evento: **Just the push event**

### 3. `.env` de produção (uma vez, via File Manager em `public_html/api/.env`)
```env
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=America/Sao_Paulo
API_BASE_PATH=/api

DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=<banco de producao>
DB_USERNAME=<usuario>
DB_PASSWORD=<senha>
DB_CHARSET=utf8mb4

CORS_ALLOWED_ORIGINS=https://seu-dominio.com.br

APP_KEY=<gerar: php -r "echo bin2hex(random_bytes(32));">
ADMIN_USERNAME=Nilza
ADMIN_PASSWORD_HASH=<gerar: php -r "echo password_hash('SENHA', PASSWORD_DEFAULT);">
LOCK_TTL_MINUTES=30
```
O `.env` fica no servidor e **sobrevive aos deploys** (o Git só toca no que é versionado).

### 4. Banco de dados
Crie o banco no hPanel → **Bancos de Dados MySQL** e monte o schema. Duas opções:

- **phpMyAdmin (simples):** importe, em ordem, `database/migrations/001..005` e o seed `database/seeds/001_seed_rooms.sql`.
- **Runner (se tiver terminal PHP na Hostinger):**
  ```bash
  php database/migrate.php status     # ver o que falta
  php database/migrate.php migrate     # aplica as pendentes (banco novo/vazio)
  ```
  Se o banco já foi montado à mão, rode antes `php database/migrate.php baseline`
  (registra as atuais sem reexecutar) e use `migrate` só para as próximas.

### 5. Conferir
- `https://seu-dominio.com/api/health` → deve responder `online`.
- Testar login: `POST /api/admin/login { "username": "Nilza", "password": "..." }`.

## Resumo
`push` na `main` → Webhook → `git pull` em `public_html/api`. `.env` e banco
permanecem intactos. Migrations novas: rodar `migrate` (ou importar no phpMyAdmin).
