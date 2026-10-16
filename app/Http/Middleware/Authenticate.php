<?php

namespace App\Http\Middleware;

use App\Support\PersistentLogin;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Before failing auth, try restoring from persistent cookie so a missing
     * session file never forces login while the user still has pekanbaru_baitul_quran.
     */
    protected function authenticate($request, array $guards)
    {
        PersistentLogin::restoreIntoRequest($request);

        parent::authenticate($request, $guards);
    }

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('login');
    }
}
