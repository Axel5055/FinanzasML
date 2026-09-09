<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('un admin no ve la opcion de rol Super Admin al listar roles disponibles', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $component = Livewire::actingAs($admin)->test('pages::usuarios.index');

    expect($component->get('roles')->pluck('name'))->not->toContain('Super Admin');
});

test('un super admin si ve la opcion de rol Super Admin al listar roles disponibles', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    $component = Livewire::actingAs($superAdmin)->test('pages::usuarios.index');

    expect($component->get('roles')->pluck('name'))->toContain('Super Admin');
});

test('un admin no puede crear un usuario con rol Super Admin aunque lo envie directo', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    Livewire::actingAs($admin)
        ->test('pages::usuarios.index')
        ->set('form.name', 'Intento Malicioso')
        ->set('form.email', 'intento@mlgrupo.com.mx')
        ->set('form.rol', 'Super Admin')
        ->set('form.password', 'password123')
        ->set('form.password_confirmation', 'password123')
        ->call('crear')
        ->assertHasErrors(['form.rol']);

    expect(User::where('email', 'intento@mlgrupo.com.mx')->exists())->toBeFalse();
});

test('un super admin si puede crear un usuario con rol Super Admin', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    Livewire::actingAs($superAdmin)
        ->test('pages::usuarios.index')
        ->set('form.name', 'Nuevo Super Admin')
        ->set('form.email', 'nuevo.super@mlgrupo.com.mx')
        ->set('form.rol', 'Super Admin')
        ->set('form.password', 'password123')
        ->set('form.password_confirmation', 'password123')
        ->call('crear')
        ->assertHasNoErrors();

    $nuevo = User::where('email', 'nuevo.super@mlgrupo.com.mx')->first();
    expect($nuevo)->not->toBeNull()
        ->and($nuevo->hasRole('Super Admin'))->toBeTrue();
});
