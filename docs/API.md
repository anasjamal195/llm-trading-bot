# API reference

## MT5 bridge (external, read-only) — exact contract (verified from source)

Base: `MT5_BRIDGE_URL` (e.g. `https://mt5.graphichat.ai`). Auth: `X-API-Key`
header (or `?key=`). No rate limiting; no pagination tokens.

- `GET /health` (open) → `{"initialized":bool,"connected":bool,
  "company":str|null,"server":str|null,"last_error":[int,str]}`
- `GET /symbols?q=&limit=` (default 500, max 12226) →
  `{"count","total_matching","symbols":[str]}`. Symbols are whatever the
  broker offers; invisible ones are auto-enabled server-side.
- `GET /klines?symbol=XAUUSD&interval=1m&limit=&startTime=&endTime=`
  (`startTime/endTime` **milliseconds**; `endTime` alone is ignored).
  Intervals: `1m 2m 3m 4m 5m 6m 10m 12m 15m 20m 30m 1h 2h 3h 4h 6h 8h 12h
  1d 1w 1mo` (local `M1→1m … MN1→1mo`). Unknown symbol → 404,
  bad interval → 400. Candle:
  `{"time":sec,"open","high","low","close","tick_volume",
  "spread","real_volume","time_utc":"…Z","binance_timestamp":ms}`.
  Ordered oldest→newest, capped at 5000 per call.
- `GET /tick?symbol=` → `{"time":sec,"time_msc":ms,"bid","ask",
  "last","volume","volume_real","flags","time_utc"}`.

Errors are `{"detail":…}` with 401/404/503 as appropriate.

## Internal admin JSON

- `GET /admin/api/status` (session auth) →
  `{"bot":{"status","message"},"account":{…},"open","pending"}`.
- Pusher auth: `GET /broadcasting/auth` (default Laravel route).
- All other admin pages are server-rendered Blade hydrated by the same
  Eloquent models; live deltas arrive on `private-*` channels listed in
  `routes/channels.php`.
