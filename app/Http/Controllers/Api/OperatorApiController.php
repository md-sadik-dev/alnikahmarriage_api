<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Banner;
use App\Models\Gallery;
use App\Models\Payment;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;

class OperatorApiController extends Controller
{
    /**
     * Get Operator Dashboard Overview & Real Statistics.
     */
    public function dashboardStats(Request $request): JsonResponse
    {
        $totalUsers = User::where(function ($q) {
            $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
        })->count();

        $bridesCount = User::where(function ($q) {
            $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
        })->where('user_type', 'BRIDE')->count();

        $groomsCount = User::where(function ($q) {
            $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
        })->where('user_type', 'GROOM')->count();

        $verifiedUsers = User::where(function ($q) {
            $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
        })->where('is_verified', 1)->count();

        $paidUsers = User::where(function ($q) {
            $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
        })->whereNotNull('package')
          ->where('package', '!=', '')
          ->where('package', '!=', 'FREE REGISTRATION')
          ->where('package', '!=', 'None')
          ->count();

        $totalBanners = DB::table('banner')->count();
        $activeBanners = DB::table('banner')->where('status', 1)->count();

        $totalGallery = DB::table('gallery')->count();
        $activeGallery = DB::table('gallery')->where('status', 1)->count();

        $totalPayments = Payment::count();
        $successfulPayments = Payment::where('payment_status', 'SUCCESS')->count();
        $totalRevenue = (float) Payment::where('payment_status', 'SUCCESS')->sum('amount');
        $pendingPayments = Payment::where('payment_status', 'PENDING')->count();

        $recentActivity = AuditLog::orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $recentUsers = User::where(function ($q) {
            $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
        })
        ->orderBy('user_id', 'desc')
        ->limit(5)
        ->select('user_id', 'profile_id', 'name', 'user_type', 'package', 'mobile', 'created_at')
        ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'users' => [
                    'total' => $totalUsers,
                    'brides' => $bridesCount,
                    'grooms' => $groomsCount,
                    'verified' => $verifiedUsers,
                    'paid' => $paidUsers,
                ],
                'banners' => [
                    'total' => $totalBanners,
                    'active' => $activeBanners,
                ],
                'gallery' => [
                    'total' => $totalGallery,
                    'active' => $activeGallery,
                ],
                'payments' => [
                    'total' => $totalPayments,
                    'successful' => $successfulPayments,
                    'pending' => $pendingPayments,
                    'total_revenue' => $totalRevenue,
                    'currency' => '₹',
                ],
                'recent_activity' => $recentActivity,
                'recent_users' => $recentUsers,
            ],
        ]);
    }

    // =========================================================================
    // 1. ALL USERS MANAGEMENT (Operator permitted viewing, searching, updating approved fields)
    // =========================================================================

    /**
     * List all registered users with server-side pagination, search, and filtering.
     */
    public function getUsers(Request $request): JsonResponse
    {
        $query = User::query()
            ->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
            });

        // Search by keyword: name, email, mobile, profile_id
        if ($request->filled('search')) {
            $s = trim($request->input('search'));
            $query->where(function ($q) use ($s) {
                $q->where('name', 'LIKE', "%{$s}%")
                  ->orWhere('email', 'LIKE', "%{$s}%")
                  ->orWhere('mobile', 'LIKE', "%{$s}%")
                  ->orWhere('profile_id', 'LIKE', "%{$s}%");
            });
        }

        // Filter by gender / user_type (BRIDE or GROOM)
        if ($request->filled('user_type')) {
            $query->where('user_type', strtoupper(trim($request->input('user_type'))));
        }

        // Filter by verification status
        if ($request->filled('is_verified')) {
            $query->where('is_verified', (int) $request->input('is_verified'));
        }

        // Filter by active status
        if ($request->filled('is_active')) {
            $query->where('is_active', (int) $request->input('is_active'));
        }

        // Filter by package
        if ($request->filled('package')) {
            $pkg = trim($request->input('package'));
            if ($pkg === 'PAID') {
                $query->whereNotNull('package')
                      ->where('package', '!=', '')
                      ->where('package', '!=', 'FREE REGISTRATION')
                      ->where('package', '!=', 'None');
            } elseif ($pkg === 'FREE') {
                $query->where(function ($q) {
                    $q->whereNull('package')
                      ->orWhere('package', '')
                      ->orWhere('package', 'FREE REGISTRATION')
                      ->orWhere('package', 'None');
                });
            } else {
                $query->where('package', 'LIKE', "%{$pkg}%");
            }
        }

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));
        $users = $query->orderBy('user_id', 'desc')->paginate($perPage);

        // Transform collection to exclude confidential passwords and add photo URLs
        $storageUrl = url('/storage/users/');
        $transformed = $users->getCollection()->map(function ($u) use ($storageUrl) {
            $photoUrl = null;
            if (!empty($u->profile_pic)) {
                $photoUrl = url("/api/photo/" . rawurlencode($u->profile_pic));
            }

            return [
                'user_id' => $u->user_id,
                'profile_id' => $u->profile_id,
                'name' => $u->name,
                'user_type' => $u->user_type,
                'email' => $u->email,
                'mobile' => $u->mobile,
                'alternate_mobile' => $u->alternate_mobile,
                'dob' => $u->dob,
                'height' => $u->height,
                'complexion' => $u->complexion,
                'marital_status' => $u->marital_status,
                'religion' => $u->religion,
                'sect' => $u->sect,
                'caste' => $u->caste,
                'qualification' => $u->qualification,
                'work' => $u->work,
                'income' => $u->income,
                'address' => $u->address,
                'prefered_location' => $u->prefered_location,
                'package' => $u->package,
                'start_date' => $u->start_date,
                'end_date' => $u->end_date,
                'is_active' => (int) $u->is_active,
                'is_verified' => (int) $u->is_verified,
                'delete_request' => (int) $u->delete_request,
                'profile_pic' => $photoUrl,
                'created_at' => $u->created_at ? $u->created_at->format('Y-m-d H:i') : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $transformed,
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Get detailed record of a single registered user.
     */
    public function getUserDetails($id): JsonResponse
    {
        $user = User::where('user_id', $id)
            ->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
            })
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or account is deleted.',
            ], 404);
        }

        // Additional user photos from users_images table
        $additionalPhotos = DB::table('users_images')
            ->where('user_id', $user->user_id)
            ->get()
            ->map(function ($img) {
                return [
                    'id' => $img->id ?? $img->image_id ?? 0,
                    'url' => url("/api/photo/" . rawurlencode($img->images)),
                ];
            });

        $userArray = $user->makeHidden(['password'])->toArray();
        if (!empty($user->profile_pic)) {
            $userArray['profile_pic_url'] = url("/api/photo/" . rawurlencode($user->profile_pic));
        }
        $userArray['additional_photos'] = $additionalPhotos;

        return response()->json([
            'success' => true,
            'data' => $userArray,
        ]);
    }

    /**
     * Update approved fields of a registered user.
     * Enforces strict RBAC:
     * - Operators CANNOT change role, delete users, or modify credentials.
     * - Records audit log.
     */
    public function updateUser(Request $request, $id): JsonResponse
    {
        $user = User::where('user_id', $id)
            ->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
            })
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found or account is deleted.',
            ], 404);
        }

        // Explicitly prohibited fields
        if ($request->has('role') || $request->has('password') || $request->has('is_deleted') || $request->has('delete_approved')) {
            $currentRole = $request->attributes->get('auth_role');
            if ($currentRole !== 'super_admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Security Error: Operators are not authorized to modify user roles, passwords, or deletion approvals.',
                ], 403);
            }
        }

        // Approved fields validator
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:100',
            'dob' => 'nullable|string|max:50',
            'height' => 'nullable|string|max:50',
            'complexion' => 'nullable|string|max:50',
            'marital_status' => 'nullable|string|max:50',
            'disability' => 'nullable|string|max:255',
            'qualification' => 'nullable|string|max:100',
            'work' => 'nullable|string|max:100',
            'religion' => 'nullable|string|max:50',
            'sect' => 'nullable|string|max:50',
            'caste' => 'nullable|string|max:50',
            'accommodation' => 'nullable|string|max:100',
            'income' => 'nullable|string|max:50',
            'f_name' => 'nullable|string|max:255',
            'm_name' => 'nullable|string|max:100',
            'sister' => 'nullable|string|max:10',
            'brother' => 'nullable|string|max:10',
            'prefered_location' => 'nullable|string|max:500',
            'email' => 'nullable|email|max:100',
            'mobile' => 'nullable|string|max:25',
            'alternate_mobile' => 'nullable|string|max:25',
            'address' => 'nullable|string|max:500',
            'n_address' => 'nullable|string|max:500',
            'additional_info' => 'nullable|string|max:2000',
            'is_active' => 'nullable|in:0,1',
            'is_verified' => 'nullable|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $approvedFields = [
            'name', 'dob', 'height', 'complexion', 'marital_status', 'disability',
            'qualification', 'work', 'religion', 'sect', 'caste', 'accommodation',
            'income', 'f_name', 'm_name', 'sister', 'brother', 'prefered_location',
            'email', 'mobile', 'alternate_mobile', 'address', 'n_address',
            'additional_info', 'is_active', 'is_verified',
        ];

        $oldValues = [];
        $newValues = [];

        foreach ($approvedFields as $field) {
            if ($request->has($field)) {
                $oldVal = $user->{$field};
                $newVal = $request->input($field);

                if ((string)$oldVal !== (string)$newVal) {
                    $oldValues[$field] = $oldVal;
                    $newValues[$field] = $newVal;
                    $user->{$field} = $newVal;
                }
            }
        }

        if (empty($newValues)) {
            return response()->json([
                'success' => true,
                'message' => 'No changes detected.',
                'data' => $user->makeHidden(['password']),
            ]);
        }

        $user->modified_at = Carbon::now();
        $user->save();

        // Dispatch notifications on verification status transition
        if (isset($newValues['is_verified'])) {
            try {
                $notificationService = app(NotificationService::class);
                $isVer = (int) $newValues['is_verified'];
                $notificationService->notifyProfileApproval($user, $isVer, (int) ($user->is_active ?? 1));

                // If profile became newly verified and active, identify matching members and notify them
                if ($isVer === 1 && (int) ($user->is_active ?? 1) === 1) {
                    $notificationService->notifyNewMatchingProfile($user);
                }
            } catch (\Throwable $e) {}
        }

        // Record Audit Log
        AuditLog::record(
            'USER_UPDATE',
            'users',
            (string) $user->user_id,
            $oldValues,
            $newValues,
            $request->input('reason', 'Operator updated user profile details'),
            $request->attributes->get('auth_user'),
            'user'
        );


        return response()->json([
            'success' => true,
            'message' => 'User profile updated successfully.',
            'changed_fields' => array_keys($newValues),
            'data' => $user->makeHidden(['password']),
        ]);
    }

    // =========================================================================
    // 2. BANNER MANAGEMENT
    // =========================================================================

    /**
     * List all banners.
     */
    public function getBanners(): JsonResponse
    {
        $banners = DB::table('banner')->orderBy('id', 'desc')->get()->map(function ($b) {
            $imgUrl = null;
            if (!empty($b->image)) {
                $imgUrl = url('/storage/banner/' . $b->image);
            }
            $b->image_url = $imgUrl;
            return $b;
        });

        return response()->json([
            'success' => true,
            'data' => $banners,
        ]);
    }

    /**
     * Create a new banner.
     */
    public function storeBanner(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'eyebrow' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'btn_text' => 'nullable|string|max:100',
            'slug' => 'nullable|string|max:500',
            'image' => 'required|file|mimes:jpeg,jpg,png,webp|max:5120',
            'status' => 'nullable|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('image');
        $safeName = time() . '-' . Str::slug($request->input('title', 'banner')) . '.' . $file->getClientOriginalExtension();
        $bannerDir = storage_path('app/public/banner');
        if (!is_dir($bannerDir)) {
            @mkdir($bannerDir, 0755, true);
        }
        $file->move($bannerDir, $safeName);

        $authUser = $request->attributes->get('auth_user');
        $actorId = $authUser ? ($authUser->id ?? $authUser->user_id ?? 1) : 1;

        $id = DB::table('banner')->insertGetId([
            'title' => $request->input('title'),
            'eyebrow' => $request->input('eyebrow'),
            'description' => $request->input('description'),
            'btn_text' => $request->input('btn_text'),
            'slug' => $request->input('slug'),
            'image' => $safeName,
            'image_alt' => $request->input('title'),
            'image_title' => $request->input('title'),
            'status' => (int) $request->input('status', 1),
            'created_by' => $actorId,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        AuditLog::record(
            'BANNER_CREATE',
            'banners',
            (string) $id,
            null,
            ['title' => $request->input('title'), 'image' => $safeName],
            'Banner created by ' . ($authUser ? $authUser->username ?? 'Operator' : 'Operator'),
            $authUser,
            'banner'
        );

        return response()->json([
            'success' => true,
            'message' => 'Banner added successfully.',
            'banner_id' => $id,
        ], 201);
    }

    /**
     * Update an existing banner.
     */
    public function updateBanner(Request $request, $id): JsonResponse
    {
        $banner = DB::table('banner')->where('id', $id)->first();
        if (!$banner) {
            return response()->json(['success' => false, 'message' => 'Banner not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'eyebrow' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'btn_text' => 'nullable|string|max:100',
            'slug' => 'nullable|string|max:500',
            'image' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:5120',
            'status' => 'nullable|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $updateData = [
            'title' => $request->input('title'),
            'eyebrow' => $request->input('eyebrow'),
            'description' => $request->input('description'),
            'btn_text' => $request->input('btn_text'),
            'slug' => $request->input('slug'),
            'status' => (int) $request->input('status', $banner->status),
            'updated_at' => Carbon::now(),
        ];

        // Handle image replacement
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $safeName = time() . '-' . Str::slug($request->input('title', 'banner')) . '.' . $file->getClientOriginalExtension();
            $bannerDir = storage_path('app/public/banner');
            $file->move($bannerDir, $safeName);

            // Remove old image
            if (!empty($banner->image) && file_exists($bannerDir . '/' . $banner->image)) {
                @unlink($bannerDir . '/' . $banner->image);
            }

            $updateData['image'] = $safeName;
        }

        DB::table('banner')->where('id', $id)->update($updateData);

        AuditLog::record(
            'BANNER_UPDATE',
            'banners',
            (string) $id,
            (array) $banner,
            $updateData,
            'Banner updated',
            $request->attributes->get('auth_user'),
            'banner'
        );

        return response()->json([
            'success' => true,
            'message' => 'Banner updated successfully.',
        ]);
    }

    /**
     * Toggle banner active status.
     */
    public function toggleBannerStatus($id): JsonResponse
    {
        $banner = DB::table('banner')->where('id', $id)->first();
        if (!$banner) {
            return response()->json(['success' => false, 'message' => 'Banner not found.'], 404);
        }

        $newStatus = ($banner->status == 1) ? 0 : 1;
        DB::table('banner')->where('id', $id)->update([
            'status' => $newStatus,
            'updated_at' => Carbon::now(),
        ]);

        AuditLog::record(
            'BANNER_STATUS_TOGGLE',
            'banners',
            (string) $id,
            ['status' => $banner->status],
            ['status' => $newStatus],
            'Banner status toggled to ' . ($newStatus ? 'active' : 'inactive'),
            request()->attributes->get('auth_user'),
            'banner'
        );

        return response()->json([
            'success' => true,
            'message' => 'Banner status updated to ' . ($newStatus ? 'Active' : 'Inactive') . '.',
            'new_status' => $newStatus,
        ]);
    }

    /**
     * Delete banner.
     */
    public function deleteBanner($id): JsonResponse
    {
        $banner = DB::table('banner')->where('id', $id)->first();
        if (!$banner) {
            return response()->json(['success' => false, 'message' => 'Banner not found.'], 404);
        }

        // Delete image file safely
        if (!empty($banner->image)) {
            $bannerFile = storage_path('app/public/banner/' . basename($banner->image));
            if (file_exists($bannerFile)) {
                @unlink($bannerFile);
            }
        }

        DB::table('banner')->where('id', $id)->delete();

        AuditLog::record(
            'BANNER_DELETE',
            'banners',
            (string) $id,
            (array) $banner,
            null,
            'Banner deleted with confirmation',
            request()->attributes->get('auth_user'),
            'banner'
        );

        return response()->json([
            'success' => true,
            'message' => 'Banner deleted successfully.',
        ]);
    }

    // =========================================================================
    // 3. GALLERY MANAGEMENT
    // =========================================================================

    /**
     * List all gallery items.
     */
    public function getGallery(): JsonResponse
    {
        $gallery = DB::table('gallery')->orderBy('id', 'desc')->get()->map(function ($g) {
            $imgUrl = null;
            if (!empty($g->image)) {
                $imgUrl = url('/storage/gallery/' . $g->image);
            }
            $g->image_url = $imgUrl;
            return $g;
        });

        return response()->json([
            'success' => true,
            'data' => $gallery,
        ]);
    }

    /**
     * Upload a new gallery image.
     */
    public function storeGallery(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|file|mimes:jpeg,jpg,png,webp|max:5120',
            'home_visibility' => 'nullable|in:0,1',
            'status' => 'nullable|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('image');
        $safeName = time() . '-gallery-' . Str::random(8) . '.' . $file->getClientOriginalExtension();
        $galleryDir = storage_path('app/public/gallery');
        if (!is_dir($galleryDir)) {
            @mkdir($galleryDir, 0755, true);
        }
        $file->move($galleryDir, $safeName);

        $id = DB::table('gallery')->insertGetId([
            'image' => $safeName,
            'home_visibility' => (int) $request->input('home_visibility', 1),
            'status' => (int) $request->input('status', 1),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        AuditLog::record(
            'GALLERY_UPLOAD',
            'gallery',
            (string) $id,
            null,
            ['image' => $safeName],
            'Gallery image uploaded',
            $request->attributes->get('auth_user'),
            'gallery'
        );

        return response()->json([
            'success' => true,
            'message' => 'Gallery image uploaded successfully.',
            'gallery_id' => $id,
            'image_url' => url('/storage/gallery/' . $safeName),
        ], 201);
    }

    /**
     * Delete gallery entry.
     */
    public function deleteGallery($id): JsonResponse
    {
        $item = DB::table('gallery')->where('id', $id)->first();
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Gallery item not found.'], 404);
        }

        // Delete image file safely without path traversal
        if (!empty($item->image)) {
            $baseName = basename($item->image);
            $filePath = storage_path('app/public/gallery/' . $baseName);
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        DB::table('gallery')->where('id', $id)->delete();

        AuditLog::record(
            'GALLERY_DELETE',
            'gallery',
            (string) $id,
            (array) $item,
            null,
            'Gallery image deleted with confirmation',
            request()->attributes->get('auth_user'),
            'gallery'
        );

        return response()->json([
            'success' => true,
            'message' => 'Gallery entry deleted successfully.',
        ]);
    }

    // =========================================================================
    // 4. PAYMENT MANAGEMENT (Audited status correction & financial security)
    // =========================================================================

    /**
     * List all payments with search, filter, and server-side pagination.
     */
    public function getPayments(Request $request): JsonResponse
    {
        $query = Payment::query();

        // Search by transaction ID, user name, email, mobile, profile_id
        if ($request->filled('search')) {
            $s = trim($request->input('search'));
            $query->where(function ($q) use ($s) {
                $q->where('transaction_id', 'LIKE', "%{$s}%")
                  ->orWhere('user_name', 'LIKE', "%{$s}%")
                  ->orWhere('user_email', 'LIKE', "%{$s}%")
                  ->orWhere('user_mobile', 'LIKE', "%{$s}%")
                  ->orWhere('profile_id', 'LIKE', "%{$s}%");
            });
        }

        // Filter by payment status (SUCCESS, PENDING, FAILED, REFUNDED)
        if ($request->filled('status')) {
            $query->where('payment_status', strtoupper(trim($request->input('status'))));
        }

        // Filter by payment method
        if ($request->filled('payment_method')) {
            $query->where('payment_method', 'LIKE', '%' . trim($request->input('payment_method')) . '%');
        }

        // Date range filter
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->input('end_date'));
        }

        $perPage = max(1, min((int) $request->input('per_page', 15), 100));
        $payments = $query->orderBy('payment_id', 'desc')->paginate($perPage);

        // Summaries
        $summary = [
            'total_count' => Payment::count(),
            'total_success_amount' => (float) Payment::where('payment_status', 'SUCCESS')->sum('amount'),
            'pending_count' => Payment::where('payment_status', 'PENDING')->count(),
            'failed_count' => Payment::where('payment_status', 'FAILED')->count(),
            'refunded_count' => Payment::where('payment_status', 'REFUNDED')->count(),
        ];

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'data' => $payments->items(),
            'pagination' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    /**
     * Limited, Audited Payment Status Correction.
     * Enforces strict financial security:
     * - Requires mandatory reason.
     * - Logs operator ID, previous status, new status, reason, timestamp.
     * - Strictly prevents changing transaction amount or gateway reference.
     * - Restricts refunds to Super Admin only.
     */
    public function correctPaymentStatus(Request $request, $id): JsonResponse
    {
        $payment = Payment::where('payment_id', $id)->first();
        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Payment record not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:SUCCESS,PENDING,FAILED,REFUNDED',
            'reason' => 'required|string|min:5|max:500',
        ], [
            'status.required' => 'Please select the target payment status.',
            'reason.required' => 'A mandatory reason is required for any manual payment status correction.',
            'reason.min' => 'Please provide an explanatory reason (at least 5 characters).',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $currentRole = $request->attributes->get('auth_role');
        $authUser = $request->attributes->get('auth_user');
        $actorId = $authUser ? ($authUser->id ?? $authUser->user_id ?? null) : null;
        $newStatus = strtoupper(trim($request->input('status')));
        $reason = trim($request->input('reason'));

        // Safeguard: Refunds restricted to Super Admin only
        if ($newStatus === 'REFUNDED' && $currentRole !== 'super_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Access Denied: Refund processing is restricted to Super Admin financial workflows.',
            ], 403);
        }

        // Safeguard: Prohibit arbitrary changes to amounts or gateway references
        if ($request->has('amount') || $request->has('gateway_reference') || $request->has('transaction_id')) {
            return response()->json([
                'success' => false,
                'message' => 'Financial Security Rule: Modifying transaction amount, transaction ID, or gateway references is strictly prohibited.',
            ], 403);
        }

        $oldStatus = $payment->payment_status;
        if ($oldStatus === $newStatus) {
            return response()->json([
                'success' => true,
                'message' => 'Payment status is already ' . $newStatus . '.',
            ]);
        }

        // Execute update in transaction
        DB::beginTransaction();
        try {
            $payment->payment_status = $newStatus;
            $payment->correction_status = 'CORRECTED';
            $payment->correction_reason = $reason;
            $payment->corrected_by = $actorId;
            $payment->corrected_at = Carbon::now();

            if ($newStatus === 'SUCCESS' && empty($payment->activated_at)) {
                $payment->activated_at = Carbon::now();

                // If user package not yet activated, activate it
                $user = User::find($payment->user_id);
                if ($user && $user->package !== $payment->plan_name) {
                    $plan = Plan::find($payment->plan_id);
                    $user->package = $payment->plan_name;
                    $user->is_verified = 1;
                    $user->is_active = 1;
                    $user->start_date = Carbon::now()->format('Y-m-d');
                    $user->end_date = Carbon::now()->addMonths(3)->format('Y-m-d');
                    $user->modified_at = Carbon::now();
                    $user->save();
                }
            }

            $payment->save();

            // Record Audit Log with old and new status
            AuditLog::record(
                'PAYMENT_STATUS_CORRECTION',
                'payments',
                (string) $payment->payment_id,
                ['status' => $oldStatus],
                ['status' => $newStatus, 'reason' => $reason, 'corrected_by' => $actorId],
                $reason,
                $authUser,
                'payment'
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Payment status updated from {$oldStatus} to {$newStatus} successfully.",
                'data' => $payment,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment status: ' . $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // 5. AUDIT LOGS (Super Admin review)
    // =========================================================================

    /**
     * Get audit logs list.
     */
    public function getAuditLogs(Request $request): JsonResponse
    {
        $query = AuditLog::query();

        if ($request->filled('module')) {
            $query->where('module', $request->input('module'));
        }
        if ($request->filled('action')) {
            $query->where('action', 'LIKE', '%' . $request->input('action') . '%');
        }

        $perPage = max(1, min((int) $request->input('per_page', 20), 100));
        $logs = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
