<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'is_encrypted'];
    protected $casts = ['is_encrypted' => 'boolean'];

    public static function get(string $key, $default = null)
    {
        $s = static::where('key', $key)->first();
        if (! $s) return $default;
        $v = $s->value;
        if ($s->is_encrypted && $v) {
            try { $v = decrypt($v); } catch (\Throwable) { return $default; }
        }
        return $v ?? $default;
    }

    public static function put(string $key, $value, string $group = 'general', bool $encrypt = false): self
    {
        $v = $encrypt && $value ? encrypt($value) : $value;
        return static::updateOrCreate(['key' => $key], ['value' => $v, 'group' => $group, 'is_encrypted' => $encrypt]);
    }
}
