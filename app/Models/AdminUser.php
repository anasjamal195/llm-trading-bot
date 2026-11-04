<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class AdminUser extends Authenticatable
{
    use Notifiable;
    protected $fillable = ['name', 'email', 'password', 'is_enabled'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['is_enabled' => 'boolean'];
}
