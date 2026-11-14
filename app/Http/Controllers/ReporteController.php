<?php

namespace App\Http\Controllers;

use App\Actions\Reportes\GenerarReporte;
use App\Models\ActivityLog;
use App\Models\Cuenta;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

        return response()->streamDownload(fn () => print ($pdf->output()), $nombre);
    }

    public function exportar(Request $request, GenerarReporte $generador): StreamedResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(GenerarReporte::TIPOS))],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'formato' => ['required', Rule::in(['pdf', 'csv'])],
        ]);

        $reporte = $generador->ejecutar(
            $datos['tipo'],
            Carbon::parse($datos['desde']),
            Carbon::parse($datos['hasta']),
            isset($datos['sucursal_id']) ? (int) $datos['sucursal_id'] : null,
        );

        if ($datos['formato'] === 'pdf' && count($reporte['filas']) > GenerarReporte::MAX_FILAS_PDF) {
            abort(422, 'El PDF admite hasta '.GenerarReporte::MAX_FILAS_PDF.' registros. Acota el periodo o descarga el Excel (CSV).');
        }

        ActivityLog::log('exportó el reporte "'.$reporte['titulo'].'" ('.mb_strtolower($reporte['periodo']).', '.$reporte['sucursal'].') en '.strtoupper($datos['formato']));

        $nombre = 'reporte-'.Str::slug($reporte['titulo']).'_'.$datos['desde'].'_a_'.$datos['hasta'].'.'.$datos['formato'];

        if ($datos['formato'] === 'csv') {
            return $this->descargarCsv($reporte, $nombre);
        }

        $pdf = Pdf::loadView('reportes.generico', ['reporte' => $reporte])
            ->setPaper('letter', count($reporte['columnas']) > 6 ? 'landscape' : 'portrait');

        return response()->streamDownload(fn () => print ($pdf->output()), $nombre);
    }

    /**
     * @param  array<string, mixed>  $reporte
     */
    private function descargarCsv(array $reporte, string $nombre): StreamedResponse
    {
        return response()->streamDownload(function () use ($reporte) {
            $salida = fopen('php://output', 'w');

            if ($salida === false) {
                return;
            }

            fwrite($salida, "\xEF\xBB\xBF");

            fputcsv($salida, [$reporte['titulo']]);
            fputcsv($salida, [$reporte['periodo'], $reporte['sucursal']]);
            fputcsv($salida, []);
            fputcsv($salida, array_column($reporte['columnas'], 'etiqueta'));

            foreach ($reporte['filas'] as $fila) {
                fputcsv($salida, array_map(
                    fn ($columna) => $this->valorCsv($fila[$columna['clave']] ?? null, $columna['tipo']),
                    $reporte['columnas'],
                ));
            }

            if ($reporte['totales'] !== []) {
                fputcsv($salida, array_map(
                    fn ($columna, $indice) => $indice === 0
                        ? 'Total'
                        : (array_key_exists($columna['clave'], $reporte['totales']) ? $this->valorCsv($reporte['totales'][$columna['clave']], $columna['tipo']) : ''),
                    $reporte['columnas'],
                    array_keys($reporte['columnas']),
                ));
            }

            fclose($salida);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function valorCsv(mixed $valor, string $tipo): string|float|int
    {
        if ($valor === null) {
            return '';
        }

        return match ($tipo) {
            'fecha' => Carbon::parse($valor)->format('d/m/Y'),
            'dinero', 'numero', 'porcentaje' => round((float) $valor, 2),
            default => $valor,
        };
    }
}
