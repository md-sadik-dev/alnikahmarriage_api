<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Api\AuthApiController;

class ProfileApiController extends Controller
{
    /**
     * Get list of matrimonial profiles from database.
     *
     * @param Request $request
     * @return JsonResponse
     */
    /**
     * Get list of matrimonial profiles from database implementing 5 core business matching rules.
     *
     * Rule 1: Opposite Gender / User Type Rule & Exclude Self (Verified & Active only)
     * Rule 2: Firqa / Sect Isolation Rule (Shia vs Sunni)
     * Rule 3: Marital Status & Age Matching Rule (Age >= 40: Divorced/Khula; Age < 40: strictly match status)
     * Rule 4: Day-by-Day Profile Unlocking Progression Limit (Days * 2)
     * Rule 5: Search Matching Rules (Forcefully retain opposite gender & isolation)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $viewer = $this->resolveViewer($request);
        $query = User::query();

        // Base criteria: is_deleted = 0, is_verified = 1 (matching old core PHP getAllProfile)
        $query->where(function ($q) {
            $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
        });
        $query->where('is_verified', 1);

        // RULE 1: Opposite Gender Rule & Exclude Self
        if ($viewer) {
            $viewerType = strtoupper(trim($viewer->user_type ?? ''));
            $oppositeType = ($viewerType === 'BRIDE') ? 'GROOM' : 'BRIDE';
            $query->where('user_type', $oppositeType);
            $query->where('user_id', '<>', $viewer->user_id);

            // RULE 2: Firqa / Sect Isolation Rule (Shia vs Sunni)
            if ($viewer->isShia()) {
                // Shia/Bohra users match only Shia or Bohra profiles
                $query->where(function ($q) {
                    $q->where('religion', 'LIKE', '%shia%')
                      ->orWhere('religion', 'LIKE', '%bohra%')
                      ->orWhere('sect', 'LIKE', '%shia%')
                      ->orWhere('sect', 'LIKE', '%bohra%');
                });
            } else {
                // Non-Shia users (Sunni/Deobandi/Barelvi/Ahle Hadees etc.) match strictly non-Shia profiles
                $query->where(function ($q) {
                    $q->where('religion', 'NOT LIKE', '%shia%')
                      ->where('religion', 'NOT LIKE', '%bohra%')
                      ->where('sect', 'NOT LIKE', '%shia%')
                      ->where('sect', 'NOT LIKE', '%bohra%');
                });
            }

            // RULE 3: Marital Status & Age Matching Rule (Applied when user has NOT explicitly filtered by marital status)
            $hasExplicitStatusFilter = $request->filled('marital_status') || $request->filled('status') || $request->filled('maritalStatus');
            if (!$hasExplicitStatusFilter) {
                $userAge = $viewer->calculateAge();
                $userMarital = strtolower(trim($viewer->marital_status ?? ''));
                if ($userAge >= 40) {
                    // Rule A: Age >= 40 -> Regardless of marital status, only Divorced or Khula match
                    $query->where(function ($q) {
                        $q->whereRaw("(LOWER(marital_status) LIKE '%divor%' OR LOWER(marital_status) LIKE '%khul%')");
                    });
                } else {
                    // Rule B: Age < 40
                    if (str_contains($userMarital, 'unmarri') || $userMarital === 'single' || str_contains($userMarital, 'never marri')) {
                        $query->where(function ($q) {
                            $q->whereRaw("(LOWER(marital_status) LIKE '%unmarri%' OR LOWER(marital_status) = 'single' OR LOWER(marital_status) LIKE '%never marri%')");
                        });
                    } elseif (str_contains($userMarital, 'divor') || str_contains($userMarital, 'khul')) {
                        $query->where(function ($q) {
                            $q->whereRaw("(LOWER(marital_status) LIKE '%divor%' OR LOWER(marital_status) LIKE '%khul%')");
                        });
                    }
                }
            }
        } else {
            // Guest browsing: filter by gender if explicitly requested
            if ($request->filled('type') || $request->filled('gender')) {
                $gender = strtoupper(trim($request->input('type', $request->input('gender'))));
                if ($gender === 'GROOM' || $gender === 'BRIDE') {
                    $query->where('user_type', $gender);
                }
            }
        }

        $isSearch = $request->boolean('is_search')
            || $request->filled('firqa')
            || $request->filled('religion')
            || $request->filled('sect')
            || $request->filled('maslak')
            || $request->filled('caste')
            || $request->filled('status')
            || $request->filled('marital_status')
            || $request->filled('maritalStatus')
            || $request->filled('city')
            || $request->filled('search')
            || $request->filled('q');


        // RULE 5 / Step 3: Additional Search & Filter Form Inputs
        // Firqa / Religion filter (users.religion)
        if ($request->filled('firqa') || $request->filled('religion')) {
            $firqa = trim($request->input('firqa', $request->input('religion')));
            if ($firqa !== 'All' && !empty($firqa)) {
                $query->where(function ($q) use ($firqa) {
                    $q->where('religion', 'LIKE', "%{$firqa}%")
                      ->orWhere('sect', 'LIKE', "%{$firqa}%");
                });
            }
        }

        // Maslak / Sect filter (users.sect) with spelling variants normalization
        if ($request->filled('maslak') || $request->filled('sect')) {
            $maslak = trim($request->input('maslak', $request->input('sect')));
            if ($maslak !== 'All' && !empty($maslak)) {
                $maslakLower = strtolower($maslak);
                $query->where(function ($q) use ($maslak, $maslakLower) {
                    if (str_contains($maslakLower, 'barelv') || str_contains($maslakLower, 'baralv')) {
                        $q->where('sect', 'LIKE', '%baral%')
                          ->orWhere('sect', 'LIKE', '%barel%');
                    } elseif (str_contains($maslakLower, 'deoband') || str_contains($maslakLower, 'devband')) {
                        $q->where('sect', 'LIKE', '%devb%')
                          ->orWhere('sect', 'LIKE', '%deob%');
                    } elseif (str_contains($maslakLower, 'salafi') || str_contains($maslakLower, 'hadees') || str_contains($maslakLower, 'hadish')) {
                        $q->where('sect', 'LIKE', '%salafi%')
                          ->orWhere('sect', 'LIKE', '%hadees%')
                          ->orWhere('sect', 'LIKE', '%hadish%');
                    } elseif (str_contains($maslakLower, 'hanafi') || str_contains($maslakLower, 'hanfi')) {
                        $q->where('sect', 'LIKE', '%hanfi%')
                          ->orWhere('sect', 'LIKE', '%hanafi%')
                          ->orWhere('sect', 'LIKE', '%sunni%');
                    } else {
                        $q->where('sect', 'LIKE', "%{$maslak}%")
                          ->orWhere('religion', 'LIKE', "%{$maslak}%");
                    }
                });
            }
        }

        // Caste filter (users.caste) with spelling variants normalization
        if ($request->filled('caste')) {
            $caste = trim($request->input('caste'));
            if ($caste !== 'All' && !empty($caste)) {
                $casteLower = strtolower($caste);
                $query->where(function ($q) use ($caste, $casteLower) {
                    if (str_contains($casteLower, 'quresh') || str_contains($casteLower, 'qurash')) {
                        $q->where('caste', 'LIKE', '%qur%');
                    } elseif (str_contains($casteLower, 'sheikh') || str_contains($casteLower, 'shaikh')) {
                        $q->where('caste', 'LIKE', '%sheikh%')
                          ->orWhere('caste', 'LIKE', '%shaikh%');
                    } elseif (str_contains($casteLower, 'pathan') || str_contains($casteLower, 'khan')) {
                        $q->where('caste', 'LIKE', '%pathan%')
                          ->orWhere('caste', 'LIKE', '%khan%');
                    } else {
                        $q->where('caste', 'LIKE', "%{$caste}%");
                    }
                });
            }
        }

        // City filter (users.n_address, address, prefered_location)
        if ($request->filled('city')) {
            $city = trim($request->input('city'));
            if ($city !== 'All' && !empty($city)) {
                $query->where(function ($q) use ($city) {
                    $q->where('n_address', 'LIKE', "%{$city}%")
                      ->orWhere('address', 'LIKE', "%{$city}%")
                      ->orWhere('prefered_location', 'LIKE', "%{$city}%");
                });
            }
        }

        // Marital Status explicit filter (users.marital_status) for both logged-in and guest users
        if ($request->filled('marital_status') || $request->filled('status') || $request->filled('maritalStatus')) {
            $statusInput = trim($request->input('marital_status', $request->input('status', $request->input('maritalStatus'))));
            if ($statusInput !== 'All' && !empty($statusInput)) {
                $statusLower = strtolower($statusInput);
                $query->where(function ($q) use ($statusInput, $statusLower) {
                    if (str_contains($statusLower, 'never') || str_contains($statusLower, 'unmarr') || $statusLower === 'single') {
                        $q->whereRaw("(LOWER(marital_status) LIKE '%unmarr%' OR LOWER(marital_status) = 'single' OR LOWER(marital_status) LIKE '%never marri%')");
                    } elseif (str_contains($statusLower, 'divorc') || str_contains($statusLower, 'khul')) {
                        $q->whereRaw("(LOWER(marital_status) LIKE '%divor%' OR LOWER(marital_status) LIKE '%khul%')");
                    } elseif (str_contains($statusLower, 'widow')) {
                        $q->whereRaw("(LOWER(marital_status) LIKE '%widow%')");
                    } elseif (str_contains($statusLower, 'separat')) {
                        $q->whereRaw("(LOWER(marital_status) LIKE '%separat%')");
                    } else {
                        $q->where('marital_status', 'LIKE', "%{$statusInput}%");
                    }
                });
            }
        }

        // Keyword Search
        if ($request->filled('search') || $request->filled('q')) {
            $search = trim($request->input('search', $request->input('q')));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('profile_id', 'LIKE', "%{$search}%")
                  ->orWhere('qualification', 'LIKE', "%{$search}%")
                  ->orWhere('work', 'LIKE', "%{$search}%")
                  ->orWhere('caste', 'LIKE', "%{$search}%")
                  ->orWhere('n_address', 'LIKE', "%{$search}%");
            });
        }

        // Order by active status first, then latest created
        $query->orderBy('is_active', 'desc')->orderBy('user_id', 'desc');

        // RULE 4: Day-by-Day Profile Unlocking Progression Limit (Days * 2)
        $unlockedProfileCount = $viewer ? $viewer->getUnlockedProfileCount() : 20;
        $daysSinceStart = $viewer ? max(1, (int) ($unlockedProfileCount / 2)) : 1;

        // Dynamic limit matching old core PHP: default to user's unlocked progression limit (Days * 2)
        if ($request->filled('limit')) {
            $limit = (int) $request->input('limit');
            if ($limit <= 0) {
                $limit = $viewer ? $unlockedProfileCount : 20;
            } else {
                $maxAllowed = $viewer ? max($unlockedProfileCount, 500) : 100;
                $limit = min($limit, $maxAllowed);
            }
        } else {
            // When no limit is passed, default to user's daily progression quota (e.g. 214 for Liaqat, 262 for Farzana)
            $limit = $viewer ? $unlockedProfileCount : 20;
        }

        // Apply daily progression limit if requested or feed mode
        if ($request->boolean('daily_limit') || $request->input('mode') === 'daily') {
            $limit = min($limit, $unlockedProfileCount);
        }

        $viewerHasPackage = $viewer ? $viewer->isPackageActive() : false;
        $isPackageExpired = $viewer ? $viewer->isPackageExpired() : false;

        if ($request->boolean('paginate', false)) {
            $paginator = $query->paginate($limit);
            $transformed = $this->formatBatchProfiles($paginator->getCollection(), false, $viewer, $request);
            $paginator->setCollection($transformed);

            return response()->json([
                'success' => true,
                'message' => 'Profiles retrieved successfully.',
                'viewer_has_package' => $viewerHasPackage,
                'is_package_expired' => $isPackageExpired,
                'daily_unlocked_limit' => $unlockedProfileCount,
                'days_since_start' => $daysSinceStart,
                'target_gender' => $viewer ? (($viewer->user_type === 'BRIDE') ? 'GROOM' : 'BRIDE') : null,
                'data' => $paginator,
            ]);
        }

        $users = $query->take($limit)->get();
        $formatted = $this->formatBatchProfiles($users, false, $viewer, $request);

        return response()->json([
            'success' => true,
            'message' => 'Profiles retrieved successfully.',
            'viewer_has_package' => $viewerHasPackage,
            'is_package_expired' => $isPackageExpired,
            'daily_unlocked_limit' => $unlockedProfileCount,
            'days_since_start' => $daysSinceStart,
            'target_gender' => $viewer ? (($viewer->user_type === 'BRIDE') ? 'GROOM' : 'BRIDE') : null,
            'count' => $formatted->count(),
            'data' => $formatted,
        ]);
    }

    /**
     * Get featured profiles for homepage carousel.
     * Logged-in users see matching opposite gender under Shia/Sunni isolation.
     * Guests see a mix of Grooms and Brides.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function featured(Request $request): JsonResponse
    {
        $viewer = $this->resolveViewer($request);
        $limitEach = (int) $request->input('limit_each', 4);
        if ($limitEach < 1 || $limitEach > 20) {
            $limitEach = 4;
        }

        $baseQuery = function () use ($viewer) {
            $q = User::where(function ($sub) {
                $sub->whereNull('is_deleted')->orWhere('is_deleted', 0);
            })->where('is_active', 1)->where('is_verified', 1);

            if ($viewer) {
                $q->where('user_id', '<>', $viewer->user_id);
                if ($viewer->isShia()) {
                    $q->where(function ($sq) {
                        $sq->where('religion', 'LIKE', '%shia%')
                           ->orWhere('religion', 'LIKE', '%bohra%')
                           ->orWhere('sect', 'LIKE', '%shia%')
                           ->orWhere('sect', 'LIKE', '%bohra%');
                    });
                } else {
                    $q->where(function ($sq) {
                        $sq->where('religion', 'NOT LIKE', '%shia%')
                           ->where('religion', 'NOT LIKE', '%bohra%')
                           ->where('sect', 'NOT LIKE', '%shia%')
                           ->where('sect', 'NOT LIKE', '%bohra%');
                    });
                }
            }

            return $q;
        };

        if ($viewer) {
            // Logged in: show opposite gender only
            $oppositeType = (strtoupper(trim($viewer->user_type ?? '')) === 'BRIDE') ? 'GROOM' : 'BRIDE';
            $featuredUsers = $baseQuery()
                ->where('user_type', $oppositeType)
                ->orderBy('is_active', 'desc')
                ->orderBy('user_id', 'desc')
                ->take($limitEach * 2)
                ->get();
            $featured = $this->formatBatchProfiles($featuredUsers, false, $viewer, $request);

            return response()->json([
                'success' => true,
                'message' => 'Featured matched profiles retrieved successfully.',
                'viewer_has_package' => $viewer->isPackageActive(),
                'is_package_expired' => $viewer->isPackageExpired(),
                'total' => $featured->count(),
                'data' => $featured,
            ]);
        }

        // Guest: show balanced mix of grooms and brides
        $grooms = $baseQuery()->where('user_type', 'GROOM')
            ->orderBy('is_active', 'desc')
            ->orderBy('user_id', 'desc')
            ->take($limitEach)
            ->get();

        $brides = $baseQuery()->where('user_type', 'BRIDE')
            ->orderBy('is_active', 'desc')
            ->orderBy('user_id', 'desc')
            ->take($limitEach)
            ->get();

        $combined = $this->formatBatchProfiles($grooms->concat($brides), false, $viewer, $request);

        return response()->json([
            'success' => true,
            'message' => 'Featured profiles retrieved successfully.',
            'viewer_has_package' => false,
            'is_package_expired' => false,
            'total' => $combined->count(),
            'grooms_count' => $grooms->count(),
            'brides_count' => $brides->count(),
            'data' => $combined,
        ]);
    }

    /**
     * Get single profile by ID or Profile Code.
     *
     * @param Request $request
     * @param string|int $id
     * @return JsonResponse
     */
    public function show(Request $request, $id): JsonResponse
    {
        $user = User::where('user_id', $id)
            ->orWhere('profile_id', $id)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found.',
            ], 404);
        }

        $viewer = $this->resolveViewer($request);

        // Security / Authorization Check: expired subscriber attempting to open profile
        if ($viewer && $viewer->user_id != $user->user_id) {
            if ($viewer->isPackageExpired()) {
                return response()->json([
                    'success' => false,
                    'view_status' => 'package_expired',
                    'message' => 'Your package has expired. Please renew your package.',
                    'is_package_expired' => true,
                    'data' => $this->formatUserProfile($user, false, $viewer, $request),
                ], 403);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile details retrieved successfully.',
            'viewer_has_package' => $viewer ? $viewer->isPackageActive() : false,
            'is_package_expired' => $viewer ? $viewer->isPackageExpired() : false,
            'data' => $this->formatUserProfile($user, true, $viewer, $request),
        ]);
    }

    /**
     * Unlock candidate contact details using view quota (Section C Paywall Protection).
     *
     * Check 1: User's package expired (end_date < today) -> package_expired alert
     * Check 2: Already viewed (profile_views table) -> viewed (direct view, no count deducted)
     * Check 3: Total package view limit exceeded (use_package_profile_limit >= package_profile_limit) -> limit_reached_package
     * Check 4: Success -> use_package_profile_limit + 1, insert into profile_views
     *
     * @param Request $request
     * @param string|int $id
     * @return JsonResponse
     */
    public function unlockProfile(Request $request, $id): JsonResponse
    {
        $viewer = $this->resolveViewer($request);
        if (!$viewer) {
            return response()->json([
                'success' => false,
                'view_status' => 'login_required',
                'message' => 'Please login to view candidate contact details.',
            ], 401);
        }

        $user = User::where('user_id', $id)
            ->orWhere('profile_id', $id)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found.',
            ], 404);
        }

        // Self profile always unlocked
        if ($viewer->user_id == $user->user_id) {
            return response()->json([
                'success' => true,
                'view_status' => 'self',
                'message' => 'This is your own profile.',
                'data' => $this->formatUserProfile($user, true, $viewer, $request),
            ]);
        }

        // Check 1: Has this profile already been viewed by the logged-in user? (Always accessible even if package expired)
        if ($viewer->hasViewedProfile($user->user_id)) {
            return response()->json([
                'success' => true,
                'view_status' => 'viewed',
                'message' => 'Candidate contact details already unlocked.',
                'data' => $this->formatUserProfile($user, true, $viewer, $request),
            ]);
        }

        // Check 2: User's package expired? (Blocks unlocking NEW unviewed profiles)
        if ($viewer->isPackageExpired()) {
            return response()->json([
                'success' => false,
                'view_status' => 'package_expired',
                'message' => 'Your membership package has expired. Please renew your package to view candidate contact details.',
            ], 403);
        }

        // Check 3: Check package view limits & unlock atomically within a database transaction
        return \Illuminate\Support\Facades\DB::transaction(function () use ($viewer, $user, $request) {
            $limits = \Illuminate\Support\Facades\DB::table('profile_view_limits')
                ->where('viewer_user_id', $viewer->user_id)
                ->lockForUpdate()
                ->first();

            if (!$limits) {
                return response()->json([
                    'success' => false,
                    'view_status' => 'limit_reached_package',
                    'message' => 'No active package view limit found. Please select a membership package to unlock contacts.',
                ], 403);
            }

            // Verify if unlocked in concurrent transaction
            $alreadyViewed = \Illuminate\Support\Facades\DB::table('profile_views')
                ->where('viewer_user_id', $viewer->user_id)
                ->where('profile_user_id', $user->user_id)
                ->exists();

            if ($alreadyViewed) {
                return response()->json([
                    'success' => true,
                    'view_status' => 'viewed',
                    'message' => 'Candidate contact details already unlocked.',
                    'data' => $this->formatUserProfile($user, true, $viewer, $request),
                ]);
            }

            $packageLimit = (int) $limits->package_profile_limit;
            $usePackageLimit = (int) $limits->use_package_profile_limit;

            if ($usePackageLimit >= $packageLimit) {
                return response()->json([
                    'success' => false,
                    'view_status' => 'limit_reached_package',
                    'message' => 'You have reached your package profile view limit (' . $packageLimit . ' profiles). Please upgrade your package to unlock more contacts.',
                    'package_profile_limit' => $packageLimit,
                    'use_package_profile_limit' => $usePackageLimit,
                    'remaining_views' => 0,
                ], 403);
            }

            \Illuminate\Support\Facades\DB::table('profile_view_limits')
                ->where('limit_id', $limits->limit_id)
                ->increment('use_package_profile_limit');

            \Illuminate\Support\Facades\DB::table('profile_views')->insert([
                'viewer_user_id' => $viewer->user_id,
                'profile_user_id' => $user->user_id,
                'viewed_at' => now(),
            ]);

            $newUsed = $usePackageLimit + 1;
            $remainingViews = max(0, $packageLimit - $newUsed);

            return response()->json([
                'success' => true,
                'view_status' => 'success',
                'message' => 'Candidate contact details unlocked successfully! (' . $remainingViews . ' views remaining)',
                'package_profile_limit' => $packageLimit,
                'use_package_profile_limit' => $newUsed,
                'remaining_views' => $remainingViews,
                'data' => $this->formatUserProfile($user, true, $viewer, $request),
            ]);
        });
    }

    /**
     * Helper to resolve the viewing user (viewer) from request context.
     *
     * @param Request|null $request
     * @return User|null
     */
    public function resolveViewer(?Request $request = null): ?User
    {
        $req = $request ?: request();

        // 1. Authenticated member attribute from middleware
        $member = $req->attributes->get('authenticated_member');
        if ($member instanceof User) {
            return $member;
        }

        // 2. Laravel Auth facade
        if (\Illuminate\Support\Facades\Auth::check()) {
            $user = \Illuminate\Support\Facades\Auth::user();
            if ($user instanceof User) {
                return $user;
            }
        }

        // 3. Active Session user_id
        $userId = session('user_id');
        if ($userId) {
            $user = User::where('user_id', $userId)
                ->where(function ($q) {
                    $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                })
                ->first();
            if ($user) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Mask candidate full name for blurred/unauthorized visibility.
     * Example: "DARAKHSHAN ANJUM" -> "D********* A****"
     *
     * @param string|null $name
     * @return string
     */
    public function maskName(?string $name): string
    {
        $name = trim($name ?? '');
        if (empty($name)) {
            return 'Confidential Member';
        }

        $words = preg_split('/\s+/', $name);
        $maskedWords = [];

        foreach ($words as $word) {
            $len = mb_strlen($word);
            if ($len <= 2) {
                $maskedWords[] = mb_substr($word, 0, 1) . '*';
            } elseif ($len <= 4) {
                $maskedWords[] = mb_substr($word, 0, 1) . str_repeat('*', $len - 1);
            } else {
                $maskedWords[] = mb_substr($word, 0, 1) . str_repeat('*', min(6, $len - 1));
            }
        }

        return implode(' ', $maskedWords);
    }

    /**
     * Helper to transform a User model into standardized Profile format
     * enforcing 3-day registration window and payment-based blur/contact rules.
     *
     * @param User $user
     * @param bool $detailed
    /**
     * Format a collection of users efficiently with pre-fetched relations to eliminate N+1 queries.
     */
    public function formatBatchProfiles($users, bool $detailed = false, $viewerUser = null, ?Request $request = null)
    {
        if (empty($users) || (is_object($users) && method_exists($users, 'isEmpty') && $users->isEmpty())) {
            return collect();
        }

        $viewer = $viewerUser ?: $this->resolveViewer($request ?: request());
        $userCollection = collect($users);
        $userIds = $userCollection->pluck('user_id')->filter()->toArray();

        // 1. Single query for all users_images
        $allImagesGrouped = \Illuminate\Support\Facades\DB::table('users_images')
            ->whereIn('user_id', $userIds)
            ->orderByDesc('main')
            ->orderBy('image_id', 'asc')
            ->get()
            ->groupBy('user_id');

        // 2. Single query for viewed profile records
        $viewedMap = [];
        if ($viewer) {
            $viewedIds = \Illuminate\Support\Facades\DB::table('profile_views')
                ->where('viewer_user_id', $viewer->user_id)
                ->whereIn('profile_user_id', $userIds)
                ->pluck('profile_user_id')
                ->toArray();
            $viewedMap = array_flip($viewedIds);
        }

        // 3. Pre-fetch limits once for viewer
        $viewerLimits = $viewer ? $viewer->getProfileViewLimits() : null;

        $preloaded = [
            'images' => $allImagesGrouped,
            'viewed' => $viewedMap,
            'limits' => $viewerLimits,
        ];

        return $userCollection->map(function ($u) use ($detailed, $viewer, $request, $preloaded) {
            return $this->formatUserProfile($u, $detailed, $viewer, $request, $preloaded);
        });
    }

    /**
     * Format a single user profile response contract.
     * Supports optional $preloaded data array to avoid N+1 queries.
     *
     * @param User $user
     * @param bool $detailed
     * @param User|null $viewerUser
     * @param Request|null $request
     * @param array $preloaded
     * @return array
     */
    public function formatUserProfile($user, bool $detailed = false, $viewerUser = null, ?Request $request = null, array $preloaded = []): array
    {
        $viewer = $viewerUser ?: $this->resolveViewer($request ?: request());
        $isSelf = ($viewer && $viewer->user_id == $user->user_id);

        $targetHasPaid = $user->hasActivePaidPackage();
        $viewerHasPaid = $viewer ? $viewer->hasActivePaidPackage() : false;

        $daysSinceRegistration = $user->daysSinceRegistration();
        $isWithin3Days = $user->isWithin3DayTrial();

        // -------------------------------------------------------------
        // ACCESS CONTROL DECISION ENGINE (Section C Paywall Protection)
        // -------------------------------------------------------------
        $hasViewed = false;
        $viewStatus = 'login_required';
        $limits = null;
        $remainingLimit = 0;

        if ($isSelf) {
            $isBlurred = false;
            $canViewContact = true;
            $viewStatus = 'self';
        } elseif ($viewer) {
            $hasViewed = isset($preloaded['viewed']) ? isset($preloaded['viewed'][$user->user_id]) : $viewer->hasViewedProfile($user->user_id);
            $limits = array_key_exists('limits', $preloaded) ? $preloaded['limits'] : $viewer->getProfileViewLimits();
            $pkgLimit = $limits ? (int) $limits->package_profile_limit : 0;
            $usedLimit = $limits ? (int) $limits->use_package_profile_limit : 0;
            $remainingLimit = max(0, $pkgLimit - $usedLimit);

            $isPackageExpired = $viewer->isPackageExpired();
            $isViewerVerified = ((int) ($viewer->is_verified ?? 0) === 1);

            if ($isViewerVerified) {
                // Verified member on dashboard: photos and names are unblurred!
                $isBlurred = false;
                $canViewContact = !$isPackageExpired;
                $viewStatus = $isPackageExpired ? 'package_expired' : ($hasViewed ? 'viewed' : 'unlocked');

                if ($detailed && !$hasViewed && !$isPackageExpired) {
                    try {
                        \Illuminate\Support\Facades\DB::table('profile_views')->insert([
                            'viewer_user_id' => $viewer->user_id,
                            'profile_user_id' => $user->user_id,
                            'viewed_at' => now(),
                        ]);
                    } catch (\Throwable $e) {}
                }
            } else {
                // Unverified member viewing
                $isBlurred = true;
                $canViewContact = false;
                $viewStatus = 'login_required';
            }
        } else {
            // Guest visitor: blurred photo & locked contacts
            $isBlurred = true;
            $canViewContact = false;
            $viewStatus = 'login_required';
        }

        $isGroom = strtoupper(trim($user->user_type ?? '')) === 'GROOM';
        $genderTitle = $isGroom ? 'Groom' : 'Bride';
        $badgeClass = $isGroom ? 'badge-groom' : 'badge-bride';

        // Profile ID formatting
        $profileId = $user->profile_id;
        if (empty($profileId)) {
            $prefix = $isGroom ? 'ALN-G-' : 'ALN-B-';
            $profileId = $prefix . $user->user_id;
        }

        // Helper to locate physical file on server
        $resolveLocalPhoto = function ($filename) {
            return $this->findLocalPhotoPath($filename);
        };

        // Helper to find web path
        $checkPhoto = function ($filename) use ($resolveLocalPhoto) {
            if (empty($filename)) return null;
            $local = $resolveLocalPhoto($filename);
            return $local ? url('/api/photo/' . rawurlencode($filename)) : null;
        };

        // Image URL and Stream resolution
        $base64Photo = null;
        $imageUrl = null;
        $photoStreamUrl = null;
        $allImages = [];
        $photosGallery = [];

        // Fetch all photos for this user from users_images (support both new and legacy records)
        $seenFilenames = [];
        if (isset($preloaded['images'])) {
            $dbImages = $preloaded['images']->get($user->user_id, collect());
        } else {
            $dbImages = \Illuminate\Support\Facades\DB::table('users_images')
                ->where('user_id', $user->user_id)
                ->orderByDesc('main')
                ->orderBy('image_id', 'asc')
                ->get();
        }

        if ($dbImages->isNotEmpty()) {
            foreach ($dbImages as $row) {
                $rawImg = trim($row->images ?? '');
                if (empty($rawImg)) continue;
                $key = strtolower($rawImg);
                if (isset($seenFilenames[$key])) continue;
                $seenFilenames[$key] = true;

                $isMainPhoto = ((int) $row->main === 1) || (!empty($user->profile_pic) && $rawImg === $user->profile_pic);
                $localPath = $resolveLocalPhoto($rawImg);

                $photoUrl = $isBlurred
                    ? url('/api/photo/blurred/' . rawurlencode($rawImg))
                    : url('/api/photo/' . rawurlencode($rawImg));

                if (!in_array($photoUrl, $allImages)) {
                    $allImages[] = $photoUrl;
                }

                $photosGallery[] = [
                    'id' => (int) $row->image_id,
                    'filename' => $rawImg,
                    'url' => $photoUrl,
                    'main' => $isMainPhoto ? 1 : 0,
                ];

                if ($isMainPhoto && !$imageUrl) {
                    $imageUrl = $photoUrl;
                    $photoStreamUrl = $photoUrl;
                    if (!$isBlurred && $localPath) {
                        $mime = @mime_content_type($localPath) ?: 'image/jpeg';
                        $fileBytes = @file_get_contents($localPath);
                        if ($fileBytes) {
                            $base64Photo = 'data:' . $mime . ';base64,' . base64_encode($fileBytes);
                        }
                    }
                }
            }
        }

        // Include users.profile_pic if present and not already added from users_images
        $mainPic = trim($user->profile_pic ?? '');
        if (!empty($mainPic)) {
            $key = strtolower($mainPic);
            if (!isset($seenFilenames[$key])) {
                $seenFilenames[$key] = true;
                $localPath = $resolveLocalPhoto($mainPic);
                $photoUrl = $isBlurred
                    ? url('/api/photo/blurred/' . rawurlencode($mainPic))
                    : url('/api/photo/' . rawurlencode($mainPic));

                $isMain = empty($imageUrl);
                $item = [
                    'id' => 0,
                    'filename' => $mainPic,
                    'url' => $photoUrl,
                    'main' => $isMain ? 1 : 0,
                ];

                if ($isMain) {
                    array_unshift($photosGallery, $item);
                    array_unshift($allImages, $photoUrl);
                    $imageUrl = $photoUrl;
                    $photoStreamUrl = $photoUrl;
                    if (!$isBlurred && $localPath) {
                        $mime = @mime_content_type($localPath) ?: 'image/jpeg';
                        $fileBytes = @file_get_contents($localPath);
                        if ($fileBytes) {
                            $base64Photo = 'data:' . $mime . ';base64,' . base64_encode($fileBytes);
                        }
                    }
                } else {
                    $photosGallery[] = $item;
                    if (!in_array($photoUrl, $allImages)) {
                        $allImages[] = $photoUrl;
                    }
                }
            }
        }

        // Include users.profile_pic1 (secondary photo for old/legacy users)
        $secPic = trim($user->profile_pic1 ?? '');
        if (!empty($secPic)) {
            $key = strtolower($secPic);
            if (!isset($seenFilenames[$key])) {
                $seenFilenames[$key] = true;
                $localPath = $resolveLocalPhoto($secPic);
                $photoUrl = $isBlurred
                    ? url('/api/photo/blurred/' . rawurlencode($secPic))
                    : url('/api/photo/' . rawurlencode($secPic));

                $photosGallery[] = [
                    'id' => 0,
                    'filename' => $secPic,
                    'url' => $photoUrl,
                    'main' => empty($imageUrl) ? 1 : 0,
                ];
                if (!in_array($photoUrl, $allImages)) {
                    $allImages[] = $photoUrl;
                }
            }
        }

        // Ensure primary image URLs are defined if images exist
        if (!$imageUrl && !empty($allImages)) {
            $imageUrl = $allImages[0];
            $photoStreamUrl = $allImages[0];
            if (!empty($photosGallery)) {
                $photosGallery[0]['main'] = 1;
                $localPath = $resolveLocalPhoto($photosGallery[0]['filename']);
                if (!$isBlurred && $localPath) {
                    $mime = @mime_content_type($localPath) ?: 'image/jpeg';
                    $fileBytes = @file_get_contents($localPath);
                    if ($fileBytes) {
                        $base64Photo = 'data:' . $mime . ';base64,' . base64_encode($fileBytes);
                    }
                }
            }
        }

        // Format City
        $city = !empty($user->n_address) ? trim($user->n_address) : (!empty($user->address) ? trim($user->address) : 'Not Specified');
        $city = preg_replace('/\s+/', ' ', $city);
        $displayCity = (strlen($city) > 40) ? substr($city, 0, 37) . '...' : $city;

        // Resolve Firqa & Maslak accurately
        $rel = strtoupper(trim($user->religion ?? ''));
        $sct = strtoupper(trim($user->sect ?? ''));

        $firqa = 'Sunni';
        if (str_contains($rel, 'SHIA') || str_contains($sct, 'SHIA') || str_contains($sct, 'BOHRA')) {
            $firqa = 'Shia';
        }

        $maslak = 'NO BAR';
        if (!empty($user->sect) && !in_array($sct, ['SUNNI', 'SHIA', 'NO-DETAIL', 'ISLAM', ''])) {
            $maslak = trim($user->sect);
        } elseif (!empty($user->religion) && !in_array($rel, ['SUNNI', 'SHIA', 'ISLAM', ''])) {
            $maslak = trim($user->religion);
        }

        // Display Name: masked if blurred, full if unblurred
        $displayName = $isBlurred ? $this->maskName($user->name) : ($user->name ?? '');

        // Contact info: masked/null if not authorized
        $mobileVal = $canViewContact ? (!empty(trim($user->mobile ?? '')) ? trim($user->mobile) : '') : null;
        $altMobileVal = $canViewContact ? (!empty(trim($user->alternate_mobile ?? '')) ? trim($user->alternate_mobile) : '') : null;
        $contactNumberVal = $canViewContact ? ($mobileVal ?: $altMobileVal) : null;
        $emailVal = $canViewContact ? (!empty(trim($user->email ?? '')) ? trim($user->email) : '') : null;
        $addressVal = $canViewContact ? (!empty(trim($user->address ?? '')) ? trim($user->address) : $displayCity) : null;
        $nAddressVal = $canViewContact ? (!empty(trim($user->n_address ?? '')) ? trim($user->n_address) : '') : null;

        $data = [
            'id' => $user->user_id,
            'profile_id' => $profileId,
            'name' => $displayName,
            'user_type' => $user->user_type ?? ($isGroom ? 'GROOM' : 'BRIDE'),
            'gender' => $genderTitle,
            'gender_badge' => $genderTitle,
            'badge_class' => $badgeClass,
            'is_verified' => (bool) ($user->is_verified == 1),
            'image' => $imageUrl,
            'image_base64' => $base64Photo,
            'photo_stream_url' => $photoStreamUrl,
            'images' => $allImages,
            'images_count' => count($allImages),
            'photos_gallery' => $photosGallery,
            
            // Access control and visibility flags
            'is_blurred' => $isBlurred,
            'is_name_blurred' => $isBlurred,
            'is_photo_blurred' => $isBlurred,
            'can_view_contact' => $canViewContact,
            'contact_locked' => !$canViewContact,
            'contact_locked_message' => !$canViewContact ? 'Mobile number and address are protected. Please purchase or use your profile view quota to unlock contact details.' : '',
            'view_status' => $viewStatus,
            'has_viewed' => $hasViewed,
            'remaining_views' => $remainingLimit,
            'is_package_expired' => $viewer ? $viewer->isPackageExpired() : false,
            'target_has_paid_package' => $targetHasPaid,
            'viewer_has_paid_package' => $viewerHasPaid,
            'days_since_registration' => $daysSinceRegistration,
            'is_within_3_days' => $isWithin3Days,
            'membership_status' => $targetHasPaid ? 'PAID_ACTIVE' : ($isWithin3Days ? 'TRIAL_ACTIVE' : 'TRIAL_EXPIRED'),
            'viewer_status' => $viewerHasPaid ? 'PAID_MEMBER' : ($viewer ? 'FREE_MEMBER' : 'GUEST'),

            'education' => !empty($user->qualification) ? trim($user->qualification) : '',
            'profession' => !empty($user->work) ? trim($user->work) : '',
            'age_dob' => !empty($user->dob) ? trim($user->dob) : '',
            'firqa' => $firqa,
            'maslak' => $maslak,
            'caste' => !empty($user->caste) ? trim($user->caste) : '',
            'status' => !empty($user->marital_status) ? ucfirst(strtolower(trim($user->marital_status))) : '',
            'city' => $displayCity,
            'full_city' => $city,

            'contact_number' => $contactNumberVal,
            'mobile' => $mobileVal,
            'alternate_mobile' => $altMobileVal,
            'email' => $emailVal,
            'address' => $addressVal,
            'n_address' => $nAddressVal,

            'religion' => !empty(trim($user->religion ?? '')) ? trim($user->religion) : $firqa,
            'sect' => !empty(trim($user->sect ?? '')) ? trim($user->sect) : $maslak,
            'marital_status' => !empty(trim($user->marital_status ?? '')) ? trim($user->marital_status) : '',
            'complexion' => !empty(trim($user->complexion ?? '')) ? trim($user->complexion) : '',
            'accommodation' => !empty(trim($user->accommodation ?? '')) ? trim($user->accommodation) : '',
            'f_name' => $canViewContact ? (!empty(trim($user->f_name ?? '')) ? trim($user->f_name) : '') : null,
            'm_name' => $canViewContact ? (!empty(trim($user->m_name ?? '')) ? trim($user->m_name) : '') : null,
            'brother' => isset($user->brother) && $user->brother !== '' ? trim($user->brother) : '',
            'sister' => isset($user->sister) && $user->sister !== '' ? trim($user->sister) : '',
            'height' => !empty(trim($user->height ?? '')) ? trim($user->height) : '',
            'dob' => !empty(trim($user->dob ?? '')) ? trim($user->dob) : '',
            'income' => !empty(trim($user->income ?? '')) ? trim($user->income) : '',
            'qualification' => !empty(trim($user->qualification ?? '')) ? trim($user->qualification) : '',
            'work' => !empty(trim($user->work ?? '')) ? trim($user->work) : '',
            'additional_info' => !empty(trim($user->additional_info ?? '')) ? trim($user->additional_info) : '',
            'prefered_location' => !empty(trim($user->prefered_location ?? '')) ? trim($user->prefered_location) : '',
            'delete_request' => (int) ($user->delete_request ?? 0),
            'delete_approved' => (int) ($user->delete_approved ?? 0),
            'delete_reason' => $user->delete_reason ?? null,
            'delete_requested_at' => $user->delete_requested_at ? (string) $user->delete_requested_at : null,
            'delete_approved_at' => $user->delete_approved_at ? (string) $user->delete_approved_at : null,
        ];

        if ($detailed) {
            $data['height'] = !empty(trim($user->height ?? '')) ? trim($user->height) : '';
            $data['complexion'] = !empty(trim($user->complexion ?? '')) ? ucfirst(strtolower(trim($user->complexion))) : '';
            $data['income'] = (!empty(trim($user->income ?? '')) && strtolower(trim($user->income)) !== 'n/a' && strtolower(trim($user->income)) !== 'no-detail') ? trim($user->income) : '';
            $data['accommodation'] = !empty(trim($user->accommodation ?? '')) ? trim($user->accommodation) : '';
            $data['father_name'] = $canViewContact ? ((!empty(trim($user->f_name ?? '')) && strtolower(trim($user->f_name)) !== 'no-detail') ? trim($user->f_name) : '') : null;
            $data['mother_name'] = $canViewContact ? ((!empty(trim($user->m_name ?? '')) && strtolower(trim($user->m_name)) !== 'no-detail') ? trim($user->m_name) : '') : null;
            $data['brothers'] = isset($user->brother) && $user->brother !== '' && strtolower(trim($user->brother)) !== 'no-detail' ? trim($user->brother) : '';
            $data['sisters'] = isset($user->sister) && $user->sister !== '' && strtolower(trim($user->sister)) !== 'no-detail' ? trim($user->sister) : '';
            $data['prefered_location'] = !empty(trim($user->prefered_location ?? '')) ? trim($user->prefered_location) : '';
            $data['disability'] = (!empty(trim($user->disability ?? '')) && strtolower(trim($user->disability)) !== 'none' && strtolower(trim($user->disability)) !== 'no-detail') ? trim($user->disability) : 'None';
            $data['mother_tongue'] = (!empty(trim($user->mother_tongue ?? '')) && strtolower(trim($user->mother_tongue)) !== 'no-detail') ? trim($user->mother_tongue) : '';
            $data['package'] = !empty(trim($user->package ?? '')) ? trim($user->package) : 'FREE REGISTRATION';
            $data['created_at_formatted'] = $user->created_at ? $user->created_at->format('M Y') : 'Active Member';
            $data['signup_date'] = $user->created_at ? $user->created_at->toIso8601String() : null;
        }

        return $data;
    }

    /**
     * Update authenticated member's profile.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('authenticated_member');
        if (!$user) {
            $userId = session('user_id');
            if ($userId) {
                $user = User::where('user_id', $userId)
                    ->where(function ($q) {
                        $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                    })
                    ->first();
            }
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Active member session is required.',
            ], 401);
        }

        $inputData = $request->isJson() ? $request->json()->all() : $request->all();
        if (empty($inputData)) {
            $inputData = json_decode($request->getContent(), true) ?: $request->all();
        }

        if (isset($inputData['preferred_location']) && !isset($inputData['prefered_location'])) {
            $inputData['prefered_location'] = $inputData['preferred_location'];
        }

        // Validate allowed editable fields
        $validator = Validator::make($inputData, [
            'name' => 'sometimes|required|string|min:2|max:30',
            'dob' => 'nullable|string|max:20',
            'height' => 'nullable|string|max:25',
            'complexion' => 'nullable|string|max:25',
            'marital_status' => 'nullable|string|max:75',
            'disability' => 'nullable|string|max:255',
            'qualification' => 'nullable|string|max:100',
            'work' => 'nullable|string|max:100',
            'religion' => 'nullable|string|max:25',
            'sect' => 'nullable|string|max:25',
            'caste' => 'nullable|string|max:25',
            'accommodation' => 'nullable|string|max:100',
            'income' => 'nullable|string|max:45',
            'f_name' => 'nullable|string|max:255',
            'm_name' => 'nullable|string|max:45',
            'brother' => 'nullable|string|max:10',
            'sister' => 'nullable|string|max:10',
            'prefered_location' => 'nullable|string|max:500',
            'address' => 'nullable|string|max:500',
            'n_address' => 'nullable|string|max:500',
            'mobile' => 'nullable|string|max:15',
            'alternate_mobile' => 'nullable|string|max:15',
            'email' => 'nullable|email|max:100',
            'additional_info' => 'nullable|string|max:2000',
        ], [
            'name.required' => 'Name cannot be empty.',
            'name.min' => 'Name must be at least 2 characters.',
            'name.max' => 'Name cannot exceed 30 characters.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        // Strict whitelist of permitted editable attributes:
        // Sensitive fields (user_id, profile_id, user_type, is_active, is_verified,
        // is_deleted, package, start_date, end_date, password, policy) are strictly prohibited.
        $allowedFields = [
            'name',
            'dob',
            'height',
            'complexion',
            'marital_status',
            'disability',
            'qualification',
            'work',
            'religion',
            'sect',
            'caste',
            'accommodation',
            'income',
            'f_name',
            'm_name',
            'brother',
            'sister',
            'prefered_location',
            'address',
            'n_address',
            'email',
            'mobile',
            'alternate_mobile',
            'additional_info',
        ];

        $existingColumns = \Illuminate\Support\Facades\Schema::getColumnListing('users');
        $updatedCount = 0;
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $inputData) && in_array($field, $existingColumns)) {
                $val = $inputData[$field];
                $user->$field = is_string($val) ? trim($val) : $val;
                $updatedCount++;
            }
        }

        if ($updatedCount === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No valid editable fields were provided in the update request.',
            ], 422);
        }

        $user->modified_at = now();
        $user->save();

        // Refresh session representation if active
        if (session('user_id') == $user->user_id) {
            session([
                'user' => $user->fresh()->toArray(),
                'user_name' => $user->name,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => $this->formatUserProfile($user->fresh(), true),
        ], 200);
    }

    /**
     * Change authenticated member's password securely.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function changePassword(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('authenticated_member');
        if (!$user) {
            $userId = session('user_id');
            if ($userId) {
                $user = User::where('user_id', $userId)
                    ->where(function ($q) {
                        $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                    })
                    ->first();
            }
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Active member session is required.',
            ], 401);
        }

        $inputData = $request->isJson() ? $request->json()->all() : $request->all();
        if (empty($inputData)) {
            $inputData = json_decode($request->getContent(), true) ?: $request->all();
        }

        $validator = Validator::make($inputData, [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|max:100|different:current_password',
            'new_password_confirmation' => 'nullable|string|same:new_password',
        ], [
            'current_password.required' => 'Please enter your current password.',
            'new_password.required' => 'Please enter a new password.',
            'new_password.min' => 'New password must be at least 6 characters.',
            'new_password.different' => 'New password cannot be the same as your current password.',
            'new_password_confirmation.same' => 'Password confirmation does not match.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $currentPassword = $inputData['current_password'];
        $newPassword = $inputData['new_password'];

        // Verify existing password against stored value
        if (!AuthApiController::verifyPassword($currentPassword, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'The current password you entered is incorrect.',
                'errors' => [
                    'current_password' => ['The current password entered is incorrect.']
                ],
            ], 422);
        }

        // Store new password
        $user->password = trim($newPassword);
        $user->modified_at = now();
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password has been updated successfully.',
        ], 200);
    }

    /**
     * Submit request to permanently delete account (requires admin approval).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function requestPermanentDelete(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('authenticated_member');
        if (!$user) {
            $userId = session('user_id');
            if ($userId) {
                $user = User::where('user_id', $userId)
                    ->where(function ($q) {
                        $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                    })
                    ->first();
            }
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Active member session is required.',
            ], 401);
        }

        $inputData = $request->isJson() ? $request->json()->all() : $request->all();
        if (empty($inputData)) {
            $inputData = json_decode($request->getContent(), true) ?: $request->all();
        }

        $reason = trim($inputData['reason'] ?? ($inputData['delete_reason'] ?? 'Personal reasons'));
        if (empty($reason)) {
            $reason = 'Personal reasons';
        }

        $user->delete_request = 1;
        $user->delete_reason = substr($reason, 0, 255);
        $user->delete_requested_at = now();
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Permanent deletion request submitted successfully. It is now awaiting Administrator review & approval.',
            'data' => [
                'delete_request' => 1,
                'delete_approved' => (int) ($user->delete_approved ?? 0),
                'delete_reason' => $user->delete_reason,
                'delete_requested_at' => (string) $user->delete_requested_at,
            ],
        ], 200);
    }

    /**
     * Cancel a pending permanent delete request.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function cancelPermanentDeleteRequest(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('authenticated_member');
        if (!$user) {
            $userId = session('user_id');
            if ($userId) {
                $user = User::where('user_id', $userId)
                    ->where(function ($q) {
                        $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                    })
                    ->first();
            }
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Active member session is required.',
            ], 401);
        }

        $user->delete_request = 0;
        $user->delete_reason = null;
        $user->delete_requested_at = null;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Permanent deletion request has been cancelled.',
            'data' => [
                'delete_request' => 0,
                'delete_approved' => (int) ($user->delete_approved ?? 0),
            ],
        ], 200);
    }

    /**
     * Permanently delete account and all associated profile data.
     * STRICT REQUIREMENT: Only executes if Administrator has approved deletion (`delete_approved` === 1).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function permanentDeleteAccount(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('authenticated_member');
        if (!$user) {
            $userId = session('user_id');
            if ($userId) {
                $user = User::where('user_id', $userId)
                    ->where(function ($q) {
                        $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                    })
                    ->first();
            }
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Active member session is required.',
            ], 401);
        }

        // 1. STRICT CHECK: Admin Approval is mandatory!
        if ((int) ($user->delete_approved ?? 0) !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Permanent account deletion cannot proceed because Administrator approval has not been granted yet. Please wait for Admin approval.',
                'error_code' => 'ADMIN_APPROVAL_REQUIRED',
            ], 403);
        }

        $inputData = $request->isJson() ? $request->json()->all() : $request->all();
        if (empty($inputData)) {
            $inputData = json_decode($request->getContent(), true) ?: $request->all();
        }

        // 2. Validate Password for Security
        $password = $inputData['password'] ?? '';
        if (empty($password)) {
            return response()->json([
                'success' => false,
                'message' => 'Please enter your account password to confirm permanent deletion.',
                'errors' => ['password' => ['Password is required to confirm permanent account deletion.']],
            ], 422);
        }

        if (!AuthApiController::verifyPassword($password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'The password you entered is incorrect. Account permanent deletion cancelled.',
                'errors' => ['password' => ['Incorrect password.']],
            ], 422);
        }

        $userId = $user->user_id;

        // 3. Remove physical photo files
        try {
            $userImages = DB::table('users_images')->where('user_id', $userId)->pluck('images')->toArray();
            if (!empty($user->profile_pic)) {
                $userImages[] = $user->profile_pic;
            }
            if (!empty($user->profile_pic1)) {
                $userImages[] = $user->profile_pic1;
            }

            foreach (array_unique(array_filter($userImages)) as $imgFile) {
                $filename = basename($imgFile);
                $paths = [
                    storage_path('app/public/users/' . $filename),
                    public_path('storage/users/' . $filename),
                    public_path('uploads/' . $filename),
                    public_path('uploads/user-image/' . $filename),
                    public_path('uploads/users/' . $filename),
                ];
                foreach ($paths as $p) {
                    if (file_exists($p)) {
                        @unlink($p);
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Error deleting user image files during permanent deletion: ' . $e->getMessage());
        }

        // 4. Clean up all associated database records
        try {
            DB::table('users_images')->where('user_id', $userId)->delete();
            DB::table('interest')->where('user_id', $userId)->orWhere('sender_id', $userId)->delete();
            DB::table('profile_views')->where('viewer_user_id', $userId)->orWhere('profile_user_id', $userId)->delete();
            DB::table('profile_view_limits')->where('viewer_user_id', $userId)->delete();
            DB::table('shortlisted')->where('user_id', $userId)->orWhere('listed_id', $userId)->delete();
            DB::table('recent_visitors')->where('user_id', $userId)->orWhere('visitor_id', $userId)->delete();
            DB::table('viewed_profile')->where('user_id', $userId)->orWhere('viewer_id', $userId)->delete();
            DB::table('login_history')->where('user_id', $userId)->delete();
            DB::table('career_details')->where('user_id', $userId)->delete();
            DB::table('family_details')->where('user_id', $userId)->delete();
        } catch (\Throwable $e) {
            \Log::warning('Error cleaning up associated user tables: ' . $e->getMessage());
        }

        // 5. Permanently delete the user record
        DB::table('users')->where('user_id', $userId)->delete();

        // 6. Invalidate and clear member session
        try {
            session()->forget(['user_id', 'authenticated_member', 'user_email', 'user_name']);
            session()->flush();
            session()->regenerate(true);
        } catch (\Throwable $e) {}

        return response()->json([
            'success' => true,
            'message' => 'Your account and all associated profile data have been permanently deleted from Al-Nikah.',
        ], 200);
    }


    /**
     * Upload and update profile photo for authenticated member.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function uploadPhoto(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('authenticated_member');
        if (!$user) {
            $userId = session('user_id');
            if ($userId) {
                $user = User::where('user_id', $userId)
                    ->where(function ($q) {
                        $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                    })
                    ->first();
            }
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Active member session is required.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'photo' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ], [
            'photo.required' => 'Please select an image file to upload.',
            'photo.image' => 'The uploaded file must be an image.',
            'photo.mimes' => 'Photo must be a JPEG, PNG, JPG, or WebP image.',
            'photo.max' => 'Photo size must not exceed 5MB.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $cleanName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
            $filename = time() . '-profile_pic-' . $cleanName;

            $destDir = storage_path('app/public/users');
            if (!file_exists($destDir)) {
                mkdir($destDir, 0777, true);
            }
            $file->move($destDir, $filename);

            $pubDir = public_path('storage/users');
            if (file_exists($pubDir)) {
                @copy($destDir . '/' . $filename, $pubDir . '/' . $filename);
            }
            $uploadsDir = public_path('uploads');
            if (file_exists($uploadsDir)) {
                @copy($destDir . '/' . $filename, $uploadsDir . '/' . $filename);
            }

            $user->profile_pic = $filename;
            $user->modified_at = now();
            $user->save();

            // Synchronize with users_images table
            \Illuminate\Support\Facades\DB::table('users_images')
                ->where('user_id', $user->user_id)
                ->update(['main' => 0]);

            \Illuminate\Support\Facades\DB::table('users_images')->insert([
                'user_id' => $user->user_id,
                'images' => $filename,
                'main' => 1,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (session('user_id') == $user->user_id) {
                session([
                    'user' => $user->fresh()->toArray(),
                    'user_name' => $user->name,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Profile photo updated successfully.',
                'photo_url' => url('/api/photo/' . rawurlencode($filename)),
                'data' => $this->formatUserProfile($user->fresh(), true),
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => 'No image file found in the request.',
        ], 422);
    }

    /**
     * Add one or more additional profile photos into users_images.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function addPhotos(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('authenticated_member');
        if (!$user) {
            $userId = session('user_id');
            if ($userId) {
                $user = User::where('user_id', $userId)
                    ->where(function ($q) {
                        $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                    })
                    ->first();
            }
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Active member session is required.',
            ], 401);
        }

        // Collect all files from possible input keys (photos, photos[], images, images[], photo)
        $files = [];
        if ($request->hasFile('photos')) {
            $f = $request->file('photos');
            $files = is_array($f) ? $f : [$f];
        } elseif ($request->hasFile('images')) {
            $f = $request->file('images');
            $files = is_array($f) ? $f : [$f];
        } elseif ($request->hasFile('photo')) {
            $files = [$request->file('photo')];
        }

        if (empty($files)) {
            return response()->json([
                'success' => false,
                'message' => 'Please select at least one photo to upload.',
            ], 422);
        }

        $destDir = storage_path('app/public/users');
        if (!file_exists($destDir)) {
            @mkdir($destDir, 0777, true);
        }
        $pubDir = public_path('storage/users');
        if (!file_exists($pubDir)) {
            @mkdir($pubDir, 0777, true);
        }
        $uploadsDir = public_path('uploads');
        if (!file_exists($uploadsDir)) {
            @mkdir($uploadsDir, 0777, true);
        }

        $uploadedCount = 0;
        $hasMain = \Illuminate\Support\Facades\DB::table('users_images')
            ->where('user_id', $user->user_id)
            ->where('main', 1)
            ->where('is_active', 1)
            ->exists();

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

        foreach ($files as $idx => $file) {
            if (!$file || !$file->isValid()) continue;

            // 1. File size limit: max 5MB
            if ($file->getSize() > 5242880) {
                continue;
            }

            // 2. Server-side MIME check
            $mime = $file->getMimeType();
            if (!in_array($mime, $allowedMimes, true)) {
                continue;
            }

            // 3. Structural image verification
            $imgInfo = @getimagesize($file->getPathname());
            if ($imgInfo === false) {
                continue;
            }

            $ext = $extMap[$mime] ?? 'jpg';
            $filename = time() . '-img' . ($idx + 1) . '-' . \Illuminate\Support\Str::random(10) . '.' . $ext;

            $file->move($destDir, $filename);
            @copy($destDir . '/' . $filename, $pubDir . '/' . $filename);
            @copy($destDir . '/' . $filename, $uploadsDir . '/' . $filename);

            $isMain = (!$hasMain && empty($user->profile_pic) && $uploadedCount === 0) ? 1 : 0;
            if ($isMain) {
                $user->profile_pic = $filename;
                $user->save();
                $hasMain = true;
            } elseif (empty($user->profile_pic1)) {
                $user->profile_pic1 = $filename;
                $user->save();
            }

            \Illuminate\Support\Facades\DB::table('users_images')->insert([
                'user_id' => $user->user_id,
                'images' => $filename,
                'main' => $isMain,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $uploadedCount++;
        }

        if ($uploadedCount === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No valid images could be uploaded. Please select JPEG, PNG, or WebP files under 5MB.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "{$uploadedCount} photo(s) added successfully.",
            'data' => $this->formatUserProfile($user->fresh(), true),
        ], 200);
    }

    /**
     * Delete a photo from users_images.
     *
     * @param Request $request
     * @param int|string $id
     * @return JsonResponse
     */
    public function deletePhoto(Request $request, $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('authenticated_member');
        if (!$user) {
            $userId = session('user_id');
            if ($userId) {
                $user = User::where('user_id', $userId)
                    ->where(function ($q) {
                        $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                    })
                    ->first();
            }
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $record = \Illuminate\Support\Facades\DB::table('users_images')
            ->where('image_id', $id)
            ->where('user_id', $user->user_id)
            ->first();

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Photo not found or already deleted.',
            ], 404);
        }

        $wasMain = ((int) $record->main === 1) || ($user->profile_pic === $record->images);

        // Delete from users_images table
        \Illuminate\Support\Facades\DB::table('users_images')
            ->where('image_id', $id)
            ->delete();

        // If it was the main picture, designate next active picture as main
        if ($wasMain) {
            $next = \Illuminate\Support\Facades\DB::table('users_images')
                ->where('user_id', $user->user_id)
                ->where('is_active', 1)
                ->orderBy('image_id', 'asc')
                ->first();

            if ($next) {
                \Illuminate\Support\Facades\DB::table('users_images')
                    ->where('image_id', $next->image_id)
                    ->update(['main' => 1]);
                $user->profile_pic = $next->images;
            } else {
                $user->profile_pic = null;
            }
            $user->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Photo removed successfully.',
            'data' => $this->formatUserProfile($user->fresh(), true),
        ], 200);
    }

    /**
     * Set a photo as main profile photo.
     *
     * @param Request $request
     * @param int|string $id
     * @return JsonResponse
     */
    public function setMainPhoto(Request $request, $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('authenticated_member');
        if (!$user) {
            $userId = session('user_id');
            if ($userId) {
                $user = User::where('user_id', $userId)
                    ->where(function ($q) {
                        $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                    })
                    ->first();
            }
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $record = \Illuminate\Support\Facades\DB::table('users_images')
            ->where('image_id', $id)
            ->where('user_id', $user->user_id)
            ->first();

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Photo not found.',
            ], 404);
        }

        \Illuminate\Support\Facades\DB::table('users_images')
            ->where('user_id', $user->user_id)
            ->update(['main' => 0]);

        \Illuminate\Support\Facades\DB::table('users_images')
            ->where('image_id', $id)
            ->update(['main' => 1]);

        $user->profile_pic = $record->images;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile picture set as main photo successfully.',
            'data' => $this->formatUserProfile($user->fresh(), true),
        ], 200);
    }

    /**
     * Get authenticated member profile in standardized format.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function meProfile(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('authenticated_member');
        if (!$user) {
            $userId = session('user_id');
            if ($userId) {
                $user = User::where('user_id', $userId)
                    ->where(function ($q) {
                        $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                    })
                    ->first();
            }
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Active member session is required.',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Member profile retrieved successfully.',
            'data' => $this->formatUserProfile($user, true),
        ]);
    }

    /**
     * Find local file path across multiple possible storage directories.
     *
     * @param string $filename
     * @return string|null
     */
    public function findLocalPhotoPath(string $filename): ?string
    {
        $filename = basename($filename);
        if (empty($filename)) return null;

        $candidates = [
            storage_path('app/public/users/' . $filename),
            public_path('storage/users/' . $filename),
            public_path('uploads/' . $filename),
            public_path('uploads/user-image/' . $filename),
            public_path('uploads/users/' . $filename),
        ];

        foreach ($candidates as $cand) {
            if (file_exists($cand) && is_file($cand)) {
                return $cand;
            }
        }

        return $this->tryFetchRemotePhoto($filename);
    }

    /**
     * Serve blurred version of profile photo.
     * Uses PHP GD multi-pass downscale & Gaussian blur with caching to storage/app/public/blurred/.
     *
     * @param string $filename
     * @return mixed
     */
    public function getBlurredPhoto(string $filename)
    {
        $filename = basename($filename);
        $sourcePath = $this->findLocalPhotoPath($filename);

        if (!$sourcePath) {
            return $this->serveNeutralAvatar();
        }

        $blurredDir = storage_path('app/public/blurred');
        if (!file_exists($blurredDir)) {
            @mkdir($blurredDir, 0777, true);
        }

        $cacheName = md5($filename . '_' . filemtime($sourcePath)) . '.jpg';
        $blurredPath = $blurredDir . '/' . $cacheName;

        if (!file_exists($blurredPath) || filesize($blurredPath) === 0) {
            $info = @getimagesize($sourcePath);
            if (!$info) {
                return $this->serveNeutralAvatar();
            }

            $mime = $info['mime'];
            switch ($mime) {
                case 'image/jpeg':
                case 'image/jpg':
                    $image = @imagecreatefromjpeg($sourcePath);
                    break;
                case 'image/png':
                    $image = @imagecreatefrompng($sourcePath);
                    break;
                case 'image/webp':
                    $image = @imagecreatefromwebp($sourcePath);
                    break;
                default:
                    $image = null;
            }

            if (!$image) {
                return $this->serveNeutralAvatar();
            }

            $w = imagesx($image);
            $h = imagesy($image);

            // Step 1: Heavy downscale to completely obliterate biometric facial features
            $smallW = 28;
            $smallH = max(1, (int) round(($h / $w) * $smallW));
            $smallImg = imagecreatetruecolor($smallW, $smallH);
            imagecopyresampled($smallImg, $image, 0, 0, 0, 0, $smallW, $smallH, $w, $h);

            // Step 2: Upscale back to smooth portrait size
            $targetW = min(360, $w);
            $targetH = max(1, (int) round(($h / $w) * $targetW));
            $blurredImg = imagecreatetruecolor($targetW, $targetH);
            imagecopyresampled($blurredImg, $smallImg, 0, 0, 0, 0, $targetW, $targetH, $smallW, $smallH);

            // Step 3: Apply multiple Gaussian blur passes
            for ($i = 0; $i < 6; $i++) {
                imagefilter($blurredImg, IMG_FILTER_GAUSSIAN_BLUR);
            }

            // Step 4: Save compressed aesthetic blurred JPEG
            imagejpeg($blurredImg, $blurredPath, 85);

            @imagedestroy($image);
            @imagedestroy($smallImg);
            @imagedestroy($blurredImg);
        }

        return response()->file($blurredPath, [
            'Content-Type' => 'image/jpeg',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    /**
     * Stream profile photo with CORS headers and backend authorization checks.
     * If viewer is unauthorized to view unblurred photo, automatically serves blurred version.
     *
     * @param Request|string $arg1
     * @param string|null $arg2
     * @return mixed
     */
    public function getPhoto($arg1, $arg2 = null)
    {
        if ($arg2 !== null) {
            $request = $arg1 instanceof Request ? $arg1 : request();
            $filename = (string) $arg2;
        } else {
            $request = request();
            $filename = (string) $arg1;
        }

        $filename = basename($filename);
        $path = $this->findLocalPhotoPath($filename);

        if (!$path || !file_exists($path) || !is_file($path)) {
            return $this->serveNeutralAvatar();
        }

        // =====================================================================
        // AUTHORIZATION-CHECKED PHOTO SERVING (Protected Member Images)
        // =====================================================================
        $viewer = $this->resolveViewer($request);

        // Find which member profile owns this photo
        $owner = User::where('profile_pic', $filename)
            ->orWhere('profile_pic1', $filename)
            ->first();

        if (!$owner) {
            $dbImg = \Illuminate\Support\Facades\DB::table('users_images')->where('images', $filename)->first();
            if ($dbImg) {
                $owner = User::where('user_id', $dbImg->user_id)->first();
            }
        }

        $isAuthorized = false;

        // 1. Admin session is always authorized
        if (session('is_admin', false)) {
            $isAuthorized = true;
        }

        // 2. Member viewing their own photo
        if (!$isAuthorized && $viewer && $owner && (int) $viewer->user_id === (int) $owner->user_id) {
            $isAuthorized = true;
        }

        // 3. Verified member with an active package
        if (!$isAuthorized && $viewer && ((int) ($viewer->is_verified ?? 0) === 1) && $viewer->isPackageActive()) {
            $isAuthorized = true;
        }

        // 4. Viewer has already viewed/unlocked this profile
        if (!$isAuthorized && $viewer && $owner && $viewer->hasViewedProfile($owner->user_id)) {
            $isAuthorized = true;
        }

        // If not authorized to view raw original photo, automatically serve privacy-protected BLURRED version
        if (!$isAuthorized && $owner) {
            return $this->getBlurredPhoto($filename);
        }

        $mime = @mime_content_type($path) ?: 'image/jpeg';
        return response()->file($path, [
            'Content-Type' => $mime,
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Attempt to fetch missing photo from live production server and cache locally.
     *
     * @param string $filename
     * @return string|null Local path if successfully fetched, null otherwise.
     */
    public function tryFetchRemotePhoto(string $filename): ?string
    {
        $filename = basename($filename);
        if (empty($filename)) return null;

        $dest = storage_path('app/public/users/' . $filename);
        if (file_exists($dest) && filesize($dest) > 500) {
            return $dest;
        }

        $baseUrls = [
            'https://alnikahmarriage.com/uploads/user-image/',
            'https://alnikahmarriage.com/uploads/',
            'https://alnikahmarriage.com/storage/users/',
        ];

        $encoded = rawurlencode($filename);
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        foreach ($baseUrls as $base) {
            $data = @file_get_contents($base . $encoded, false, $ctx);
            if ($data !== false && strlen($data) > 500) {
                if (strpos($data, '<!DOCTYPE') === false && strpos($data, '<html') === false) {
                    $dir = dirname($dest);
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0777, true);
                    }
                    @file_put_contents($dest, $data);
                    return $dest;
                }
            }
        }

        return null;
    }

    /**
     * Return neutral SVG silhouette avatar response when an image is genuinely missing.
     *
     * @return \Illuminate\Http\Response
     */
    protected function serveNeutralAvatar()
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" width="120" height="120">' .
            '<circle cx="60" cy="60" r="58" fill="#faf4eb" stroke="#c5a059" stroke-width="3"/>' .
            '<circle cx="60" cy="46" r="22" fill="#8c1d28"/>' .
            '<path d="M26 102 C26 78, 42 68, 60 68 C78 68, 94 102 Z" fill="#8c1d28"/>' .
            '</svg>';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}


