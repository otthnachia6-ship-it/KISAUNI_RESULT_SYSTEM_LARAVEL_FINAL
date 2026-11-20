<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Maintenance-mode bypass cookie (Developer). It is read BEFORE cookies are
        // decrypted and is already protected by an HMAC of the maintenance secret.
        'laravel_maintenance',
    ];
}
