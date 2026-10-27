<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Role on admin table
        if (Schema::hasTable('admin') && !Schema::hasColumn('admin', 'role')) {
            Schema::table('admin', function (Blueprint $table) {
                $table->string('role', 20)->default('operator')->after('type');
            });
            DB::table('admin')->where('id', 1)->orWhere('type', 'LIKE', '%Master%')->update(['role' => 'super_admin']);
        }

        // 2. Role on users table
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role', 20)->default('customer')->after('user_type');
            });
            DB::table('users')->whereNull('role')->orWhere('role', '')->update(['role' => 'customer']);
        }

        // 3. Payments table
        if (!Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->increments('payment_id');
                $table->string('transaction_id', 100)->unique();
                $table->integer('user_id')->index();
                $table->string('profile_id', 50)->nullable();
                $table->string('user_name', 100)->nullable();
                $table->string('user_email', 100)->nullable();
                $table->string('user_mobile', 25)->nullable();
                $table->integer('plan_id')->nullable();
                $table->string('plan_name', 100);
                $table->decimal('amount', 10, 2)->default(0.00);
                $table->string('currency', 10)->default('INR');
                $table->string('payment_method', 100)->default('Online Payment / UPI');
                $table->string('gateway', 50)->default('internal');
                $table->string('gateway_reference', 150)->nullable();
                $table->enum('payment_status', ['PENDING', 'SUCCESS', 'FAILED', 'REFUNDED'])->default('PENDING')->index();
                $table->string('correction_status', 50)->nullable();
                $table->text('correction_reason')->nullable();
                $table->integer('corrected_by')->nullable();
                $table->dateTime('corrected_at')->nullable();
                $table->dateTime('activated_at')->nullable();
                $table->text('notes')->nullable();
                $table->dateTime('created_at')->useCurrent()->index();
                $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        // 4. Audit Logs table
        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('actor_id')->nullable()->index();
                $table->string('actor_type', 50)->default('operator');
                $table->string('actor_name', 100)->nullable();
                $table->string('actor_email', 100)->nullable();
                $table->string('action', 100)->index();
                $table->string('module', 50)->index();
                $table->string('target_id', 100)->nullable();
                $table->string('target_type', 50)->nullable();
                $table->longText('old_values')->nullable();
                $table->longText('new_values')->nullable();
                $table->text('reason')->nullable();
                $table->string('ip_address', 50)->nullable();
                $table->text('user_agent')->nullable();
                $table->dateTime('created_at')->useCurrent()->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('payments');
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
        if (Schema::hasTable('admin') && Schema::hasColumn('admin', 'role')) {
            Schema::table('admin', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }
};
