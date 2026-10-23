<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Api\AuthApiController;

class ProfileApiController extends Controller
{
    /**
     * Get list of matrimonial profiles from database.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        // Optional filter by is_deleted
        $query->where(function ($q) {
            $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
        });

        // Gender / Type filter (GROOM or BRIDE)
        if ($request->filled('type') || $request->filled('gender')) {
            $gender = strtoupper(trim($request->input('type', $request->input('gender'))));
            if ($gender === 'GROOM' || $gender === 'BRIDE') {
                $query->where('user_type', $gender);
            }
        }

        // Firqa / Sect filter
        if ($request->filled('firqa') || $request->filled('sect')) {
            $sect = trim($request->input('firqa', $request->input('sect')));
            if ($sect !== 'All' && !empty($sect)) {
                $query->where('sect', 'LIKE', "%{$sect}%");
            }
        }

        // Maslak filter
        if ($request->filled('maslak')) {
            $maslak = trim($request->input('maslak'));
            if ($maslak !== 'All' && !empty($maslak)) {
                $query->where(function ($q) use ($maslak) {
                    $q->where('religion', 'LIKE', "%{$maslak}%")
                      ->orWhere('sect', 'LIKE', "%{$maslak}%");
                });
            }
        }

        // Caste filter
        if ($request->filled('caste')) {
            $caste = trim($request->input('caste'));
            if ($caste !== 'All' && !empty($caste)) {
                $query->where('caste', 'LIKE', "%{$caste}%");
            }
        }

        // City filter
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

        // Marital Status filter
        if ($request->filled('marital_status') || $request->filled('status')) {
            $status = trim($request->input('marital_status', $request->input('status')));
            if ($status !== 'All' && !empty($status)) {
                $query->where('marital_status', 'LIKE', "%{$status}%");
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

        $limit = (int) $request->input('limit', 20);
        $limit = ($limit > 100 || $limit < 1) ? 20 : $limit;

        $viewer = $this->resolveViewer($request);

        if ($request->boolean('paginate', false)) {
            $paginator = $query->paginate($limit);
            $transformed = $paginator->getCollection()->map(function ($user) use ($viewer, $request) {
                return $this->formatUserProfile($user, false, $viewer, $request);
            });
            $paginator->setCollection($transformed);

            return response()->json([
                'success' => true,
                'message' => 'Profiles retrieved successfully.',
                'viewer_has_package' => $viewer ? $viewer->hasActivePaidPackage() : false,
                'data' => $paginator,
            ]);
        }

        $users = $query->take($limit)->get();
        $formatted = $users->map(function ($user) use ($viewer, $request) {
            return $this->formatUserProfile($user, false, $viewer, $request);
        });

        return response()->json([
            'success' => true,
            'message' => 'Profiles retrieved successfully.',
            'viewer_has_package' => $viewer ? $viewer->hasActivePaidPackage() : false,
            'count' => $formatted->count(),
            'data' => $formatted,
        ]);
    }

    /**
     * Get featured profiles for homepage carousel (mix of Grooms & Brides).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function featured(Request $request): JsonResponse
    {
        $limitEach = (int) $request->input('limit_each', 4);
        if ($limitEach < 1 || $limitEach > 20) {
            $limitEach = 4;
        }

        $grooms = User::where('user_type', 'GROOM')
            ->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
            })
            ->orderBy('is_active', 'desc')
            ->orderBy('user_id', 'desc')
            ->take($limitEach)
            ->get();

        $brides = User::where('user_type', 'BRIDE')
            ->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
            })
            ->orderBy('is_active', 'desc')
            ->orderBy('user_id', 'desc')
            ->take($limitEach)
            ->get();

        $viewer = $this->resolveViewer($request);

        $combined = $grooms->concat($brides)->map(function ($user) use ($viewer, $request) {
            return $this->formatUserProfile($user, false, $viewer, $request);
        });

        return response()->json([
            'success' => true,
            'message' => 'Featured profiles retrieved successfully.',
            'viewer_has_package' => $viewer ? $viewer->hasActivePaidPackage() : false,
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

        return response()->json([
            'success' => true,
            'message' => 'Profile details retrieved successfully.',
            'viewer_has_package' => $viewer ? $viewer->hasActivePaidPackage() : false,
            'data' => $this->formatUserProfile($user, true, $viewer, $request),
        ]);
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

        // 4. Custom X-User-Id header for headless client communication
        if ($req->hasHeader('X-User-Id')) {
            $hdrId = (int) $req->header('X-User-Id');
            if ($hdrId > 0) {
                $user = User::where('user_id', $hdrId)
                    ->where(function ($q) {
                        $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                    })
                    ->first();
                if ($user) {
                    return $user;
                }
            }
        }

        // 5. Query param viewer_id (for direct image / API links)
        if ($req->filled('viewer_id')) {
            $vId = (int) $req->input('viewer_id');
            if ($vId > 0) {
                return User::where('user_id', $vId)
                    ->where(function ($q) {
                        $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                    })
                    ->first();
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
     * @param User|null $viewerUser
     * @param Request|null $request
     * @return array
     */
    public function formatUserProfile($user, bool $detailed = false, $viewerUser = null, ?Request $request = null): array
    {
        $viewer = $viewerUser ?: $this->resolveViewer($request ?: request());
        $isSelf = ($viewer && $viewer->user_id == $user->user_id);

        $targetHasPaid = $user->hasActivePaidPackage();
        $viewerHasPaid = $viewer ? $viewer->hasActivePaidPackage() : false;

        $daysSinceRegistration = $user->daysSinceRegistration();
        $isWithin3Days = $user->isWithin3DayTrial();

        // -------------------------------------------------------------
        // ACCESS CONTROL DECISION ENGINE:
        // -------------------------------------------------------------
        // 1. Self viewing own profile -> Full unblurred access & full contacts
        // 2. Target has NOT paid:
        //    - First 3 days: other users see blurred photo & name, hidden contacts
        //    - After 3 days without payment: remains blurred, hidden contacts
        // 3. Target HAS paid & activated:
        //    - Visible unblurred ONLY to users with active eligible package
        //    - Contacts visible ONLY to users with active package granting contact access
        // 4. Viewer has NOT purchased a package:
        //    - Always sees blurred photo & name, hidden contacts
        // -------------------------------------------------------------
        if ($isSelf) {
            $isBlurred = false;
            $canViewContact = true;
        } else {
            if (!$viewerHasPaid) {
                // Any viewer without an active paid package ALWAYS sees blurred name/photo & hidden contacts
                $isBlurred = true;
                $canViewContact = false;
            } else {
                // Viewing user HAS an active paid package (eligible viewer)
                if ($targetHasPaid) {
                    // Both have paid: fully visible unblurred & contacts unlocked
                    $isBlurred = false;
                    $canViewContact = true;
                } else {
                    // Target has NOT purchased a package:
                    // During first 3 days of signup: eligible paid viewers can view photo & name unblurred
                    // After 3 days without payment: remains blurred until target package is purchased & activated
                    // Mobile and address must strictly remain hidden while target is unpaid
                    $isBlurred = $isWithin3Days ? false : true;
                    $canViewContact = false;
                }
            }
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
            if (empty($filename)) return null;
            $candidates = [
                storage_path('app/public/users/' . $filename),
                public_path('storage/users/' . $filename),
                public_path('uploads/' . $filename),
                public_path('images/' . $filename),
            ];
            foreach ($candidates as $cand) {
                if (file_exists($cand) && is_file($cand)) {
                    return $cand;
                }
            }
            return null;
        };

        // Helper to find web path
        $checkPhoto = function ($filename) {
            if (empty($filename)) return null;
            if (file_exists(public_path('storage/users/' . $filename))) {
                return asset('storage/users/' . $filename);
            }
            if (file_exists(storage_path('app/public/users/' . $filename))) {
                return asset('storage/users/' . $filename);
            }
            if (file_exists(public_path('storage/' . $filename))) {
                return asset('storage/' . $filename);
            }
            if (file_exists(public_path('uploads/' . $filename))) {
                return asset('uploads/' . $filename);
            }
            if (file_exists(public_path('images/' . $filename))) {
                return asset('images/' . $filename);
            }
            return null;
        };

        // Image URL and Stream resolution
        $base64Photo = null;
        $imageUrl = null;
        $photoStreamUrl = null;
        $allImages = [];

        if (!empty($user->profile_pic)) {
            if ($isBlurred) {
                // Return server-side blurred photo endpoint
                $imageUrl = url('/api/photo/blurred/' . rawurlencode($user->profile_pic));
                $photoStreamUrl = $imageUrl;
                $allImages[] = $imageUrl;
            } else {
                // Return crisp original photo endpoint
                $localPath = $resolveLocalPhoto($user->profile_pic);
                if ($localPath) {
                    $imageUrl = asset('storage/users/' . $user->profile_pic);
                    $photoStreamUrl = url('/api/photo/' . rawurlencode($user->profile_pic));
                    $mime = @mime_content_type($localPath) ?: 'image/jpeg';
                    $fileBytes = @file_get_contents($localPath);
                    if ($fileBytes) {
                        $base64Photo = 'data:' . $mime . ';base64,' . base64_encode($fileBytes);
                    }
                } else {
                    $imageUrl = $checkPhoto($user->profile_pic);
                    $photoStreamUrl = $imageUrl ? url('/api/photo/' . rawurlencode($user->profile_pic)) : null;
                }

                if ($imageUrl) {
                    $allImages[] = $imageUrl;
                }

                // Add secondary images only if unblurred
                $dbImages = \Illuminate\Support\Facades\DB::table('users_images')
                    ->where('user_id', $user->user_id)
                    ->where('is_active', 1)
                    ->get();

                foreach ($dbImages as $row) {
                    $path = $checkPhoto($row->images);
                    if ($path && !in_array($path, $allImages)) {
                        $allImages[] = $path;
                    }
                }

                if (!empty($user->profile_pic1)) {
                    $pic1 = $checkPhoto($user->profile_pic1);
                    if ($pic1 && !in_array($pic1, $allImages)) {
                        $allImages[] = $pic1;
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
            
            // Access control and visibility flags
            'is_blurred' => $isBlurred,
            'is_name_blurred' => $isBlurred,
            'is_photo_blurred' => $isBlurred,
            'can_view_contact' => $canViewContact,
            'contact_locked' => !$canViewContact,
            'contact_locked_message' => !$canViewContact ? 'Mobile number and address are protected. Please purchase an active membership package to view contact details.' : '',
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

        $updatedCount = 0;
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $inputData)) {
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

        // Store new password with supported secure hashing
        $user->password = Hash::make(trim($newPassword));
        $user->modified_at = now();
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password has been updated successfully.',
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

            $user->profile_pic = $filename;
            $user->modified_at = now();
            $user->save();

            if (session('user_id') == $user->user_id) {
                session([
                    'user' => $user->fresh()->toArray(),
                    'user_name' => $user->name,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Profile photo updated successfully.',
                'photo_url' => asset('storage/users/' . $filename),
                'data' => $this->formatUserProfile($user->fresh(), true),
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => 'No image file found in the request.',
        ], 422);
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
     * Serve blurred version of profile photo.
     * Uses PHP GD multi-pass downscale & Gaussian blur with caching to storage/app/public/blurred/.
     *
     * @param string $filename
     * @return mixed
     */
    public function getBlurredPhoto(string $filename)
    {
        $filename = basename($filename);
        $candidates = [
            storage_path('app/public/users/' . $filename),
            public_path('storage/users/' . $filename),
            public_path('uploads/' . $filename),
            public_path('images/' . $filename),
        ];

        $sourcePath = null;
        foreach ($candidates as $cand) {
            if (file_exists($cand) && is_file($cand)) {
                $sourcePath = $cand;
                break;
            }
        }

        if (!$sourcePath) {
            return response()->json(['success' => false, 'message' => 'Image not found.'], 404);
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
                return response()->json(['success' => false, 'message' => 'Invalid image source.'], 400);
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
                return response()->json(['success' => false, 'message' => 'Failed to process image.'], 500);
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

        // Determine profile owner for this photo
        $ownerUser = User::where('profile_pic', $filename)
            ->orWhere('profile_pic1', $filename)
            ->first();

        if (!$ownerUser) {
            $dbImg = \Illuminate\Support\Facades\DB::table('users_images')
                ->where('images', $filename)
                ->first();
            if ($dbImg) {
                $ownerUser = User::where('user_id', $dbImg->user_id)->first();
            }
        }

        if ($ownerUser) {
            $viewer = $this->resolveViewer($request);
            $isSelf = ($viewer && $viewer->user_id == $ownerUser->user_id);
            $targetHasPaid = $ownerUser->hasActivePaidPackage();
            $viewerHasPaid = $viewer ? $viewer->hasActivePaidPackage() : false;
            $isWithin3Days = $ownerUser->isWithin3DayTrial();

            $shouldBlur = true;
            if ($isSelf) {
                $shouldBlur = false;
            } elseif ($viewerHasPaid) {
                if ($targetHasPaid) {
                    $shouldBlur = false;
                } elseif ($isWithin3Days) {
                    $shouldBlur = false;
                }
            }

            if ($shouldBlur) {
                return $this->getBlurredPhoto($filename);
            }
        }

        $candidates = [
            storage_path('app/public/users/' . $filename),
            public_path('storage/users/' . $filename),
            public_path('uploads/' . $filename),
            public_path('images/' . $filename),
        ];

        foreach ($candidates as $path) {
            if (file_exists($path) && is_file($path)) {
                $mime = @mime_content_type($path) ?: 'image/jpeg';
                return response()->file($path, [
                    'Content-Type' => $mime,
                    'Access-Control-Allow-Origin' => '*',
                    'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }
        }

        return response()->json(['success' => false, 'message' => 'Image not found.'], 404);
    }
}


