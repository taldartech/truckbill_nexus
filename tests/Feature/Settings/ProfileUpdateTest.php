<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile name can be updated without changing email', function () {
    $user = User::factory()->create([
        'email' => 'keep@example.com',
    ]);

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'attacker@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('keep@example.com');
    expect($user->email_verified_at)->not->toBeNull();
});

test('self-service email change is ignored when submitted', function () {
    $user = User::factory()->create([
        'email' => 'original@example.com',
    ]);

    $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'changed@example.com',
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->email)->toBe('original@example.com');
});

test('profile destroy route is not registered', function () {
    expect(Route::has('profile.destroy'))->toBeFalse();
});

test('account hard-delete endpoint is absent', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/settings/profile', [
            'password' => 'password',
        ]);

    expect($response->status())->toBeIn([404, 405]);
    expect($user->fresh())->not->toBeNull();
});

test('registration remains disabled', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});
