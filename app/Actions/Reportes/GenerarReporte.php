<?php

namespace App\Actions\Reportes;

use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\ItemCuenta;
use App\Models\Merma;
use App\Models\Salida;
use App\Models\Sucursal;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Construye los reportes del sistema en una estructura tabular común
 * (resumen, columnas, filas y totales) que se muestra en pantalla,
 * se exporta a PDF y se descarga como CSV.
 *
 * @phpstan-type Columna array{clave: string, etiqueta: string, tipo: string, alinear?: string}
 * @phpstan-type Indicador array{etiqueta: string, valor: float|int|string, tipo: string, tono: string, icono: string}
 * @phpstan-type Datos array{resumen: list<Indicador>, columnas: list<Columna>, filas: array<int, array<string, mixed>>, totales: array<string, float|int|string>}
 * @phpstan-type Reporte array{tipo: string, titulo: string, descripcion: string, periodo: string, sucursal: string, resumen: list<Indicador>, columnas: list<Columna>, filas: array<int, array<string, mixed>>, totales: array<string, float|int|string>}
 */
class GenerarReporte
{
    /**
     * @var array<string, array{titulo: string, descripcion: string, icono: string}>
     */
    public const TIPOS = [
        'ventas' => [
            'titulo' => 'Ventas por cuenta',
            'descripcion' => 'Cada cuenta del periodo con su venta, efectivo, tarjeta y diferencia.',
            'icono' => 'banknotes',
        ],
        'sucursales' => [
            'titulo' => 'Comparativo de sucursales',
            'descripcion' => 'Ventas, ticket promedio, faltantes, gastos y mermas de cada sucursal.',
            'icono' => 'building-storefront',
        ],
        'diferencias' => [
            'titulo' => 'Faltantes y sobrantes',
            'descripcion' => 'Las cuentas donde lo capturado no coincide con la venta.',
            'icono' => 'scale',
        ],
        'gastos' => [
            'titulo' => 'Gastos y mermas',
            'descripcion' => 'Detalle de gastos y mermas registrados, con su concepto e importe.',
            'icono' => 'receipt-percent',
        ],
        'productos' => [
            'titulo' => 'Entradas y sobrantes por producto',
            'descripcion' => 'Cantidades e importes de entrada y sobrante de cada producto.',
            'icono' => 'cube',
        ],
        'transferencias' => [
            'titulo' => 'Transferencias entre sucursales',
            'descripcion' => 'Productos enviados de una sucursal a otra, con cantidad e importe.',
            'icono' => 'truck',
        ],
    ];

    /**
     * @return Reporte
     */
    public function ejecutar(string $tipo, CarbonInterface $desde, CarbonInterface $hasta, ?int $sucursalId = null): array
    {
        abort_unless(isset(self::TIPOS[$tipo]), 404);

        $inicio = $desde->toDateString();
        $fin = $hasta->toDateString();

        $datos = match ($tipo) {
            'ventas' => $this->ventas($inicio, $fin, $sucursalId),
            'sucursales' => $this->sucursales($inicio, $fin, $sucursalId),
            'diferencias' => $this->diferencias($inicio, $fin, $sucursalId),
            'gastos' => $this->gastos($inicio, $fin, $sucursalId),
            'productos' => $this->productos($inicio, $fin, $sucursalId),
            'transferencias' => $this->transferencias($inicio, $fin, $sucursalId),
        };

        return [
            'tipo' => $tipo,
            'titulo' => self::TIPOS[$tipo]['titulo'],
            'descripcion' => self::TIPOS[$tipo]['descripcion'],
            'periodo' => 'Del '.$desde->format('d/m/Y').' al '.$hasta->format('d/m/Y'),
            'sucursal' => $sucursalId ? (Sucursal::find($sucursalId)->name ?? 'Sucursal') : 'Todas las sucursales',
            ...$datos,
        ];
    }

