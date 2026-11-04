<?php

namespace App\Services\News;

use App\Models\AgentTask;
use App\Models\NewsArticle;
use App\Models\Setting;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * News provider abstraction. Configurable search API (NOT Google HTML scraping).
 * Providers: 'manual' (admin-entered / webhook), 'newsapi' (NewsAPI.org), 'finnhub'.
 * Fetched headlines are queued for the NewsResearchAgent to classify.
 */
class NewsService
{
    public function provider(): string
    {
        return (string) Setting::get('news.provider', 'manual');
    }

    public function fetch(string $query = 'forex central bank'): array
    {
        $provider = $this->provider();
        $items = match ($provider) {
            'newsapi' => $this->viaNewsApi($query),
            'finnhub' => $this->viaFinnhub($query),
            default => [],
        };
        $stored = 0;
        foreach ($items as $item) {
            $hash = hash('sha256', strtolower(trim($item['headline'])) . '|' . ($item['source'] ?? ''));
            $created = NewsArticle::firstOrCreate(['hash' => $hash], [
                'headline' => substr($item['headline'], 0, 500),
                'source' => $item['source'] ?? $provider,
                'url' => $item['url'] ?? null,
                'published_at' => $item['published_at'] ?? now(),
                'retrieved_at' => now(),
                'currency' => $item['currency'] ?? null,
                'expires_at' => now()->addHours(48),
            ]);
            if ($created->wasRecentlyCreated) $stored++;
        }
        // Queue classification task.
        AgentTask::dispatchOnce([
            'task_type' => 'research_news', 'agent_name' => 'news_research',
            'priority' => 6, 'status' => 'PENDING',
            'input_context' => ['trigger' => 'fetch', 'fresh_count' => $stored],
            'scheduled_at' => now(), 'dedupe_key' => 'research_news|' . today()->toDateString() . '|' . date('H'),
        ]);
        return ['provider' => $provider, 'fresh' => $stored];
    }

    private function viaNewsApi(string $query): array
    {
        $key = Setting::get('news.api_key', '');
        if (! $key) { Log::warning('NewsAPI key missing'); return []; }
        try {
            $res = Http::timeout(20)->get('https://newsapi.org/v2/everything', [
                'q' => $query, 'language' => 'en', 'sortBy' => 'publishedAt', 'pageSize' => 20, 'apiKey' => $key,
            ]);
            if ($res->failed()) return [];
            return collect($res->json('articles') ?? [])->map(fn ($a) => [
                'headline' => $a['title'] ?? '', 'source' => $a['source']['name'] ?? 'newsapi',
                'url' => $a['url'] ?? null, 'published_at' => isset($a['publishedAt']) ? date('Y-m-d H:i:s', strtotime($a['publishedAt'])) : now(),
            ])->filter(fn ($a) => $a['headline'] !== '')->all();
        } catch (\Throwable $e) {
            Log::warning('NewsAPI fetch failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function viaFinnhub(string $query): array
    {
        $key = Setting::get('news.api_key', '');
        if (! $key) return [];
        try {
            $res = Http::timeout(20)->get('https://finnhub.io/api/v1/news', ['category' => 'forex', 'token' => $key]);
            if ($res->failed()) return [];
            return collect($res->json() ?? [])->take(20)->map(fn ($a) => [
                'headline' => $a['headline'] ?? '', 'source' => $a['source'] ?? 'finnhub',
                'url' => $a['url'] ?? null,
                'published_at' => isset($a['datetime']) ? date('Y-m-d H:i:s', (int) $a['datetime']) : now(),
            ])->filter(fn ($a) => $a['headline'] !== '')->all();
        } catch (\Throwable) { return []; }
    }
}
