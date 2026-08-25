<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The name the unique index carries however it is built.
     *
     * The same name Laravel would generate for $table->unique('active_domain'),
     * so dropUnique() reverses either spelling of it.
     */
    public const INDEX = 'domain_claims_active_domain_unique';

    /**
     * The condition that makes a claim the domain's admin.
     */
    public function expression(): string
    {
        return 'case when verified_at is not null and superseded_at is null then domain end';
    }

    /**
     * How the given driver spells a generated column.
     *
     * Laravel's virtualAs/storedAs modifiers exist on the MySQL, PostgreSQL
     * and sqlite grammars only. SQL Server has no such modifier at all — its
     * generated columns are a distinct column type, and asking for storedAs
     * there would silently drop the expression and leave a plain nullable
     * column that is always NULL, which is a constraint over nothing.
     *
     * sqlite gets a virtual column because ALTER TABLE accepts no other kind
     * there. Virtual and stored are both indexable, which is all this needs.
     */
    public function generatedColumnStyle(string $driver): string
    {
        return match ($driver) {
            'sqlite' => 'virtual',
            'sqlsrv' => 'computed',
            default => 'stored',
        };
    }

    /**
     * Whether the driver needs the index to exclude NULL explicitly.
     *
     * The invariant rests on NULL not colliding with NULL: every inactive
     * claim — unverified, superseded, historic — reads as NULL, and there are
     * many of them per domain. PostgreSQL, MySQL and sqlite all treat NULLs as
     * distinct in a unique index, so a plain one says exactly what is meant.
     *
     * SQL Server does not. It compares NULLs as equal for uniqueness, so a
     * plain unique index there permits one NULL row in the entire table and
     * the second superseded claim — or the second unverified one — is
     * rejected. Worse, on an existing database that already holds history the
     * index simply refuses to be created. A filtered index is Microsoft's
     * documented answer for uniqueness over a nullable column.
     */
    public function usesFilteredUniqueIndex(string $driver): bool
    {
        return $driver === 'sqlsrv';
    }

    /**
     * The filtered unique index, for drivers that need one spelled out.
     *
     * Built through the connection's own grammar rather than by hand. The
     * Schema calls around it apply the connection's table prefix, and raw DDL
     * naming a literal table would quietly point somewhere else on a prefixed
     * connection — creating the index on a table that may not exist, and
     * leaving the one that does without its invariant.
     *
     * The index name stays the same on every driver and prefix, so down()
     * drops it by that name without needing to know which branch built it.
     */
    public function filteredUniqueIndexSql(Grammar $grammar): string
    {
        return sprintf(
            'create unique index %s on %s (%s) where %s is not null',
            $grammar->wrap(static::INDEX),
            $grammar->wrapTable('domain_claims'),
            $grammar->wrap('active_domain'),
            $grammar->wrap('active_domain'),
        );
    }

    /**
     * Make "one active claim per domain" a rule the database keeps.
     *
     * Active is a combination of two nullable timestamps — verified_at set,
     * superseded_at not — and no portable unique index can be built over a
     * condition. A column that holds the domain exactly while the claim is
     * active, and NULL otherwise, can be.
     *
     * The column is generated rather than written by the application, so it
     * cannot drift from the timestamps it stands for and no writer can evade
     * the constraint by setting them and forgetting it.
     */
    public function up(): void
    {
        $this->supersedeDuplicateActiveClaims();

        $driver = Schema::getConnection()->getDriverName();
        $style = $this->generatedColumnStyle($driver);
        $expression = $this->expression();

        Schema::table('domain_claims', function (Blueprint $table) use ($style, $expression) {
            match ($style) {
                'computed' => $table->computed('active_domain', $expression)->persisted(),
                'virtual' => $table->string('active_domain')->nullable()->virtualAs($expression),
                default => $table->string('active_domain')->nullable()->storedAs($expression),
            };
        });

        if ($this->usesFilteredUniqueIndex($driver)) {
            DB::statement($this->filteredUniqueIndexSql(Schema::getConnection()->getSchemaGrammar()));

            return;
        }

        Schema::table('domain_claims', function (Blueprint $table) {
            $table->unique('active_domain', static::INDEX);
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
     *
     * Both spellings of the index carry the same name, and dropping an index
     * by name is the same statement whether or not it was filtered, so this
     * needs no branch of its own.
     */
    public function down(): void
    {
        Schema::table('domain_claims', function (Blueprint $table) {
            $table->dropUnique(static::INDEX);
        });

        Schema::table('domain_claims', function (Blueprint $table) {
            $table->dropColumn('active_domain');
        });
    }
};
