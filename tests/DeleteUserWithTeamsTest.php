<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Actions\Jetstream\CreateTeam;
use App\Actions\Jetstream\DeleteUser;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\TeamPolicy;
use Laravel\Jetstream\Tests\Fixtures\User;

class DeleteUserWithTeamsTest extends OrchestraTestCase
{
    use RefreshDatabase;

    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        Gate::policy(Team::class, TeamPolicy::class);
        Jetstream::useUserModel(User::class);
    }

    public function test_user_can_be_deleted()
    {
        $team = $this->createTeam();
        $otherTeam = $this->createTeam();

        $otherTeam->users()->attach($team->owner, ['role' => null]);

        $this->assertSame(2, DB::table('teams')->count());
        $this->assertSame(1, DB::table('team_user')->count());

        $action = new DeleteUser;

        $action->delete($team->owner);

        // The user is soft deleted and disappears from queries and member
        // lists, while their data is retained until the purge command runs.
        $this->assertTrue($team->owner->fresh()->trashed());
        $this->assertNull(User::find($team->owner->id));
        $this->assertSame(2, DB::table('teams')->count());
        $this->assertCount(0, $otherTeam->fresh()->users);
    }

    protected function createTeam()
    {
        $action = new CreateTeam;

        $user = User::forceCreate([
            'name' => Str::random(10),
            'email' => Str::random(10).'@laravel.com',
            'password' => 'secret',
        ]);

        return $action->create($user, ['name' => 'Test Team']);
    }

    protected function afterRefreshingDatabase()
    {
        Schema::create('personal_access_tokens', function ($table) {
            $table->id();
            $table->foreignId('tokenable_id');
            $table->string('tokenable_type');
        });
    }
}
