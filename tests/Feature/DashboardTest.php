<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard does not show repository or documentation links', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertDontSee('Repositorio');
    $response->assertDontSee('Documentación');
    $response->assertDontSee('github.com/laravel/livewire-starter-kit', false);
    $response->assertDontSee('laravel.com/docs/starter-kits', false);
});