<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\Setting;
use App\Services\Notifications\NotifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_supports_multiple_recipients(): void
    {
        Setting::put('notifications.alert_email', 'anasj5749@gmail.com, graphichat.ai@gmail.com , not-an-email', 'notifications');
        $svc = app(NotifyService::class);
        $this->assertSame(['anasj5749@gmail.com', 'graphichat.ai@gmail.com'], $svc->recipients());
        $this->assertTrue($svc->alert('test_event', 'Hello', 'Body here'));
        $this->assertSame(2, NotificationLog::where('type', 'test_event')->count());
        $this->assertSame(['anasj5749@gmail.com', 'graphichat.ai@gmail.com'], NotificationLog::where('type', 'test_event')->orderBy('id')->pluck('recipient')->all());
        // Cooldown suppresses the duplicate.
        $this->assertFalse($svc->alert('test_event', 'Hello', 'Body here'));
        $this->assertSame(2, NotificationLog::where('type', 'test_event')->count());
    }

    public function test_alert_without_recipients_returns_false(): void
    {
        Setting::put('notifications.alert_email', '', 'notifications');
        $this->assertFalse(app(NotifyService::class)->alert('x', 's', 'b'));
        $this->assertSame(0, NotificationLog::count());
    }
}
