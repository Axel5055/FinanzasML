<?php

namespace App\Livewire;

use App\Models\Cuenta;
use App\Models\StatusCuenta;
use App\Models\Sucursal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\Components\Filters\FilterBase;
use PowerComponents\LivewirePowerGrid\Components\SetUp\Footer;
use PowerComponents\LivewirePowerGrid\Components\SetUp\Header;
use PowerComponents\LivewirePowerGrid\Facades\Filter;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;
use PowerComponents\LivewirePowerGrid\PowerGridFields;

final class CuentaTable extends PowerGridComponent
{
    public string $tableName = 'cuenta-table';

    public string $sortField = 'fecha_venta';

    public string $sortDirection = 'desc';

    /**
     * @return array<int, Header|Footer>
     */
    public function setUp(): array
    {
        return [
            PowerGrid::header()->showSearchInput(),
            PowerGrid::footer()
                ->showPerPage()
                ->showRecordCount(),
        ];
    }

    /**
     * @return Builder<Cuenta>
     */
    public function datasource(): Builder
    {
        return Cuenta::query()
            ->where(function ($query) {
                $query->where('efectivo_pollo', '!=', 0)
                    ->orWhere('efectivo_marinado', '!=', 0);
            })
            ->with([
                'sucursal',
                'status_cuenta',
                'itemsCuenta.producto',
            ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function relationSearch(): array
    {
        // PowerGrid combina relationSearch() con orWhereHas() al nivel superior
        // del query, lo que rompe el AND con el filtro base de datasource()
        // (excluir cuentas "vacías"). Se deja vacío a propósito, igual que en
        // el proyecto original; el filtro por sucursal (dropdown) sí aplica
        // AND correctamente vía FilterHandler.
        return [];
    }

    private function formatearMoneda(float|int|string $valor): string
    {
        $valor = (float) $valor;

        return fmod($valor, 1.0) === 0.0
            ? number_format($valor, 0)
            : number_format($valor, 2);
    }

    private function pill(string $class, string $text): string
    {
        return "<span class='inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold {$class}'>{$text}</span>";
    }

    public function fields(): PowerGridFields
    {
        return PowerGrid::fields()
            ->add(
                'fecha_venta_formatted',
                fn (Cuenta $model) => '<span class="font-semibold text-zinc-900 dark:text-zinc-100 tabular-nums">'.Carbon::parse($model->fecha_venta)->format('d/m/Y').'</span>'
            )
            ->add(
                'sucursal_id',
                fn (Cuenta $model) => '<span class="font-semibold text-zinc-900 dark:text-zinc-100">'.e($model->sucursal?->name).'</span>'
            )
            ->add(
                'total_venta_formated',
                fn (Cuenta $model) => '<span class="font-bold tabular-nums '.($model->total_venta < 0 ? 'text-negative' : 'text-zinc-900 dark:text-zinc-100').'">$'.
                    $this->formatearMoneda($model->total_venta).'</span>'
            )
            ->add(
                'diferencia_formated',
                fn (Cuenta $model) => $this->pill(
                    $model->diferencia > 0 ? 'bg-negative-soft text-negative' : 'bg-positive-soft text-positive',
                    '$'.$this->formatearMoneda($model->diferencia)
                )
            )
            ->add('status_cuenta_id', function (Cuenta $model) {
                $status = e($model->status_cuenta?->name);
                $class = match ($status) {
                    'PAGADO' => 'bg-positive-soft text-positive',
                    'PAGO PARCIAL' => 'bg-accent-soft text-accent',
                    'PENDIENTE' => 'bg-negative-soft text-negative',
                    default => 'bg-zinc-100 dark:bg-white/10 text-zinc-500',
                };

                return $this->pill($class, $status ?: 'SIN STATUS');
            })
            ->add(
                'acciones',
                fn (Cuenta $model) => "<div class='flex gap-1.5'>".
                    "<a class='text-xs font-bold rounded px-2.5 py-1.5 bg-accent-soft text-accent transition-colors hover:brightness-90' href='".route('admin.cuentas.show', ['cuenta' => $model])."' wire:navigate>Ver</a>".
                    "<a class='text-xs font-bold rounded px-2.5 py-1.5 border border-zinc-200 dark:border-zinc-700 text-zinc-900 dark:text-zinc-100 transition-colors hover:bg-zinc-100 hover:border-zinc-300 dark:hover:bg-white/10 dark:hover:border-zinc-600' href='".route('admin.registrar.index', ['cuenta' => $model])."' wire:navigate>Editar</a>".
                    "<a class='text-xs font-bold rounded px-2.5 py-1.5 border border-zinc-200 dark:border-zinc-700 text-zinc-900 dark:text-zinc-100 transition-colors hover:bg-zinc-100 hover:border-zinc-300 dark:hover:bg-white/10 dark:hover:border-zinc-600' target='_blank' href='".route('admin.cuentas.pdf', ['cuenta' => $model])."'>PDF</a>".
                    '</div>'
            );
    }

    /**
     * @return array<int, Column>
     */
    public function columns(): array
    {
        return [
            Column::make('Fecha venta', 'fecha_venta_formatted', 'fecha_venta')->sortable(),
            Column::make('Sucursal', 'sucursal_id'),
            Column::make('Total venta', 'total_venta_formated', 'total_venta')->sortable(),
            Column::make('Diferencia', 'diferencia_formated', 'diferencia')->sortable(),
            Column::make('Status', 'status_cuenta_id'),
            Column::make('Acciones', 'acciones'),
        ];
    }

    /**
     * @return array<int, FilterBase>
     */
    public function filters(): array
    {
        return [
            Filter::select('sucursal_id')
                ->dataSource(Sucursal::activas()->orderBy('name')->get())
                ->optionValue('id')
                ->optionLabel('name'),
            Filter::select('status_cuenta_id')
                ->dataSource(StatusCuenta::all())
                ->optionValue('id')
                ->optionLabel('name'),
            Filter::datepicker('fecha_venta_formatted', 'fecha_venta'),
        ];
    }
}
