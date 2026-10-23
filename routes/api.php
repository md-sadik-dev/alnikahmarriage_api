<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProfileApiController;
use App\Http\Controllers\Api\PackageApiController;
use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\OtpApiController;
use App\Http\Controllers\Api\MetaApiController;
use App\Http\Controllers\Api\GalleryApiController;
use App\Http\Controllers\Api\BannerApiController;
use App\Http\Controllers\Api\VideoApiController;

/*
|--------------------------------------------------------------------------
| API Routes - Al-Nikah Matrimonial Services
|--------------------------------------------------------------------------
| Public and Protected API routes.
*/

// Authentication APIs (Login, Signup, Me, Logout)
Route::post('/login', [AuthApiController::class, 'login'])->name('api.login');
Route::post('/signup', [AuthApiController::class, 'register'])->name('api.signup');
Route::post('/register', [AuthApiController::class, 'register'])->name('api.register');
Route::get('/me', [AuthApiController::class, 'me'])->name('api.me');
Route::post('/logout', [AuthApiController::class, 'logout'])->name('api.logout');
Route::get('/logout', [AuthApiController::class, 'logout'])->name('api.logout.get');

// OTP Endpoints (Signup Verification)
Route::post('/send-otp', [OtpApiController::class, 'sendOtp'])->name('api.otp.send');
Route::post('/verify-otp', [OtpApiController::class, 'verifyOtp'])->name('api.otp.verify');

// Metadata Options (Active Religions/Sects and Castes)
Route::get('/meta/options', [MetaApiController::class, 'options'])->name('api.meta.options');

// Public Profile Browsing APIs & Photo Stream
Route::get('/photo/blurred/{filename}', [ProfileApiController::class, 'getBlurredPhoto'])->name('api.photo.blurred');
Route::get('/photo/{filename}', [ProfileApiController::class, 'getPhoto'])->name('api.photo.stream');
Route::prefix('profiles')->group(function () {
    Route::get('/', [ProfileApiController::class, 'index'])->name('api.profiles.index');
    Route::get('/featured', [ProfileApiController::class, 'featured'])->name('api.profiles.featured');
    Route::get('/{id}', [ProfileApiController::class, 'show'])->name('api.profiles.show');
    Route::post('/{id}/unlock', [ProfileApiController::class, 'unlockProfile'])->name('api.profiles.unlock');
});

use App\Http\Controllers\Api\InterestApiController;

// Protected Member Profile Endpoints
Route::middleware('auth.member')->group(function () {
    Route::get('/profile', [ProfileApiController::class, 'meProfile'])->name('api.profile.me');
    Route::match(['put', 'post'], '/profile/update', [ProfileApiController::class, 'update'])->name('api.profile.update');
    Route::post('/profile/photo', [ProfileApiController::class, 'uploadPhoto'])->name('api.profile.photo');
    Route::post('/profile/change-password', [ProfileApiController::class, 'changePassword'])->name('api.profile.change-password');

    // Inbox & Interests Endpoints
    Route::prefix('interests')->group(function () {
        Route::get('/sent', [InterestApiController::class, 'sent'])->name('api.interests.sent');
        Route::get('/received', [InterestApiController::class, 'received'])->name('api.interests.received');
        Route::get('/accepted', [InterestApiController::class, 'accepted'])->name('api.interests.accepted');
        Route::get('/counts', [InterestApiController::class, 'counts'])->name('api.interests.counts');
        Route::post('/send', [InterestApiController::class, 'send'])->name('api.interests.send');
        Route::post('/{id}/accept', [InterestApiController::class, 'accept'])->name('api.interests.accept');
        Route::post('/{id}/decline', [InterestApiController::class, 'decline'])->name('api.interests.decline');
        Route::match(['delete', 'post'], '/{id}/cancel', [InterestApiController::class, 'cancel'])->name('api.interests.cancel');
    });
});

// Membership Packages APIs
Route::prefix('packages')->group(function () {
    Route::get('/', [PackageApiController::class, 'index'])->name('api.packages.index');

    // Protected Member Plan Operations
    Route::middleware('auth.member')->group(function () {
        Route::get('/current', [PackageApiController::class, 'currentPlan'])->name('api.packages.current');
        Route::post('/purchase', [PackageApiController::class, 'purchase'])->name('api.packages.purchase');
        Route::post('/upgrade', [PackageApiController::class, 'purchase'])->name('api.packages.upgrade');
        Route::post('/subscribe', [PackageApiController::class, 'purchase'])->name('api.packages.subscribe');
    });

    Route::get('/{id}', [PackageApiController::class, 'show'])->name('api.packages.show');
});

// Homepage Banners APIs
Route::prefix('banners')->group(function () {
    Route::get('/', [BannerApiController::class, 'index'])->name('api.banners.index');
    Route::get('/{id}', [BannerApiController::class, 'show'])->name('api.banners.show');
});
Route::get('/banner', [BannerApiController::class, 'index'])->name('api.banner.index');
Route::get('/home-banners', [BannerApiController::class, 'index'])->name('api.home-banners.index');

// Gallery & Success Stories APIs
Route::prefix('gallery')->group(function () {
    Route::get('/', [GalleryApiController::class, 'index'])->name('api.gallery.index');
    Route::get('/{id}', [GalleryApiController::class, 'show'])->name('api.gallery.show');
});
Route::get('/success-stories', [GalleryApiController::class, 'index'])->name('api.success-stories.index');

// Video Guides APIs
Route::prefix('videos')->group(function () {
    Route::get('/', [VideoApiController::class, 'index'])->name('api.videos.index');
    Route::get('/{id}', [VideoApiController::class, 'show'])->name('api.videos.show');
});
Route::get('/video-guides', [VideoApiController::class, 'index'])->name('api.video-guides.index');

// Contact / Feedback APIs
Route::post('/feedback', [\App\Http\Controllers\PageController::class, 'submitFeedback'])->name('api.feedback.submit');
Route::post('/contact-us', [\App\Http\Controllers\PageController::class, 'submitFeedback'])->name('api.contact.submit');
Route::post('/queries', [\App\Http\Controllers\PageController::class, 'submitFeedback'])->name('api.queries.submit');
