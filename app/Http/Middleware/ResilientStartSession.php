<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Session\Middleware\StartSession;
use Throwable;

/**
 * Start session; if the store/file is unreadable or locked, drop the broken
 * session id and create a fresh one. PersistentLogin cookie is never cleared here.
 */
class ResilientStartSession extends StartSession
{
    public function handle($request, Closure $next)
    {
        try {
            return parent::handle($request, $next);
        } catch (Throwable $e) {
            if (!$this->isRecoverableSessionError($e)) {
                throw $e;
            }
        }

        $this->resetBrokenSession($request);

        try {
            return parent::handle($request, $next);
        } catch (Throwable $e) {
            if (!$this->isRecoverableSessionError($e)) {
                throw $e;
            }
        }

        // Still failing: send user back to the same place with a clean retry.
        // Do not logout and do not clear PersistentLogin.
        if ($request->isMethod('GET') && !$request->boolean('_retry')) {
            return redirect()->to($request->fullUrlWithQuery(['_retry' => 1]));
        }

        $fallback = url()->previous() ?: url('/admin');
        if ($fallback === $request->fullUrl()) {
            $fallback = url('/admin');
        }

        return redirect()->to($fallback);
    }

    private function resetBrokenSession($request): void
    {
        $cookieName = (string) config('session.cookie');
        $request->cookies->remove($cookieName);

        try {
            $this->manager->forgetDrivers();
        } catch (Throwable $e) {
            // ignore
        }
    }

    private function isRecoverableSessionError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage() . ' ' . ($e->getPrevious()?->getMessage() ?? ''));

        return str_contains($message, 'session store not set')
            || str_contains($message, 'failed to retrieve the session')
            || str_contains($message, 'unable to create lockable file')
            || str_contains($message, 'resource temporarily unavailable')
            || str_contains($message, 'session file')
            || str_contains($message, 'failed to open stream')
            || str_contains($message, 'unserialize')
            || str_contains($message, 'serialization')
            || str_contains($message, 'error while reading')
            || str_contains($message, 'no such file')
            || str_contains($message, 'permission denied');
    }
}
