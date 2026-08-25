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
     * Let Sanctum's token table hold the UUID keys this package's users have.
     *
     * "jetstream:install" runs "install:api", which publishes Sanctum's own
     * migration. That migration types tokenable_id with morphs() — an
     * auto-incrementing integer — while every user this package creates has a
     * UUID key, so the first token an application issues is rejected outright
     * on PostgreSQL. sqlite accepts it: its typing is dynamic, so a column
     * with integer affinity keeps a value that is not a well-formed integer as
     * text and the UUID survives intact. It is permitting a contract stricter
     * engines reject rather than damaging anything, which is why this went
     * unnoticed for as long as it did.
     *
     * A string rather than a UUID, for the reason audit_logs.auditable_id is
     * one: tokenable is polymorphic and the table is Sanctum's, not this
     * package's. An application is free to issue tokens to a model of its own
     * with an integer key, and narrowing the column to UUID would break that
     * as surely as leaving it an integer breaks this package's users.
     *
     * The installer also corrects the migration it publishes, so a fresh
     * install creates the right column rather than altering one. This is for
     * applications that were installed before that, and is deliberately a
     * no-op when the table is absent — an application without the API feature
     * has nothing to widen, and one installing now has it built correctly.
     */
    public function up(): void
    {
        $this->retypeTokenableId(function (): void {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->string('tokenable_id')->change();
            });
        });
    }

    /**
     * Whether the driver refuses to retype a column that an index covers.
     *
     * Sanctum's morphs() indexes tokenable_type and tokenable_id together, and
     * SQL Server will not alter the type of an indexed column — the exception
     * it makes for widening a character column does not cover this. So there
     * the index comes off, the column changes, and the index goes back on.
     */
    public function needsIndexRebuildForTypeChange(string $driver): bool
    {
        return $driver === 'sqlsrv';
    }

    /**
     * Retype tokenable_id, taking the morph index out of the way if need be.
     *
     * The index is named by its columns, so Laravel regenerates the name it
     * gave it originally — including the table prefix where prefix_indexes is
     * on, which a hardcoded name would get wrong.
     *
     * @param  \Closure(): void  $change
     */
    protected function retypeTokenableId(Closure $change): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        $rebuild = $this->needsIndexRebuildForTypeChange(
            Schema::getConnection()->getDriverName()
        );

        if ($rebuild) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropIndex(['tokenable_type', 'tokenable_id']);
            });
        }

        $change();

        if ($rebuild) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->index(['tokenable_type', 'tokenable_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Narrowing back will fail on any token whose owner has a UUID key, which
     * is every user this package creates. That is the point: those rows are
     * the ones this migration exists to allow, and discarding them to make the
     * type fit would sign people out with no record of why.
     */
    public function down(): void
    {
        $this->retypeTokenableId(function (): void {
            $connection = Schema::getConnection();

            if ($this->needsExplicitNarrowingCast($connection->getDriverName())) {
                DB::statement($this->narrowingSql($connection->getSchemaGrammar()));

                return;
            }

            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->foreignId('tokenable_id')->change();
            });
        });
    }

    /**
     * Whether the driver refuses to narrow a string to an integer on its own.
     *
     * PostgreSQL has no assignment cast from a string type to a numeric one —
     * that conversion is explicit-only — so ALTER COLUMN needs the cast
     * written out, and says so on an empty table as readily as a full one.
     * Without it the rollback fails for the wrong reason entirely, before it
     * ever reaches the rows that genuinely cannot be narrowed.
     */
    public function needsExplicitNarrowingCast(string $driver): bool
    {
        return $driver === 'pgsql';
    }

    /**
     * The narrowing statement, for drivers that need the cast written out.
     *
     * Built through the connection's grammar so the table prefix and the
     * identifier quoting are the ones every other schema call here uses.
     */
    public function narrowingSql(Grammar $grammar): string
    {
        return sprintf(
            'alter table %s alter column %s type bigint using %s::bigint',
            $grammar->wrapTable('personal_access_tokens'),
            $grammar->wrap('tokenable_id'),
            $grammar->wrap('tokenable_id'),
        );
    }
};
