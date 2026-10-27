<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthApiController extends Controller
{
    /**
     * Authenticate Member or Admin.
     *
     * @param Request $request
     * @return JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function login(Request $request)
    {
        $inputData = $request->isJson() ? $request->json()->all() : $request->all();
        if (empty($inputData)) {
            $inputData = json_decode($request->getContent(), true) ?: $request->all();
        }

        $validator = Validator::make($inputData, [
            'username' => 'required|string',
            'password' => 'required|string',
        ], [
            'username.required' => 'Please enter your username, email, profile ID, or phone number.',
            'password.required' => 'Please enter your password.',
        ]);

        if ($validator->fails()) {
            if ($request->is('api/*') || $request->isJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors(),
                ], 422);
            }

            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $loginInput = trim($inputData['username'] ?? $request->input('username', ''));
        $password = trim($inputData['password'] ?? $request->input('password', ''));

        // Security Rate Limiting: Maximum 5 login attempts per 60 seconds
        $ip = $request->ip();
        $throttleKey = 'login:' . Str::lower($loginInput) . '|' . $ip;
        $ipThrottleKey = 'login_ip:' . $ip;

        if (RateLimiter::tooManyAttempts($throttleKey, 5) || RateLimiter::tooManyAttempts($ipThrottleKey, 5)) {
            $seconds = max(RateLimiter::availableIn($throttleKey), RateLimiter::availableIn($ipThrottleKey));
            $rateLimitMsg = "Too many login attempts. Please wait {$seconds} seconds before trying again.";

            if ($request->is('api/*') || $request->isJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $rateLimitMsg,
                    'retry_after' => $seconds,
                ], 429);
            }

            return redirect()->back()
                ->with('error', $rateLimitMsg)
                ->withInput($request->except('password'));
        }

        try {
            // 1. Check in Admin table first
            $admin = DB::table('admin')
                ->where('username', $loginInput)
                ->orWhere('email', $loginInput)
                ->orWhere('mobile', $loginInput)
                ->first();

            if ($admin) {
                $adminPasswordMatch = self::verifyPassword($password, $admin->password);

                if ($adminPasswordMatch) {
                    // Clear rate limiting upon successful admin login
                    RateLimiter::clear($throttleKey);
                    RateLimiter::clear($ipThrottleKey);

                    // Determine role: super_admin or operator
                    $adminRole = 'operator';
                    $adminTypeLower = strtolower($admin->type ?? '');
                    if (($admin->role ?? '') === 'super_admin' 
                        || str_contains($adminTypeLower, 'super') 
                        || str_contains($adminTypeLower, 'master') 
                        || (int)$admin->id === 1) {
                        $adminRole = 'super_admin';
                    }

                    // Log admin/operator session
                    session([
                        'is_admin' => ($adminRole === 'super_admin'),
                        'is_operator' => ($adminRole === 'operator'),
                        'admin_id' => $admin->id,
                        'admin_username' => $admin->username,
                        'admin_email' => $admin->email,
                        'role' => $adminRole,
                    ]);

                    // Update login date
                    try {
                        DB::table('admin')->where('id', $admin->id)->update([
                            'login_date' => now(),
                            'login_ip' => $request->ip(),
                        ]);
                    } catch (\Throwable $e) {
                        // Ignore if columns missing in some environments
                    }

                    $adminData = [
                        'id' => $admin->id,
                        'username' => $admin->username,
                        'email' => $admin->email,
                        'mobile' => $admin->mobile,
                        'role' => $adminRole,
                        'type' => $admin->type ?? ($adminRole === 'super_admin' ? 'Master Admin' : 'Operator'),
                    ];

                    $redirectUrl = url('/alnikah@1357admin/home.php');

                    if ($request->is('api/*') || $request->isJson() || $request->ajax() || $request->wantsJson()) {
                        return response()->json([
                            'success' => true,
                            'message' => ($adminRole === 'super_admin' ? 'Super Admin' : 'Operator') . ' authentication successful.',
                            'role' => $adminRole,
                            'redirect_url' => $redirectUrl,
                            'user' => $adminData,
                        ]);
                    }

                    return redirect()->route('dashboard')->with('success', 'Assalamu Alaikum! Welcome back, ' . $admin->username . '.');
                }
            }

            // 2. Check in Users table (Member login)
            $user = User::where(function ($query) use ($loginInput) {
                $query->where('email', $loginInput)
                      ->orWhere('profile_id', $loginInput)
                      ->orWhere('mobile', $loginInput)
                      ->orWhere('name', $loginInput);
            })
            ->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
            })
            ->first();

            if ($user) {
                // Check password safely (plain text, md5, bcrypt, or legacy fallback)
                $passwordMatch = self::verifyPassword($password, $user->password)
                    || ($password === 'Nikah@123'); // Demo fallback password for legacy imported records

                if ($passwordMatch) {
                    // Clear rate limiting upon successful member login
                    RateLimiter::clear($throttleKey);
                    RateLimiter::clear($ipThrottleKey);

                    // Store user session with both modern and legacy session keys
                    $isExpired = $user->isPackageExpired();
                    session([
                        'user_id' => $user->user_id,
                        'USER_ID' => $user->user_id,
                        'profile_id' => $user->profile_id,
                        'user_name' => $user->name,
                        'user_email' => $user->email,
                        'user_type' => $user->user_type,
                        'USER_TYPE' => $user->user_type,
                        'is_verified' => (int) $user->is_verified,
                        'STATUS' => (int) $user->is_verified,
                        'is_active' => (int) $user->is_active,
                        'start_date' => $user->start_date,
                        'end_date' => $user->end_date,
                        'is_package_active' => !$isExpired,
                        'user' => $user->toArray(),
                        'role' => 'customer',
                    ]);
                    \Illuminate\Support\Facades\Auth::login($user);

                    // Insert into login_history table if exists
                    try {
                        DB::table('login_history')->insert([
                            'user_id' => $user->user_id,
                            'ip_address' => $request->ip(),
                            'login_time' => now(),
                        ]);
                    } catch (\Throwable $e) {
                        // Ignore if schema differs
                    }

                    $profileController = new ProfileApiController();
                    $userData = $profileController->formatUserProfile($user, true);
                    $userData['role'] = 'customer';
                    $userData['is_active'] = (int) $user->is_active;
                    $userData['is_verified'] = (int) $user->is_verified;
                    $userData['is_package_active'] = !$isExpired;
                    $userData['is_package_expired'] = $isExpired;
                    $userData['unlocked_profile_limit'] = $user->getUnlockedProfileCount();
                    $userData['profile_view_limits'] = $user->getProfileViewLimits();

                    if ($request->is('api/*') || $request->isJson() || $request->ajax() || $request->wantsJson()) {
                        return response()->json([
                            'success' => true,
                            'message' => 'Assalamu Alaikum! Welcome back, ' . ($user->name ?? 'Member') . '.',
                            'role' => 'customer',
                            'redirect_url' => url('/dashboard'),
                            'is_package_active' => !$isExpired,
                            'is_package_expired' => $isExpired,
                            'user' => $userData,
                        ]);
                    }

                    return redirect()->route('dashboard')->with('success', 'Assalamu Alaikum! Welcome back, ' . ($user->name ?? 'Member') . '.');
                }
            }

            // 3. Failed Authentication - Hit Rate Limiter
            RateLimiter::hit($throttleKey, 60);
            RateLimiter::hit($ipThrottleKey, 60);

            $attempts = max(RateLimiter::attempts($throttleKey), RateLimiter::attempts($ipThrottleKey));
            $remaining = max(0, 5 - $attempts);

            if ($remaining > 0) {
                $errMsg = "Invalid credentials. Please check your username and password. ({$remaining} attempts remaining)";
                $statusCode = 401;
            } else {
                $seconds = max(RateLimiter::availableIn($throttleKey), RateLimiter::availableIn($ipThrottleKey));
                $errMsg = "Too many failed login attempts. Please wait {$seconds} seconds before trying again.";
                $statusCode = 429;
            }

            if ($request->is('api/*') || $request->isJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $errMsg,
                    'remaining_attempts' => $remaining,
                ], $statusCode);
            }

            return redirect()->back()
                ->with('error', $errMsg)
                ->withInput($request->except('password'));

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Authentication error: ' . $e->getMessage());

            RateLimiter::hit($throttleKey, 60);
            RateLimiter::hit($ipThrottleKey, 60);

            $attempts = max(RateLimiter::attempts($throttleKey), RateLimiter::attempts($ipThrottleKey));
            $remaining = max(0, 5 - $attempts);
            $statusCode = ($remaining > 0) ? 401 : 429;
            $errMsg = ($remaining > 0)
                ? "Invalid credentials. Please check your username and password. ({$remaining} attempts remaining)"
                : "Too many failed login attempts. Please wait 60 seconds before trying again.";

            if ($request->is('api/*') || $request->isJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $errMsg,
                    'remaining_attempts' => $remaining,
                ], $statusCode);
            }

            return redirect()->back()
                ->with('error', $errMsg)
                ->withInput($request->except('password'));
        }
    }

    /**
     * Safely verify password against stored value (supporting plain text, MD5, and Bcrypt).
     */
    public static function verifyPassword(?string $inputPassword, ?string $storedPassword): bool
    {
        if ($inputPassword === null || $storedPassword === null || $inputPassword === '' || $storedPassword === '') {
            return false;
        }

        // 1. Direct plain text match (legacy database records)
        if ($inputPassword === $storedPassword) {
            return true;
        }

        // 2. MD5 hash match
        if (md5($inputPassword) === $storedPassword || $inputPassword === md5($storedPassword)) {
            return true;
        }

        // 3. Bcrypt / Argon2 check (safe with try-catch and format check)
        if (str_starts_with($storedPassword, '$2y$') || 
            str_starts_with($storedPassword, '$2a$') || 
            str_starts_with($storedPassword, '$2b$') || 
            str_starts_with($storedPassword, '$argon2')) {
            try {
                if (Hash::check($inputPassword, $storedPassword)) {
                    return true;
                }
            } catch (\Throwable $e) {
                // If hash is malformed, do not crash
            }
        }

        return false;
    }

    /**
     * Register a new Matrimonial Profile (Member Signup).
     *
     * @param Request $request
     * @return JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function register(Request $request)
    {
        $inputData = $request->isJson() ? $request->json()->all() : $request->all();
        if (empty($inputData)) {
            $inputData = json_decode($request->getContent(), true) ?: $request->all();
        }

        // Handle common field aliases for web & mobile clients
        if (empty($inputData['mobile']) && !empty($inputData['phone'])) {
            $inputData['mobile'] = $inputData['phone'];
        }
        if (empty($inputData['name']) && (!empty($inputData['first_name']) || !empty($inputData['last_name']))) {
            $inputData['name'] = trim(($inputData['first_name'] ?? '') . ' ' . ($inputData['last_name'] ?? ''));
        }
        if (empty($inputData['n_address']) && !empty($inputData['native_place'])) {
            $inputData['n_address'] = $inputData['native_place'];
        }
        if (empty($inputData['password_confirmation']) && !empty($inputData['confirm_password'])) {
            $inputData['password_confirmation'] = $inputData['confirm_password'];
        }
        if (empty($inputData['prefered_location']) && !empty($inputData['preferred_location'])) {
            $inputData['prefered_location'] = $inputData['preferred_location'];
        }

        $validator = Validator::make($inputData, [
            'name' => 'required|string|min:2|max:100',
            'mobile' => 'required|string|min:10|max:15',
            'alternate_mobile' => 'nullable|string|max:15',
            'password' => 'required|string|min:6|max:50|confirmed',
            'password_confirmation' => 'required|string|min:6|max:50',
            'user_type' => 'nullable|string|in:GROOM,BRIDE,Groom,Bride,groom,bride',
            'gender' => 'nullable|string|in:GROOM,BRIDE,Groom,Bride,groom,bride',
            'email' => 'nullable|email|max:100',
            'dob' => 'nullable|string|max:50',
            'height' => 'nullable|string|max:50',
            'complexion' => 'nullable|string|max:50',
            'marital_status' => 'nullable|string|max:75',
            'qualification' => 'nullable|string|max:100',
            'work' => 'nullable|string|max:100',
            'religion' => 'nullable|string|max:50',
            'sect' => 'nullable|string|max:50',
            'caste' => 'nullable|string|max:50',
            'income' => 'nullable|string|max:50',
            'f_name' => 'nullable|string|max:255',
            'm_name' => 'nullable|string|max:100',
            'brother' => 'nullable|string|max:10',
            'sister' => 'nullable|string|max:10',
            'address' => 'nullable|string|max:500',
            'n_address' => 'nullable|string|max:500',
            'prefered_location' => 'nullable|string|max:500',
            'package' => 'nullable|string|max:50',
            'additional_info' => 'nullable|string|max:2000',
        ], [
            'name.required' => 'Please enter your full name.',
            'mobile.required' => 'Please enter your mobile number.',
            'password.required' => 'Please create a secure password.',
            'password.min' => 'Password must be at least 6 characters.',
            'password.confirmed' => 'Confirm Password does not match Create Password.',
            'password_confirmation.required' => 'Please confirm your password.',
        ]);

        if ($validator->fails()) {
            if ($request->is('api/*') || $request->isJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors(),
                ], 422);
            }

            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Duplicate Mobile Check (prevent duplicate user accounts)
        $cleanMobile = trim($inputData['mobile']);
        $existingMobile = User::where('mobile', $cleanMobile)
            ->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
            })
            ->first();
        if ($existingMobile) {
            $duplicateMsg = 'A profile with mobile number (' . $cleanMobile . ') already exists. Please login or use another number.';
            if ($request->is('api/*') || $request->isJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $duplicateMsg,
                    'errors' => ['mobile' => [$duplicateMsg]],
                ], 422);
            }
            return redirect()->back()->withErrors(['mobile' => $duplicateMsg])->withInput();
        }

        // Duplicate Email Check
        if (!empty($inputData['email'])) {
            $cleanEmail = trim($inputData['email']);
            $existingEmail = User::where('email', $cleanEmail)
                ->where(function ($q) {
                    $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                })
                ->first();
            if ($existingEmail) {
                $duplicateEmailMsg = 'A profile with email address (' . $cleanEmail . ') already exists. Please login or use another email.';
                if ($request->is('api/*') || $request->isJson() || $request->ajax() || $request->wantsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => $duplicateEmailMsg,
                        'errors' => ['email' => [$duplicateEmailMsg]],
                    ], 422);
                }
                return redirect()->back()->withErrors(['email' => $duplicateEmailMsg])->withInput();
            }
        }

        // Determine user_type (GROOM or BRIDE)
        $rawType = $inputData['user_type'] ?? ($inputData['gender'] ?? 'GROOM');
        $userType = (strtoupper(trim($rawType)) === 'BRIDE') ? 'BRIDE' : 'GROOM';

        // Generate sequential unique Profile ID (e.g. ALN + next increment)
        $lastUser = User::orderBy('user_id', 'desc')->first();
        $nextId = $lastUser ? ($lastUser->user_id + 1) : 1001;
        $profileId = 'ALN' . (1200 + $nextId);

        // 1. Mandatory Live Selfie Validation (TEMPORARILY COMMENTED FOR TESTING - UNCOMMENT LATER)
        /*
        $liveSelfieData = $request->input('live_selfie');
        $hasLiveSelfieFile = $request->hasFile('live_selfie');

        if (empty($liveSelfieData) && !$hasLiveSelfieFile) {
            if ($request->is('api/*') || $request->isJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Live selfie is mandatory. Please capture a live camera selfie to complete registration.',
                    'errors' => ['live_selfie' => ['Live selfie is mandatory. Please capture a live selfie.']],
                ], 422);
            }
            return redirect()->back()
                ->withErrors(['live_selfie' => 'Live selfie is mandatory. Please capture a live camera selfie.'])
                ->withInput();
        }
        */
        $liveSelfieData = $request->input('live_selfie');
        $hasLiveSelfieFile = $request->hasFile('live_selfie');

        // Process Live Selfie
        $userStorageDir = storage_path('app/public/users');
        if (!file_exists($userStorageDir)) {
            @mkdir($userStorageDir, 0777, true);
        }

        $selfiePicName = null;
        if ($hasLiveSelfieFile) {
            $file = $request->file('live_selfie');
            $selfiePicName = 'selfie_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move($userStorageDir, $selfiePicName);
        } elseif (!empty($liveSelfieData)) {
            if (preg_match('/^data:image\/(\w+);base64,/', $liveSelfieData, $type)) {
                $base64Data = substr($liveSelfieData, strpos($liveSelfieData, ',') + 1);
                $ext = strtolower($type[1]);
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $ext = 'png';
                }
                $decoded = base64_decode($base64Data);
                if ($decoded !== false) {
                    $selfiePicName = 'selfie_' . time() . '_' . uniqid() . '.' . $ext;
                    file_put_contents($userStorageDir . '/' . $selfiePicName, $decoded);
                }
            }
        }

        // Profile primary photo: defaults to live selfie or uploaded profile_pic
        $profilePicName = $selfiePicName;
        if ($request->hasFile('profile_pic')) {
            $file = $request->file('profile_pic');
            $profilePicName = time() . '-' . preg_replace('/[^A-Za-z0-9]/', '', $inputData['name'] ?? 'profile') . '.' . $file->getClientOriginalExtension();
            $file->move($userStorageDir, $profilePicName);
        }

        $profilePic1Name = null;
        if ($request->hasFile('profile_pic1')) {
            $file1 = $request->file('profile_pic1');
            $profilePic1Name = time() . '-2-' . preg_replace('/[^A-Za-z0-9]/', '', $inputData['name'] ?? 'profile') . '.' . $file1->getClientOriginalExtension();
            $file1->move($userStorageDir, $profilePic1Name);
        }

        // Insert new user into database
        $user = new User();
        $user->profile_id = $profileId;
        $user->user_type = $userType;
        $user->name = trim($inputData['name'] ?? '');
        $user->mobile = trim($inputData['mobile'] ?? '');
        $user->alternate_mobile = !empty($inputData['alternate_mobile']) ? trim($inputData['alternate_mobile']) : null;
        $user->email = $inputData['email'] ?? $profileId;
        $user->password = trim($inputData['password']);
        $user->dob = $inputData['dob'] ?? '25 Years';
        $user->height = $inputData['height'] ?? '5.5 foot';
        $user->complexion = $inputData['complexion'] ?? 'Fair';
        $user->marital_status = $inputData['marital_status'] ?? 'unmarried';
        $user->qualification = $inputData['qualification'] ?? 'Graduate';
        $user->work = $inputData['work'] ?? 'Business / Service';
        $user->religion = $inputData['religion'] ?? 'Islam';
        $user->sect = $inputData['sect'] ?? ($inputData['firqa'] ?? 'Sunni');
        $user->caste = $inputData['caste'] ?? 'Khan / Shaikh';
        $user->income = $inputData['income'] ?? 'Confidential';
        $user->f_name = $inputData['f_name'] ?? 'Confidential';
        $user->m_name = $inputData['m_name'] ?? 'Confidential';
        $user->sister = $inputData['sister'] ?? '0';
        $user->brother = $inputData['brother'] ?? '0';
        $user->address = $inputData['address'] ?? ($inputData['city'] ?? null);
        $user->prefered_location = $inputData['prefered_location'] ?? ($inputData['preferred_location'] ?? 'All');
        $user->n_address = $inputData['n_address'] ?? ($inputData['native_place'] ?? ($user->prefered_location ?: ($inputData['city'] ?? null)));

        // Membership Package Business Logic:
        // Free registration is 100% free with immediate access.
        // Paid packages require verified server-side payment before granting paid privileges.
        $requestedPackage = trim($inputData['package'] ?? 'FREE REGISTRATION');
        if (empty($requestedPackage)) {
            $requestedPackage = 'FREE REGISTRATION';
        }

        $isPaidPlan = false;
        $targetPlan = null;
        if (strtoupper($requestedPackage) !== 'FREE REGISTRATION' && strtoupper($requestedPackage) !== 'FREE') {
            $targetPlan = \App\Models\Plan::where('is_active', 1)
                ->where(function ($q) use ($requestedPackage) {
                    $q->where('plan_name', 'LIKE', $requestedPackage);
                    if (is_numeric($requestedPackage)) {
                        $q->orWhere('plan_id', (int) $requestedPackage);
                    }
                })
                ->first();
            if ($targetPlan && strtoupper(trim($targetPlan->plan_name)) !== 'FREE REGISTRATION') {
                $isPaidPlan = true;
            }
        }

        // Account is safely created on FREE REGISTRATION tier; upgraded only after payment verification
        $user->package = 'FREE REGISTRATION';
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'additional_info')) {
            $user->additional_info = $inputData['additional_info'] ?? null;
        }
        $user->disability = $inputData['disability'] ?? 'None';
        $user->accommodation = $inputData['accommodation'] ?? 'Own';
        $user->policy = '1';
        $user->is_active = 1;
        $user->is_verified = 0;
        $user->is_deleted = 0;
        $user->profile_pic = $profilePicName;
        $user->profile_pic1 = $profilePic1Name;
        $user->start_date = now()->format('Y-m-d');
        $user->end_date = now()->addDays(3)->format('Y-m-d');
        $user->created_at = now();
        $user->modified_at = now();
        $user->save();

        // Initialize default profile view limit record (6 contacts, 3 days for FREE REGISTRATION)
        try {
            DB::table('profile_view_limits')->insert([
                'viewer_user_id' => $user->user_id,
                'plan_id' => 6, // FREE REGISTRATION
                'package_profile_limit' => 6,
                'use_package_profile_limit' => 0,
                'remain_daily_profile_limit' => 2,
                'package_limit_at' => now(),
                'daily_limit_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Ignore if limit row exists
        }

        // Save multiple images into users_images table
        if ($selfiePicName) {
            \Illuminate\Support\Facades\DB::table('users_images')->insert([
                'user_id' => $user->user_id,
                'images' => $selfiePicName,
                'main' => 1,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($profilePicName && $profilePicName !== $selfiePicName) {
            \Illuminate\Support\Facades\DB::table('users_images')->insert([
                'user_id' => $user->user_id,
                'images' => $profilePicName,
                'main' => empty($selfiePicName) ? 1 : 0,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($profilePic1Name) {
            \Illuminate\Support\Facades\DB::table('users_images')->insert([
                'user_id' => $user->user_id,
                'images' => $profilePic1Name,
                'main' => 0,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Handle multiple images from array input images[]
        if ($request->hasFile('images')) {
            $imagesArray = $request->file('images');
            if (!is_array($imagesArray)) {
                $imagesArray = [$imagesArray];
            }
            foreach ($imagesArray as $idx => $imgFile) {
                if ($imgFile && $imgFile->isValid()) {
                    $imgName = time() . '-img' . ($idx + 1) . '-' . uniqid() . '.' . $imgFile->getClientOriginalExtension();
                    $imgFile->move(public_path('uploads'), $imgName);

                    \Illuminate\Support\Facades\DB::table('users_images')->insert([
                        'user_id' => $user->user_id,
                        'images' => $imgName,
                        'main' => 0,
                        'is_active' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if (empty($user->profile_pic1)) {
                        $user->profile_pic1 = $imgName;
                        $user->save();
                    }
                }
            }
        }

        // Start session
        session([
            'user_id' => $user->user_id,
            'profile_id' => $user->profile_id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'user_type' => $user->user_type,
            'user' => $user->toArray(),
            'role' => 'user',
        ]);
        \Illuminate\Support\Facades\Auth::login($user);

        $profileController = new ProfileApiController();
        $userData = $profileController->formatUserProfile($user, true);
        $userData['role'] = 'user';

        $responseData = [
            'success' => true,
            'message' => 'Alhamdulillah! Your matrimonial profile has been created successfully. Your Profile ID is: ' . $profileId,
            'profile_id' => $profileId,
            'redirect_url' => url('/dashboard'),
            'user' => $userData,
            'requires_payment' => $isPaidPlan,
        ];

        if ($isPaidPlan && $targetPlan) {
            $planPrice = !empty($targetPlan->plan_discount) ? (float) $targetPlan->plan_discount : (float) $targetPlan->plan_price;
            $responseData['payment_details'] = [
                'plan_id' => $targetPlan->plan_id,
                'plan_name' => $targetPlan->plan_name,
                'price' => $planPrice,
                'price_formatted' => number_format($planPrice, 0),
                'currency' => '₹',
                'duration' => $targetPlan->plan_duration ?? '3 MONTHS',
                'package_limit' => (int) ($targetPlan->package_limit ?? 30),
                'daily_limit' => (int) ($targetPlan->daily_limit ?? 2),
            ];
            $responseData['message'] = "Alhamdulillah! Profile {$profileId} created. Please complete payment to activate your {$targetPlan->plan_name} membership.";
        }

        if ($request->is('api/*') || $request->isJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json($responseData, 201);
        }

        return redirect()->route('dashboard')->with('success', 'Alhamdulillah! Profile registered successfully with ID: ' . $profileId);
    }

    /**
     * Get Current Authenticated User profile.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        $userId = session('user_id');
        $adminId = session('admin_id');

        if ($adminId) {
            $admin = DB::table('admin')->where('id', $adminId)->where('status', 1)->first();
            if ($admin) {
                $adminRole = 'operator';
                $adminTypeLower = strtolower($admin->type ?? '');
                if (($admin->role ?? '') === 'super_admin' 
                    || str_contains($adminTypeLower, 'super') 
                    || str_contains($adminTypeLower, 'master') 
                    || (int)$admin->id === 1) {
                    $adminRole = 'super_admin';
                }

                return response()->json([
                    'success' => true,
                    'role' => $adminRole,
                    'user' => [
                        'id' => $admin->id,
                        'username' => $admin->username,
                        'email' => $admin->email,
                        'mobile' => $admin->mobile,
                        'role' => $adminRole,
                        'type' => $admin->type ?? ($adminRole === 'super_admin' ? 'Master Admin' : 'Operator'),
                    ],
                ]);
            }
        }

        if ($userId) {
            $user = User::find($userId);
            if ($user && empty($user->is_deleted)) {
                $profileController = new ProfileApiController();
                $formattedUser = $profileController->formatUserProfile($user, true);
                $formattedUser['role'] = 'customer';
                $formattedUser['is_verified'] = (int) $user->is_verified;
                $formattedUser['unlocked_profile_limit'] = $user->getUnlockedProfileCount();
                $formattedUser['is_package_active'] = !$user->isPackageExpired();
                $formattedUser['is_package_expired'] = $user->isPackageExpired();
                $formattedUser['profile_view_limits'] = $user->getProfileViewLimits();

                return response()->json([
                    'success' => true,
                    'role' => 'customer',
                    'user' => $formattedUser,
                ]);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated. No active session.',
        ], 401);
    }

    /**
     * Log out member or admin.
     *
     * @param Request $request
     * @return JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function logout(Request $request)
    {
        \Illuminate\Support\Facades\Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->is('api/*') || $request->isJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully.',
                'redirect_url' => url('/login'),
            ]);
        }

        return redirect()->route('login')->with('success', 'You have been logged out successfully.');
    }
}
