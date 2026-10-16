<?php

namespace App\Http\Middleware;

use App\Support\PersistentLogin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckSession
{
    /**
     * Soft session check: rely on Auth (restored via PersistentLogin).
     * Never force-logout or show "sesi habis"; only gate unauthenticated users.
     */
    public function handle(Request $request, Closure $next)
    {
        PersistentLogin::restoreIntoRequest($request);

        if (Auth::check()) {
            return $next($request);
        }

        return $request->expectsJson() || $request->ajax()
            ? response()->json(['message' => 'Unauthenticated.', 'csrf_token' => csrf_token()], 401)
            : redirect()->guest(route('login'));
    }
}
