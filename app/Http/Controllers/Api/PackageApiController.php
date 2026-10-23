<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PackageApiController extends Controller
{
    /**
     * Get list of all active matrimonial membership packages from DB.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Plan::query();

        // Filter active plans by default
        if ($request->has('all') && $request->boolean('all')) {
            // return all including inactive
        } else {
            $query->where('is_active', 1);
        }

        // Custom sequence requested:
        // 1. FREE REGISTRATION
        // 2. SILVER
        // 3. GOLD
        // 4. DIAMOND
        // 5. SPECIAL CATEGORY
        // 6. PLATINUM CATEGORY
        $desiredOrder = [
            'FREE REGISTRATION',
            'SILVER',
            'GOLD',
            'DIAMOND',
            'SPECIAL CATEGORY',
            'PLATINUM CATEGORY',
        ];

        $caseSql = "CASE ";
        foreach ($desiredOrder as $idx => $name) {
            $caseSql .= "WHEN UPPER(TRIM(plan_name)) = '{$name}' THEN {$idx} ";
        }
        $caseSql .= "ELSE 99 END, plan_id ASC";

        $plans = $query->orderByRaw($caseSql)->get();

        $formatted = $plans->map(function ($plan) {
            return $this->formatPlan($plan);
        });

        return response()->json([
            'success' => true,
            'message' => 'Packages retrieved successfully from database.',
            'count' => $formatted->count(),
            'data' => $formatted,
        ]);
    }

    /**
     * Get single package by plan_id or name.
     *
     * @param string|int $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $plan = Plan::where('plan_id', $id)
            ->orWhere('plan_name', 'LIKE', $id)
            ->first();

        if (!$plan) {
            return response()->json([
                'success' => false,
                'message' => 'Package plan not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Package details retrieved successfully.',
            'data' => $this->formatPlan($plan),
        ]);
    }

    /**
     * Purchase or Upgrade Package Plan.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function purchase(Request $request): JsonResponse
    {
        // 1. Identify Target User from session, Auth facade, or verified parameters
        /** @var User|null $user */
        $user = $request->attributes->get('authenticated_member');
        if (!$user) {
            $userId = session('user_id') ?: \Illuminate\Support\Facades\Auth::id();
            if ($userId) {
                $user = User::where('user_id', $userId)
                    ->where(function ($q) {
                        $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                    })
                    ->first();
            }
        }

        if (!$user && ($request->filled('profile_id') || $request->filled('user_id'))) {
            $candidateQuery = User::where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
            });
            if ($request->filled('profile_id')) {
                $candidateQuery->where('profile_id', trim($request->input('profile_id')));
            } else {
                $candidateQuery->where('user_id', $request->input('user_id'));
            }
            $candidate = $candidateQuery->first();
            if ($candidate && (empty(session('user_id')) || session('user_id') == $candidate->user_id)) {
                $user = $candidate;
            }
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Active member session is required.',
            ], 401);
        }

        // 2. Identify Target Plan (by plan_id or plan_name)
        $planId = $request->input('plan_id') ?? $request->input('package_id');
        $planName = $request->input('plan_name') ?? $request->input('package');

        if (!$planId && !$planName) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide plan_id or plan_name to purchase or upgrade.',
            ], 422);
        }

        $query = Plan::query();
        if ($planId) {
            $query->where('plan_id', $planId);
        } else {
            $query->where('plan_name', 'LIKE', $planName);
        }
        $plan = $query->first();

        if (!$plan) {
            return response()->json([
                'success' => false,
                'message' => 'Selected package plan does not exist or is inactive.',
            ], 404);
        }

        $previousPackage = $user->package ?? 'None';
        $startDate = Carbon::now()->format('Y-m-d');
        $endDate = $this->calculateEndDate($plan->plan_duration ?? '3 MONTHS');

        // 3. Update User profile with new package & duration
        $user->package = $plan->plan_name;
        $user->start_date = $startDate;
        $user->end_date = $endDate;
        $user->modified_at = Carbon::now();
        $user->save();

        // 4. Update or Insert profile_view_limits
        $existingLimit = DB::table('profile_view_limits')
            ->where('viewer_user_id', $user->user_id)
            ->first();

        $limitData = [
            'plan_id' => $plan->plan_id,
            'package_profile_limit' => (int) ($plan->package_limit ?? 30),
            'use_package_profile_limit' => 0,
            'remain_daily_profile_limit' => (int) ($plan->daily_limit ?? 2),
            'package_limit_at' => Carbon::now(),
            'daily_limit_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];

        if ($existingLimit) {
            DB::table('profile_view_limits')
                ->where('limit_id', $existingLimit->limit_id)
                ->update($limitData);
        } else {
            $limitData['viewer_user_id'] = $user->user_id;
            DB::table('profile_view_limits')->insert($limitData);
        }

        // 5. Update session if this user is currently authenticated
        if (session('user_id') == $user->user_id) {
            session([
                'package' => $user->package,
                'user' => $user->fresh()->toArray(),
            ]);
        }

        $offerPrice = !empty($plan->plan_discount) ? (float) $plan->plan_discount : (float) $plan->plan_price;
        $isUpgrade = (!empty($previousPackage) && strtoupper($previousPackage) !== 'NONE' && strtoupper($previousPackage) !== strtoupper($plan->plan_name));
        $actionWord = $isUpgrade ? 'upgraded' : 'purchased';

        return response()->json([
            'success' => true,
            'message' => "Alhamdulillah! Package successfully {$actionWord} to {$plan->plan_name}.",
            'action' => $actionWord,
            'data' => [
                'user_id' => $user->user_id,
                'profile_id' => $user->profile_id,
                'user_name' => $user->name,
                'previous_package' => $previousPackage,
                'current_package' => $user->package,
                'plan_id' => $plan->plan_id,
                'plan_name' => $plan->plan_name,
                'price' => $offerPrice,
                'currency' => '₹',
                'duration' => $plan->plan_duration,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'package_limit' => (int) ($plan->package_limit ?? 30),
                'daily_limit' => (int) ($plan->daily_limit ?? 2),
                'payment_status' => 'SUCCESS',
                'payment_method' => $request->input('payment_method', 'Online Payment / UPI'),
                'transaction_id' => $request->input('transaction_id', 'TXN' . strtoupper(uniqid())),
                'activated_at' => Carbon::now()->toDateTimeString(),
            ],
        ], 200);
    }

    /**
     * Get Current Active Plan and Profile View Limits for User.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function currentPlan(Request $request): JsonResponse
    {
        // Identify user strictly from authenticated session principal
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

        $plan = Plan::where('plan_name', 'LIKE', $user->package)->first();
        $limit = DB::table('profile_view_limits')
            ->where('viewer_user_id', $user->user_id)
            ->first();

        $totalLimit = $limit ? (int) $limit->package_profile_limit : (int) ($plan->package_limit ?? 30);
        $usedLimit = $limit ? (int) $limit->use_package_profile_limit : 0;
        $remainingLimit = max(0, $totalLimit - $usedLimit);

        return response()->json([
            'success' => true,
            'message' => 'Current package and usage limits retrieved.',
            'data' => [
                'user_id' => $user->user_id,
                'profile_id' => $user->profile_id,
                'user_name' => $user->name,
                'current_package' => $user->package ?? 'FREE REGISTRATION',
                'start_date' => $user->start_date,
                'end_date' => $user->end_date,
                'total_contact_limit' => $totalLimit,
                'used_contact_limit' => $usedLimit,
                'remaining_contact_limit' => $remainingLimit,
                'daily_limit' => $limit ? (int) $limit->remain_daily_profile_limit : (int) ($plan->daily_limit ?? 2),
                'plan_details' => $plan ? $this->formatPlan($plan) : null,
            ],
        ]);
    }

    /**
     * Helper to compute end_date based on plan duration text.
     *
     * @param string $duration
     * @return string
     */
    private function calculateEndDate(string $duration): string
    {
        $d = strtoupper(trim($duration));
        if (str_contains($d, 'UNLIMITED') || str_contains($d, 'UN LIMITED') || str_contains($d, 'TILL NIKAH')) {
            return Carbon::now()->addYears(5)->format('Y-m-d');
        }
        if (preg_match('/(\d+)\s*MONTH/i', $d, $m)) {
            $months = (int) $m[1];
            return Carbon::now()->addMonths($months)->format('Y-m-d');
        }
        if (str_contains($d, 'ONE MONTH')) {
            return Carbon::now()->addMonth()->format('Y-m-d');
        }
        if (preg_match('/(\d+)\s*YEAR/i', $d, $m)) {
            $years = (int) $m[1];
            return Carbon::now()->addYears($years)->format('Y-m-d');
        }
        return Carbon::now()->addMonths(3)->format('Y-m-d');
    }

    /**
     * Format Plan model into normalized API structure.
     *
     * @param Plan $plan
     * @return array
     */
    public function formatPlan(Plan $plan): array
    {
        // Extract non-empty feature bullet points
        $features = [];
        for ($i = 1; $i <= 6; $i++) {
            $key = "plan_text{$i}";
            if (!empty($plan->$key) && trim($plan->$key) !== '') {
                $features[] = trim($plan->$key);
            }
        }

        // Determine badge / ribbon
        $planNameUpper = strtoupper(trim($plan->plan_name));
        $ribbon = 'Membership Plan';
        $isFeatured = false;
        $cardClass = 'package-card';

        if (str_contains($planNameUpper, 'FREE')) {
            $ribbon = 'Special Offer';
        } elseif (str_contains($planNameUpper, 'SILVER')) {
            $ribbon = 'Silver Plan';
        } elseif (str_contains($planNameUpper, 'GOLD')) {
            $ribbon = 'Popular Choice';
        } elseif (str_contains($planNameUpper, 'DIAMOND')) {
            $ribbon = 'Most Recommended';
            $isFeatured = true;
            $cardClass = 'package-card package-card-featured';
        } elseif (str_contains($planNameUpper, 'SPECIAL')) {
            $ribbon = 'Special Category';
        } elseif (str_contains($planNameUpper, 'PLATINUM')) {
            $ribbon = 'VIP Elite';
        }

        $originalPrice = (float) $plan->plan_price;
        $offerPrice = !empty($plan->plan_discount) ? (float) $plan->plan_discount : $originalPrice;

        return [
            'id' => $plan->plan_id,
            'plan_id' => $plan->plan_id,
            'name' => $plan->plan_name,
            'ribbon' => $ribbon,
            'is_featured' => $isFeatured,
            'card_class' => $cardClass,
            'original_price' => $originalPrice,
            'original_price_formatted' => number_format($originalPrice, 0) . ' INR',
            'offer_price' => $offerPrice,
            'offer_price_formatted' => number_format($offerPrice, 0),
            'currency' => '₹',
            'currency_code' => 'INR',
            'duration' => $plan->plan_duration ?? '1 MONTH',
            'package_limit' => $plan->package_limit ?? 0,
            'daily_limit' => $plan->daily_limit ?? 0,
            'features' => $features,
            'is_active' => (bool) ($plan->is_active == 1),
        ];
    }
}
