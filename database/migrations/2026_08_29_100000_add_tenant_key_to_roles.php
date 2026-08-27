<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The columns the old unique index was built over.
     *
     * Named by columns rather than by a literal, so Laravel regenerates the
     * name it would have generated — including the connection's table prefix.
     *
     * @var list<string>
     */
    public const REPLACED = ['tenant_id', 'key'];

    /**
     * The columns the new unique index is built over.
     *
     * @var list<string>
     */
    public const UNIQUE = ['tenant_key', 'key'];

    /**
     * The scope a role belongs to, written so that "the application" is one.
     *
     * A role with no tenant is not a role with a missing field; it is one of
     * the application's default roles, the base set every tenant inherits and
     * may override. That is a scope like any other, and two of them under one
     * key are the same role twice.
     *
     * A unique index cannot say so, because NULL is distinct from NULL in one
     * on PostgreSQL, MySQL and sqlite. Collapsing NULL to a value the index can
     * compare is what makes the rule expressible. The empty string is safe as
     * that value: the column it stands in for holds UUIDs, so no real tenant
     * key can ever collide with it.
     *
     * What "the application" coalesces to is a string, so the tenant it stands
     * beside has to be one too — and what foreignUuid() compiles to differs by
     * driver. It is a native uuid on PostgreSQL and a uniqueidentifier on SQL
     * Server, neither of which is character data. MySQL gives it char(36),
     * which is; MariaDB gives it char(36) or a native uuid depending on the
     * server, because Laravel's MariaDbGrammar asks the version and picks uuid
     * from 10.7.
     *
     * sqlite is the only driver deliberately left uncast. Every other one
     * converts explicitly, so that the expression does not depend on how the
     * server at hand happens to represent a UUID.
     *
     * Where the column is not a string the cast is not optional. Coalesce has
     * one type, not one per branch, and both engines that can be reasoned about
     * here resolve it to the UUID rather than to the string — by different
     * routes, with the same result on the one value this column exists to
     * represent.
     *
     * On PostgreSQL '' is an untyped literal, so it is resolved to the type of
     * the other branch. The expression becomes uuid and the column cannot be
     * created at all; removing the cast here produces, verbatim, "invalid input
     * syntax for type uuid". SQL Server has two typed operands instead and
     * chooses between them by data type precedence, where uniqueidentifier
     * outranks varchar — so it converts the '' branch the same way and fails on
     * the same value.
     *
     * The spellings differ because the engines do. char(36) is the portable
     * CAST spelling across the MySQL and MariaDB versions this package
     * supports. SQL Server accepts varchar but reads an unqualified one as
     * varchar(30), which would silently truncate a 36-character UUID and leave
     * the key standing for a prefix of the tenant rather than the tenant, so
     * the length there is load-bearing. PostgreSQL's unqualified varchar is
     * unbounded and needs none.
     */
    public function expression(string $driver): string
    {
        return match ($driver) {
            'pgsql' => "coalesce(cast(tenant_id as varchar), '')",
            'sqlsrv' => "coalesce(cast(tenant_id as varchar(36)), '')",
            'mysql', 'mariadb' => "coalesce(cast(tenant_id as char(36)), '')",
            default => "coalesce(tenant_id, '')",
        };
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
     * Make "one role per key per scope" a database rule, the application
     * included.
     *
     * The seeder looks for a default role before writing one, which decides
     * nothing when two processes read before either writes — a rolling deploy
     * running `migrate --seed` on every node is enough. What that check was
     * standing in for is a constraint, so this states it as one.
     *
     * The column is generated rather than written by the application, so it
     * cannot drift from the tenant it stands for and no writer can evade the
     * constraint by setting the tenant and forgetting the key.
     *
     * Because the generated column is never NULL, a plain unique index says
     * what is meant on every supported driver — including SQL Server, which
     * treats NULLs as equal and would otherwise have needed a filtered index.
     */
    public function up(): void
    {
        $this->removeDuplicateDefaultRoles();

        $driver = Schema::getConnection()->getDriverName();
        $style = $this->generatedColumnStyle($driver);
        $expression = $this->expression($driver);

        Schema::table('roles', function (Blueprint $table) use ($style, $expression) {
            match ($style) {
                'computed' => $table->computed('tenant_key', $expression)->persisted(),
                'virtual' => $table->string('tenant_key')->nullable()->virtualAs($expression),
                default => $table->string('tenant_key')->nullable()->storedAs($expression),
            };
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->unique(static::UNIQUE);
        });

        // The old index is a strict subset of the new one: every pair it
        // separated, the new one separates too. Keeping it would mean a second
        // index that can never be the one to reject anything.
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(static::REPLACED);
        });
    }

    /**
     * Leave one application default per key before the index insists.
     *
     * An installation that has been seeding from more than one node may
     * already hold two rows for one default role. Only rows with no tenant can
     * be affected: the index being replaced already separated every other pair,
     * so no duplicate among them can exist to find.
     *
     * The extra rows are deleted, keeping the oldest. Deleting a role row is
     * what the application itself does to a role, and the survivor is a role of
     * the same key in the same scope, so the key goes on resolving. What the
     * survivor grants may differ from what a deleted twin granted — that is the
     * ambiguity this migration exists to end, and there is no basis in the data
     * for preferring one row's permissions to another's.
     *
     * Re-running the seeder afterwards restores every default to the catalog
     * the application actually declares, which is the authoritative answer that
     * the rows themselves cannot give.
     */
    protected function removeDuplicateDefaultRoles(): void
    {
        $groups = DB::table('roles')
            ->select('key')
            ->whereNull('tenant_id')
            ->groupBy('key')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $duplicates = DB::table('roles')
                ->where('key', $group->key)
                ->whereNull('tenant_id');

            // Ordered by the key as well as the timestamp: created_at is
            // nullable, and rows written in the same race commonly share it.
            $keep = (clone $duplicates)->orderBy('created_at')->orderBy('id')->value('id');

            $duplicates->where('id', '!=', $keep)->delete();
        }
    }

    /**
     * Reverse the migrations.
     *
     * The old index is restored before the new one is dropped, so the table is
     * never left with no uniqueness at all. It can always be created: the rows
     * it separates are a subset of those the new index separates, so anything
     * the new index permits, the old one permits too.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->unique(static::REPLACED);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique(static::UNIQUE);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('tenant_key');
        });
    }
};
