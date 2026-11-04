<?php

namespace App\Services\System;

use App\Models\SystemLog;
use Illuminate\Support\Facades\Log;

/**
 * Structured backend logging: every entry goes to a Monolog channel AND the
 * system_logs DB table (viewable in admin). Secrets are redacted from context.
 */
class AppLogger
{
    private const SECRET_KEYS = ['api_key', 'apikey', 'password', 'passwd', 'secret', 'token', 'authorization', 'bearer', 'private_key', 'client_secret'];

    public function info(string $component, string $message, array $context = []): void
    {
        $this->write('info', $component, $message, $context);
    }

    public function warning(string $component, string $message, array $context = []): void
    {
        $this->write('warning', $component, $message, $context);
    }

    public function error(string $component, string $message, array $context = []): void
    {
        $this->write('error', $component, $message, $context);
    }

    private function write(string $level, string $component, string $message, array $context): void
    {
        $context = $this->redact($context);
        try {
            SystemLog::create(['level' => $level, 'component' => $component, 'message' => substr($message, 0, 1000), 'context' => $context ?: null]);
        } catch (\Throwable) {
            // DB logging must never break the operation being logged.
        }
        $channel = match ($component) {
            'broker', 'risk', 'trading' => 'trading',
            'agent', 'llm' => 'agents',
            default => null,
        };
        try {
            $logger = $channel ? Log::channel($channel) : Log::getLogger();
            $logger->$level("[{$component}] {$message}", $context);
        } catch (\Throwable) {
        }
    }

    public function redact(array $context): array
    {
        foreach ($context as $k => $v) {
            $lk = strtolower((string) $k);
            foreach (self::SECRET_KEYS as $s) {
                if (str_contains($lk, $s)) {
                    $context[$k] = '[redacted]';
                    continue 2;
                }
            }
            if (is_array($v)) $context[$k] = $this->redact($v);
            elseif (is_string($v) && preg_match('/^(sk-|Bearer\s)/i', $v)) $context[$k] = '[redacted]';
        }
        return $context;
    }
}
