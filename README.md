# ClinicaIdeiaApi

API PHP/MySQL para calendario de disponibilidade, soft lock de horarios e confirmacao de reservas do Instituto Ideia.

## Stack

- PHP 8.1+
- MySQL 8+ ou MariaDB 10.5+
- PDO MySQL
- Sem framework pesado
- Autoload PSR-4 simples

## Estrutura

```txt
config/                 Configuracao de rotas e CORS
database/migrations/    Schema MySQL
database/seeds/         Dados iniciais
public/                 Entry point HTTP
src/Core/               Infra: DB, Router, Request, Response, Env
src/Controllers/        Controllers HTTP
src/Repositories/       SQL e persistencia
src/Services/           Regras de negocio
storage/logs/           Logs locais
```

## Deploy Hostinger

Opcao simples:

1. Suba o conteudo desta pasta para `public_html/api`.
2. Copie `.env.example` para `.env`.
3. Configure credenciais MySQL da Hostinger.
4. Importe `database/migrations/001_schema.sql`.
5. Importe `database/seeds/001_seed_rooms.sql`.
6. Acesse `https://seu-dominio.com/api/health`.

## Desenvolvimento local

PHP portatil instalado neste projeto:

```txt
C:\ClinicaIdeiaApi\tools\php\php.exe
```

Subir API local:

```powershell
C:\ClinicaIdeiaApi\tools\php\php.exe -S 127.0.0.1:8080 -t public public/index.php
```

No front React, configure:

```env
VITE_API_BASE_URL=http://127.0.0.1:8080/api
```

## Endpoints

```txt
GET  /health
GET  /rooms
GET  /availability?sala_id=<uuid>&date=YYYY-MM-DD
POST /reservations/lock
POST /reservations/confirm
GET  /admin/reservations
GET  /admin/reservations/day?date=YYYY-MM-DD
POST /admin/slots/generate
POST /admin/reservations/confirm
POST /admin/reservations/confirm-by-id
POST /admin/reservations/cancel
POST /admin/reservations/update
POST /admin/slots/block
POST /admin/slots/unblock
```

Acoes admin sobre reservas:

- `confirm-by-id` `{ reserva_id }`: confirmacao manual (cliente sem o aparelho original).
- `cancel` `{ reserva_id }`: cancela/recusa; a reserva vira `cancelada` e o slot volta a `livre`.
- `update` `{ reserva_id, cliente_nome?, cliente_whatsapp?, plano? }`: edita dados do cadastro (reserva + slot); campos omitidos nao mudam.
- `day?date=`: todos os cadastros do dia (planilha do painel).

## Soft lock

Ao clicar em um horario livre, o front chama `POST /reservations/lock`.
O MySQL faz um `UPDATE` atomico somente se o slot estiver livre ou com lock expirado.
Se duas pessoas clicarem ao mesmo tempo, apenas uma atualizacao afeta 1 linha. A outra recebe `409`.

## Regra de calendario

- O bloqueio acontece em `agenda_slots`.
- Cada slot representa exatamente uma combinacao de sala + data + horario.
- Podem existir varios cadastros no mesmo dia.
- Nao podem existir dois cadastros ativos para a mesma sala no mesmo horario.
- Quando um slot e travado, a API tambem grava o cadastro completo em `reservas`.
- Slots com `lock_temporario`, `confirmada` ou `bloqueada_admin` devem aparecer bloqueados/vermelhos no calendario.
- Locks expirados voltam para `livre` automaticamente na proxima consulta.

## Exemplo: lock

```json
{
  "slot_id": "uuid-do-slot",
  "cliente_nome": "Maria",
  "cliente_whatsapp": "+55 34 9971-0952",
  "plano": "Light - Hora avulsa"
}
```

## Confirmacao por codigo

Ao travar um slot, a API gera um codigo de 6 digitos gravado na reserva.
O codigo nunca aparece na resposta publica: somente em `GET /admin/reservations` (painel da equipe).
Fluxo: cliente cadastra -> paga o PIX no WhatsApp -> equipe envia o codigo -> cliente confirma no site.

```txt
POST /reservations/confirm
{ "reserva_id": "uuid-da-reserva", "codigo": "123456" }
```

Regras:

- Codigo errado: 422 (maximo 5 tentativas por reserva, depois 429).
- Lock expirado: 409, o slot volta para livre.
- Codigo certo: reserva e slot viram `confirmada` (bloqueio permanente na agenda).

## Headers admin

Rotas `/admin/*` exigem:

```txt
X-Admin-Token: <token da equipe>
```

O `.env` guarda de preferencia apenas o hash do token (`ADMIN_TOKEN_HASH`),
gerado com:

```txt
php -r "echo hash('sha256', 'SEU-TOKEN-AQUI');"
```

Assim, quem ler o `.env` nao descobre a senha. Quando `ADMIN_TOKEN_HASH`
existe, `ADMIN_TOKEN` (texto puro) e ignorado.
