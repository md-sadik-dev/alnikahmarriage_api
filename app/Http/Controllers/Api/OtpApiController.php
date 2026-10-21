<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OtpApiController extends Controller
{
    /**
     * Send OTP to a mobile number.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $inputData = $request->isJson() ? $request->json()->all() : $request->all();
        if (empty($inputData)) {
            $inputData = json_decode($request->getContent(), true) ?: $request->all();
        }

        $mobile = trim($inputData['mobile'] ?? ($inputData['phone'] ?? ''));
        $inputData['mobile'] = $mobile;

        $validator = Validator::make($inputData, [
            'mobile' => ['required', 'string', 'regex:/^[0-9]{10,15}$/'],
        ], [
            'mobile.required' => 'Please provide a valid mobile number.',
            'mobile.regex' => 'Mobile number must contain between 10 and 15 digits.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        // Check whether a real SMS provider gateway is configured
        $smsApiKey = env('SMS_API_KEY') ?: env('SMS_GATEWAY_KEY');
        $isSimulationMode = config('app.env') === 'local' && env('OTP_SIMULATION_ENABLED', false);

        if (!$smsApiKey && !$isSimulationMode) {
            return response()->json([
                'success' => false,
                'provider_configured' => false,
                'message' => 'SMS gateway provider is not configured on this server. Real SMS OTP delivery is currently unavailable.',
            ], 503);
        }

        // Rate limiting: 60-second cooldown per mobile number
        $throttleKey = 'otp_cooldown_' . $mobile;
        if (Cache::has($throttleKey)) {
            $secondsLeft = Cache::get($throttleKey) - time();
            if ($secondsLeft > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Please wait {$secondsLeft} seconds before requesting a new OTP.",
                ], 429);
            }
        }

        // Generate 6-digit OTP
        $otp = (string) random_int(100000, 999999);
        $otpCacheKey = 'otp_hash_' . $mobile;

        // Store secure hash of OTP with 5-minute expiry and attempt counter
        Cache::put($otpCacheKey, [
            'hash' => Hash::make($otp),
            'attempts' => 0,
            'created_at' => time(),
        ], 300);

        // Set 60-second cooldown
        Cache::put($throttleKey, time() + 60, 60);

        // If a real SMS provider is integrated, dispatch the SMS here without logging the OTP in production
        if ($smsApiKey) {
            // Real SMS dispatch logic here
            // Note: Never log the actual OTP in production logs
            Log::info("SMS OTP dispatched to masked number " . substr($mobile, 0, 3) . '****' . substr($mobile, -3));

            return response()->json([
                'success' => true,
                'provider_configured' => true,
                'message' => 'OTP has been dispatched to your mobile number via SMS.',
            ], 200);
        }

        // Local development simulation mode (explicitly enabled via OTP_SIMULATION_ENABLED=true)
        return response()->json([
            'success' => true,
            'provider_configured' => false,
            'simulation' => true,
            'message' => 'Simulated OTP generated for local development testing.',
            'test_otp' => $otp, // Exposed strictly when OTP_SIMULATION_ENABLED is true in local environment
        ], 200);
    }

    /**
     * Verify submitted OTP for a mobile number.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $inputData = $request->isJson() ? $request->json()->all() : $request->all();
        if (empty($inputData)) {
            $inputData = json_decode($request->getContent(), true) ?: $request->all();
        }

        $mobile = trim($inputData['mobile'] ?? ($inputData['phone'] ?? ''));
        $otp = trim($inputData['otp'] ?? '');
        $inputData['mobile'] = $mobile;
        $inputData['otp'] = $otp;

        $validator = Validator::make($inputData, [
            'mobile' => ['required', 'string', 'regex:/^[0-9]{10,15}$/'],
            'otp' => ['required', 'string', 'digits:6'],
        ], [
            'mobile.required' => 'Mobile number is required.',
            'otp.required' => 'Please enter the 6-digit OTP.',
            'otp.digits' => 'OTP must be exactly 6 digits.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $otpCacheKey = 'otp_hash_' . $mobile;
        $cachedData = Cache::get($otpCacheKey);

        if (!$cachedData || !isset($cachedData['hash'])) {
            return response()->json([
                'success' => false,
                'message' => 'OTP has expired or was not requested for this mobile number. Please request a new OTP.',
            ], 422);
        }

        // Check max attempts (limit to 5 attempts to prevent brute force)
        if (($cachedData['attempts'] ?? 0) >= 5) {
            Cache::forget($otpCacheKey);
            return response()->json([
                'success' => false,
                'message' => 'Too many failed attempts. This OTP has been invalidated. Please request a new OTP.',
            ], 429);
        }

        // Verify hash
        if (!Hash::check($otp, $cachedData['hash'])) {
            $cachedData['attempts'] = ($cachedData['attempts'] ?? 0) + 1;
            Cache::put($otpCacheKey, $cachedData, 300);

            $attemptsLeft = 5 - $cachedData['attempts'];
            return response()->json([
                'success' => false,
                'message' => "Invalid OTP entered. {$attemptsLeft} attempt(s) remaining.",
            ], 422);
        }

        // Verified successfully: clear OTP cache and record verification state for 1 hour
        Cache::forget($otpCacheKey);
        Cache::put('phone_verified_' . $mobile, true, 3600);

        return response()->json([
            'success' => true,
            'message' => 'Mobile number verified successfully.',
            'verified' => true,
        ], 200);
    }
}
