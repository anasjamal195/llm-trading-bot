<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $fillable = ['type', 'recipient', 'subject', 'body', 'status', 'dedupe_key'];
}
