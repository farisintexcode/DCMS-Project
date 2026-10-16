<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AuthenticateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuditWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function create(string $portal = 'admin'): View
    {
        return view('auth.login', compact('portal'));
    }

    public function store(LoginRequest $request, AuthenticateUser $authenticate, string $portal = 'admin'): RedirectResponse
    {
        $authenticate->handle($request->validated('email'), $request->validated('password'), $request->boolean('remember'), $portal);
        $request->session()->regenerate();

        return redirect()->route($portal.'.home');
    }

    public function destroy(Request $request, AuditWriter $audit): RedirectResponse
    {
        $portal = $request->user()->canUseRiderInterface() && ! $request->user()->can('admin.access') ? 'rider' : 'admin';
        $audit->record($request->user(), 'logout');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($portal === 'rider' ? 'rider.login' : 'login');
    }
}
