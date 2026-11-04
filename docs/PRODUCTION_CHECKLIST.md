# Production readiness checklist

Complete these before exposing the bot beyond local inspection.

## Configuration
- [ ] `.env`: `APP_ENV=production`, `APP_DEBUG=false`, strong `APP_KEY`
  (already generated — keep it secret), `APP_URL` = real HTTPS URL.
- [ ] `.env` file permissions `600`, owned by the deploy user. Never commit it
  (git-ignored; verified clean with `git grep`).
- [ ] MySQL: dedicated least-privilege user (not root), strong password,
  nightly dumps (`mysqldump llm_trading_bot`), test restores.
- [ ] Change the default admin password (`admin@example.com / ChangeMe123!`).
- [ ] Real SMTP transport + real `notifications.alert_email` in Settings.
- [ ] OpenRouter: confirm per-model prices in `openrouter.pricing` against
  https://openrouter.ai/models, set a sane `openrouter.daily_budget`.
- [ ] Pusher/Reverb: production host, `wss`, restricted origins; confirm
  private channels reject unauthenticated users (`/broadcasting/auth` → 401).

## Runtime
- [ ] Install `deploy/supervisor.conf` (edit paths), `supervisorctl status`
  shows all 8 workers `RUNNING`.
- [ ] Cron: `* * * * * cd /var/www/llm-trading-bot && php artisan schedule:run`.
- [ ] `npm run build` after any frontend/env (`VITE_*`) change.
- [ ] `php artisan config:cache route:cache view:cache` on deploy; clear on
  rollback. Restart workers after every deploy (`supervisorctl restart trading:*`).

## Data & safety
- [ ] Start with a fresh paper account or explicit Reset (audit-logged).
- [ ] Review risk defaults (0.25%/trade, 2 positions, 1% daily loss) — they
  are starting parameters, not validated optima.
- [ ] Backtest any strategy/prompt change (walk-forward) before enabling.
- [ ] Monitor: System Logs page, `storage/logs/*.log` (30-day rotation),
  `failed_jobs` table, queue depth, disk space, bridge `/health`.
- [ ] Kill switch location known (Settings → Bot control → KILL).
- [ ] `DROP DATABASE laravel;` on this host only after confirming nothing
  else uses it (leftover from an early setup misconfiguration; app now uses
  `llm_trading_bot`).

## Verified in this build
- 25 tests / 87 assertions green (`./vendor/bin/phpunit`).
- Deterministic pipeline simulation green
  (`PipelineSimulationTest`: candles → snapshot → discovery/auto-TF →
  decision → risk → fill → TP close → analysis → metrics, $38.40 on fixture).
- Deterministic walk-forward backtest runs (rejects unprofitable defaults
  instead of inventing profit).
- Full admin surface returns HTTP 200 behind auth.
