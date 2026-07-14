# ClinicaIdeiaApi

API PHP/MySQL para calendario de disponibilidade, soft lock de horarios e confirmacao de reservas do Instituto Ideia.

Repositorio: `https://github.com/pavarinekristen/ClinicaPsicoApi`
Branches: `main` (producao) e `developer` (homologacao). Trabalho novo entra por `feat/*` -> PR -> `developer` -> `main`.

## Estado atual (producao)

No ar em **https://clinicaideia.com.br/api** (PHP 8.2 + MySQL da Hostinger). Login nominal
(usuario `Nilza`), auditoria ativa, agenda com horarios gerados ate 31/12/2026.

**Deploy AUTOMATICO ligado**: cada `push` na `main` faz a Hostinger dar `git pull` sozinha em
`public_html/api` (Git nativo da Hostinger + Webhook do GitHub — testado e funcionando). Sem
build, sem passo manual. Passo a passo completo em `DEPLOY.md`.

O `.env` de producao (credenciais, `APP_KEY`, hash da senha) vive **so no servidor** e nunca vai
para o repositorio. O front (site) tem deploy separado — ver `FrontSitePsico`.

## Stack

- PHP 8.1+
- MySQL 8+ ou MariaDB 10.5+ (dev: MariaDB 12.3 local na porta 3317)
- PDO MySQL (sem framework pesado, autoload PSR-4 simples)

## Estrutura

```txt
config/                 Configuracao de rotas e CORS
database/migrations/    Schema MySQL
database/seeds/         Dados iniciais
public/                 Entry point HTTP (index.php + .htaccess)
src/Core/               Infra: DB, Router, Request, Response, Env, Validator, RateLimiter
src/Controllers/        Controllers HTTP (Auth, Admin, Availability, Reservation, Room, Health)
src/Repositories/       SQL e persistencia (Slot, Room, Audit)
src/Services/           Regras de negocio (Availability, Reservation, Auth)
storage/logs/           Logs locais (inclui app-errors.log)
storage/cache/          Estado do rate limiter (nao versionado)
```

## Autenticacao do painel (login nominal + auditoria)

O acesso ao `/admin/*` e feito por **usuario + senha** (nao ha mais token compartilhado).

Fluxo:

1. `POST /admin/login { username, password }` valida a credencial e devolve um **token de sessao assinado** (HMAC, valido 12h).
2. As demais rotas `/admin/*` exigem o header `Authorization: Bearer <token>`.
3. A cada acao que altera dados de paciente (confirmar, cancelar, editar, bloquear, gerar slots) e a cada login, uma linha e gravada em `audit_log` (quem, acao, alvo, IP).

Configuracao no `.env`:

```env
APP_KEY=<segredo aleatorio para assinar o token>   # php -r "echo bin2hex(random_bytes(32));"
ADMIN_USERNAME=<usuario do painel>
ADMIN_PASSWORD_HASH=<hash bcrypt da senha>          # php -r "echo password_hash('SUA-SENHA', PASSWORD_DEFAULT);"
```

A senha nunca fica em texto no servidor: guarda-se apenas o hash bcrypt. Sem `APP_KEY`, o login fica desabilitado (falha fechado).

Protecoes: lockout por IP de **10 tentativas / 15 min** (429) no login e nas rotas admin, alem de atraso de ~350ms por 401 e comparacao constant-time.

## Endpoints

```txt
# Publicos
GET  /health
GET  /rooms
GET  /availability?sala_id=<uuid>&date=YYYY-MM-DD
GET  /availability/range?sala_id=<uuid>&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
POST /reservations/lock
POST /reservations/confirm            { reserva_id, codigo }

# Autenticacao do painel
POST /admin/login                     { username, password } -> { token, username, expires_at }

# Admin (exigem header Authorization: Bearer <token de sessao>)
GET  /admin/reservations
GET  /admin/reservations/day?date=YYYY-MM-DD
POST /admin/slots/generate
POST /admin/reservations/confirm            (legado: slot_id + lock_token)
POST /admin/reservations/confirm-by-id      { reserva_id }  confirmacao manual
POST /admin/reservations/cancel             { reserva_id }  cancela/recusa; slot volta a livre
POST /admin/reservations/update             { reserva_id, cliente_nome?, cliente_whatsapp?, plano? }
POST /admin/slots/block                     { slot_id, reason }
POST /admin/slots/unblock                   { slot_id }
```

## Soft lock

Ao clicar em um horario livre, o front chama `POST /reservations/lock`.
O MySQL faz um `UPDATE` atomico somente se o slot estiver livre ou com lock expirado.
Se duas pessoas clicarem ao mesmo tempo, apenas uma atualizacao afeta 1 linha; a outra recebe `409`.

## Regra de calendario

