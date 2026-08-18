<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     * address, and no row is removed or emptied.
     *
     * The column carries a plain index rather than a unique one, so
     * collapsing two addresses that differed only by case cannot fail — but
     * succeeding is not the same as being unambiguous. Two accounts that
     * held distinct spellings of one address now hold the same value, and
     * neither can be recovered through it until an operator separates them,
     * so any group this creates is reported. Nothing is merged, renamed or
     * deleted to resolve it: which account keeps the address is not a
     * decision a migration should make.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'recovery_email')) {
            return;
        }

        DB::table('users')
            ->whereNotNull('recovery_email')
            ->update(['recovery_email' => DB::raw('LOWER(TRIM(recovery_email))')]);

        $this->reportAmbiguousAddresses();
    }

    /**
     * Report recovery addresses that are now verified on more than one account.
     *
     * The addresses themselves are left out of the log; the user ids are
     * enough to resolve the conflict without writing recovery addresses into
     * the application's log files. They are reported grouped, because a flat
     * list of ids would not tell an operator which accounts conflict with
     * which — the one thing they need in order to act on it.
     */
    protected function reportAmbiguousAddresses(): void
    {
        $duplicated = DB::table('users')
            ->select('recovery_email')
            ->whereNotNull('recovery_email')
            ->whereNotNull('recovery_email_verified_at')
            ->groupBy('recovery_email')
            ->havingRaw('count(*) > 1')
            ->pluck('recovery_email');

        if ($duplicated->isEmpty()) {
            return;
        }

        $groups = DB::table('users')
            ->select('id', 'recovery_email')
            ->whereIn('recovery_email', $duplicated->all())
            ->whereNotNull('recovery_email_verified_at')
            ->get()
            ->groupBy('recovery_email')
            ->map(static fn ($rows) => $rows->pluck('id')->values()->all())
            ->values()
            ->all();

        Log::warning(sprintf(
            'Normalizing recovery email addresses left %d address(es) verified on more than one account. Those accounts cannot be recovered by recovery email until the duplicates are resolved.',
            count($groups)
        ), ['conflicting_user_id_groups' => $groups]);
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
