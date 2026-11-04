# LLM-Driven Autonomous Forex Paper Trading System

Autonomous LLM-driven paper-trading platform (Laravel 12 + MySQL). An internal
paper broker is the **only** execution environment — MT5 is used exclusively
for historical/live candle data via the MT5 API bridge. OpenRouter provides
all LLM intelligence. Real-time UI updates flow through the existing
Reverb-compatible Pusher WebSocket server.

## Quick start

1. `cp .env.example .env` and fill infrastructure values (DB, MT5 bridge,
   Pusher/Reverb, mail). `php artisan key:generate`.
2. Create MySQL database, then `php artisan migrate --force && php artisan db:seed`.
3. Default admin: `admin@example.com / ChangeMe123!` (change immediately).
4. `npm install && npm run build`.
5. Start scheduler cron: `* * * * * php /path/artisan schedule:run`.
6. Start Supervisor workers (see `deploy/supervisor.conf`), then open the
   admin panel and configure the OpenRouter API key under Settings.
7. Press **Start** in Settings → Bot control.

## Architecture

- `app/Services/MT5/CandleProvider.php` — read-only bridge client
  (`GET /health` open; `/symbols`, `/klines`, `/tick` via `X-API-Key`).
  Bridge intervals are lowercase (`1m…1mo`); mapped from `M1…MN1`.
  `startTime/endTime` are **milliseconds**. Never sends orders.
- `app/Services/MarketData/MarketDataService.php` — cursor sync, gap
  detection, batch upserts, completed-candle preference, snapshots, health.
  **Automatic timeframe selection**: each symbol carries an
  `analysis_timeframe` pinned by the discovery agent from the deterministic
  `timeframe.regime_map` (TRENDING→H4, RANGING→M15, VOLATILE→H1).
  Execution and SL/TP monitoring always stay on M1.
- `app/Services/System/AppLogger.php` — dual logging to Monolog channels
  (`trading.log`, `agents.log`, 30-day rotation) AND the `system_logs` table
  (admin System Logs page), with secret redaction.
- `app/Agents/*` — 10 agents (discovery, news, currency, analysis,
  strategy, decision, management, performance, improvement, chief) with
  persisted `agent_tasks` (lease, retry, dedupe, expiry).
- `app/Services/PaperBroker/PaperBrokerService.php` — limit fills from
  completed-candle OHLC, conservative ambiguity (SL wins ties), atomic
  order→position, SL/TP/max-hold monitoring, idempotent closes.
- `app/Services/Risk/RiskManagementService.php` — deterministic limits,
  lot sizing from equity × risk% ÷ stop distance, kill switch.
- `app/Services/LLM/OpenRouterService.php` — JSON-mode chat, retries,
  budget cap, token/cost logging. No key is ever sent to the browser.
- `app/Services/Learning/*` — deterministic metrics, EMA-crossover
  backtester with 70/30 walk-forward, candidate promotion workflow.
- Broadcasting: queued `ShouldBroadcast` events on `private-*` channels
  (admin-only auth in `routes/channels.php`).

## Safety rules enforced in code

MT5 order execution does not exist in this codebase. LLM agents cannot
mutate financial records; only `PaperBrokerService` does, inside DB
transactions. Every order has expiry; every position has SL + max-hold.
Stale market data blocks new entries and degrades the bot status.
Strategy improvements require walk-forward validation before promotion.

## Docs

- `docs/DEPLOY.md` — server setup, Supervisor, cron, queues.
- `docs/ARCHITECTURE.md` — modules, data flow, acceptance mapping.
- `docs/API.md` — bridge contract + internal JSON endpoints.
- `docs/PROMPTS.md` — agent prompts and versioning.
- `docs/TROUBLESHOOTING.md` — operations runbook.
