<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reduce stored recovery emails to their canonical form.
     *
     * Recovery addresses are now stored trimmed and lower-cased so the
     * recovery lookup can match them with an ordinary equality comparison on
     * every supported database. PostgreSQL compares strings case-sensitively,
     * so a row written as "Recovery.User@Example.COM" before this change
     * could never be matched by a user typing their address in lower case.
     *
     * LOWER() and TRIM() are ANSI SQL and behave identically across SQLite,
     * MySQL/MariaDB and PostgreSQL for the ASCII addresses this column holds.
     *
     * Only the address itself is rewritten: verification timestamps are left
     * untouched, because canonicalizing an address is not a change of
     * address, and no row is removed or emptied. The column carries a plain
     * index rather than a unique one, so collapsing two addresses that
     * differed only by case cannot violate a constraint — and where it does
     * collapse them, both accounts already shared a mailbox that was
     * verified for each of them.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'recovery_email')) {
            return;
        }

        DB::table('users')
            ->whereNotNull('recovery_email')
            ->update(['recovery_email' => DB::raw('LOWER(TRIM(recovery_email))')]);
    }

    /**
     * Reverse the migrations.
     *
     * The original casing is not recorded anywhere, so there is nothing to
     * restore. Leaving the addresses canonical is harmless: the recovery
     * lookup normalizes its input either way.
     */
    public function down(): void
    {
        //
    }
};
