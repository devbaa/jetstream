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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('event');
            // Not nullableUuidMorphs: the trait is offered for any Eloquent
            // model, so this column holds one model's UUID and another's
            // auto-incrementing integer. The type column tells them apart.
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id')->nullable();
            $table->index(['auditable_type', 'auditable_id']);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
