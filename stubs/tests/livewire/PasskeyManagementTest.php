<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Http\Livewire\PasskeyManager;
use Livewire\Livewire;
use Tests\TestCase;

class PasskeyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_passkeys_are_listed(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $user->passkeys()->create([
            'name' => 'MacBook Pro',
            'credential_id' => 'test-credential',
            'credential' => ['publicKey' => 'stub'],
        ]);

        $this->actingAs($user);

        Livewire::test(PasskeyManager::class)
            ->assertSee('MacBook Pro');
    }

    public function test_passkeys_can_be_deleted(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $passkey = $user->passkeys()->create([
            'name' => 'MacBook Pro',
            'credential_id' => 'test-credential',
            'credential' => ['publicKey' => 'stub'],
        ]);

        $this->actingAs($user)
            ->session(['auth.password_confirmed_at' => time()]);

        Livewire::test(PasskeyManager::class)
            ->call('deletePasskey', $passkey->id);

        $this->assertNull($passkey->fresh());
    }
}
