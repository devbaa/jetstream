<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Http\Livewire\CreateTenantForm;
use Livewire\Livewire;
use Tests\TestCase;

class CreateTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenants_can_be_created(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        Livewire::test(CreateTenantForm::class)
            ->set(['state' => ['name' => 'Test Organization']])
            ->call('createTenant');

        $this->assertCount(1, $user->fresh()->ownedTenants);
        $this->assertEquals('Test Organization', $user->fresh()->ownedTenants()->latest('id')->first()->name);
        $this->assertEquals('test-organization', $user->fresh()->ownedTenants()->latest('id')->first()->slug);
    }
}
