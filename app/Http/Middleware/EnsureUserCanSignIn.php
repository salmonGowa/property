<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks out users who were deactivated or lost their role while signed in, as Filament does.
 */
class EnsureUserCanSignIn
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->canSignIn() === false) {
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
            }

            abort(Response::HTTP_FORBIDDEN, 'Your account has been deactivated.');
        }

        return $next($request);
    }
}
