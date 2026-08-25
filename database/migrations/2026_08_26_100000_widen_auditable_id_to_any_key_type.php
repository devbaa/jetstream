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
     * Let the audit log hold the key of any model, as it is documented to.
     *
     * Auditable is offered for "any Eloquent model" — the README's own example
     * is an Invoice extending Model, which in a stock Laravel application has
     * an auto-incrementing bigint key — but auditable_id was declared with
     * nullableUuidMorphs and so accepted only UUIDs. On PostgreSQL auditing
     * such a model fails outright with "invalid input syntax for type uuid".
     * sqlite stores the integer without complaint, which is why this went
     * unnoticed.
     *
     * A polymorphic column that spans models cannot be typed as one model's
     * key. Held as a string it takes UUIDs, ULIDs and integers alike, which is
     * what the trait's contract already promised. The morph type column
     * distinguishes them, so a "1" from one model and a "1" from another were
     * never in danger of being confused with each other in the first place.
     */
    public function up(): void
    {
        $this->retypeAuditableId(function (): void {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->string('auditable_id')->nullable()->change();
            });
        });
    }

    /**
     * Whether the driver refuses to retype a column that an index covers.
     *
     * auditable_id is half of the morph index, and SQL Server will not alter
     * the type of an indexed column at all — the only exception it makes is
     * widening a character column, which uniqueidentifier to nvarchar is not.
     * So there the index comes off, the column changes, and the index goes
     * back on. The other drivers alter in place and rebuild the index
     * themselves, and their live paths are covered by the tests, so none of
     * them takes this route.
     */
    public function needsIndexRebuildForTypeChange(string $driver): bool
    {
        return $driver === 'sqlsrv';
    }

    /**
     * Retype auditable_id, taking the morph index out of the way if need be.
     *
     * The index is named by its columns rather than by a literal, so Laravel
     * regenerates whatever name it gave the index in the first place —
     * including the table prefix when prefix_indexes is on, which a hardcoded
     * name would get wrong.
     */
    protected function retypeAuditableId(Closure $change): void
    {
        $rebuild = $this->needsIndexRebuildForTypeChange(
            Schema::getConnection()->getDriverName()
        );

        if ($rebuild) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropIndex(['auditable_type', 'auditable_id']);
            });
        }

        $change();

        if ($rebuild) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index(['auditable_type', 'auditable_id']);
            });
        }
    }

    /**
     * Whether the driver refuses to narrow a string to a UUID on its own.
     *
     * PostgreSQL will not cast between them implicitly in ALTER COLUMN, and
     * says so whether or not the table holds a single row, so the cast has to
     * be spelled out. The others accept the change as written.
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
            'alter table %s alter column %s type uuid using %s::uuid',
            $grammar->wrapTable('audit_logs'),
            $grammar->wrap('auditable_id'),
            $grammar->wrap('auditable_id'),
        );
    }

    /**
     * Reverse the migrations.
     *
     * Narrowing back will fail on any row whose key is not a UUID, which is
     * the point: those rows are the ones this migration exists to allow, and
     * quietly dropping them to make the type fit would lose audit history.
     */
    public function down(): void
    {
        $this->retypeAuditableId(function (): void {
            $connection = Schema::getConnection();

            if ($this->needsExplicitNarrowingCast($connection->getDriverName())) {
                DB::statement($this->narrowingSql($connection->getSchemaGrammar()));

                return;
            }

            Schema::table('audit_logs', function (Blueprint $table) {
                $table->uuid('auditable_id')->nullable()->change();
            });
        });
    }
};
