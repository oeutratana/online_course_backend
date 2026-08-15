<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'admin') {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Only admins can access this resource.',
                ], 403);
            }

            return redirect()->route('student.dashboard');
        }

        return $next($request);
    }
}
