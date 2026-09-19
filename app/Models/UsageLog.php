<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsageLog extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = ['tenant_id', 'metric', 'value', 'logged_date'];

    protected $casts = [
        'logged_date' => 'date',
    ];
}
