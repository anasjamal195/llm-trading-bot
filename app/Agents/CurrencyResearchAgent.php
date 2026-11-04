<?php

namespace App\Agents;

use App\Models\CurrencyResearch;

class CurrencyResearchAgent extends BaseAgent
{
    public function name(): string { return 'currency_research'; }

    public function systemPrompt(): string
    {
        return 'You are a currency fundamental analyst. Given macro news and market context for one currency, output its outlook. '
            . 'Express uncertainty when evidence conflicts. Respond ONLY with JSON: {"currency":"USD","outlook":"BULLISH|BEARISH|NEUTRAL",'
            . '"confidence":0-1,"fundamental_outlook":"...","news_sentiment":"BULLISH|BEARISH|NEUTRAL","evidence":"...","expiry_hours":24,'
            . '"next_review_minutes":30-120}.';
    }

    public function validate(array $data): array
    {
        if (! isset($data['currency'], $data['outlook'])) return [false, 'missing currency/outlook'];
        if (! in_array($data['outlook'], ['BULLISH', 'BEARISH', 'NEUTRAL'], true)) return [false, 'bad outlook'];
        return [true, ''];
    }

    protected function afterComplete(\App\Models\AgentTask $task, array $data): void
    {
        CurrencyResearch::create([
            'currency' => $data['currency'],
            'outlook' => $data['outlook'],
            'confidence' => $data['confidence'] ?? null,
            'fundamental_outlook' => $data['fundamental_outlook'] ?? null,
            'news_sentiment' => $data['news_sentiment'] ?? null,
            'evidence' => $data['evidence'] ?? null,
            'expires_at' => now()->addHours((int) ($data['expiry_hours'] ?? 24)),
            'model' => \App\Models\Setting::get('openrouter.model.currency_research', 'default'),
        ]);
        \App\Events\CurrencyResearchCompleted::dispatch($data);
    }
}
