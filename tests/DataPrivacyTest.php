<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Tests;

use App\Models\DataRequest;
use Laravel\Jetstream\DataRequest as DataRequestContract;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Http\Livewire\DataPrivacyForm;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Tests\Fixtures\User;
use Livewire\Livewire;

class DataPrivacyTest extends OrchestraTestCase
{
    /** {@inheritdoc} */
    #[\Override]
    protected function defineEnvironment($app)
    {
        $features = $app->config->get('jetstream.features', []);

        $features[] = Features::dataPrivacy();

        $app->config->set('jetstream.features', $features);

        $app->config->set('view.paths', array_merge(
            $app->config->get('view.paths', []),
            [__DIR__.'/../stubs/livewire/resources/views'],
        ));

        Jetstream::useUserModel(User::class);
    }

    protected function createUser(): User
    {
        return User::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);
    }

    public function test_a_deletion_request_can_be_filed_with_a_grace_period(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->session(['auth.password_confirmed_at' => time()]);

        Livewire::test(DataPrivacyForm::class)
            ->set('reason', 'Leaving the platform')
            ->call('requestAccountDeletion')
            ->assertHasNoErrors()
            ->assertDispatched('saved');

        $request = DataRequest::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(DataRequestContract::TYPE_DELETION, $request->type);
        $this->assertSame(DataRequestContract::STATUS_PENDING, $request->status);
        $this->assertSame('Leaving the platform', $request->reason);
        $this->assertNotNull($request->ip_address);
        $this->assertTrue($request->process_after->isFuture());
    }

    public function test_a_deletion_request_requires_a_recent_password_confirmation(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        Livewire::test(DataPrivacyForm::class)
            ->call('requestAccountDeletion')
            ->assertStatus(403);

        $this->assertSame(0, DataRequest::query()->count());
    }

    public function test_only_one_deletion_request_may_be_pending_at_a_time(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->session(['auth.password_confirmed_at' => time()]);

        Livewire::test(DataPrivacyForm::class)->call('requestAccountDeletion');

        Livewire::test(DataPrivacyForm::class)
            ->call('requestAccountDeletion')
            ->assertHasErrors('reason');

        $this->assertSame(1, DataRequest::query()->where('user_id', $user->id)->count());
    }

    public function test_a_pending_deletion_request_can_be_cancelled(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->session(['auth.password_confirmed_at' => time()]);

        Livewire::test(DataPrivacyForm::class)->call('requestAccountDeletion');

        Livewire::test(DataPrivacyForm::class)
            ->call('cancelAccountDeletion')
            ->assertDispatched('saved');

        $request = DataRequest::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(DataRequestContract::STATUS_CANCELLED, $request->status);
        $this->assertNotNull($request->cancelled_at);
    }

    public function test_personal_data_can_be_downloaded(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        Livewire::test(DataPrivacyForm::class)
            ->call('downloadPersonalData')
            ->assertFileDownloaded('personal-data.json');

        $export = DataRequest::query()
            ->where('user_id', $user->id)
            ->where('type', DataRequestContract::TYPE_EXPORT)
            ->firstOrFail();

        $this->assertSame(DataRequestContract::STATUS_COMPLETED, $export->status);
    }

    public function test_the_export_never_contains_the_password_hash(): void
    {
        $user = $this->createUser();

        $this->actingAs($user);

        $response = Livewire::test(DataPrivacyForm::class)->call('downloadPersonalData');

        $response->assertFileDownloaded('personal-data.json');

        $this->assertSame(1, DataRequest::query()->where('user_id', $user->id)->count());
    }
}
