<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Tests\TestCase;

class ForgotPasswordFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_complete_forgot_password_to_login_flow(): void
    {
        $user = User::factory()->create([
            'email' => 'sokam@gmail.com',
            'password' => Hash::make('OldPass@2026'),
        ]);

        $this->postJson('/api/forgot-password', ['email' => 'sokam@gmail.com'])
            ->assertOk()
            ->assertJson([
                'message' => 'If an account exists for that email, we have sent a password reset link.',
            ]);

        $mail = $this->capturedResetMail($user);
        $token = $this->resetTokenFromMail($mail);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'sokam@gmail.com',
            'password' => 'SokAm@2026',
            'password_confirmation' => 'SokAm@2026',
        ])->assertOk()->assertJson([
            'message' => 'Your password has been reset. You can now log in.',
        ]);

        $storedPassword = $user->fresh()->password;

        $this->assertNotSame('SokAm@2026', $storedPassword);
        $this->assertStringStartsWith('$2y$', $storedPassword);
        $this->assertTrue(Hash::check('SokAm@2026', $storedPassword));

        $this->postJson('/api/login', [
            'email' => 'sokam@gmail.com',
            'password' => 'OldPass@2026',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $login = $this->postJson('/api/login', [
            'email' => 'sokam@gmail.com',
            'password' => 'SokAm@2026',
        ])->assertOk()->assertJsonStructure(['user', 'token']);

        $this->assertNotEmpty($login->json('token'));

        $this->withHeader('Authorization', 'Bearer '.$login->json('token'))
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', 'sokam@gmail.com');
    }

    public function test_reset_token_cannot_be_reused(): void
    {
        $user = User::factory()->create([
            'email' => 'reuse@example.com',
            'password' => Hash::make('OldPass@2026'),
        ]);

        $this->postJson('/api/forgot-password', ['email' => 'reuse@example.com'])->assertOk();

        $token = $this->resetTokenFromMail($this->capturedResetMail($user));

        $payload = [
            'token' => $token,
            'email' => 'reuse@example.com',
            'password' => 'SokAm@2026',
            'password_confirmation' => 'SokAm@2026',
        ];

        $this->postJson('/api/reset-password', $payload)->assertOk();
        $this->postJson('/api/reset-password', $payload)->assertStatus(422)
            ->assertJson(['message' => 'This password reset token is invalid.']);
    }

    public function test_forgot_password_reports_a_delivery_failure_without_leaking_details(): void
    {
        Password::shouldReceive('sendResetLink')
            ->once()
            ->andThrow(new RuntimeException('Mail transport failure.'));

        $this->postJson('/api/forgot-password', ['email' => 'known@example.com'])
            ->assertStatus(503)
            ->assertJson([
                'message' => 'We could not send a password reset link right now. Please try again later.',
            ])
            ->assertDontSee('Mail transport failure.');
    }

    public function test_reset_is_not_reported_as_successful_when_the_password_is_not_persisted(): void
    {
        $user = User::factory()->create([
            'email' => 'notpersisted@example.com',
            'password' => Hash::make('OldPass@2026'),
        ]);

        $token = Password::broker()->createToken($user);
        $event = 'eloquent.saving: '.User::class;

        Event::listen($event, fn () => false);

        try {
            $this->postJson('/api/reset-password', [
                'token' => $token,
                'email' => 'notpersisted@example.com',
                'password' => 'SokAm@2026',
                'password_confirmation' => 'SokAm@2026',
            ])->assertStatus(500)->assertJson([
                'message' => 'Your password could not be saved. Please try again.',
            ]);
        } finally {
            Event::forget($event);
        }

        $this->assertTrue(Hash::check('OldPass@2026', $user->fresh()->password));
        $this->assertFalse(Hash::check('SokAm@2026', $user->fresh()->password));
        $this->assertTrue(Password::broker()->tokenExists($user, $token));
    }

    public function test_reset_email_is_branded_and_uses_the_frontend_url(): void
    {
        config()->set('app.name', 'CourseHub');
        config()->set('app.reset_password_app_name', 'CourseHub');

        $user = User::factory()->create(['email' => 'brand@example.com']);

        $this->postJson('/api/forgot-password', ['email' => 'brand@example.com'])->assertOk();

        $mail = $this->capturedResetMail($user);

        $appName = 'CourseHub';
        $copy = implode(' ', [...$mail->introLines, ...$mail->outroLines]);

        $this->assertSame('Reset Your Password', $mail->subject);
        $this->assertSame('Reset Password', $mail->actionText);
        $this->assertStringContainsString($appName, $copy);
        $this->assertStringContainsString('expire in 60 minutes', $copy);
        $this->assertStringContainsString($appName, (string) $mail->salutation);
        $this->assertStringStartsWith(
            rtrim((string) config('app.frontend_url'), '/').'/reset-password?',
            $mail->actionUrl
        );
        $this->assertStringContainsString('token=', $mail->actionUrl);
        $this->assertStringContainsString('email=brand%40example.com', $mail->actionUrl);
    }

    private function capturedResetMail(User $user): MailMessage
    {
        $mail = null;

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$mail) {
            $mail = $notification->toMail($user);

            return true;
        });

        $this->assertInstanceOf(MailMessage::class, $mail);

        return $mail;
    }

    private function resetTokenFromMail(MailMessage $mail): string
    {
        $query = [];
        parse_str((string) parse_url($mail->actionUrl, PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('token', $query);
        $this->assertArrayHasKey('email', $query);

        return $query['token'];
    }
}
