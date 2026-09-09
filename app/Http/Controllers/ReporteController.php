<?php

namespace App\Http\Controllers;

use App\Models\Cuenta;
use App\Models\Sucursal;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReporteController extends Controller
{
    public function download(Cuenta $cuenta): StreamedResponse
    {
        $cuenta->load([
            'sucursal', 'status_cuenta', 'itemsCuenta.producto',
            'entradas.producto', 'entradas.sucursalOrigen',
            'salidas.producto', 'salidas.sucursalDestino',
            'gastos', 'mermas',
        ]);

        $pdf = Pdf::loadView('reportes.cuenta_individual', ['cuenta' => $cuenta]);

        $nombre = 'cuenta_'
            .Str::slug($cuenta->sucursal->name ?? 'sucursal')
            .'_'.Carbon::parse($cuenta->fecha_venta)->format('Y-m-d')
            .'.pdf';

        return response()->streamDownload(fn () => print ($pdf->stream()), $nombre);
    }

    public function downloadByDateRange(Request $request): RedirectResponse|StreamedResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'sucursal_id' => 'required|integer|exists:sucursales,id',
        ]);

        $startDate = $validated['start_date'];
        $endDate = $validated['end_date'];
        $sucursal = Sucursal::findOrFail((int) $validated['sucursal_id']);

        $cuentas = Cuenta::where('sucursal_id', $sucursal->id)
            ->whereBetween('fecha_venta', [$startDate, $endDate])
            ->with('status_cuenta')
            ->orderBy('fecha_venta')
            ->get();

        if ($cuentas->isEmpty()) {
            return redirect()->back()->with('error', 'No se encontraron cuentas para la sucursal y el rango de fechas especificados.');
        }

        $pdf = Pdf::loadView('reportes.cuenta_general', [
            'cuentas' => $cuentas,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'sucursal' => $sucursal,
        ]);

        $nombre = 'reporte-cuentas_'
            .Str::slug($sucursal->name)
            .'_'.$startDate.'_a_'.$endDate
            .'.pdf';

        return response()->streamDownload(fn () => print ($pdf->stream()), $nombre);
    }
}
