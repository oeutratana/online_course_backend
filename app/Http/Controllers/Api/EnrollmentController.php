<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function index(Request $request)
    {
        $enrollments = Enrollment::with(['user', 'course'])
            ->when($request->user_id, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($request->course_id, fn ($query, $courseId) => $query->where('course_id', $courseId))
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->get();

        return response()->json($enrollments);
    }

    public function show(Enrollment $enrollment)
    {
        return response()->json($enrollment->load(['user', 'course']));
    }

    public function update(Request $request, Enrollment $enrollment)
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,completed,cancelled'],
            'enrolled_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $enrollment->update($validated);

        return response()->json($enrollment->fresh()->load(['user', 'course']));
    }

    public function destroy(Enrollment $enrollment)
    {
        $enrollment->delete();

        return response()->json([
            'message' => 'Enrollment deleted successfully.',
        ]);
    }
}
