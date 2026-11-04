# Agent prompts

Prompts live in `App\Agents\*::systemPrompt()` with DB overrides in
`prompt_versions` (active version wins; see seeder). All agents must return
**JSON objects only**; `BaseAgent` validates shape before persisting, and
`OpenRouterService` attempts `{…}` recovery on noisy output.

Capabilities/limits per agent are stated in the prompt text itself
(e.g. TradeDecision may only emit `LIMIT_BUY/LIMIT_SELL` or `NO_TRADE`;
StrategyImprovement may only propose candidates, never mutate live config;
ChiefTrading has no execution authority and may only emit `task_type` values
from the allowlist enforced in code).

When editing prompts in Settings (future UI: `prompt_versions`), bump
`version` and keep the old row — decisions store `model` + task input/output
so any outcome is traceable to its prompt version.
