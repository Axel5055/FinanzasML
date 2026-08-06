<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('admin/sucursales', 'pages::sucursales.index')
        ->middleware('can:admin.sucursales.index')
        ->name('admin.sucursales.index');

    Route::livewire('admin/productos', 'pages::productos.index')
        ->middleware('role_or_permission:Capturista|admin.productos.index')
        ->name('admin.productos.index');

    Route::livewire('admin/users', 'pages::usuarios.index')
        ->middleware('can:admin.users.index')
        ->name('admin.users.index');
});
