<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'slug',
    'logo',
    'base_url',
    'callback_url',
    'username',
    'password',
    'app_key',
    'app_secret',
    'webhook',
    'shortcode',
    'store_id',
    'is_active',
    'is_testmode',
    'is_localhost',
])]

class PaymentGetway extends Model
{
    protected $casts = [
        'username' => 'encrypted',
        'password' => 'encrypted',
        'app_key' => 'encrypted',
        'app_secret' => 'encrypted',
        'store_id' => 'encrypted',
        'webhook' => 'encrypted',
        'shortcode' => 'encrypted',

        'is_active' => 'boolean',
        'is_testmode' => 'boolean',
        'is_localhost' => 'boolean',
    ];
}
