<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'invoice_id', 'transaction_id', 'gateway_name',
    'amount', 'currency', 'status', 'payment_method',
    'gateway_reference', 'payload', 'paid_at',
])]

class Payment extends Model
{
    protected $casts = [
        'amount' => 'decimal:2',
        'payload' => 'array',
        'paid_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
