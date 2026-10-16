<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Plan.md §7; Module.md M01; Prototype.md UI01/UI46. */
class AuthenticateUser
{
    public function __construct(private AuditWriter $audit) {}

    public function handle(string $email, string $password, bool $remember, string $portal): User
    {
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        $validPassword = Hash::check($password, $user?->password ?? Hash::make('invalid-credential-timing-placeholder'));
        $allowed = $user?->isActive() && ($portal === 'rider' ? $user->canUseRiderInterface() : $user->can('admin.access'));
        if (! $validPassword || ! $allowed) {
            $this->audit->record(null, 'login_failed', metadata: [
                'portal' => $portal,
                'login_fingerprint' => hash_hmac('sha256', 'login:'.$email, config('app.key')),
            ]);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        DB::transaction(function () use ($user, $portal): void {
            $user->last_login_at = now();
            $user->save();
            $this->audit->record($user, 'login', metadata: ['portal' => $portal]);
        });
        Auth::login($user, $remember);

        return $user;
    }
}
