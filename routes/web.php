<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return response()->file(resource_path('static/login.html'));
});

Route::middleware('guest')->get('/login', function () {
    return response()->file(resource_path('static/login.html'));
})->name('login');

Route::get('/courses', function () {
    return response()->file(resource_path('static/courses.html'));
})->name('courses.index');

Route::middleware('auth')->get('/dashboard', function () {
    return auth()->user()->role === 'admin'
        ? redirect()->route('admin.dashboard')
        : redirect()->route('student.dashboard');
})->name('dashboard');

Route::middleware(['auth', 'admin'])->get('/admin/dashboard', function () {
    return response()->file(resource_path('static/dashboard.html'));
})->name('admin.dashboard');

Route::middleware('auth')->get('/student/dashboard', function () {
    return response()->file(resource_path('static/student-dashboard.html'));
})->name('student.dashboard');
