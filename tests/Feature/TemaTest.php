<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('el selector de tema aparece en las vistas con sesion iniciada', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-test="theme-toggle"', false)
        ->assertSee('Claro')
        ->assertSee('Oscuro')
        ->assertSee('Automático');
});

test('el selector de tema aparece en la pantalla de inicio de sesion', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-test="theme-toggle"', false);
});
