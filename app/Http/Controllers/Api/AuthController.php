<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['sometimes', 'string', 'in:student'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'] ?? 'student',
        ]);

        $this->loginSessionUser($request, $user);

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('frontend')->plainTextToken,
        ], 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $this->loginSessionUser($request, $user);

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('frontend')->plainTextToken,
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $status = Password::sendResetLink(['email' => $validated['email']]);

            if ($status !== Password::RESET_LINK_SENT) {
                Log::info('Password reset link was not sent.', [
                    'status' => $status,
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Password reset email could not be sent.', [
                'exception' => $exception::class,
            ]);

            return response()->json([
                'message' => 'We could not send a password reset link right now. Please try again later.',
            ], 503);
        }

        return response()->json([
            'message' => 'If an account exists for that email, we have sent a password reset link.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $status = DB::transaction(function () use ($validated) {
                return Password::reset(
                    $validated,
                    function (User $user, string $password) {
                        $user->forceFill([
                            'password' => Hash::make($password),
                        ])->setRememberToken(Str::random(60));

                        if (! $user->save()) {
                            throw new RuntimeException('Password reset could not be persisted.');
                        }

                        $user->tokens()->delete();
                    }
                );
            });
        } catch (Throwable $exception) {
            Log::error('Password reset failed to persist.', [
                'exception' => $exception::class,
            ]);

            return response()->json([
                'message' => 'Your password could not be saved. Please try again.',
            ], 500);
        }

        if ($status === Password::PASSWORD_RESET) {
            $freshUser = User::where('email', $validated['email'])->first();

            if (! $freshUser || ! Hash::check($validated['password'], $freshUser->password)) {
                Log::error('Password reset verification failed after the reset transaction.');

                return response()->json([
                    'message' => 'Your password could not be saved. Please try again.',
                ], 500);
            }

            return response()->json([
                'message' => 'Your password has been reset. You can now log in.',
            ]);
        }

        return response()->json([
            'message' => __($status),
        ], 422);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function profile(Request $request)
    {
        $user = $request->user()->load([
            'courses',
            'enrollments.course',
            'lessonProgress.lesson',
            'payments.course',
        ]);

        return response()->json($user);
    }

    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,'.$request->user()->id],
            'password' => ['sometimes', 'string', 'min:8'],
        ]);

        $user = $request->user();

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        return response()->json($user);
    }

    public function logout(Request $request)
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    private function loginSessionUser(Request $request, User $user): void
    {
        if (! $request->hasSession()) {
            return;
        }

        Auth::login($user);
        $request->session()->regenerate();
    }
}
