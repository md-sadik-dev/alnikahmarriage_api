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
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'delete_request')) {
                $table->tinyInteger('delete_request')->default(0)->after('is_deleted');
            }
            if (!Schema::hasColumn('users', 'delete_approved')) {
                $table->tinyInteger('delete_approved')->default(0)->after('delete_request');
            }
            if (!Schema::hasColumn('users', 'delete_reason')) {
                $table->string('delete_reason', 255)->nullable()->after('delete_approved');
            }
            if (!Schema::hasColumn('users', 'delete_requested_at')) {
                $table->dateTime('delete_requested_at')->nullable()->after('delete_reason');
            }
            if (!Schema::hasColumn('users', 'delete_approved_at')) {
                $table->dateTime('delete_approved_at')->nullable()->after('delete_requested_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'delete_request',
                'delete_approved',
                'delete_reason',
                'delete_requested_at',
                'delete_approved_at'
            ]);
        });
    }
};
