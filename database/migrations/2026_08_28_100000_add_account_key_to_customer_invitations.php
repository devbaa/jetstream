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
    public const REPLACED = ['tenant_id', 'customer_account_id', 'email'];

    /**
     * The columns the new unique index is built over.
     *
     * @var list<string>
     */
    public const UNIQUE = ['tenant_id', 'account_key', 'email'];

    /**
     * The destination of an invitation, written so that "none" is a value.
     *
     * An invitation carrying no customer account is not an invitation with a
     * missing field; it is an invitation to a destination that does not exist
     * yet — a new account, created for the invitee when they accept. That is a
     * destination like any other, and two invitations naming it are the same
     * invitation twice.
     *
     * A unique index cannot say so, because NULL is distinct from NULL in one
     * on PostgreSQL, MySQL and sqlite. Collapsing NULL to a value the index
     * can compare is what makes the rule expressible. The empty string is
     * safe as that value: the column it stands in for holds UUIDs, so no real
     * account key can ever collide with it.
     *
     * What "none" coalesces to is a string, so the account it stands beside
     * has to be one too — and what foreignUuid() compiles to differs by
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
     * one type, not one per branch, and both engines that can be reasoned
     * about here resolve it to the UUID rather than to the string — by
     * different routes, with the same result on the one value this column
     * exists to represent.
     *
     * On PostgreSQL '' is an untyped literal, so it is resolved to the type of
     * the other branch. The expression becomes uuid and the column cannot be
     * created at all; removing the cast produces, verbatim, "invalid input
     * syntax for type uuid". SQL Server has two typed operands instead and
     * chooses between them by data type precedence, where uniqueidentifier
     * outranks varchar — so it converts the '' branch the same way and fails
     * on the same value.
     *
     * MySQL is cast even though char(36) would coalesce with a string as it
     * stands, and MariaDB with no server here to say which type it would get.
     * The alternative is an expression whose correctness depends on which
     * MariaDB version the application happens to be running.
     *
     * The spellings differ because the engines do. char(36) is the portable
     * CAST spelling across the MySQL and MariaDB versions this package
     * supports. SQL Server accepts varchar but reads an unqualified one as
     * varchar(30), which would silently truncate a 36-character UUID and leave
     * the key standing for a prefix of the account rather than the account, so
     * the length there is load-bearing. PostgreSQL's unqualified varchar is
     * unbounded and needs none.
     *
     * What the index then treats as the same email is the column's collation,
     * not anything decided here. Laravel's default MySQL and MariaDB collation
     * is case-insensitive, so two invitations differing only in the case of the
     * address collide there; PostgreSQL and sqlite compare them as distinct.
     * This migration deliberately does not normalize the address — canonical
     * email storage is its own change, with its own semantics.
     */
    public function expression(string $driver): string
    {
        return match ($driver) {
            'pgsql' => "coalesce(cast(customer_account_id as varchar), '')",
            'sqlsrv' => "coalesce(cast(customer_account_id as varchar(36)), '')",
            'mysql', 'mariadb' => "coalesce(cast(customer_account_id as char(36)), '')",
            default => "coalesce(customer_account_id, '')",
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
     * Make "one pending invitation per person per destination" a database rule.
     *
     * The application checked for an existing invitation before inserting one,
     * which decides nothing when two requests read before either writes. What
     * that check was standing in for is a constraint, so this states it as one.
     *
     * The column is generated rather than written by the application, so it
     * cannot drift from the account it stands for and no writer can evade the
     * constraint by setting the account and forgetting the key.
     *
     * Because the generated column is never NULL, a plain unique index says
     * what is meant on every supported driver — including SQL Server, which
     * treats NULLs as equal and would otherwise have needed a filtered index.
     */
    public function up(): void
    {
        $this->removeDuplicatePendingInvitations();

        $driver = Schema::getConnection()->getDriverName();
        $style = $this->generatedColumnStyle($driver);
        $expression = $this->expression($driver);

        Schema::table('customer_invitations', function (Blueprint $table) use ($style, $expression) {
            match ($style) {
                'computed' => $table->computed('account_key', $expression)->persisted(),
                'virtual' => $table->string('account_key')->nullable()->virtualAs($expression),
                default => $table->string('account_key')->nullable()->storedAs($expression),
            };
        });

        Schema::table('customer_invitations', function (Blueprint $table) {
            $table->unique(static::UNIQUE);
        });

        // The old index is a strict subset of the new one: every pair it
        // separated, the new one separates too. Keeping it would mean a second
        // index that can never be the one to reject anything.
        Schema::table('customer_invitations', function (Blueprint $table) {
            $table->dropUnique(static::REPLACED);
        });
    }

    /**
     * Leave one pending invitation per destination before the index insists.
     *
     * An application that has been running the racing version may already hold
     * two rows for one invitation. Only rows with no customer account can be
     * affected: the index being replaced already separated every other pair,
     * so no duplicate among them can exist to find.
     *
     * The extra rows are deleted, keeping the oldest. That is not a repair
     * invented here — deleting the row is exactly what the application does to
     * an invitation when it is accepted or cancelled, and every row in a set
     * names the same tenant, the same person and the same destination, so one
     * of them stands for all of them.
     *
     * Rows are still being deleted, and with them the signed links carrying
     * their ids. An invitee holding one of those sees the invitation as no
     * longer available and needs a fresh invitation, which the application can
     * now issue because the survivor still stands.
     */
    protected function removeDuplicatePendingInvitations(): void
    {
        $groups = DB::table('customer_invitations')
            ->select('tenant_id', 'email')
            ->whereNull('customer_account_id')
            ->groupBy('tenant_id', 'email')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $duplicates = DB::table('customer_invitations')
                ->where('tenant_id', $group->tenant_id)
                ->where('email', $group->email)
                ->whereNull('customer_account_id');

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
        Schema::table('customer_invitations', function (Blueprint $table) {
            $table->unique(static::REPLACED);
        });

        Schema::table('customer_invitations', function (Blueprint $table) {
            $table->dropUnique(static::UNIQUE);
        });

        Schema::table('customer_invitations', function (Blueprint $table) {
            $table->dropColumn('account_key');
        });
    }
};
