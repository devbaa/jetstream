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
        Schema::create('domain_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('domain_claim_id')->index();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->foreignUuid('subject_id')->nullable()->index();
            $table->string('action');
            $table->json('details')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domain_activities');
    }
};
