<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\V1\CurrentUserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Cookie-based sign in for the first-party SPA (Sanctum stateful authentication).
 */
class AuthenticatedSessionController extends Controller
{
    public function store(LoginRequest $request): CurrentUserResource
    {
        $request->authenticate();
        $request->session()->regenerate();

        return new CurrentUserResource($request->user());
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
