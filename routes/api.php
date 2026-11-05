<?php

use App\Http\Controllers\Api\Admin\CourseController as AdminCourseController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\LessonController as AdminLessonController;
use App\Http\Controllers\Api\Admin\QuestionController as AdminQuestionController;
use App\Http\Controllers\Api\Admin\QuizController as AdminQuizController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CourseReviewController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\LessonProgressController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\QuizAttemptController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will be
| assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:6,1')
    ->name('api.auth.forgot-password');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:6,1')
    ->name('api.auth.reset-password');

Route::middleware('web')->prefix('auth/google')->group(function () {
    Route::get('/redirect', [GoogleAuthController::class, 'redirect'])
        ->middleware('throttle:20,1')
        ->name('api.auth.google.redirect');
    Route::get('/callback', [GoogleAuthController::class, 'callback'])
        ->middleware('throttle:30,1')
        ->name('api.auth.google.callback');
});

// Google Cloud Console is configured with the root callback URL. Keep this
// alias because API routes otherwise receive the automatic /api prefix.
Route::middleware('web')->get('/auth/google/callback', [GoogleAuthController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('auth.google.callback');

Route::post('/auth/google/exchange', [GoogleAuthController::class, 'exchange'])
    ->middleware('throttle:20,1')
    ->name('api.auth.google.exchange');

// PayWay server-to-server callback. No Sanctum token: PayWay hits this URL
// directly and authenticates itself with the X-PAYWAY-HMAC-SHA512 header.
Route::post('/payments/payway/callback', [PaymentController::class, 'paywayCallback']);

// Public certificate verification by certificate number. Declared before
// /certificates/{certificate} so the literal `verify` segment is never
// swallowed by the {certificate} parameter.
Route::get('/certificates/verify/{number}', [CertificateController::class, 'verify'])
    ->name('api.certificates.verify');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::get('/user/profile', [AuthController::class, 'profile']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/my/courses', [CourseController::class, 'myIndex']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::post('/payments/{payment}/check', [PaymentController::class, 'check']);

    // Learning dashboard (own data only).
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Student lesson progress. The user is always taken from the token.
    Route::post('/lessons/{lesson}/progress', [LessonProgressController::class, 'store']);
    Route::delete('/lessons/{lesson}/progress', [LessonProgressController::class, 'destroy']);
    Route::get('/courses/{course}/progress', [LessonProgressController::class, 'courseProgress']);

    // Student quiz attempts. Answers are graded on the server.
    Route::post('/quizzes/{quiz}/attempts', [QuizAttemptController::class, 'store']);
    Route::get('/quizzes/{quiz}/attempts', [QuizAttemptController::class, 'index']);
    Route::get('/quiz-attempts/{attempt}', [QuizAttemptController::class, 'show']);

    // Wishlist — every action is scoped to the authenticated student.
    Route::get('/wishlist/check/{course}', [WishlistController::class, 'check']);
    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{course}', [WishlistController::class, 'destroy']);

    // Certificates — listing, detail, PDF download and claiming a certificate.
    Route::get('/certificates', [CertificateController::class, 'index']);
    Route::post('/certificates', [CertificateController::class, 'store']);
    Route::get('/certificates/{certificate}/view', [CertificateController::class, 'view'])
        ->name('api.certificates.view');
    Route::get('/certificates/{certificate}/download', [CertificateController::class, 'download'])
        ->name('api.certificates.download');
    Route::get('/certificates/{certificate}', [CertificateController::class, 'show']);

    // Reviews are submitted by enrolled students; reading them is public.
    Route::post('/courses/{course}/reviews', [CourseReviewController::class, 'store']);
    Route::put('/courses/{course}/reviews/{review}', [CourseReviewController::class, 'update']);
    Route::delete('/courses/{course}/reviews/{review}', [CourseReviewController::class, 'destroy']);

    // Content management is admin-only. Public read endpoints for courses,
    // lessons, quizzes and questions stay unauthenticated below.
    Route::middleware('admin')->group(function () {
        Route::post('/courses', [CourseController::class, 'store']);
        Route::put('/courses/{course}', [CourseController::class, 'update']);
        Route::delete('/courses/{course}', [CourseController::class, 'destroy']);

        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

        Route::post('/lessons', [AdminLessonController::class, 'store']);
        Route::put('/lessons/{lesson}', [AdminLessonController::class, 'update']);
        Route::delete('/lessons/{lesson}', [AdminLessonController::class, 'destroy']);

        Route::post('/quizzes', [AdminQuizController::class, 'store']);
        Route::put('/quizzes/{quiz}', [AdminQuizController::class, 'update']);
        Route::delete('/quizzes/{quiz}', [AdminQuizController::class, 'destroy']);

        Route::post('/quizzes/{quiz}/questions', [AdminQuestionController::class, 'store']);
        Route::put('/quizzes/{quiz}/questions/reorder', [AdminQuestionController::class, 'reorder']);
        Route::put('/questions/{question}', [AdminQuestionController::class, 'update']);
        Route::delete('/questions/{question}', [AdminQuestionController::class, 'destroy']);
    });

    Route::middleware('admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);

        Route::get('/enrollments', [EnrollmentController::class, 'index']);
        Route::get('/enrollments/{enrollment}', [EnrollmentController::class, 'show']);
        Route::put('/enrollments/{enrollment}', [EnrollmentController::class, 'update']);
        Route::delete('/enrollments/{enrollment}', [EnrollmentController::class, 'destroy']);

        Route::get('/lesson-progress', [LessonProgressController::class, 'index']);
        Route::get('/lesson-progress/{lessonProgress}', [LessonProgressController::class, 'show']);

        Route::get('/payments', [PaymentController::class, 'index']);
        Route::get('/payments/{payment}', [PaymentController::class, 'show']);

        // Admin-only content management. Public read endpoints for lessons and
        // quizzes stay unauthenticated above; these add the write operations
        // and a paginated list for the admin console.
        Route::prefix('admin')->group(function () {
            Route::get('/dashboard', [AdminDashboardController::class, 'index'])
                ->name('api.admin.dashboard');
            // Drafts included: the public listing only exposes published ones.
            Route::get('/courses', [AdminCourseController::class, 'index']);
            Route::get('/courses/{course}/analytics', [AdminCourseController::class, 'analytics']);
            Route::get('/certificates', [CertificateController::class, 'adminIndex']);
            Route::get('/reviews', [CourseReviewController::class, 'adminIndex']);
            Route::get('/lessons', [AdminLessonController::class, 'index']);
            Route::post('/lessons', [AdminLessonController::class, 'store']);
            Route::get('/lessons/{lesson}', [AdminLessonController::class, 'show']);
            Route::put('/lessons/{lesson}', [AdminLessonController::class, 'update']);
            Route::delete('/lessons/{lesson}', [AdminLessonController::class, 'destroy']);

            Route::get('/quizzes', [AdminQuizController::class, 'index']);
            Route::get('/quizzes/{quiz}', [AdminQuizController::class, 'show']);
            Route::get('/questions', [AdminQuestionController::class, 'index']);
        });
    });
});

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);

Route::get('/courses', [CourseController::class, 'index']);
Route::get('/courses/{course}/reviews', [CourseReviewController::class, 'index']);
Route::get('/courses/{course}/rating', [CourseReviewController::class, 'rating']);
Route::get('/courses/{course}', [CourseController::class, 'show']);

Route::get('/lessons', [LessonController::class, 'index']);
Route::get('/lessons/{lesson}', [LessonController::class, 'show']);

Route::get('/quizzes', [QuizController::class, 'index']);
Route::get('/quizzes/{quiz}', [QuizController::class, 'show']);

Route::get('/questions', [QuestionController::class, 'index']);
Route::get('/questions/{question}', [QuestionController::class, 'show']);
