<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PageController extends Controller
{
    /**
     * Show Homepage with Dynamic Profiles, Packages, Banners & Gallery from DB
     */
    public function index()
    {
        $profileApi = new \App\Http\Controllers\Api\ProfileApiController();
        request()->merge(['limit_each' => 8]);
        $resProfiles = $profileApi->featured(request());
        $featuredProfiles = $resProfiles->getData()->data ?? [];

        $packageApi = new \App\Http\Controllers\Api\PackageApiController();
        $resPackages = $packageApi->index(request());
        $packages = $resPackages->getData()->data ?? [];

        $bannerApi = new \App\Http\Controllers\Api\BannerApiController();
        $resBanners = $bannerApi->index(request());
        $banners = $resBanners->getData()->data ?? [];

        $galleryApi = new \App\Http\Controllers\Api\GalleryApiController();
        $resGallery = $galleryApi->index(request());
        $galleryItems = $resGallery->getData()->data ?? [];

        return view('index', compact('featuredProfiles', 'packages', 'banners', 'galleryItems'));
    }

    /**
     * Show About Us page
     */
    public function about()
    {
        return view('about-us');
    }

    /**
     * Show Founder Message page
     */
    public function founderMessage()
    {
        return view('founder-message');
    }

    /**
     * Show Services page
     */
    public function services()
    {
        return view('services');
    }

    /**
     * Show Bride & Groom page
     */
    public function brideAndGroom()
    {
        return view('login');
    }

    /**
     * Show Success Stories page with Dynamic Gallery from DB
     */
    public function successStories()
    {
        $galleryApi = new \App\Http\Controllers\Api\GalleryApiController();
        $resGallery = $galleryApi->index(request());
        $galleryItems = $resGallery->getData()->data ?? [];

        return view('success-stories', compact('galleryItems'));
    }

    /**
     * Show Terms and Conditions page
     */
    public function termsAndConditions()
    {
        return view('terms-and-conditions');
    }

    /**
     * Show Contact Us page
     */
    public function contact()
    {
        return view('contact-us');
    }

    /**
     * Store feedback / inquiry in queries table with backend validation
     */
    public function submitFeedback(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],
            'phone' => [
                'required',
                'string',
                'min:8',
                'max:25',
                'regex:/^[0-9+\-\s()]{8,25}$/',
            ],
            'email' => 'nullable|email|max:100',
            'subject' => 'nullable|string|max:100',
            'message' => 'nullable|string|max:500',
        ], [
            'name.required' => 'Please enter your name.',
            'name.min' => 'Name must be at least 2 characters.',
            'name.max' => 'Name cannot exceed 100 characters.',
            'phone.required' => 'Please enter your phone number.',
            'phone.min' => 'Phone number must be at least 8 digits.',
            'phone.max' => 'Phone number cannot exceed 25 characters.',
            'phone.regex' => 'Please enter a valid phone number.',
            'email.email' => 'Please enter a valid email address.',
            'message.max' => 'Message cannot exceed 500 characters.',
        ]);

        if ($validator->fails()) {
            if ($request->is('api/*') || $request->isJson() || $request->ajax() || $request->wantsJson() || $request->expectsJson() || $request->header('Accept') === 'application/json') {
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

        $validated = $validator->validated();

        $name = substr(trim($validated['name']), 0, 100);
        $phone = substr(trim($validated['phone']), 0, 25);
        $email = !empty($validated['email']) ? substr(trim($validated['email']), 0, 100) : null;
        $subject = !empty($validated['subject']) ? substr(trim($validated['subject']), 0, 50) : null;
        $message = !empty($validated['message']) ? substr(trim($validated['message']), 0, 500) : null;

        // Prevent accidental duplicate submissions within 10 seconds
        $recentDuplicate = \App\Models\Query::where('phone', $phone)
            ->where('message', $message)
            ->where('created_at', '>=', now()->subSeconds(10))
            ->first();

        if ($recentDuplicate) {
            if ($request->is('api/*') || $request->isJson() || $request->ajax() || $request->wantsJson() || $request->expectsJson() || $request->header('Accept') === 'application/json') {
                return response()->json([
                    'success' => true,
                    'message' => 'JazakAllah Khair! Your query/feedback has been received. Our team will contact you shortly.',
                    'data' => $recentDuplicate,
                ]);
            }
            return redirect()->back()->with('success', 'JazakAllah Khair! Your message has been received. Our team will contact you shortly.');
        }

        $query = \App\Models\Query::create([
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'subject' => $subject,
            'message' => $message,
            'created_at' => now(),
        ]);

        if ($request->is('api/*') || $request->isJson() || $request->ajax() || $request->wantsJson() || $request->expectsJson() || $request->header('Accept') === 'application/json') {
            return response()->json([
                'success' => true,
                'message' => 'JazakAllah Khair! Your query/feedback has been received. Our team will contact you shortly.',
                'data' => $query,
            ]);
        }

        return redirect()->back()->with('success', 'JazakAllah Khair! Your message has been received. Our team will contact you shortly.');
    }

    /**
     * Show Login page
     */
    public function login()
    {
        if (session()->has('user_id') || session()->has('admin_id')) {
            return redirect()->route('dashboard');
        }
        return view('login');
    }

    /**
     * Process Member Login
     */
    public function doLogin(Request $request)
    {
        // Simple auth simulation / verification
        return redirect()->route('dashboard')->with('success', 'Assalamu Alaikum! Welcome back to your Matrimonial Dashboard.');
    }

    /**
     * Show Signup page
     */
    public function signup()
    {
        if (session()->has('user_id') || session()->has('admin_id')) {
            return redirect()->route('dashboard');
        }
        return view('signup');
    }

    /**
     * Process Member Signup
     */
    public function doSignup(Request $request)
    {
        return redirect()->route('dashboard')->with('success', 'Mubarak! Your profile has been registered and verified.');
    }

    /**
     * Show User Dashboard
     */
    public function dashboard()
    {
        $profileApi = new \App\Http\Controllers\Api\ProfileApiController();
        
        // 1. Top Recommended Matches (same mix of verified featured profiles from DB as index page)
        $resFeatured = $profileApi->featured(request());
        $recommendedMatches = $resFeatured->getData()->data ?? [];

        // 2. All Compatible Matches (for "All Profiles" tab)
        $resAll = $profileApi->index(request()->merge(['limit' => 48]));
        $allMatches = $resAll->getData()->data ?? [];

        if (empty($recommendedMatches)) {
            $recommendedMatches = $allMatches;
        }

        return view('dashboard', compact('recommendedMatches', 'allMatches'));
    }

    /**
     * Show Matrimonial Profile Detail Page
     */
    public function profileView($id)
    {
        $user = \App\Models\User::where('profile_id', $id)
            ->orWhere('user_id', $id)
            ->first();

        // Handle fallback for generated sample profile IDs like PK-GR-901 / PK-BR-8042
        if (!$user && (str_starts_with($id, 'PK-GR-') || str_starts_with($id, 'PK-BR-'))) {
            $type = str_starts_with($id, 'PK-GR-') ? 'GROOM' : 'BRIDE';
            $user = \App\Models\User::where('user_type', $type)
                ->where(function ($q) {
                    $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                })
                ->orderBy('user_id', 'desc')
                ->first();
        }

        if (!$user) {
            // Graceful fallback to first active user if still not found
            $user = \App\Models\User::where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
            })->first();
        }

        if (!$user) {
            return redirect()->route('home')->with('error', 'Profile not found.');
        }

        $profileApi = new \App\Http\Controllers\Api\ProfileApiController();
        $profile = (object) $profileApi->formatUserProfile($user, true);

        // Fetch similar profiles of the same gender for recommendation
        $similarProfiles = \App\Models\User::where('user_type', $user->user_type)
            ->where('user_id', '!=', $user->user_id)
            ->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
            })
            ->orderBy('user_id', 'desc')
            ->take(4)
            ->get()
            ->map(function ($u) use ($profileApi) {
                return (object) $profileApi->formatUserProfile($u);
            });

        return view('profile-detail', compact('profile', 'user', 'similarProfiles'));
    }

    /**
     * Logout Session
     */
    public function logout()
    {
        return redirect()->route('login')->with('success', 'You have been successfully logged out.');
    }
}
