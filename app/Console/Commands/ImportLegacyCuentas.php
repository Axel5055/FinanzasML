<?php

namespace App\Console\Commands;

use App\Models\Categoria;
use App\Models\Cuenta;
use App\Models\Direccion;
use App\Models\Producto;
use App\Models\StatusCuenta;
use App\Models\Sucursal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('legacy:import-cuentas {--connection-database=tepetlapa : Nombre de la base de datos legada en el mismo servidor MySQL} {--force : Permite ejecutar aunque ya existan cuentas importadas}')]
#[Description('Importa cuentas y sus datos relacionados (item_cuentas, salidas, entradas, gastos, mermas) desde la base de datos legada de mlgrupoc_tepetlapa')]
class ImportLegacyCuentas extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (Cuenta::query()->exists() && ! $this->option('force')) {
            $this->error('Ya existen cuentas en la base de datos. Usa --force si de verdad quieres volver a importar (puede duplicar datos).');

            return self::FAILURE;
        }

        config(['database.connections.legacy' => array_merge(
            config('database.connections.mysql'),
            ['database' => $this->option('connection-database')]
        )]);

        $legacy = DB::connection('legacy');

        try {
            $legacy->getPdo();
        } catch (\Throwable $e) {
            $this->error("No se pudo conectar a la base de datos legada '{$this->option('connection-database')}': {$e->getMessage()}");

            return self::FAILURE;
        }

        DB::transaction(function () use ($legacy) {
            $sucursalMap = $this->importSucursales($legacy);
            $productoMap = $this->importProductos($legacy);
            $statusMap = $this->mapStatusCuentas($legacy);

            $this->importCuentas($legacy, $sucursalMap, $statusMap);
            $this->importSalidas($legacy, $sucursalMap, $productoMap);
            $this->importEntradas($legacy, $sucursalMap, $productoMap);
            $this->importItemCuentas($legacy, $productoMap);
            $this->importGastos($legacy, $sucursalMap);
            $this->importMermas($legacy, $sucursalMap);
        });

        $this->info('Importación completada.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, int> mapa de sucursal_id legado -> sucursal_id actual
     */
    private function importSucursales(Connection $legacy): array
    {
        $existentes = Sucursal::query()->get(['id', 'name'])->keyBy(fn ($s) => Str::upper(trim($s->name)));

        $map = [];

        foreach ($legacy->table('sucursales')->join('direcciones', 'direcciones.id', '=', 'sucursales.direccion_id')
            ->select('sucursales.id', 'sucursales.name', 'direcciones.codigo_postal', 'direcciones.colonia', 'direcciones.estado', 'direcciones.numero_interior', 'direcciones.numero_exterior', 'direcciones.calle')
            ->get() as $legacySucursal) {
            $nombre = trim($legacySucursal->name);

            if ($nombre === '' || Str::upper($nombre) === 'NINGUNA') {
                continue;
            }

            $existente = $existentes->get(Str::upper($nombre));

            if ($existente) {
                $map[$legacySucursal->id] = $existente->id;

                continue;
            }

            $direccion = Direccion::create([
                'codigo_postal' => $legacySucursal->codigo_postal,
                'colonia' => $legacySucursal->colonia,
                'estado' => $legacySucursal->estado,
                'numero_interior' => $legacySucursal->numero_interior,
                'numero_exterior' => $legacySucursal->numero_exterior,
                'calle' => $legacySucursal->calle,
            ]);

            $sucursal = Sucursal::create([
                'name' => $nombre,
                'direccion_id' => $direccion->id,
                'activo' => true,
            ]);

            $existentes->put(Str::upper($nombre), $sucursal);
            $map[$legacySucursal->id] = $sucursal->id;
        }

        $this->info(sprintf('Sucursales: %d mapeadas/creadas.', count($map)));

        return $map;
    }

    /**
     * @return array<int, int> mapa de producto_id legado -> producto_id actual
     */
    private function importProductos(Connection $legacy): array
    {
        $categoriaMap = Categoria::query()->get(['id', 'name'])->keyBy(fn ($c) => Str::upper(trim($c->name)))
            ->map(fn ($c) => $c->id);

        $legacyCategorias = $legacy->table('categorias')->pluck('name', 'id');

        $existentes = Producto::query()->withTrashed()->get(['id', 'name'])->keyBy(fn ($p) => Str::upper(trim($p->name)));

        $map = [];

        foreach ($legacy->table('productos')->orderBy('id')->get() as $legacyProducto) {
            $nombre = Str::upper(trim($legacyProducto->name));

            if ($nombre === '') {
                continue;
            }

            $existente = $existentes->get($nombre);

            if ($existente) {
                $map[$legacyProducto->id] = $existente->id;

                continue;
            }

            $categoriaNombre = Str::upper(trim($legacyCategorias[$legacyProducto->categoria_id] ?? ''));
            $categoriaId = $categoriaMap->get($categoriaNombre) ?? $categoriaMap->first();

            $producto = Producto::create([
                'name' => $nombre,
                'categoria_id' => $categoriaId,
            ]);

            $existentes->put($nombre, $producto);
            $map[$legacyProducto->id] = $producto->id;
        }

        $this->info(sprintf('Productos: %d mapeados/creados.', count($map)));

        return $map;
    }

    /**
     * @return array<int, int> mapa de status_cuenta_id legado -> status_cuenta_id actual
     */
    private function mapStatusCuentas(Connection $legacy): array
    {
        $existentes = StatusCuenta::query()->get(['id', 'name'])->keyBy(fn ($s) => Str::upper(trim($s->name)));

        $map = [];

        foreach ($legacy->table('status_cuentas')->get() as $legacyStatus) {
            $nombre = Str::upper(trim($legacyStatus->name));
            $existente = $existentes->get($nombre);

            if ($existente) {
                $map[$legacyStatus->id] = $existente->id;
            }
        }

        $this->info(sprintf('Status de cuenta: %d mapeados.', count($map)));

        return $map;
    }

    /**
     * @param  array<int, int>  $sucursalMap
     * @param  array<int, int>  $statusMap
     */
    private function importCuentas(Connection $legacy, array $sucursalMap, array $statusMap): void
    {
        $total = 0;

        $legacy->table('cuentas')->orderBy('id')->chunk(500, function ($rows) use ($sucursalMap, $statusMap, &$total) {
            $insert = [];

            foreach ($rows as $row) {
                if (! isset($sucursalMap[$row->sucursal_id])) {
                    continue;
                }

                $insert[] = [
                    'id' => $row->id,
                    'efectivo_pollo' => $row->efectivo_pollo,
                    'efectivo_marinado' => $row->efectivo_marinado,
                    'efectivo_total' => $row->efectivo_total,
                    'efectivo_entregado' => $row->efectivo_entregado,
                    'total_venta' => $row->total_venta,
                    'tarjeta' => $row->transferencia,
                    'diferencia' => $row->diferencia,
                    'sobrante' => $row->sobrante,
                    'fecha_captura' => $row->fecha_captura,
                    'fecha_venta' => $row->fecha_venta,
                    'sucursal_id' => $sucursalMap[$row->sucursal_id],
                    'status_cuenta_id' => $statusMap[$row->status_cuenta_id] ?? 1,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
            }

            if ($insert !== []) {
                DB::table('cuentas')->insert($insert);
                $total += count($insert);
            }
        });

        $this->info("Cuentas importadas: {$total}");
    }

    /**
     * @param  array<int, int>  $sucursalMap
     * @param  array<int, int>  $productoMap
     */
    private function importSalidas(Connection $legacy, array $sucursalMap, array $productoMap): void
    {
        $total = 0;

        $legacy->table('salidas')->orderBy('id')->chunk(500, function ($rows) use ($sucursalMap, $productoMap, &$total) {
            $insert = [];

            foreach ($rows as $row) {
                if (! isset($sucursalMap[$row->sucursal_origen_id], $sucursalMap[$row->sucursal_destino_id], $productoMap[$row->producto_id])) {
                    continue;
                }

                $insert[] = [
                    'id' => $row->id,
                    'precio' => $row->precio,
                    'cantidad' => $row->cantidad,
                    'total' => $row->total,
                    'fecha_salida' => $row->fecha_salida,
                    'producto_id' => $productoMap[$row->producto_id],
                    'cuenta_id' => $row->cuenta_id,
                    'sucursal_destino_id' => $sucursalMap[$row->sucursal_destino_id],
                    'sucursal_origen_id' => $sucursalMap[$row->sucursal_origen_id],
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
            }

            if ($insert !== []) {
                DB::table('salidas')->insert($insert);
                $total += count($insert);
            }
        });

        $this->info("Salidas importadas: {$total}");
    }

    /**
     * @param  array<int, int>  $sucursalMap
     * @param  array<int, int>  $productoMap
     */
    private function importEntradas(Connection $legacy, array $sucursalMap, array $productoMap): void
    {
        $total = 0;

        $legacy->table('entradas')->orderBy('id')->chunk(500, function ($rows) use ($sucursalMap, $productoMap, &$total) {
            $insert = [];

            foreach ($rows as $row) {
                if (! isset($sucursalMap[$row->sucursal_origen_id], $sucursalMap[$row->sucursal_destino_id], $productoMap[$row->producto_id])) {
                    continue;
                }

                $insert[] = [
                    'id' => $row->id,
                    'precio' => $row->precio,
                    'precio_envio' => $row->precio_envio,
                    'cantidad' => $row->cantidad,
                    'fecha_entrada' => $row->fecha_entrada,
                    'producto_id' => $productoMap[$row->producto_id],
                    'sucursal_origen_id' => $sucursalMap[$row->sucursal_origen_id],
                    'sucursal_destino_id' => $sucursalMap[$row->sucursal_destino_id],
                    'salida_id' => $row->salida_id,
                    'cuenta_id' => $row->cuenta_id,
                    'total' => $row->total,
                    'deleted_at' => $row->deleted_at,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
            }

            if ($insert !== []) {
                DB::table('entradas')->insert($insert);
                $total += count($insert);
            }
        });

        $this->info("Entradas importadas: {$total}");
    }

    /**
     * @param  array<int, int>  $productoMap
     */
    private function importItemCuentas(Connection $legacy, array $productoMap): void
    {
        $total = 0;

        $legacy->table('item_cuentas')->orderBy('id')->chunk(1000, function ($rows) use ($productoMap, &$total) {
            $insert = [];

            foreach ($rows as $row) {
                if (! isset($productoMap[$row->producto_id])) {
                    continue;
                }

                $insert[] = [
                    'id' => $row->id,
                    'precio' => $row->precio,
                    'cantidad_existencia' => $row->cantidad_existencia,
                    'importe_existencia' => $row->importe_existencia,
                    'cantidad_entrada' => $row->cantidad_entrada,
                    'importe_entrada' => $row->importe_entrada,
                    'cantidad_salida' => $row->cantidad_salida,
                    'importe_salida' => $row->importe_salida,
                    'cantidad_sobrante' => $row->cantidad_sobrante,
                    'importe_sobrante' => $row->importe_sobrante,
                    'cantidad_mayoreo' => $row->cantidad_mayoreo,
                    'importe_mayoreo' => $row->importe_mayoreo,
                    'producto_id' => $productoMap[$row->producto_id],
                    'cuenta_id' => $row->cuenta_id,
                    'fecha_venta' => $row->fecha_venta,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
            }

            if ($insert !== []) {
                DB::table('item_cuentas')->insert($insert);
                $total += count($insert);
            }
        });

        $this->info("Item cuentas importados: {$total}");
    }

    /**
     * @param  array<int, int>  $sucursalMap
     */
    private function importGastos(Connection $legacy, array $sucursalMap): void
    {
        $total = 0;

        $legacy->table('gastos')->orderBy('id')->chunk(500, function ($rows) use ($sucursalMap, &$total) {
            $insert = [];

            foreach ($rows as $row) {
                if (! isset($sucursalMap[$row->sucursal_id])) {
                    continue;
                }

                $insert[] = [
                    'id' => $row->id,
                    'precio' => $row->precio,
                    'concepto' => $row->concepto,
                    'sucursal_id' => $sucursalMap[$row->sucursal_id],
                    'cuenta_id' => $row->cuenta_id,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
            }

            if ($insert !== []) {
                DB::table('gastos')->insert($insert);
                $total += count($insert);
            }
        });

        $this->info("Gastos importados: {$total}");
    }

    /**
     * @param  array<int, int>  $sucursalMap
     */
    private function importMermas(Connection $legacy, array $sucursalMap): void
    {
        $total = 0;

        $legacy->table('mermas')->orderBy('id')->chunk(500, function ($rows) use ($sucursalMap, &$total) {
            $insert = [];

            foreach ($rows as $row) {
                if (! isset($sucursalMap[$row->sucursal_id])) {
                    continue;
                }

                $insert[] = [
                    'id' => $row->id,
                    'precio' => $row->precio,
                    'concepto' => $row->concepto,
                    'sucursal_id' => $sucursalMap[$row->sucursal_id],
                    'cuenta_id' => $row->cuenta_id,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
            }

            if ($insert !== []) {
                DB::table('mermas')->insert($insert);
                $total += count($insert);
            }
        });

        $this->info("Mermas importadas: {$total}");
    }
}
