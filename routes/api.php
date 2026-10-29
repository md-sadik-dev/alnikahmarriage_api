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
use App\Http\Controllers\Api\NotificationApiController;

/*
|--------------------------------------------------------------------------
| API Routes - Al-Nikah Matrimonial Services
|--------------------------------------------------------------------------
| Public and Protected API routes.
*/

// Authentication APIs (Login, Signup, Me, Logout)
Route::post('/login', [AuthApiController::class, 'login'])->middleware('throttle:5,1')->name('api.login');
Route::post('/signup', [AuthApiController::class, 'register'])->name('api.signup');
Route::get('/me', [AuthApiController::class, 'me'])->name('api.me');
Route::post('/logout', [AuthApiController::class, 'logout'])->name('api.logout');
Route::get('/logout', [AuthApiController::class, 'logout'])->name('api.logout.get');

// Password Recovery Endpoints
Route::post('/forgot-password/request', [AuthApiController::class, 'forgotPasswordRequest'])->name('api.forgot-password.request');
Route::post('/forgot-password/reset', [AuthApiController::class, 'forgotPasswordReset'])->name('api.forgot-password.reset');

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
    Route::post('/profile/photos/add', [ProfileApiController::class, 'addPhotos'])->name('api.profile.photos.add');
    Route::post('/profile/photos/{id}/set-main', [ProfileApiController::class, 'setMainPhoto'])->name('api.profile.photos.set-main');
    Route::match(['delete', 'post'], '/profile/photos/{id}/delete', [ProfileApiController::class, 'deletePhoto'])->name('api.profile.photos.delete.post');
    Route::delete('/profile/photos/{id}', [ProfileApiController::class, 'deletePhoto'])->name('api.profile.photos.delete');
    Route::post('/profile/change-password', [ProfileApiController::class, 'changePassword'])->name('api.profile.change-password');
    Route::post('/profile/delete-request', [ProfileApiController::class, 'requestPermanentDelete'])->name('api.profile.delete-request');
    Route::post('/profile/cancel-delete-request', [ProfileApiController::class, 'cancelPermanentDeleteRequest'])->name('api.profile.cancel-delete-request');
    Route::match(['delete', 'post'], '/profile/permanent-delete', [ProfileApiController::class, 'permanentDeleteAccount'])->name('api.profile.permanent-delete');

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

    // Browser Push Notifications & History Endpoints
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationApiController::class, 'index'])->name('api.notifications.index');
        Route::get('/unread-count', [NotificationApiController::class, 'unreadCount'])->name('api.notifications.unread-count');
        Route::post('/subscribe', [NotificationApiController::class, 'subscribe'])->name('api.notifications.subscribe');
        Route::post('/unsubscribe', [NotificationApiController::class, 'unsubscribe'])->name('api.notifications.unsubscribe');
        Route::patch('/read-all', [NotificationApiController::class, 'markAllAsRead'])->name('api.notifications.read-all');
        Route::post('/read-all', [NotificationApiController::class, 'markAllAsRead'])->name('api.notifications.read-all.post');
        Route::patch('/{id}/read', [NotificationApiController::class, 'markAsRead'])->name('api.notifications.read');
        Route::post('/{id}/read', [NotificationApiController::class, 'markAsRead'])->name('api.notifications.read.post');
    });
});

// Public Notification Configuration (VAPID key & Web App Config)
Route::get('/notifications/config', [NotificationApiController::class, 'config'])->name('api.notifications.config');


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

use App\Http\Controllers\Api\OperatorApiController;

// Operator & Admin Protected Routes (Role: Operator or Super Admin)
Route::prefix('operator')->middleware('role:operator,super_admin')->group(function () {
    // Dashboard Statistics
    Route::get('/dashboard-stats', [OperatorApiController::class, 'dashboardStats'])->name('api.operator.dashboard-stats');

    // All Users Management
    Route::get('/users', [OperatorApiController::class, 'getUsers'])->name('api.operator.users.index');
    Route::get('/users/{id}', [OperatorApiController::class, 'getUserDetails'])->name('api.operator.users.show');
    Route::match(['put', 'post'], '/users/{id}', [OperatorApiController::class, 'updateUser'])->name('api.operator.users.update');

    // Banner Management
    Route::get('/banners', [OperatorApiController::class, 'getBanners'])->name('api.operator.banners.index');
    Route::post('/banners', [OperatorApiController::class, 'storeBanner'])->name('api.operator.banners.store');
    Route::match(['put', 'post'], '/banners/{id}', [OperatorApiController::class, 'updateBanner'])->name('api.operator.banners.update');
    Route::post('/banners/{id}/status', [OperatorApiController::class, 'toggleBannerStatus'])->name('api.operator.banners.status');
    Route::delete('/banners/{id}', [OperatorApiController::class, 'deleteBanner'])->name('api.operator.banners.delete');

    // Gallery Management
    Route::get('/gallery', [OperatorApiController::class, 'getGallery'])->name('api.operator.gallery.index');
    Route::post('/gallery', [OperatorApiController::class, 'storeGallery'])->name('api.operator.gallery.store');
    Route::delete('/gallery/{id}', [OperatorApiController::class, 'deleteGallery'])->name('api.operator.gallery.delete');

    // Payment Management
    Route::get('/payments', [OperatorApiController::class, 'getPayments'])->name('api.operator.payments.index');
    Route::post('/payments/{id}/correct-status', [OperatorApiController::class, 'correctPaymentStatus'])->name('api.operator.payments.correct');
});

// Super Admin Only Protected Routes (Role: Super Admin)
Route::prefix('admin-rbac')->middleware('role:super_admin')->group(function () {
    Route::get('/audit-logs', [OperatorApiController::class, 'getAuditLogs'])->name('api.admin.audit-logs');
});