    /**
     * Formatea un valor según el tipo de columna para pantalla y PDF.
     */
    public static function formatear(mixed $valor, string $tipo): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        return match ($tipo) {
            'dinero' => '$'.number_format((float) $valor, 2),
            'numero' => fmod((float) $valor, 1.0) === 0.0 ? number_format((float) $valor, 0) : number_format((float) $valor, 2),
            'porcentaje' => number_format((float) $valor, 1).'%',
            'fecha' => Carbon::parse($valor)->format('d/m/Y'),
            default => (string) $valor,
        };
    }

    /**
     * Color de la etiqueta según su texto.
     */
    public static function tonoDeEtiqueta(string $valor): string
    {
        return match ($valor) {
            'Pagado', 'Sobrante' => 'positive',
            'Pendiente', 'Faltante', 'Merma' => 'negative',
            'Pago parcial', 'Gasto' => 'warning',
            default => 'neutral',
        };
    }

    /**
     * @return Datos
     */
    private function ventas(string $inicio, string $fin, ?int $sucursalId): array
    {
        $cuentas = Cuenta::query()
            ->with(['sucursal:id,name', 'status_cuenta:id,name'])
            ->whereBetween('fecha_venta', [$inicio, $fin])
            ->where('total_venta', '!=', 0)
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->orderBy('fecha_venta')
            ->orderBy('sucursal_id')
            ->get();

        $faltantes = (float) $cuentas->where('diferencia', '>', 0)->sum('diferencia');
        $sobrantes = abs((float) $cuentas->where('diferencia', '<', 0)->sum('diferencia'));

        return [
            'resumen' => [
                $this->indicador('Cuentas', $cuentas->count(), 'numero', 'neutral', 'clipboard-document-list'),
                $this->indicador('Total vendido', (float) $cuentas->sum('total_venta'), 'dinero', 'neutral', 'banknotes'),
                $this->indicador('Faltantes', $faltantes, 'dinero', $faltantes > 0 ? 'negative' : 'neutral', 'exclamation-triangle'),
                $this->indicador('Sobrantes', $sobrantes, 'dinero', 'positive', 'square-3-stack-3d'),
            ],
            'columnas' => [
                $this->columna('fecha', 'Fecha', 'fecha'),
                $this->columna('sucursal', 'Sucursal', 'texto'),
                $this->columna('efectivo', 'Efectivo', 'dinero'),
                $this->columna('tarjeta', 'Tarjeta', 'dinero'),
                $this->columna('total', 'Total venta', 'dinero'),
                $this->columna('diferencia', 'Diferencia', 'dinero'),
                $this->columna('status', 'Status', 'etiqueta'),
            ],
            'filas' => $cuentas->map(fn (Cuenta $cuenta) => [
                'fecha' => Carbon::parse($cuenta->fecha_venta)->toDateString(),
                'sucursal' => $cuenta->sucursal->name ?? '—',
                'efectivo' => (float) $cuenta->efectivo_entregado,
                'tarjeta' => (float) $cuenta->tarjeta,
                'total' => (float) $cuenta->total_venta,
                'diferencia' => (float) $cuenta->diferencia,
                'status' => $this->nombreStatus($cuenta->status_cuenta->name ?? null),
            ])->all(),
            'totales' => [
                'efectivo' => (float) $cuentas->sum('efectivo_entregado'),
                'tarjeta' => (float) $cuentas->sum('tarjeta'),
                'total' => (float) $cuentas->sum('total_venta'),
                'diferencia' => (float) $cuentas->sum('diferencia'),
            ],
        ];
    }

    /**
     * @return Datos
     */
    private function sucursales(string $inicio, string $fin, ?int $sucursalId): array
    {
        $ventas = Cuenta::query()
            ->whereBetween('fecha_venta', [$inicio, $fin])
            ->where('total_venta', '!=', 0)
            ->groupBy('sucursal_id')
            ->selectRaw('sucursal_id, COUNT(*) as cuentas, SUM(total_venta) as ventas, SUM(CASE WHEN diferencia > 0 THEN diferencia ELSE 0 END) as faltantes, SUM(CASE WHEN diferencia < 0 THEN -diferencia ELSE 0 END) as sobrantes')
            ->get()
            ->keyBy('sucursal_id');

        $gastos = $this->totalPorSucursal(Gasto::query(), 'gastos', $inicio, $fin);
        $mermas = $this->totalPorSucursal(Merma::query(), 'mermas', $inicio, $fin);

        $sucursales = Sucursal::query()
            ->when($sucursalId, fn ($q) => $q->where('id', $sucursalId))
            ->orderBy('name')
            ->get()
            ->filter(fn (Sucursal $sucursal) => $sucursal->activo || $ventas->has($sucursal->id));

        $totalVentas = (float) $sucursales->sum(fn (Sucursal $sucursal) => (float) ($ventas[$sucursal->id]->ventas ?? 0));

        $filas = $sucursales->map(function (Sucursal $sucursal) use ($ventas, $gastos, $mermas, $totalVentas) {
            $fila = $ventas[$sucursal->id] ?? null;
            $monto = (float) ($fila->ventas ?? 0);
            $cuentas = (int) ($fila->cuentas ?? 0);

            return [
                'sucursal' => $sucursal->name,
                'cuentas' => $cuentas,
                'ventas' => $monto,
                'ticket' => $cuentas > 0 ? $monto / $cuentas : 0.0,
                'faltantes' => (float) ($fila->faltantes ?? 0),
                'sobrantes' => (float) ($fila->sobrantes ?? 0),
                'gastos' => (float) ($gastos[$sucursal->id] ?? 0),
                'mermas' => (float) ($mermas[$sucursal->id] ?? 0),
                'participacion' => $totalVentas > 0 ? ($monto / $totalVentas) * 100 : 0.0,
            ];
        })->sortByDesc('ventas')->values();

        $totalCuentas = (int) $filas->sum('cuentas');
        $mejor = $filas->first();

        return [
            'resumen' => [
                $this->indicador('Ventas totales', $totalVentas, 'dinero', 'neutral', 'banknotes'),
                $this->indicador('Cuentas', $totalCuentas, 'numero', 'neutral', 'clipboard-document-list'),
                $this->indicador('Ticket promedio', $totalCuentas > 0 ? $totalVentas / $totalCuentas : 0.0, 'dinero', 'neutral', 'calculator'),
                $this->indicador('Sucursal líder', $mejor && $mejor['ventas'] > 0 ? $mejor['sucursal'] : '—', 'texto', 'positive', 'trophy'),
            ],
            'columnas' => [
                $this->columna('sucursal', 'Sucursal', 'texto'),
                $this->columna('cuentas', 'Cuentas', 'numero'),
                $this->columna('ventas', 'Ventas', 'dinero'),
                $this->columna('ticket', 'Ticket promedio', 'dinero'),
                $this->columna('faltantes', 'Faltantes', 'dinero'),
                $this->columna('sobrantes', 'Sobrantes', 'dinero'),
                $this->columna('gastos', 'Gastos', 'dinero'),
                $this->columna('mermas', 'Mermas', 'dinero'),
                $this->columna('participacion', '% del total', 'porcentaje'),
            ],
            'filas' => $filas->all(),
            'totales' => [
                'cuentas' => $totalCuentas,
                'ventas' => $totalVentas,
                'ticket' => $totalCuentas > 0 ? $totalVentas / $totalCuentas : 0.0,
                'faltantes' => (float) $filas->sum('faltantes'),
                'sobrantes' => (float) $filas->sum('sobrantes'),
                'gastos' => (float) $filas->sum('gastos'),
                'mermas' => (float) $filas->sum('mermas'),
                'participacion' => $totalVentas > 0 ? 100.0 : 0.0,
            ],
        ];
    }

    /**
     * @return Datos
     */
    private function diferencias(string $inicio, string $fin, ?int $sucursalId): array
    {
        $cuentas = Cuenta::query()
            ->with(['sucursal:id,name', 'status_cuenta:id,name'])
            ->whereBetween('fecha_venta', [$inicio, $fin])
            ->where('total_venta', '!=', 0)
            ->where(fn ($q) => $q->where('diferencia', '>=', 0.01)->orWhere('diferencia', '<=', -0.01))
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->orderBy('fecha_venta')
            ->orderBy('sucursal_id')
            ->get();

        $faltantes = $cuentas->where('diferencia', '>', 0);
        $sobrantes = $cuentas->where('diferencia', '<', 0);
        $montoFaltantes = (float) $faltantes->sum('diferencia');
        $montoSobrantes = abs((float) $sobrantes->sum('diferencia'));

        return [
            'resumen' => [
                $this->indicador('Cuentas con faltante', $faltantes->count(), 'numero', $faltantes->isNotEmpty() ? 'negative' : 'neutral', 'exclamation-triangle'),
                $this->indicador('Monto faltante', $montoFaltantes, 'dinero', $montoFaltantes > 0 ? 'negative' : 'neutral', 'arrow-trending-down'),
                $this->indicador('Cuentas con sobrante', $sobrantes->count(), 'numero', 'neutral', 'square-3-stack-3d'),
                $this->indicador('Monto sobrante', $montoSobrantes, 'dinero', 'positive', 'arrow-trending-up'),
            ],
            'columnas' => [
                $this->columna('fecha', 'Fecha', 'fecha'),
                $this->columna('sucursal', 'Sucursal', 'texto'),
                $this->columna('total', 'Total venta', 'dinero'),
                $this->columna('capturado', 'Capturado', 'dinero'),
                $this->columna('diferencia', 'Diferencia', 'dinero'),
                $this->columna('tipo', 'Tipo', 'etiqueta'),
                $this->columna('status', 'Status', 'etiqueta'),
            ],
            'filas' => $cuentas->map(fn (Cuenta $cuenta) => [
                'fecha' => Carbon::parse($cuenta->fecha_venta)->toDateString(),
                'sucursal' => $cuenta->sucursal->name ?? '—',
                'total' => (float) $cuenta->total_venta,
                'capturado' => (float) $cuenta->efectivo_entregado + (float) $cuenta->tarjeta,
                'diferencia' => abs((float) $cuenta->diferencia),
                'tipo' => $cuenta->diferencia > 0 ? 'Faltante' : 'Sobrante',
                'status' => $this->nombreStatus($cuenta->status_cuenta->name ?? null),
            ])->all(),
            'totales' => [
                'total' => (float) $cuentas->sum('total_venta'),
                'capturado' => (float) $cuentas->sum(fn (Cuenta $cuenta) => (float) $cuenta->efectivo_entregado + (float) $cuenta->tarjeta),
            ],
        ];
    }

    /**
     * @return Datos
     */
    private function gastos(string $inicio, string $fin, ?int $sucursalId): array
    {
        $cargar = fn ($consulta, string $tipo) => $consulta
            ->with(['sucursal:id,name', 'cuenta:id,fecha_venta'])
            ->whereHas('cuenta', fn ($q) => $q->whereBetween('fecha_venta', [$inicio, $fin]))
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->get()
            ->map(fn ($registro) => [
                'fecha' => Carbon::parse($registro->cuenta->fecha_venta)->toDateString(),
                'sucursal' => $registro->sucursal->name ?? '—',
                'tipo' => $tipo,
                'concepto' => $registro->concepto,
                'importe' => (float) $registro->precio,
            ]);

        $registros = $cargar(Gasto::query(), 'Gasto')
            ->concat($cargar(Merma::query(), 'Merma'))
            ->sortBy([['fecha', 'asc'], ['sucursal', 'asc'], ['tipo', 'asc']])
            ->values();

        $totalGastos = (float) $registros->where('tipo', 'Gasto')->sum('importe');
        $totalMermas = (float) $registros->where('tipo', 'Merma')->sum('importe');

        return [
            'resumen' => [
                $this->indicador('Gastos', $totalGastos, 'dinero', 'neutral', 'credit-card'),
                $this->indicador('Mermas', $totalMermas, 'dinero', $totalMermas > 0 ? 'negative' : 'neutral', 'trash'),
                $this->indicador('Total', $totalGastos + $totalMermas, 'dinero', 'neutral', 'calculator'),
                $this->indicador('Registros', $registros->count(), 'numero', 'neutral', 'queue-list'),
            ],
            'columnas' => [
                $this->columna('fecha', 'Fecha', 'fecha'),
                $this->columna('sucursal', 'Sucursal', 'texto'),
                $this->columna('tipo', 'Tipo', 'etiqueta'),
                $this->columna('concepto', 'Concepto', 'texto'),
                $this->columna('importe', 'Importe', 'dinero'),
            ],
            'filas' => $registros->all(),
            'totales' => ['importe' => $totalGastos + $totalMermas],
        ];
    }

    /**
     * @return Datos
     */
    private function productos(string $inicio, string $fin, ?int $sucursalId): array
    {
        $productos = ItemCuenta::query()
            ->join('cuentas', 'cuentas.id', '=', 'item_cuentas.cuenta_id')
            ->join('productos', 'productos.id', '=', 'item_cuentas.producto_id')
            ->leftJoin('categorias', 'categorias.id', '=', 'productos.categoria_id')
            ->whereBetween('cuentas.fecha_venta', [$inicio, $fin])
            ->when($sucursalId, fn ($q) => $q->where('cuentas.sucursal_id', $sucursalId))
            ->groupBy('productos.id', 'productos.name', 'categorias.name')
            ->selectRaw('productos.name as producto, categorias.name as categoria, SUM(item_cuentas.cantidad_entrada) as cantidad_entrada, SUM(item_cuentas.importe_entrada) as importe_entrada, SUM(item_cuentas.cantidad_sobrante) as cantidad_sobrante, SUM(item_cuentas.importe_sobrante) as importe_sobrante')
            ->orderByDesc('importe_entrada')
            ->get();

        $totalEntrada = (float) $productos->sum('importe_entrada');
        $totalSobrante = (float) $productos->sum('importe_sobrante');
        $principal = $productos->first();

        return [
            'resumen' => [
                $this->indicador('Productos', $productos->count(), 'numero', 'neutral', 'cube'),
                $this->indicador('Importe de entradas', $totalEntrada, 'dinero', 'neutral', 'arrow-down-tray'),
                $this->indicador('Importe de sobrantes', $totalSobrante, 'dinero', 'neutral', 'square-3-stack-3d'),
                $this->indicador('Producto principal', $principal && (float) $principal->importe_entrada > 0 ? $principal->producto : '—', 'texto', 'positive', 'trophy'),
            ],
            'columnas' => [
                $this->columna('producto', 'Producto', 'texto'),
                $this->columna('categoria', 'Categoría', 'texto'),
                $this->columna('cantidad_entrada', 'Cant. entrada', 'numero'),
                $this->columna('importe_entrada', 'Importe entrada', 'dinero'),
                $this->columna('cantidad_sobrante', 'Cant. sobrante', 'numero'),
                $this->columna('importe_sobrante', 'Importe sobrante', 'dinero'),
            ],
            'filas' => $productos->map(fn ($fila) => [
                'producto' => $fila->producto,
                'categoria' => $fila->categoria ?? '—',
                'cantidad_entrada' => (float) $fila->cantidad_entrada,
                'importe_entrada' => (float) $fila->importe_entrada,
                'cantidad_sobrante' => (float) $fila->cantidad_sobrante,
                'importe_sobrante' => (float) $fila->importe_sobrante,
            ])->all(),
            'totales' => [
                'cantidad_entrada' => (float) $productos->sum('cantidad_entrada'),
                'importe_entrada' => $totalEntrada,
                'cantidad_sobrante' => (float) $productos->sum('cantidad_sobrante'),
                'importe_sobrante' => $totalSobrante,
            ],
        ];
    }

    /**
     * @return Datos
     */
    private function transferencias(string $inicio, string $fin, ?int $sucursalId): array
    {
        $transferencias = Salida::query()
            ->join('productos', 'productos.id', '=', 'salidas.producto_id')
            ->join('sucursales as origen', 'origen.id', '=', 'salidas.sucursal_origen_id')
            ->join('sucursales as destino', 'destino.id', '=', 'salidas.sucursal_destino_id')
            ->whereBetween('salidas.fecha_salida', [$inicio, $fin])
            ->when($sucursalId, fn ($q) => $q->where(fn ($q) => $q->where('salidas.sucursal_origen_id', $sucursalId)->orWhere('salidas.sucursal_destino_id', $sucursalId)))
            ->orderBy('salidas.fecha_salida')
            ->orderBy('salidas.id')
            ->toBase()->get(['salidas.fecha_salida', 'origen.name as origen', 'destino.name as destino', 'productos.name as producto', 'salidas.cantidad', 'salidas.precio', 'salidas.total']);

        $total = (float) $transferencias->sum('total');

        return [
            'resumen' => [
                $this->indicador('Transferencias', $transferencias->count(), 'numero', 'neutral', 'truck'),
                $this->indicador('Importe enviado', $total, 'dinero', 'neutral', 'banknotes'),
                $this->indicador('Productos distintos', $transferencias->pluck('producto')->unique()->count(), 'numero', 'neutral', 'cube'),
                $this->indicador('Ruta más frecuente', $this->rutaMasFrecuente($transferencias), 'texto', 'positive', 'arrows-right-left'),
            ],
            'columnas' => [
                $this->columna('fecha', 'Fecha', 'fecha'),
                $this->columna('origen', 'Origen', 'texto'),
                $this->columna('destino', 'Destino', 'texto'),
                $this->columna('producto', 'Producto', 'texto'),
                $this->columna('cantidad', 'Cantidad', 'numero'),
                $this->columna('precio', 'Precio', 'dinero'),
                $this->columna('total', 'Total', 'dinero'),
            ],
            'filas' => $transferencias->map(fn ($fila) => [
                'fecha' => Carbon::parse($fila->fecha_salida)->toDateString(),
                'origen' => $fila->origen,
                'destino' => $fila->destino,
                'producto' => $fila->producto,
                'cantidad' => (float) $fila->cantidad,
                'precio' => (float) $fila->precio,
                'total' => (float) $fila->total,
            ])->all(),
            'totales' => [
                'cantidad' => (float) $transferencias->sum('cantidad'),
                'total' => $total,
            ],
        ];
    }

    /**
     * Suma por sucursal de gastos o mermas cuyas cuentas caen en el periodo.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $consulta
     * @param  'gastos'|'mermas'  $tabla
     * @return Collection<int, float|string>
     */
    private function totalPorSucursal($consulta, string $tabla, string $inicio, string $fin)
    {
        return $consulta
            ->join('cuentas', 'cuentas.id', '=', "{$tabla}.cuenta_id")
            ->whereBetween('cuentas.fecha_venta', [$inicio, $fin])
            ->groupBy("{$tabla}.sucursal_id")
            ->selectRaw(match ($tabla) {
                'gastos' => 'gastos.sucursal_id as sid, SUM(gastos.precio) as total',
                'mermas' => 'mermas.sucursal_id as sid, SUM(mermas.precio) as total',
            })
            ->pluck('total', 'sid');
    }

    /**
     * @param  Collection<int, mixed>  $transferencias
     */
    private function rutaMasFrecuente($transferencias): string
    {
        $ruta = $transferencias
            ->groupBy(fn ($fila) => $fila->origen.' → '.$fila->destino)
            ->map->count()
            ->sortDesc()
            ->keys()
            ->first();

        return (string) ($ruta ?? '—');
    }

    private function nombreStatus(?string $nombre): string
    {
        return $nombre ? ucfirst(mb_strtolower($nombre)) : '—';
    }

    /**
     * @return array{clave: string, etiqueta: string, tipo: string}
     */
    private function columna(string $clave, string $etiqueta, string $tipo): array
    {
        return ['clave' => $clave, 'etiqueta' => $etiqueta, 'tipo' => $tipo];
    }

    /**
     * @return array{etiqueta: string, valor: float|int|string, tipo: string, tono: string, icono: string}
     */
    private function indicador(string $etiqueta, float|int|string $valor, string $tipo, string $tono, string $icono): array
    {
        return compact('etiqueta', 'valor', 'tipo', 'tono', 'icono');
    }
}
