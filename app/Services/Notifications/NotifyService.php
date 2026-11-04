<?php

namespace App\Services\Notifications;

use App\Models\NotificationLog;
use App\Models\Setting;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email alerts with dedupe + cooldown. Supports multiple recipients: set
 * notifications.alert_email to a comma-separated list.
 * Uses the configured Laravel mail transport.
 */
class NotifyService
{
    /** @return string[] */
    public function recipients(): array
    {
        $raw = (string) Setting::get('notifications.alert_email', '');
        return collect(preg_split('/[,;\s]+/', $raw) ?: [])
            ->map(fn ($e) => trim((string) $e))
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()->values()->all();
    }

    public function alert(string $type, string $subject, string $body, int $cooldownMinutes = 60): bool
    {
        $recipients = $this->recipients();
        if ($recipients === []) { Log::info('Alert skipped (no recipient)', ['type' => $type]); return false; }
        $sent = 0;
        foreach ($recipients as $recipient) {
            $dedupe = $type . '|' . md5($recipient . '|' . $subject);
            $recent = NotificationLog::where('dedupe_key', $dedupe)
                ->where('created_at', '>=', now()->subMinutes($cooldownMinutes))->exists();
            if ($recent) continue;
            try {
                Mail::raw($body, fn ($m) => $m->to($recipient)->subject('[Trading Bot] ' . $subject));
                $status = 'SENT';
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Alert email failed', ['type' => $type, 'recipient' => $recipient, 'error' => $e->getMessage()]);
                $status = 'FAILED';
            }
            NotificationLog::create(['type' => $type, 'recipient' => $recipient, 'subject' => $subject, 'body' => substr($body, 0, 2000), 'status' => $status, 'dedupe_key' => $dedupe]);
        }
        return $sent > 0;
    }
}