- O bloqueio acontece em `agenda_slots`; cada slot = sala + data + horario.
- Para calendario semanal/mensal, use `/availability/range`; a API agrupa os slots por data local em `slots_by_date` e limita cada consulta a 62 dias.
- Podem existir varios cadastros no mesmo dia, mas nunca dois ativos para a mesma sala no mesmo horario (`UNIQUE (sala_id, slot_inicio)`).
- Quando um slot e travado, a API grava o cadastro completo em `reservas`.
- Pacotes mensais podem ter horarios nao consecutivos; a API exige apenas mesma sala, mesmo mes local e todos os slots livres.
- Slots com `lock_temporario`, `confirmada` ou `bloqueada_admin` aparecem bloqueados/vermelhos.
- Locks expirados voltam para `livre` automaticamente na proxima consulta.

Rotina recomendada para cron na hospedagem:

```bash
php /caminho/da/api/scripts/cleanup-expired-locks.php
```

Essa rotina libera locks expirados mesmo sem acesso de usuarios e registra o resultado em `storage/logs/cleanup-expired-locks.log`.

## Confirmacao por codigo

Ao travar um slot, a API gera um codigo de 6 digitos gravado na reserva.
O codigo nunca aparece na resposta publica: somente em `GET /admin/reservations` (painel da equipe).
Fluxo: cliente cadastra -> paga o PIX no WhatsApp -> equipe envia o codigo -> cliente confirma no site.

Regras: codigo errado 422 (maximo 5 tentativas por reserva, depois 429); lock expirado 409; codigo certo -> reserva e slot viram `confirmada`.

## Seguranca implementada

- **SQL injection**: 100% prepared statements (PDO, `EMULATE_PREPARES=false`).
- **Autenticacao**: login nominal usuario+senha (bcrypt), token de sessao assinado (HMAC/`APP_KEY`), lockout por IP.
- **Auditoria (LGPD)**: `audit_log` registra as acoes da equipe sobre dados de paciente.
- **CORS fail-closed**: sem `*`; so libera origens da allowlist (`CORS_ALLOWED_ORIGINS`).
- **Erros**: stack trace vai para `storage/logs/app-errors.log`; o cliente so recebe detalhes com `APP_ENV=local`.
- **Rate limit publico**: maximo 3 pre-reservas ativas por IP (429).
- **`.htaccess`**: forca HTTPS + HSTS, `Permissions-Policy`, bloqueia `.mysql-data/`, `.git/`, `config/`, `src/`, `database/`, `storage/`, `tools/`, `docs/` e dotfiles.
- IDs publicos em UUID (nao enumeraveis).

## Migracoes

```txt
001_schema.sql            salas, agenda_slots, reservas
002_add_reservas.sql      (legado) tabela reservas
003_add_confirm_code.sql  confirm_code CHAR(6) + confirm_attempts em reservas
004_add_created_ip.sql    created_ip VARCHAR(45) + indice (rate limit por IP)
005_add_audit_log.sql     tabela audit_log (trilha de auditoria do painel)
006_add_reserva_slots.sql tabela de ligacao reserva -> varios horarios
007_add_reserva_slot_status.sql status/cancelamento individual por horario
008_add_reserva_dados_profissionais.sql dados do cadastro profissional
009_add_payment_acceptance.sql pagamento PIX + aceite legal
010_add_schedule_performance_indexes.sql indices para calendario/painel
```

## Deploy Hostinger

1. Suba **apenas o codigo** para `public_html/api` (nunca `.env`, `.mysql-data/` nem `tools/`).
2. Copie `.env.example` para `.env` e configure:
   - `APP_ENV=production`, `APP_DEBUG=false`
   - credenciais MySQL da Hostinger
   - `APP_KEY` novo (gerado no servidor)
   - `ADMIN_USERNAME` + `ADMIN_PASSWORD_HASH`
   - `CORS_ALLOWED_ORIGINS=https://seu-dominio.com.br`
3. Importe as migracoes `001` -> `010` na ordem e o seed `001_seed_rooms.sql`.
4. Garanta HTTPS/SSL ativo.
5. Acesse `https://seu-dominio.com/api/health`.

## Desenvolvimento local

PHP portatil: `C:\ClinicaIdeiaApi\tools\php\php.exe`

```powershell
# Banco: MariaDB 12.3 local, datadir .mysql-data, porta 3317, banco clinica_ideia
& "C:\Program Files\MariaDB 12.3\bin\mysqld.exe" --datadir=C:\ClinicaIdeiaApi\.mysql-data --port=3317

# API local (http://127.0.0.1:8091/api/health)
C:\ClinicaIdeiaApi\tools\php\php.exe -S 127.0.0.1:8091 -t public public/index.php
```

No front, use `VITE_API_BASE_URL=http://127.0.0.1:8091/api`.
