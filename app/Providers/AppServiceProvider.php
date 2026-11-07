<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $resetUrl = function (User $user, string $token): string {
            $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');

            return $frontendUrl.'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        };

        ResetPassword::createUrlUsing($resetUrl);

        ResetPassword::toMailUsing(function (User $user, string $token) use ($resetUrl): MailMessage {
            $appName = (string) config('app.reset_password_app_name', config('app.name', 'CourseHub'));
            $expiresIn = (int) config(
                'auth.passwords.'.config('auth.defaults.passwords', 'users').'.expire',
                60
            );

            return (new MailMessage)
                ->subject('Reset Your Password')
                ->greeting('Hello '.($user->name ?: 'there').'!')
                ->line('You are receiving this email because we received a password reset request for your '.$appName.' account.')
                ->action('Reset Password', $resetUrl($user, $token))
                ->line('This password reset link will expire in '.$expiresIn.' minutes.')
                ->line('If you did not request a password reset, no further action is required.')
                ->salutation('The '.$appName.' Team');
        });
    }
}
