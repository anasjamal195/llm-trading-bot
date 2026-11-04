# Troubleshooting runbook

- **Bot stuck INITIALIZING** — check `system_health_logs` component `init`
  and bridge `/health`. Restart via Stop → Start (idempotent; never
  duplicates state).
- **DEGRADED status** — `bot:health` found stale/missing M1 candles.
  Weekends are expected (market closed). Otherwise check bridge, then
  `candle_sync_states.last_error`.
- **No agent output** — OpenRouter key missing/invalid (`model_usage_logs`
  status FAILED), or daily budget hit (`openrouter.daily_budget`), or bot
  not RUNNING (`bot:dispatch-agents` only runs when RUNNING).
- **Orders never fill** — normal: limits fill only when completed candles
  touch entry. Check `pending_orders` expiry and spread assumptions.
- **Worker crash mid-fill** — safe: fills/closes are row-locked
  transactions; `bot:expire-recover` reaps stale RUNNING task leases.
- **Duplicate scheduler runs** — commands use `withoutOverlapping`;
  critical sections additionally use `lockForUpdate` leases.
- **P&L mismatch** — reconcile `paper_transactions` (append-only) against
  `trading_accounts`; `paper_account_snapshots` + `DailyReportJob`.
- **WS silent** — check `VITE_PUSHER_*` build-time values (`npm run build`
  after changing them), `/broadcasting/auth` (admin session), and the
  external Reverb-compatible server status.
