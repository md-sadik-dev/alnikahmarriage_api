<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
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
Route::post('/login', [AuthApiController::class, 'login'])->middleware('throttle:5,1')->name('login.post');
Route::post('/signup', [AuthApiController::class, 'register'])->name('signup.post');
Route::get('/logout', [AuthApiController::class, 'logout'])->name('logout.get');
Route::post('/logout', [AuthApiController::class, 'logout'])->name('logout.post');
Route::post('/forgot-password/request', [AuthApiController::class, 'forgotPasswordRequest'])->name('forgot-password.request');
Route::post('/forgot-password/reset', [AuthApiController::class, 'forgotPasswordReset'])->name('forgot-password.reset');
Route::post('/contact-us', [PageController::class, 'submitFeedback'])->name('contact.post');
Route::post('/feedback', [PageController::class, 'submitFeedback'])->name('feedback.post');

Route::get('api/photo/blurred/{filename}', [ProfileApiController::class, 'getBlurredPhoto']);
Route::get('api/photo/{filename}', [ProfileApiController::class, 'getPhoto']);

// Route direct storage/users and uploads access through the authorization-checked endpoint
Route::get('storage/users/{filename}', [ProfileApiController::class, 'getPhoto']);
Route::get('uploads/user-image/{filename}', [ProfileApiController::class, 'getPhoto']);
Route::get('uploads/{filename}', [ProfileApiController::class, 'getPhoto']);

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

// Web Migration Route: Disabled by default for production security.
// To run migrations safely, use CLI: php artisan migrate
// If strictly needed on shared hosting, set ALLOW_WEB_MIGRATIONS=true and MIGRATION_ROUTE_SECRET in .env
Route::get('/run-migration-live', function (Request $request) {
    $allowWebMigrations = filter_var(env('ALLOW_WEB_MIGRATIONS', false), FILTER_VALIDATE_BOOLEAN);
    $secretKey = env('MIGRATION_ROUTE_SECRET');

    if (!$allowWebMigrations || empty($secretKey) || !hash_equals($secretKey, (string)$request->query('key'))) {
        abort(404);
    }

    try {
        ini_set('max_execution_time', 300);
        Artisan::call('migrate', ['--force' => true]);
        $output = Artisan::output();
        return '<div style="font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; padding: 25px; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; border-radius: 10px; max-width: 650px; margin: 50px auto;">'
            . '<h2 style="margin-top:0; color: #15803d;">✅ Migration Executed Successfully!</h2>'
            . '<pre style="background: #ffffff; padding: 15px; border-radius: 6px; color: #334155; border: 1px solid #e2e8f0; overflow-x: auto; font-size: 13px;">' 
            . htmlspecialchars($output ?: 'Database is already up to date! Nothing to migrate.') 
            . '</pre>'
            . '</div>';
    } catch (\Throwable $e) {
        return '<div style="font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; padding: 25px; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 10px; max-width: 650px; margin: 50px auto;">'
            . '<h2 style="margin-top:0; color: #b91c1c;">❌ Migration Error</h2>'
            . '<p style="background: #ffffff; padding: 15px; border-radius: 6px; color: #334155; border: 1px solid #fee2e2; font-size: 13px;">' 
            . htmlspecialchars($e->getMessage()) 
            . '</p>'
            . '</div>';
    }
});