<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        TrustProxies::at(config('bakery.trusted_proxies'));
        Gate::define('active-account', fn (User $user) => $user->active);
        Gate::define('staff', fn (User $user) => $user->active && in_array($user->role, ['admin', 'staff'], true));
        Gate::define('admin', fn (User $user) => $user->active && $user->role === 'admin');

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(25)->by('ip:'.$request->ip()),
            Limit::perMinute(6)->by('email:'.Str::lower(is_string($request->input('email')) ? $request->input('email') : '').'|'.$request->ip()),
        ]);
        RateLimiter::for('staff-factor', fn (Request $request) => [
            Limit::perMinute(15)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('staff:'.$request->session()->get('staff_mfa_pending.id', 'none')),
        ]);
        RateLimiter::for('tracking', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('tracking-resend', function (Request $request) {
            $code = $request->input('code');
            $code = is_string($code) ? Str::upper(trim($code)) : '';

            return [
                Limit::perMinute(10)->by('ip:'.$request->ip()),
                Limit::perMinutes(10, 3)->by('code:'.hash('sha256', $code)),
            ];
        });

        ResetPassword::toMailUsing(fn (User $user, string $token) => (new MailMessage)
            ->subject('Đặt lại mật khẩu')
            ->line('Chúng tôi đã nhận được yêu cầu đặt lại mật khẩu của bạn.')
            ->action('Đặt lại mật khẩu', rtrim(config('app.url'), '/').route('password.reset', ['token' => $token, 'email' => $user->email], absolute: false))
            ->line('Nếu bạn không yêu cầu đổi mật khẩu, bạn có thể bỏ qua email này.'));
    }
}
