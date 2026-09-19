<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'price', 'billing_cycle', 'feature_limits', 'is_active'];

    protected $casts = [
        'feature_limits' => 'array',
        'is_active' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function limit(string $key, $default = null)
    {
        return data_get($this->feature_limits, $key, $default);
    }
}
