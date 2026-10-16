<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Recheck disabled staff/accounts on every request, including Livewire updates. */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => __('auth.failed')]);
        }

        return $next($request);
    }
}
