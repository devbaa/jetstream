<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Jetstream\Http\Livewire\PasskeyManager;
use Livewire\Livewire;

test('registered passkeys are listed', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $user->passkeys()->create([
        'name' => 'MacBook Pro',
        'credential_id' => 'test-credential',
        'credential' => ['publicKey' => 'stub'],
    ]);

    $this->actingAs($user);

    Livewire::test(PasskeyManager::class)
        ->assertSee('MacBook Pro');
});

test('passkeys can be deleted', function () {
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

    expect($passkey->fresh())->toBeNull();
});
