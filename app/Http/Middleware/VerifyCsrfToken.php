<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Illuminate\Support\Facades\Auth;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'login',
        'logout',
        'keep-alive',
    ];

    /**
     * Token lama (session dibuat ulang / file session hilang) tidak boleh berujung 419.
     * User yang sudah login (termasuk hasil restore cookie persistent) tetap diterima.
     * Cross-site tetap ditolak praktis oleh SameSite=Lax pada cookie session/auth.
     */
    protected function tokensMatch($request)
    {
        if (parent::tokensMatch($request)) {
            return true;
        }

        return Auth::check();
    }
}
