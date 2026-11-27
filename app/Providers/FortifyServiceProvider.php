<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\PasswordResetLinkRequestedResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
        $this->app->singleton(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Disabled users fail exactly like a wrong password (also before the 2FA challenge).
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::where('email', $request->input(Fortify::username()))->first();
            // Unknown and disabled users still pay for a hash, so timing doesn't reveal accounts.
            $passwordMatches = Hash::check((string) $request->input('password'), $user?->password ?? self::dummyHash());

            return $user !== null && ! $user->isDisabled() && $passwordMatches ? $user : null;
        });

        $this->registerViews();
        $this->registerRateLimiters();
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('timing-equalizer');
    }

    private function registerViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('Auth/Login', [
            'status' => $request->session()->get('status'),
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('Auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(function (Request $request) {
            $token = (string) $request->route('token');
            $user = User::where('email', Str::lower(trim((string) $request->query('email', ''))))->first();
            $linkValid = $user !== null && Password::broker(config('fortify.passwords'))->tokenExists($user, $token);

            // An invalid link gets no token or e-mail back, so the page only shows the error.
            return Inertia::render('Auth/ResetPassword', [
                'linkValid' => $linkValid,
                'token' => $linkValid ? $token : '',
                'email' => $linkValid ? $user->email : '',
            ]);
        });

        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmPassword'));
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower(trim((string) $request->input(Fortify::username()))).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // Hitting the limit returns the usual generic answer, so it reveals nothing.
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)
            ->by($request->ip())
            ->response(fn (Request $request) => (new PasswordResetLinkRequestedResponse)->toResponse($request)));

        // Fortify registers its routes itself; the reset-link route has no limiter option.
        $this->app->booted(function () {
            $routes = Route::getRoutes();
            $routes->refreshNameLookups();
            $routes->getByName('password.email')?->middleware('throttle:password-reset');
        });
    }
}
