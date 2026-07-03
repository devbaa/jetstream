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
            $table->softDeletes();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('customer_accounts', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('customer_accounts', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
