<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The condition that makes a claim the domain's admin.
     */
    protected function expression(): string
    {
        return 'case when verified_at is not null and superseded_at is null then domain end';
    }

    /**
     * Make "one active claim per domain" a rule the database keeps.
     *
     * Active is a combination of two nullable timestamps — verified_at set,
     * superseded_at not — and no portable unique index can be built over a
     * condition. A column that holds the domain exactly while the claim is
     * active, and NULL otherwise, can be: every supported database exempts
     * NULL from uniqueness, so superseded and unverified claims never collide
     * while two active claims for one domain cannot both exist.
     *
     * The column is generated rather than written by the application, so it
     * cannot drift from the timestamps it stands for and no writer can evade
     * the constraint by setting them and forgetting it. Generated columns are
     * added stored where that is allowed and virtual on sqlite, which permits
     * only virtual ones in ALTER TABLE; both are indexable, so the invariant
     * is the same one on every driver rather than a strong rule on PostgreSQL
     * and nothing on sqlite.
     */
    public function up(): void
    {
        $this->supersedeDuplicateActiveClaims();

        $virtual = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::table('domain_claims', function (Blueprint $table) use ($virtual) {
            $column = $table->string('active_domain')->nullable();

            $virtual ? $column->virtualAs($this->expression()) : $column->storedAs($this->expression());
        });

        Schema::table('domain_claims', function (Blueprint $table) {
            $table->unique('active_domain');
        });
    }

    /**
     * Leave one active claim per domain before the index insists on it.
     *
     * An application that has been running the racing version of the
     * activation may already hold two. Resolved the way the feature documents
     * its own rule — the most recent successful verification keeps the flag —
     * rather than by an arbitrary pick, and by superseding the others, which
     * is the state they would have been left in had the activations not
     * overlapped. No history is deleted.
     */
    protected function supersedeDuplicateActiveClaims(): void
    {
        $domains = DB::table('domain_claims')
            ->select('domain')
            ->whereNotNull('verified_at')
            ->whereNull('superseded_at')
            ->groupBy('domain')
            ->havingRaw('count(*) > 1')
            ->pluck('domain');

        foreach ($domains as $domain) {
            $keep = DB::table('domain_claims')
                ->where('domain', $domain)
                ->whereNotNull('verified_at')
                ->whereNull('superseded_at')
                ->orderByDesc('verified_at')
                ->orderByDesc('id')
                ->value('id');

            DB::table('domain_claims')
                ->where('domain', $domain)
                ->whereNotNull('verified_at')
                ->whereNull('superseded_at')
                ->where('id', '!=', $keep)
                ->update(['superseded_at' => now()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domain_claims', function (Blueprint $table) {
            $table->dropUnique(['active_domain']);
        });

        Schema::table('domain_claims', function (Blueprint $table) {
            $table->dropColumn('active_domain');
        });
    }
};
