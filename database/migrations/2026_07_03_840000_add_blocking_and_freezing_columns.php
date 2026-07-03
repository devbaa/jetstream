<?php

declare(strict_types=1);

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
            $table->timestamp('blocked_at')->nullable();
            $table->string('blocked_reason')->nullable();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('frozen_at')->nullable();
        });

        Schema::table('tenant_user', function (Blueprint $table) {
            $table->timestamp('frozen_at')->nullable();
        });

        Schema::table('customer_accounts', function (Blueprint $table) {
            $table->timestamp('frozen_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['blocked_at', 'blocked_reason']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('frozen_at');
        });

        Schema::table('tenant_user', function (Blueprint $table) {
            $table->dropColumn('frozen_at');
        });

        Schema::table('customer_accounts', function (Blueprint $table) {
            $table->dropColumn('frozen_at');
        });
    }
};
