<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\User;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\SanctumServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A token can be issued to the UUID users this package installs.
 *
 * "php artisan jetstream:install livewire --api" runs install:api, which
 * publishes Sanctum's own migration. That migration types tokenable_id with
 * morphs(), an auto-incrementing integer, while every user this package
 * creates has a UUID key — so the very first token an application issues is
 * rejected on any database that checks.
 *
 * Sanctum's migration is required here rather than copied, so this tracks
 * what Sanctum actually ships instead of an assumption about it.
 */
class SanctumTokenKeyTypeTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [SanctumServiceProvider::class]);
    }

    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        Jetstream::useUserModel(User::class);
    }

    /**
     * The path Sanctum's create migration is published from.
     */
    protected function sanctumMigration(): string
    {
        $paths = (array) glob(
            __DIR__.'/../vendor/laravel/sanctum/database/migrations/*_create_personal_access_tokens_table.php'
        );

        $path = $paths[0] ?? null;

        if (! is_string($path)) {
            $this->markTestSkipped('Sanctum does not ship a personal access tokens migration.');
        }

        return $path;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::hasTable('migrations');

        Schema::dropIfExists('personal_access_tokens');

        // Exactly what install:api leaves in the application...
        (require $this->sanctumMigration())->up();

        // ...and the correction this package ships for one already installed.
        (require __DIR__.'/../database/migrations/2026_08_27_100000_widen_sanctum_tokenable_id.php')->up();
    }

    protected function tearDown(): void
    {
        try {
            Schema::dropIfExists('personal_access_tokens');
        } catch (QueryException) {
            // A test that asserts a statement is rejected leaves PostgreSQL's
            // transaction aborted, and it refuses everything after that. The
            // refresh rolls it back either way, so there is nothing to clean.
        }

        parent::tearDown();
    }

    /**
     * The widening migration this package ships.
     */
    protected function widening(): \Illuminate\Database\Migrations\Migration
    {
        return require __DIR__.'/../database/migrations/2026_08_27_100000_widen_sanctum_tokenable_id.php';
    }

    protected function createUser(string $email = 'taylor@laravel.com'): User
    {
        return User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => $email,
            'password' => 'secret',
        ]);
    }

    public function test_a_uuid_user_can_be_issued_a_token(): void
    {
        $user = $this->createUser();

        $token = $user->createToken('probe');

        $this->assertSame((string) $user->getKey(), (string) $token->accessToken->tokenable_id);
    }

    public function test_the_token_resolves_back_to_its_owner(): void
    {
        // Issuing it is half of the contract; the lookup Sanctum performs on
        // every authenticated request is the other half.
        $user = $this->createUser();

        $plain = $user->createToken('probe')->plainTextToken;

        $found = PersonalAccessToken::findToken($plain);

        $this->assertNotNull($found);
        $this->assertSame((string) $user->getKey(), (string) $found?->tokenable_id);
        $this->assertTrue($found?->tokenable?->is($user) ?? false);
    }

    public function test_the_users_own_token_relation_finds_it(): void
    {
        $user = $this->createUser();

        $user->createToken('one');
        $user->createToken('two');

        $this->assertSame(2, $user->tokens()->count());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function indexRebuildProvider(): array
    {
        return [
            // These alter the column in place; their live paths are run above.
            'pgsql' => ['pgsql', false],
            'sqlite' => ['sqlite', false],
            'mysql' => ['mysql', false],
            'mariadb' => ['mariadb', false],

            // morphs() indexes the pair, and SQL Server will not alter the
            // type of a column an index covers.
            'sqlsrv' => ['sqlsrv', true],
        ];
    }

    #[DataProvider('indexRebuildProvider')]
    public function test_only_the_driver_that_locks_indexed_columns_rebuilds(string $driver, bool $rebuild): void
    {
        $migration = require __DIR__.'/../database/migrations/2026_08_27_100000_widen_sanctum_tokenable_id.php';

        $this->assertSame($rebuild, $migration->needsIndexRebuildForTypeChange($driver));
    }

    public function test_the_widening_is_a_no_op_without_the_table(): void
    {
        // An application without the API feature has no token table. The
        // correction has to pass over it rather than fail the whole migrate.
        Schema::dropIfExists('personal_access_tokens');

        $migration = require __DIR__.'/../database/migrations/2026_08_27_100000_widen_sanctum_tokenable_id.php';

        $migration->up();

        $this->assertFalse(Schema::hasTable('personal_access_tokens'));
    }

    public function test_the_widening_rolls_back_and_forward_on_an_empty_table(): void
    {
        // down() was not exercised at all until now. On PostgreSQL there is no
        // assignment cast from a string type to a numeric one, so narrowing
        // needs the cast spelled out — and fails without it whether or not the
        // table holds anything, which would hide the real reason a rollback
        // should be refused.
        $migration = $this->widening();

        $migration->down();

        $this->assertTrue(Schema::hasTable('personal_access_tokens'));

        $migration->up();

        // ...and after the round trip the column still takes a UUID key.
        $user = $this->createUser();

        $this->assertSame(
            (string) $user->getKey(),
            (string) $user->createToken('probe')->accessToken->tokenable_id
        );
    }

    public function test_rolling_back_numeric_history_keeps_it(): void
    {
        // A token issued to a model of the application's own with an integer
        // key narrows back cleanly, because it genuinely fits.
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\\Models\\Widget',
            'tokenable_id' => '7',
            'name' => 'probe',
            'token' => str_repeat('a', 64),
        ]);

        $this->widening()->down();

        $this->assertSame('7', (string) DB::table('personal_access_tokens')->value('tokenable_id'));
    }

    public function test_rolling_back_a_uuid_owned_token_is_refused_where_types_are_enforced(): void
    {
        // A token issued to a UUID user does not fit in the old column, and
        // this is the condition down() is meant to refuse on — reachable only
        // now that the narrowing gets past the cast.
        //
        // The two engines part company here, and the difference is the whole
        // reason this finding survived: sqlite's typing is dynamic, so an
        // integer-affinity column keeps a value that is not a well-formed
        // integer as text. The UUID is not truncated or coerced — it round
        // trips intact — so sqlite has nothing to object to. It is permitting
        // a schema contract that stricter engines reject, not corrupting
        // anything.
        $user = $this->createUser();

        $user->createToken('probe');

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->widening()->down();

            $this->assertSame(
                (string) $user->getKey(),
                (string) DB::table('personal_access_tokens')->value('tokenable_id')
            );

            return;
        }

        $this->expectException(QueryException::class);

        $this->widening()->down();
    }

    public function test_tokens_of_different_users_do_not_bleed(): void
    {
        $taylor = $this->createUser();
        $adam = $this->createUser('adam@laravel.com');

        $taylor->createToken('one');
        $adam->createToken('two');

        $this->assertSame(1, $taylor->tokens()->count());
        $this->assertSame(1, $adam->tokens()->count());
    }
}
