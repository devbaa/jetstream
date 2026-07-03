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
            $table->uuid('current_tenant_id')->nullable();
            $table->uuid('current_customer_account_id')->nullable();
            $table->boolean('is_system_admin')->default(false);
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['current_tenant_id', 'current_customer_account_id', 'is_system_admin']);
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
    }
};
