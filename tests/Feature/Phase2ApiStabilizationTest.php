<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Api\AuthApiController;

class Phase2ApiStabilizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'alnikahmarr_db',
            'session.driver' => 'array',
        ]);
        DB::purge('mysql');
    }
    /**
     * Test 1: Public Endpoints Smoke Tests.
     */
    public function test_public_endpoints_smoke(): void
    {
        // Meta options
        $response = $this->getJson('/api/meta/options');
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'religions',
                    'castes',
                ],
            ]);

        // Featured profiles
        $response = $this->getJson('/api/profiles/featured');
        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Packages
        $response = $this->getJson('/api/packages');
        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Banners
        $response = $this->getJson('/api/banners');
        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Gallery
        $response = $this->getJson('/api/gallery');
        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * Test 2: Unauthenticated access to protected member endpoints must fail with 401.
     */
    public function test_unauthenticated_access_to_protected_endpoints_fails(): void
    {
        // Profile update
        $res = $this->putJson('/api/profile/update', ['name' => 'Hacker']);
        $res->assertStatus(401)
            ->assertJson(['success' => false]);

        // Change password
        $res = $this->postJson('/api/profile/change-password', [
            'current_password' => 'old',
            'new_password' => 'new123456',
        ]);
        $res->assertStatus(401)
            ->assertJson(['success' => false]);

        // Current plan
        $res = $this->getJson('/api/packages/current');
        $res->assertStatus(401)
            ->assertJson(['success' => false]);

        // Purchase package
        $res = $this->postJson('/api/packages/purchase', ['plan_id' => 1]);
        $res->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    /**
     * Test 3: Admin session cannot impersonate or access member-only profile endpoints.
     */
    public function test_admin_session_rejected_from_member_endpoints(): void
    {
        $res = $this->withSession([
            'is_admin' => true,
            'admin_id' => 1,
            'role' => 'admin',
        ])->putJson('/api/profile/update', ['name' => 'Admin Edited']);

        $res->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    /**
     * Test 4: Authenticated member can update permitted fields, but cannot modify sensitive fields.
     */
    public function test_authenticated_member_can_update_only_permitted_fields(): void
    {
        DB::beginTransaction();
        try {
            // Find existing active test user
            $user = User::where('is_active', 1)->first();
            $this->assertNotNull($user, 'Active user record required for test.');

            $originalId = $user->user_id;
            $originalType = $user->user_type;
            $originalVerified = $user->is_verified;
            $originalPackage = $user->package;
            $originalPassword = $user->password;

            $payload = [
                // Permitted fields
                'qualification' => 'Master of Computer Applications',
                'work' => 'Lead Software Engineer',
                'accommodation' => 'Own Luxury Apartment',
                // Malicious / Forbidden fields
                'user_id' => 99999,
                'user_type' => 'BRIDE',
                'is_verified' => 1,
                'is_active' => 0,
                'is_deleted' => 1,
                'package' => 'VIP PLATINUM SPECIAL',
                'password' => 'HackedPassword123!',
            ];

            $res = $this->withSession([
                'user_id' => $user->user_id,
                'profile_id' => $user->profile_id,
                'role' => 'user',
            ])->putJson('/api/profile/update', $payload);

            $res->assertStatus(200)
                ->assertJson(['success' => true]);

            $fresh = $user->fresh();

            // Assert permitted fields were updated
            $this->assertEquals('Master of Computer Applications', $fresh->qualification);
            $this->assertEquals('Lead Software Engineer', $fresh->work);
            $this->assertEquals('Own Luxury Apartment', $fresh->accommodation);

            // Assert forbidden fields were NOT altered
            $this->assertEquals($originalId, $fresh->user_id);
            $this->assertEquals($originalType, $fresh->user_type);
            $this->assertEquals($originalVerified, $fresh->is_verified);
            $this->assertEquals($originalPackage, $fresh->package);
            $this->assertEquals($originalPassword, $fresh->password);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Test 5: Member A cannot update or view Member B's profile or package.
     */
    public function test_member_a_cannot_access_or_update_member_b_data(): void
    {
        DB::beginTransaction();
        try {
            $users = User::where('is_active', 1)->take(2)->get();
            $this->assertGreaterThanOrEqual(2, $users->count(), 'At least two users needed for isolation test.');

            $memberA = $users[0];
            $memberB = $users[1];

            $originalBPackage = $memberB->package;

            // Member A tries to inspect Member B's package by passing user_id or profile_id
            $res = $this->withSession([
                'user_id' => $memberA->user_id,
                'role' => 'user',
            ])->getJson("/api/packages/current?user_id={$memberB->user_id}&profile_id={$memberB->profile_id}");

            $res->assertStatus(200);
            // Verify returned data belongs to Member A, NOT Member B
            $res->assertJsonPath('data.user_id', $memberA->user_id);
            $this->assertNotEquals($memberB->user_id, $res->json('data.user_id'));

            // Member A tries to purchase/upgrade Member B's plan by injecting user_id in payload
            $plan = Plan::where('is_active', 1)->first();
            $this->assertNotNull($plan);

            $purchaseRes = $this->withSession([
                'user_id' => $memberA->user_id,
                'role' => 'user',
            ])->postJson('/api/packages/purchase', [
                'user_id' => $memberB->user_id,
                'profile_id' => $memberB->profile_id,
                'plan_id' => $plan->plan_id,
            ]);

            $purchaseRes->assertStatus(200);
            $purchaseRes->assertJsonPath('data.user_id', $memberA->user_id);

            // Verify Member B was NOT modified
            $this->assertEquals($originalBPackage, $memberB->fresh()->package);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Test 6: Invalid current password is rejected.
     */
    public function test_invalid_current_password_is_rejected(): void
    {
        $user = User::where('is_active', 1)->first();
        $this->assertNotNull($user);

        $res = $this->withSession([
            'user_id' => $user->user_id,
            'role' => 'user',
        ])->postJson('/api/profile/change-password', [
            'current_password' => 'WrongPasswordDefinitely',
            'new_password' => 'NewSecurePassword123',
            'new_password_confirmation' => 'NewSecurePassword123',
        ]);

        $res->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'The current password you entered is incorrect.',
            ]);
    }

    /**
     * Test 7: Password changes are securely hashed with bcrypt.
     */
    public function test_password_change_securely_hashed(): void
    {
        DB::beginTransaction();
        try {
            $user = User::where('is_active', 1)->first();
            $this->assertNotNull($user);

            // Set known initial password in transaction
            $initialPlain = 'TemporaryInit123';
            $user->password = $initialPlain;
            $user->save();

            $newPassword = 'BrandNewSecurePassword456!';

            $res = $this->withSession([
                'user_id' => $user->user_id,
                'role' => 'user',
            ])->postJson('/api/profile/change-password', [
                'current_password' => $initialPlain,
                'new_password' => $newPassword,
                'new_password_confirmation' => $newPassword,
            ]);

            $res->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Password has been updated successfully.',
                ]);

            $freshUser = $user->fresh();

            // Verify it is hashed with bcrypt and matches
            $this->assertTrue(str_starts_with($freshUser->password, '$2y$') || str_starts_with($freshUser->password, '$2a$'));
            $this->assertTrue(Hash::check($newPassword, $freshUser->password));
            $this->assertTrue(AuthApiController::verifyPassword($newPassword, $freshUser->password));
            $this->assertFalse(AuthApiController::verifyPassword($initialPlain, $freshUser->password));
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Test 8: OTP endpoint validation and unconfigured provider response.
     */
    public function test_otp_endpoints_behavior(): void
    {
        // Missing mobile validation
        $res = $this->postJson('/api/send-otp', []);
        $res->assertStatus(422)
            ->assertJson(['success' => false]);

        // Valid mobile format, but unconfigured SMS provider in production environment
        $res = $this->postJson('/api/send-otp', ['mobile' => '9876543210']);
        // With unconfigured SMS gateway in production/standard mode, returns 503
        $this->assertTrue(in_array($res->status(), [200, 503]));
        if ($res->status() === 503) {
            $res->assertJson([
                'success' => false,
                'provider_configured' => false,
            ]);
        }

        // Invalid verify OTP without request
        $res = $this->postJson('/api/verify-otp', [
            'mobile' => '9876543210',
            'otp' => '000000',
        ]);
        $res->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    /**
     * Test 9: CORS preflight headers.
     */
    public function test_cors_preflight_and_credentials(): void
    {
        $res = $this->call(
            'OPTIONS',
            '/api/profiles',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'http://localhost:5500',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type, Accept',
            ]
        );

        $res->assertStatus(204);
        $this->assertEquals('http://localhost:5500', $res->headers->get('Access-Control-Allow-Origin'));
        $this->assertEquals('true', $res->headers->get('Access-Control-Allow-Credentials'));
    }
}
