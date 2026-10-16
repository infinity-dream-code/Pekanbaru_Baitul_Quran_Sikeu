<?php

namespace App\Http\Middleware;

use App\Support\PersistentLogin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestorePersistentLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (PersistentLogin::restoreIntoRequest($request)) {
            return $next($request);
        }

        // Invalid/expired cookie payload only — never clear on session-file failure.
        if (PersistentLogin::hasAnyCookie()) {
            PersistentLogin::forget();
        }

        return $next($request);
    }
}
