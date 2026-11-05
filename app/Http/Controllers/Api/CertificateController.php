<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Course;
use App\Services\CertificateService;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    private const PER_PAGE = 12;

    private const MAX_PER_PAGE = 60;

    public function __construct(private readonly CertificateService $certificates) {}

    /**
     * The signed-in student's certificates, newest first.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $certificates = Certificate::query()
            ->where('user_id', $request->user()->id)
            ->with($this->relations())
            ->latest('issued_at')
            ->latest('id')
            ->paginate($validated['per_page'] ?? self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Certificate $certificate) => $this->serializeCertificate($certificate));

        return response()->json($certificates);
    }

    /**
     * Admin listing of every issued certificate.
     *
     * Mounted under the existing `admin` middleware; students keep using the
     * index() action above, which is scoped to their own certificates.
     */
    public function adminIndex(Request $request)
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $certificates = Certificate::query()
            ->with($this->relations())
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';

                $query->where(function ($query) use ($like) {
                    $query->where('certificate_number', 'like', $like)
                        ->orWhereHas('user', function ($user) use ($like) {
                            $user->where('name', 'like', $like)->orWhere('email', 'like', $like);
                        })
                        ->orWhereHas('course', fn ($course) => $course->where('title', 'like', $like));
                });
            })
            ->latest('issued_at')
            ->latest('id')
            ->paginate($validated['per_page'] ?? self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Certificate $certificate) => $this->serializeCertificate($certificate));

        return response()->json($certificates);
    }

    /**
     * Claim the certificate of a completed course.
     *
     * Idempotent: calling it again returns the certificate that already exists
     * instead of creating a second one.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
        ]);

        $course = Course::findOrFail($validated['course_id']);

        // Eligibility is always recomputed server side; the client cannot
        // claim to have finished the course.
        $eligibility = $this->certificates->eligibility($request->user(), $course);

        if (! $eligibility['eligible']) {
            return response()->json([
                'message' => $eligibility['reason'],
                'progress' => $eligibility['progress'],
            ], 403);
        }

        $alreadyIssued = Certificate::where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->exists();

        $certificate = $this->certificates->issue($request->user(), $course);

        return response()->json([
            'message' => $alreadyIssued
                ? 'This certificate has already been issued.'
                : 'Certificate issued.',
            'data' => $this->serializeCertificate($certificate->load($this->relations())),
        ], $alreadyIssued ? 200 : 201);
    }

    public function show(Request $request, Certificate $certificate)
    {
        $this->authorize('view', $certificate);

        return response()->json(
            $this->serializeCertificate($certificate->load($this->relations()))
        );
    }

    /**
     * Open the PDF certificate in the browser instead of downloading it.
     */
    public function view(Request $request, Certificate $certificate)
    {
        $this->authorize('view', $certificate);

        return $this->certificates
            ->renderPdf($certificate)
            ->stream($this->certificates->downloadName($certificate));
    }

    /**
     * Stream the PDF certificate.
     */
    public function download(Request $request, Certificate $certificate)
    {
        $this->authorize('download', $certificate);

        return $this->certificates
            ->renderPdf($certificate)
            ->download($this->certificates->downloadName($certificate));
    }

    /**
     * Public verification by certificate number.
     *
     * Only public-facing fields are returned: no email, no user id, no
     * password/remember token, no payment data.
     */
    public function verify(string $number)
    {
        $certificate = Certificate::where('certificate_number', $number)
            ->with([
                'user' => fn ($query) => $query->select('id', 'name'),
                'course' => fn ($query) => $query->select('id', 'title', 'slug'),
            ])
            ->first();

        if (! $certificate) {
            return response()->json([
                'valid' => false,
                'certificate_number' => $number,
                'message' => 'No certificate was found for this number.',
            ], 404);
        }

        return response()->json([
            'valid' => true,
            'certificate_number' => $certificate->certificate_number,
            'student_name' => $certificate->user->name,
            'course' => [
                'id' => $certificate->course->id,
                'title' => $certificate->course->title,
                'slug' => $certificate->course->slug,
            ],
            'issued_at' => $certificate->issued_at?->toIso8601String(),
        ]);
    }

    /**
     * Relations needed to render a certificate row (keeps the listing at a
     * fixed number of queries).
     *
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'user' => fn ($query) => $query->select('id', 'name'),
            'course.instructor',
            'course.category',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCertificate(Certificate $certificate): array
    {
        $course = $certificate->course;

        return [
            'id' => $certificate->id,
            'certificate_number' => $certificate->certificate_number,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
            'view_url' => route('api.certificates.view', $certificate->id),
            'download_url' => route('api.certificates.download', $certificate->id),
            'verify_url' => route('api.certificates.verify', $certificate->certificate_number),
            'course' => $course ? [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
                'instructor' => $course->instructor?->name,
                'category' => $course->category?->name,
            ] : null,
            'student_name' => $certificate->user?->name,
        ];
    }
}
