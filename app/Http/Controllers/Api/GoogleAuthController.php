<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Throwable;

class GoogleAuthController extends Controller
{
    private const STATE_KEY = 'google_oauth_state';

    private const REDIRECT_KEY = 'google_oauth_redirect';

    private const CODE_PREFIX = 'google_oauth_code:';

    private const CODE_TTL_SECONDS = 120;

    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return $this->frontendError('google_not_configured');
        }

        $state = Str::random(40);
        $redirect = $this->safeRedirectPath($request->query('redirect'));

        $request->session()->put(self::STATE_KEY, $state);
        $request->session()->put(self::REDIRECT_KEY, $redirect);

        $url = Socialite::driver('google')
            ->stateless()
            ->with([
                'state' => $state,
                'prompt' => 'select_account',
            ])
            ->redirect()
            ->getTargetUrl();

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull(self::STATE_KEY);
        $receivedState = (string) $request->query('state', '');
        $redirect = $request->session()->pull(self::REDIRECT_KEY);

        if (! is_string($expectedState) || $expectedState === '' || ! hash_equals($expectedState, $receivedState)) {
            return $this->frontendError('google_state');
        }

        if ($request->filled('error')) {
            return $this->frontendError('google_denied');
        }

        if (! $this->isConfigured()) {
            return $this->frontendError('google_not_configured');
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $exception) {
            Log::warning('Google OAuth callback failed.', ['exception' => $exception::class]);

            return $this->frontendError('google_auth_failed');
        }

        $googleId = (string) $googleUser->getId();
        $email = strtolower(trim((string) $googleUser->getEmail()));

        if ($googleId === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->frontendError('google_auth_failed');
        }

        if (! $this->hasVerifiedEmail($googleUser)) {
            return $this->frontendError('google_email_unverified');
        }

        try {
            $user = $this->findOrCreateUser($googleUser, $googleId, $email);
        } catch (Throwable $exception) {
            Log::warning('Google OAuth user linking failed.', ['exception' => $exception::class]);

            return $this->frontendError('google_auth_failed');
        }

        if (! $user) {
            return $this->frontendError('google_account_conflict');
        }

        $code = Str::random(64);

        Cache::put(
            $this->codeCacheKey($code),
            ['user_id' => $user->id, 'redirect' => $this->safeRedirectPath($redirect)],
            now()->addSeconds(self::CODE_TTL_SECONDS),
        );

        return redirect()->away($this->frontendCallbackUrl(['code' => $code]));
    }

    public function exchange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:32', 'max:128'],
        ]);

        $payload = Cache::pull($this->codeCacheKey($validated['code']));

        if (! is_array($payload) || ! isset($payload['user_id'])) {
            return response()->json([
                'message' => 'This Google sign-in link is invalid or has expired. Please try again.',
            ], 422);
        }

        $user = User::find($payload['user_id']);

        if (! $user) {
            return response()->json([
                'message' => 'This Google sign-in link is no longer valid. Please try again.',
            ], 422);
        }

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('google')->plainTextToken,
            'redirect' => $this->safeRedirectPath($payload['redirect'] ?? null),
        ]);
    }

    private function findOrCreateUser(GoogleUser $googleUser, string $googleId, string $email): ?User
    {
        return DB::transaction(function () use ($googleUser, $googleId, $email) {
            $linkedUser = User::where('google_id', $googleId)->lockForUpdate()->first();

            if ($linkedUser) {
                return $this->updateGoogleProfile($linkedUser, $googleUser);
            }

            $existingUser = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();

            if ($existingUser) {
                if ($existingUser->google_id && ! hash_equals($existingUser->google_id, $googleId)) {
                    return null;
                }

                return $this->updateGoogleProfile($existingUser, $googleUser);
            }

            $user = User::create([
                'name' => $googleUser->getName() ?: Str::before($email, '@'),
                'email' => $email,
                'role' => 'student',
                'password' => Hash::make(Str::random(64)),
                'google_id' => $googleId,
                'avatar' => $googleUser->getAvatar(),
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            return $user;
        });
    }

    private function updateGoogleProfile(User $user, GoogleUser $googleUser): User
    {
        $updates = [
            'google_id' => (string) $googleUser->getId(),
            'avatar' => $googleUser->getAvatar() ?: $user->avatar,
        ];

        if (! $user->email_verified_at) {
            $updates['email_verified_at'] = now();
        }

        if (! $user->name && $googleUser->getName()) {
            $updates['name'] = $googleUser->getName();
        }

        $user->forceFill($updates)->save();

        return $user->refresh();
    }

    private function hasVerifiedEmail(GoogleUser $googleUser): bool
    {
        $raw = $googleUser->getRaw();
        $verified = $raw['email_verified'] ?? $raw['verified_email'] ?? false;

        return filter_var($verified, FILTER_VALIDATE_BOOLEAN);
    }

    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'))
            && filled(config('services.google.frontend_callback'));
    }

    private function frontendCallbackBaseUrl(): string
    {
        return rtrim((string) config('services.google.frontend_callback'), '/');
    }

    private function frontendCallbackUrl(array $query): string
    {
        $url = $this->frontendCallbackBaseUrl();
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query($query);
    }

    private function frontendError(string $code): RedirectResponse
    {
        return redirect()->away($this->frontendCallbackUrl(['error' => $code]));
    }

    private function codeCacheKey(string $code): string
    {
        return self::CODE_PREFIX.hash('sha256', $code);
    }

    private function safeRedirectPath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim($path);

        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        return $path;
    }
}
