<?php

namespace App\Services\Notifications;

use App\Models\NotificationLog;
use App\Models\Setting;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email alerts with dedupe + cooldown. Uses configured Laravel mail transport.
 */
class NotifyService
{
    public function alert(string $type, string $subject, string $body, int $cooldownMinutes = 60): bool
    {
        $recipient = Setting::get('notifications.alert_email', '');
        if (! $recipient) { Log::info('Alert skipped (no recipient)', ['type' => $type]); return false; }
        $dedupe = $type . '|' . md5($subject);
        $recent = NotificationLog::where('dedupe_key', $dedupe)
            ->where('created_at', '>=', now()->subMinutes($cooldownMinutes))->exists();
        if ($recent) return false;
        try {
            Mail::raw($body, fn ($m) => $m->to($recipient)->subject('[Trading Bot] ' . $subject));
            $status = 'SENT';
        } catch (\Throwable $e) {
            Log::warning('Alert email failed', ['type' => $type, 'error' => $e->getMessage()]);
            $status = 'FAILED';
        }
        NotificationLog::create(['type' => $type, 'recipient' => $recipient, 'subject' => $subject, 'body' => substr($body, 0, 2000), 'status' => $status, 'dedupe_key' => $dedupe]);
        return $status === 'SENT';
    }
}
