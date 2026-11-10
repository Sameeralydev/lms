<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TenantDomain;
use App\Models\TenantMembership;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function create(): Response|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route(auth()->user()->hasPlatformPermission('platform.dashboard.view') ? 'platform.dashboard' : 'workspaces.index');
        }

        return Inertia::render('auth/login');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:200'], 'remember' => ['sometimes', 'boolean']]);
        $key = hash('sha256', strtolower($validated['email']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, 'Too many sign-in attempts. Try again later.');
        }

        if (! Auth::attempt(['email' => strtolower($validated['email']), 'password' => $validated['password']], (bool) ($validated['remember'] ?? false))) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'The supplied credentials could not be verified.'])->onlyInput('email');
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        if (! $request->user()->hasVerifiedEmail()) {
            return to_route('verification.notice');
        }

        $domain = $request->attributes->get('verifiedTenantDomain');
        if ($domain instanceof TenantDomain) {
            $membership = TenantMembership::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $domain->tenant_id)
                ->where('user_id', $request->user()->getKey())
                ->where('status', 'active')
                ->exists();

            if (! $membership) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return back()->withErrors(['email' => 'The supplied credentials could not be verified.'])->onlyInput('email');
            }

            return redirect()->route('tenant.dashboard', ['tenant' => $domain->tenant->slug]);
        }

        return redirect()->intended(route(auth()->user()->hasPlatformPermission('platform.dashboard.view') ? 'platform.dashboard' : 'workspaces.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function forgot(): Response
    {
        return Inertia::render('auth/forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $mailIsTestFake = app()->runningUnitTests() && (
            Mail::getFacadeRoot() instanceof MailFake || Notification::getFacadeRoot() instanceof NotificationFake
        );
        abort_if(in_array(config('mail.default'), ['log', 'array'], true) && ! $mailIsTestFake, 503, 'Password reset mail is unavailable until a real mail transport is configured.');
        $request->validate(['email' => ['required', 'email', 'max:255']]);
        Password::sendResetLink(['email' => strtolower($request->string('email')->toString())]);

        return back()->with('status', 'If the account exists, password reset instructions have been sent.');
    }

    public function reset(string $token, Request $request): Response
    {
        return Inertia::render('auth/reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->mixedCase()->numbers()],
        ]);

        $status = Password::reset($validated, function ($user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Your password has been reset. Sign in with your new password.')
            : back()->withErrors(['email' => __($status)]);
    }
}
