<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemHealthLog extends Model
{
    protected $fillable = ['component', 'status', 'message', 'details'];
    protected $casts = ['details' => 'array'];
}
