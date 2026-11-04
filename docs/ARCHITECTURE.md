# Architecture & data flow

```
MT5 bridge (/health /symbols /klines /tick, read-only)
  → CandleProvider → MarketDataService → trading_candles (unique symbol/tf/time)
  → MarketSnapshotJob → market_snapshots
  → scheduler (bot:sync-candles every minute, M1 close triggers order/position eval)
  → AgentTasks (lease + dedupe + retry + expiry) → 10 agents via OpenRouterService
  → TradeDecision → ProposalService → RiskManagementService (deterministic)
  → approved → paper_orders (PENDING, expires_at)
  → PaperBrokerService (completed candles, conservative ambiguity)
  → paper_positions (OPEN, SL/TP/max-hold) → close → trade_analyses
  → PerformanceService → Learning (proposals → backtest → walk-forward)
  → queued broadcast events → Reverb-compatible Pusher → admin UI (Echo)
```

## Key decisions

- **One class per file** everywhere PSR-4 autoloading applies; the only
  exception is `app/Events/TradingEvents.php`, explicitly required in
  `AppServiceProvider` (documented, intentional).
- **MySQL is the source of truth.** WebSockets are live hints; every view
  hydrates from APIs first and reconnects with backoff.
- **Freshness gate:** `bot:health` marks the bot DEGRADED on stale/missing
  M1 data; `validateProposal` is additionally gated by the caller on
  `isFresh()` before dispatching trade-decision tasks (enforced in
  `MarketInitializationJob` + scheduler chain).
- **Costs:** spread halves modeled per side; commission per lot from
  `trading_symbols`; ledger is append-only (`paper_transactions`).
- **Weekends:** Forex market closed Sat/Sun (UTC) is reported distinctly
  from stale feeds so the bot degrades only on real outages.
