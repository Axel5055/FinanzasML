<?php

use App\Http\Controllers\ReporteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('admin/cuentas', 'pages::cuentas.index')
        ->middleware('can:admin.cuentas.index')
        ->name('admin.cuentas.index');

    Route::livewire('admin/cuentas/{cuenta}', 'pages::cuentas.show')
        ->middleware('can:admin.cuentas.show')
        ->name('admin.cuentas.show');

    Route::livewire('admin/registrar/{cuenta?}', 'pages::cuentas.registrar')
        ->middleware('can:admin.registrar.index')
        ->name('admin.registrar.index');

    Route::livewire('capturista/registrar', 'pages::cuentas.registrar')
        ->middleware('role:Capturista')
        ->name('capturista.registrar.index');

    Route::get('cuentas/{cuenta}/pdf', [ReporteController::class, 'download'])
        ->middleware('can:admin.cuentas.show')
        ->name('admin.cuentas.pdf');

    Route::livewire('admin/reportes', 'pages::reportes.index')
        ->middleware('role:Admin|Super Admin')
        ->name('admin.reportes.index');

    Route::get('admin/reportes/descargar', [ReporteController::class, 'exportar'])
        ->middleware('role:Admin|Super Admin')
        ->name('admin.reportes.descargar');
});
