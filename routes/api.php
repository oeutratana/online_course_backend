<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\LessonProgressController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\UserController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/courses', [CourseController::class, 'store']);
    Route::get('/my/courses', [CourseController::class, 'myIndex']);
    Route::put('/courses/{course}', [CourseController::class, 'update']);
    Route::delete('/courses/{course}', [CourseController::class, 'destroy']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::post('/payments/{payment}/check', [PaymentController::class, 'check']);

    Route::middleware('admin')->group(function () {
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
    
        Route::get('/enrollments', [EnrollmentController::class, 'index']);
        Route::get('/enrollments/{enrollment}', [EnrollmentController::class, 'show']);
    
        Route::get('/lesson-progress', [LessonProgressController::class, 'index']);
        Route::get('/lesson-progress/{lessonProgress}', [LessonProgressController::class, 'show']);
    
        Route::get('/payments', [PaymentController::class, 'index']);
        Route::get('/payments/{payment}', [PaymentController::class, 'show']);
    });
});

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);

Route::get('/courses', [CourseController::class, 'index']);
Route::get('/courses/{course}', [CourseController::class, 'show']);

Route::get('/lessons', [LessonController::class, 'index']);
Route::get('/lessons/{lesson}', [LessonController::class, 'show']);

Route::get('/quizzes', [QuizController::class, 'index']);
Route::get('/quizzes/{quiz}', [QuizController::class, 'show']);

Route::get('/questions', [QuestionController::class, 'index']);
Route::get('/questions/{question}', [QuestionController::class, 'show']);
