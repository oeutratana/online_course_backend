<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Never deliver real email during tests, even if a test forgets to
        // fake the specific notification.
        Mail::fake();
        Notification::fake();
    }

    public function test_forgot_password_sends_a_reset_link_to_a_known_email(): void
    {
        $user = User::factory()->create(['email' => 'known@example.com']);

        $this->postJson('/api/forgot-password', ['email' => 'known@example.com'])
            ->assertOk()
            ->assertJson([
                'message' => 'If an account exists for that email, we have sent a password reset link.',
            ]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'http://localhost:5173/reset-password?')
                && str_contains($url, 'token=')
                && str_contains($url, 'email=known%40example.com');
        });
    }

    public function test_forgot_password_returns_a_generic_message_for_an_unknown_email(): void
    {
        $this->postJson('/api/forgot-password', ['email' => 'missing@example.com'])
            ->assertOk()
            ->assertJson([
                'message' => 'If an account exists for that email, we have sent a password reset link.',
            ]);

        Notification::assertNothingSent();
    }

    public function test_forgot_password_requires_a_valid_email(): void
    {
        $this->postJson('/api/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_legacy_auth_forgot_password_alias_still_works(): void
    {
        $user = User::factory()->create(['email' => 'legacy@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'legacy@example.com'])
            ->assertOk()
            ->assertJson([
                'message' => 'If an account exists for that email, we have sent a password reset link.',
            ]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_updates_the_password_and_revokes_existing_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => Hash::make('old-password'),
        ]);
        $user->createToken('existing');

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'reset@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()->assertJson([
            'message' => 'Your password has been reset. You can now log in.',
        ]);

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'reset@example.com']);
    }

    public function test_reset_password_rejects_an_invalid_token(): void
    {
        User::factory()->create(['email' => 'reset@example.com']);

        $this->postJson('/api/reset-password', [
            'token' => str_repeat('a', 64),
            'email' => 'reset@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422)
            ->assertJson(['message' => 'This password reset token is invalid.']);
    }

    public function test_reset_password_rejects_an_expired_token(): void
    {
        $user = User::factory()->create([
            'email' => 'expired@example.com',
            'password' => Hash::make('old-password'),
        ]);

        $token = Password::broker()->createToken($user);

        DB::table('password_reset_tokens')
            ->where('email', 'expired@example.com')
            ->update(['created_at' => now()->subMinutes(config('auth.passwords.users.expire') + 1)]);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'expired@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422)
            ->assertJson(['message' => 'This password reset token is invalid.']);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_reset_password_requires_a_valid_email(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => Hash::make('old-password'),
        ]);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'not-an-email',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
        $this->assertTrue(Password::broker()->tokenExists($user, $token));
    }

    public function test_forgot_password_response_does_not_expose_the_reset_token(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $response = $this->postJson('/api/forgot-password', ['email' => 'known@example.com'])
            ->assertOk();

        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertArrayNotHasKey('reset_token', $response->json());
    }

    public function test_reset_password_requires_a_confirmed_password(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'reset@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'different-password',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
