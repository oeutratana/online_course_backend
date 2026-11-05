<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.google.client_id', 'test-client-id');
        config()->set('services.google.client_secret', 'test-client-secret');
        config()->set('services.google.redirect', 'http://localhost:8000/api/auth/google/callback');
        config()->set('services.google.frontend_callback', 'http://localhost:5173/auth/google/callback');
    }

    public function test_redirect_builds_google_url_and_stores_state_and_safe_redirect(): void
    {
        $response = $this->get('/api/auth/google/redirect?redirect=/my-courses');

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $query);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth?', $location);
        $this->assertSame('test-client-id', $query['client_id']);
        $this->assertSame('http://localhost:8000/api/auth/google/callback', $query['redirect_uri']);
        $this->assertSame('select_account', $query['prompt']);
        $this->assertNotEmpty($query['state']);
        $this->assertSame($query['state'], session('google_oauth_state'));
        $this->assertSame('/my-courses', session('google_oauth_redirect'));
    }

    public function test_redirect_ignores_an_external_redirect_path(): void
    {
        $this->get('/api/auth/google/redirect?redirect=https://evil.example/phishing')
            ->assertRedirect();

        $this->assertNull(session('google_oauth_redirect'));
    }

    public function test_redirect_reports_missing_google_configuration_without_exposing_secrets(): void
    {
        config()->set('services.google.client_id', null);

        $this->get('/api/auth/google/redirect')
            ->assertRedirect('http://localhost:5173/auth/google/callback?error=google_not_configured');
    }

    public function test_callback_rejects_a_missing_or_mismatched_state(): void
    {
        $this->withSession([
            'google_oauth_state' => 'expected-state',
            'google_oauth_redirect' => '/my-courses',
        ])->get('/api/auth/google/callback?state=wrong-state&code=valid-code')
            ->assertRedirectContains('error=google_state');

        $this->get('/api/auth/google/callback?state=expected-state&code=valid-code')
            ->assertRedirectContains('error=google_state');
    }

    public function test_callback_reports_google_denial(): void
    {
        $this->withSession([
            'google_oauth_state' => 'valid-state',
            'google_oauth_redirect' => null,
        ])->get('/api/auth/google/callback?state=valid-state&error=access_denied')
            ->assertRedirectContains('error=google_denied');
    }

    public function test_callback_rejects_a_google_user_with_an_unverified_email(): void
    {
        $this->mockGoogleUser(SocialiteUser::fake([
            'email' => 'unverified@example.com',
            'email_verified' => false,
        ]));

        $this->withSession([
            'google_oauth_state' => 'valid-state',
            'google_oauth_redirect' => null,
        ])->get('/api/auth/google/callback?state=valid-state&code=valid-code')
            ->assertRedirectContains('error=google_email_unverified');

        $this->assertDatabaseMissing('users', ['email' => 'unverified@example.com']);
    }

    public function test_callback_creates_a_new_student_and_exchange_returns_a_working_token(): void
    {
        $this->mockGoogleUser(SocialiteUser::fake([
            'id' => 'google-new-user',
            'name' => 'New Google Student',
            'email' => 'new.student@example.com',
            'email_verified' => true,
            'avatar' => 'https://example.com/new-avatar.jpg',
        ]));

        $code = $this->completeGoogleCallback('/my-courses');

        $this->assertDatabaseHas('users', [
            'email' => 'new.student@example.com',
            'name' => 'New Google Student',
            'role' => 'student',
            'google_id' => 'google-new-user',
            'avatar' => 'https://example.com/new-avatar.jpg',
        ]);

        $user = User::where('email', 'new.student@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotEmpty($user->password);

        $response = $this->postJson('/api/auth/google/exchange', ['code' => $code]);

        $response->assertOk()
            ->assertJsonPath('user.email', 'new.student@example.com')
            ->assertJsonPath('user.role', 'student')
            ->assertJsonPath('redirect', '/my-courses')
            ->assertJsonStructure(['user', 'token']);

        $token = $response->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', 'new.student@example.com');
    }

    public function test_callback_logs_in_an_already_linked_google_user(): void
    {
        $user = User::factory()->create([
            'email' => 'returning@example.com',
            'role' => 'student',
            'google_id' => 'google-returning',
            'avatar' => null,
        ]);

        $this->mockGoogleUser(SocialiteUser::fake([
            'id' => 'google-returning',
            'name' => 'Returning Student',
            'email' => 'returning@example.com',
            'email_verified' => true,
            'avatar' => 'https://example.com/returning.jpg',
        ]));

        $code = $this->completeGoogleCallback('/profile');

        $this->assertSame(1, User::where('email', 'returning@example.com')->count());

        $user->refresh();
        $this->assertSame('student', $user->role);
        $this->assertSame('google-returning', $user->google_id);
        $this->assertSame('https://example.com/returning.jpg', $user->avatar);

        $this->postJson('/api/auth/google/exchange', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('user.email', 'returning@example.com')
            ->assertJsonPath('user.role', 'student');
    }

    public function test_callback_links_an_existing_user_and_preserves_the_admin_role(): void
    {
        $admin = User::factory()->create([
            'name' => 'Existing Admin',
            'email' => 'admin@example.com',
            'role' => 'admin',
            'google_id' => null,
        ]);

        $this->mockGoogleUser(SocialiteUser::fake([
            'id' => 'google-admin',
            'name' => 'Google Admin Name',
            'email' => 'ADMIN@example.com',
            'email_verified' => true,
        ]));

        $code = $this->completeGoogleCallback('/admin/dashboard');

        $this->assertSame(1, User::where('email', 'admin@example.com')->count());

        $admin->refresh();
        $this->assertSame('admin', $admin->role);
        $this->assertSame('google-admin', $admin->google_id);
        $this->assertNotNull($admin->email_verified_at);

        $this->postJson('/api/auth/google/exchange', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('redirect', '/admin/dashboard');
    }

    public function test_callback_rejects_an_email_already_linked_to_a_different_google_account(): void
    {
        User::factory()->create([
            'email' => 'linked@example.com',
            'google_id' => 'google-original',
        ]);

        $this->mockGoogleUser(SocialiteUser::fake([
            'id' => 'google-different',
            'email' => 'linked@example.com',
            'email_verified' => true,
        ]));

        $this->withSession([
            'google_oauth_state' => 'valid-state',
            'google_oauth_redirect' => null,
        ])->get('/api/auth/google/callback?state=valid-state&code=valid-code')
            ->assertRedirectContains('error=google_account_conflict');

        $this->assertSame('google-original', User::where('email', 'linked@example.com')->value('google_id'));
    }

    public function test_callback_reports_provider_failures(): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->once()->andThrow(new RuntimeException('Provider unavailable.'));

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $this->withSession([
            'google_oauth_state' => 'valid-state',
            'google_oauth_redirect' => null,
        ])->get('/api/auth/google/callback?state=valid-state&code=valid-code')
            ->assertRedirectContains('error=google_auth_failed');
    }

    public function test_exchange_rejects_invalid_and_replayed_codes(): void
    {
        $this->postJson('/api/auth/google/exchange', ['code' => str_repeat('a', 64)])
            ->assertStatus(422);

        $this->mockGoogleUser(SocialiteUser::fake([
            'id' => 'google-replay',
            'email' => 'replay@example.com',
            'email_verified' => true,
        ]));

        $code = $this->completeGoogleCallback();

        $this->postJson('/api/auth/google/exchange', ['code' => $code])->assertOk();
        $this->postJson('/api/auth/google/exchange', ['code' => $code])->assertStatus(422);
    }

    public function test_google_token_can_be_logged_out(): void
    {
        $this->mockGoogleUser(SocialiteUser::fake([
            'id' => 'google-logout',
            'email' => 'logout@example.com',
            'email_verified' => true,
        ]));

        $code = $this->completeGoogleCallback();
        $token = $this->postJson('/api/auth/google/exchange', ['code' => $code])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    public function test_existing_email_and_password_login_still_works(): void
    {
        User::factory()->create([
            'email' => 'password@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'password@example.com',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('user.email', 'password@example.com')
            ->assertJsonStructure(['user', 'token']);
    }

    private function mockGoogleUser(SocialiteUser $user): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->once()->andReturn($user);

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);
    }

    private function completeGoogleCallback(string $redirect = '/profile'): string
    {
        $response = $this->withSession([
            'google_oauth_state' => 'valid-state',
            'google_oauth_redirect' => $redirect,
        ])->get('/api/auth/google/callback?state=valid-state&code=valid-code');

        $response->assertRedirect();
        $location = $response->headers->get('Location');

        $this->assertStringStartsWith('http://localhost:5173/auth/google/callback?', $location);
        $this->assertStringNotContainsString('token=', $location);

        parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $query);

        $this->assertArrayHasKey('code', $query);
        $this->assertNotEmpty($query['code']);

        return $query['code'];
    }
}
