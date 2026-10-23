<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProfileApiController;
use App\Http\Controllers\Api\PackageApiController;
use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\GalleryApiController;
use App\Http\Controllers\Api\BannerApiController;
use App\Http\Controllers\PageController;

/*
|--------------------------------------------------------------------------
| Headless API Backend Routes - Al Nikah Marriage Bureau
|--------------------------------------------------------------------------
| The frontend has been fully migrated to pure HTML/CSS/JS.
| This backend provides headless RESTful APIs for Web and Mobile Apps.
*/
Route::get('/', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'API is running',
    ]);
});

// Authentication Web Aliases (forwarding to API controller)
Route::post('/login', [AuthApiController::class, 'login'])->name('login.post');
Route::post('/signup', [AuthApiController::class, 'register'])->name('signup.post');
Route::post('/register', [AuthApiController::class, 'register'])->name('register.post');
Route::get('/logout', [AuthApiController::class, 'logout'])->name('logout.get');
Route::post('/logout', [AuthApiController::class, 'logout'])->name('logout.post');
Route::post('/contact-us', [PageController::class, 'submitFeedback'])->name('contact.post');
Route::post('/feedback', [PageController::class, 'submitFeedback'])->name('feedback.post');

Route::get('api/photo/blurred/{filename}', [ProfileApiController::class, 'getBlurredPhoto']);
Route::get('api/photo/{filename}', [ProfileApiController::class, 'getPhoto']);

// Web prefix API fallbacks for backwards compatibility
Route::prefix('api/profiles')->group(function () {
    Route::get('/', [ProfileApiController::class, 'index']);
    Route::get('/featured', [ProfileApiController::class, 'featured']);
    Route::get('/{id}', [ProfileApiController::class, 'show']);
});

Route::prefix('api/packages')->group(function () {
    Route::get('/', [PackageApiController::class, 'index']);
    Route::get('/current', [PackageApiController::class, 'currentPlan']);
    Route::post('/purchase', [PackageApiController::class, 'purchase']);
    Route::post('/upgrade', [PackageApiController::class, 'purchase']);
    Route::get('/{id}', [PackageApiController::class, 'show']);
});

Route::prefix('api/banners')->group(function () {
    Route::get('/', [BannerApiController::class, 'index']);
    Route::get('/{id}', [BannerApiController::class, 'show']);
});
Route::get('api/banner', [BannerApiController::class, 'index']);
Route::get('api/home-banners', [BannerApiController::class, 'index']);

Route::prefix('api/gallery')->group(function () {
    Route::get('/', [GalleryApiController::class, 'index']);
    Route::get('/{id}', [GalleryApiController::class, 'show']);
});
Route::get('api/success-stories', [GalleryApiController::class, 'index']);
