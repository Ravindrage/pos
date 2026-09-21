<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = ['business_id', 'name', 'phone', 'email', 'balance_due'];

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
