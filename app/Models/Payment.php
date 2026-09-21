<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = ['business_id', 'sale_id', 'method', 'amount', 'reference'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
