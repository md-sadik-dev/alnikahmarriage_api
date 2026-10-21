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

        if ($request->boolean('paginate', false)) {
            $paginator = $query->paginate($limit);
            $transformed = $paginator->getCollection()->map(function ($user) {
                return $this->formatUserProfile($user);
            });
            $paginator->setCollection($transformed);

            return response()->json([
                'success' => true,
                'message' => 'Profiles retrieved successfully.',
                'data' => $paginator,
            ]);
        }

        $users = $query->take($limit)->get();
        $formatted = $users->map(function ($user) {
            return $this->formatUserProfile($user);
        });

        return response()->json([
            'success' => true,
            'message' => 'Profiles retrieved successfully.',
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

        $combined = $grooms->concat($brides)->map(function ($user) {
            return $this->formatUserProfile($user);
        });

        return response()->json([
            'success' => true,
            'message' => 'Featured profiles retrieved successfully.',
            'total' => $combined->count(),
            'grooms_count' => $grooms->count(),
            'brides_count' => $brides->count(),
            'data' => $combined,
        ]);
    }

    /**
     * Get single profile by ID or Profile Code.
     *
     * @param string|int $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
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

        return response()->json([
            'success' => true,
            'message' => 'Profile details retrieved successfully.',
            'data' => $this->formatUserProfile($user, true),
        ]);
    }

    /**
     * Helper to transform a User model into standardized Profile format.
     *
     * @param User $user
     * @param bool $detailed
     * @return array
     */
    public function formatUserProfile($user, bool $detailed = false): array
    {
        $isGroom = strtoupper(trim($user->user_type ?? '')) === 'GROOM';
        $genderTitle = $isGroom ? 'Groom' : 'Bride';
        $badgeClass = $isGroom ? 'badge-groom' : 'badge-bride';

        // Profile ID formatting
        $profileId = $user->profile_id;
        if (empty($profileId)) {
            $prefix = $isGroom ? 'ALN-G-' : 'ALN-B-';
            $profileId = $prefix . $user->user_id;
        }

        // Helper to find file in storage/users, public/storage, uploads, or images
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

        // Compute dynamic image URLs and Base64 representation for seamless CORS-free PDF export
        $base64Photo = null;
        $imageUrl = null;
        $photoStreamUrl = null;

        if (!empty($user->profile_pic)) {
            $localPath = $resolveLocalPhoto($user->profile_pic);
            if ($localPath) {
                $imageUrl = asset('storage/users/' . $user->profile_pic);
                $photoStreamUrl = url('/api/photo/' . $user->profile_pic);
                $mime = @mime_content_type($localPath) ?: 'image/jpeg';
                $fileBytes = @file_get_contents($localPath);
                if ($fileBytes) {
                    $base64Photo = 'data:' . $mime . ';base64,' . base64_encode($fileBytes);
                }
            } else {
                $imageUrl = $checkPhoto($user->profile_pic);
            }
        }

        // Format City
        $city = !empty($user->n_address) ? trim($user->n_address) : (!empty($user->address) ? trim($user->address) : 'Not Specified');
        // Clean up newlines or extra spaces in city
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

        // Fetch real uploaded images for this user
        $allImages = [];
        if ($imageUrl) {
            $allImages[] = $imageUrl;
        }

        // Fetch additional images from users_images table
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

        $data = [
            'id' => $user->user_id,
            'profile_id' => $profileId,
            'name' => $user->name ?? 'Confidential',
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
            'education' => !empty($user->qualification) ? trim($user->qualification) : 'Graduate',
            'profession' => !empty($user->work) ? trim($user->work) : 'Business / Service',
            'age_dob' => !empty($user->dob) ? trim($user->dob) : '28 Years',
            'firqa' => $firqa,
            'maslak' => $maslak,
            'caste' => !empty($user->caste) ? trim($user->caste) : 'Khan / Shaikh',
            'status' => !empty($user->marital_status) ? ucfirst(strtolower(trim($user->marital_status))) : 'Unmarried',
            'city' => $displayCity,
            'full_city' => $city,
            'contact_number' => !empty(trim($user->mobile ?? '')) ? trim($user->mobile) : (!empty(trim($user->alternate_mobile ?? '')) ? trim($user->alternate_mobile) : ''),
            'mobile' => !empty(trim($user->mobile ?? '')) ? trim($user->mobile) : '',
            'alternate_mobile' => !empty(trim($user->alternate_mobile ?? '')) ? trim($user->alternate_mobile) : '',
            'address' => !empty(trim($user->address ?? '')) ? trim($user->address) : (!empty(trim($user->n_address ?? '')) ? trim($user->n_address) : $city),
            'n_address' => !empty(trim($user->n_address ?? '')) ? trim($user->n_address) : '',
        ];

        if ($detailed) {
            $data['height'] = !empty(trim($user->height ?? '')) ? trim($user->height) : "5' 6\" (Approx)";
            $data['complexion'] = !empty(trim($user->complexion ?? '')) ? ucfirst(strtolower(trim($user->complexion))) : 'Fair';
            $data['income'] = (!empty(trim($user->income ?? '')) && strtolower(trim($user->income)) !== 'n/a') ? trim($user->income) : 'Confidential / Disclosed to Family';
            $data['accommodation'] = !empty(trim($user->accommodation ?? '')) ? trim($user->accommodation) : 'Own House';
            $data['father_name'] = (!empty(trim($user->f_name ?? '')) && strtolower(trim($user->f_name)) !== 'no-detail') ? trim($user->f_name) : 'Respected Family Member';
            $data['mother_name'] = (!empty(trim($user->m_name ?? '')) && strtolower(trim($user->m_name)) !== 'no-detail') ? trim($user->m_name) : 'Homemaker';
            $data['brothers'] = isset($user->brother) && $user->brother !== '' ? trim($user->brother) : '0';
            $data['sisters'] = isset($user->sister) && $user->sister !== '' ? trim($user->sister) : '0';
            $data['prefered_location'] = !empty(trim($user->prefered_location ?? '')) ? trim($user->prefered_location) : 'Lucknow & Nearby / All India';
            $data['disability'] = !empty(trim($user->disability ?? '')) ? trim($user->disability) : 'None';
            $data['package'] = !empty(trim($user->package ?? '')) ? trim($user->package) : 'Silver';
            $data['created_at_formatted'] = $user->created_at ? $user->created_at->format('M Y') : 'Active Member';
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
            'alternate_mobile' => 'nullable|string|max:11',
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
     * Stream profile photo with CORS headers for PDF export & external display.
     *
     * @param string $filename
     * @return mixed
     */
    public function getPhoto(string $filename)
    {
        $filename = basename($filename);
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


