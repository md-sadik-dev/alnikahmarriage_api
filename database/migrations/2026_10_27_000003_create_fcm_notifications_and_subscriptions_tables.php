<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop any partially created tables if existed
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('push_subscriptions');

        // 1. Push Subscriptions (Browser device tokens per member)
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->index();
            $table->string('token', 512)->unique();
            $table->text('user_agent')->nullable();
            $table->string('device_name', 100)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // Foreign key to users.user_id (int 11 signed)
            $table->foreign('user_id')
                ->references('user_id')
                ->on('users')
                ->onDelete('cascade');
        });

        // 2. Notifications (Persistent notification history & deduplication)
        Schema::create('notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->index();
            $table->string('type', 64)->index(); // package_expiry, matching_profile, profile_status, interest_received, interest_response
            $table->string('title', 191);
            $table->text('body');
            $table->string('action_url', 255)->default('/dashboard');
            $table->json('data')->nullable();
            $table->string('dedup_key', 191)->nullable()->unique();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('user_id')
                ->on('users')
                ->onDelete('cascade');
        });

        // 3. Notification Deliveries (FCM delivery attempt & response tracking)
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('notification_id')->index();
            $table->unsignedBigInteger('push_subscription_id')->nullable()->index();
            $table->string('status', 32)->default('queued')->index(); // queued, sent, failed, invalid_token
            $table->string('fcm_message_id', 191)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempt_count')->default(1);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->foreign('notification_id')
                ->references('id')
                ->on('notifications')
                ->onDelete('cascade');

            $table->foreign('push_subscription_id')
                ->references('id')
                ->on('push_subscriptions')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('push_subscriptions');
    }
};
