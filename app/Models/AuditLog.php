<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = ['category', 'action', 'admin_user_id', 'subject_type', 'subject_id', 'context'];
    protected $casts = ['context' => 'array'];

    public static function log(string $category, string $action, array $context = [], $admin = null): void
    {
        try {
            static::create([
                'category' => $category, 'action' => $action,
                'admin_user_id' => $admin?->id, 'context' => $context,
            ]);
        } catch (\Throwable) {}
    }
}
