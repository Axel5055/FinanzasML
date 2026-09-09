<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Cuenta {{ $cuenta->sucursal->name ?? '' }} — {{ \Carbon\Carbon::parse($cuenta->fecha_venta)->format('d/m/Y') }}</title>
    <style>
        @php
            $statusMeta = [
                1 => ['label' => 'Pendiente', 'bg' => '#F6E4E1', 'color' => '#B23B2E'],
                2 => ['label' => 'Pago parcial', 'bg' => '#F3E3CE', 'color' => '#C1721E'],
                3 => ['label' => 'Pagado', 'bg' => '#E1EFE5', 'color' => '#2F7A4F'],
            ][$cuenta->status_cuenta_id] ?? ['label' => $cuenta->status_cuenta->name ?? 'Sin estatus', 'bg' => '#F3E3CE', 'color' => '#C1721E'];

            $diferenciaColor = $cuenta->diferencia > 0 ? '#B23B2E' : '#2F7A4F';
            $diferenciaBg = $cuenta->diferencia > 0 ? '#F6E4E1' : '#E1EFE5';
        @endphp

        body {
            font-family: 'Helvetica', Arial, sans-serif;
            color: #1C211D;
            font-size: 11px;
        }

        .header {
            width: 100%;
            margin-bottom: 16px;
        }

        .header td {
            vertical-align: middle;
        }

        .header .brand {
            font-size: 15px;
            font-weight: bold;
            color: #1C211D;
        }

        .header .sub {
            font-size: 10px;
            color: #5C6961;
        }

        .pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: bold;
            background-color: {{ $statusMeta['bg'] }};
            color: {{ $statusMeta['color'] }};
        }

        .meta {
            width: 100%;
            margin-bottom: 14px;
            border-collapse: collapse;
        }

        .meta td {
            padding: 6px 10px;
            border: 1px solid #DDE3D8;
            font-size: 10px;
        }

        .meta .label {
            color: #8A968E;
            text-transform: uppercase;
            font-size: 8.5px;
            font-weight: bold;
            display: block;
            margin-bottom: 2px;
        }

        .stat-row {
            width: 100%;
            border-collapse: separate;
            border-spacing: 6px 0;
            margin-bottom: 14px;
        }

        .stat-box {
            border: 1px solid #DDE3D8;
            border-radius: 6px;
            padding: 8px 10px;
            width: 25%;
        }

        .stat-box .label {
            font-size: 8.5px;
            text-transform: uppercase;
            font-weight: bold;
            color: #8A968E;
        }

        .stat-box .value {
            font-size: 14px;
            font-weight: bold;
            color: #1C211D;
        }

        .section-title {
            font-size: 11.5px;
            font-weight: bold;
            color: #1C211D;
            margin: 16px 0 6px;
            padding-bottom: 4px;
            border-bottom: 1.5px solid #1C211D;
        }

        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }

        table.data th {
            background-color: #1C211D;
            color: #fff;
            font-size: 9px;
            text-transform: uppercase;
            padding: 6px 8px;
            text-align: left;
            white-space: nowrap;
        }

        table.data td {
            font-size: 10px;
            padding: 5px 8px;
            border-bottom: 1px solid #E9ECE6;
        }

        table.data tfoot td {
            font-weight: bold;
            text-align: right;
            border-top: 1.5px solid #1C211D;
            border-bottom: none;
        }

        .text-center {
            text-align: center;
        }

        .muted {
            color: #8A968E;
        }

        .empty-row td {
            text-align: center;
            color: #8A968E;
            padding: 10px;
        }
    </style>
</head>

<body>

    <table class="header">
        <tr>
            <td>
                <div class="brand">Reporte de cuenta diaria</div>
                <div class="sub">{{ $cuenta->sucursal->name ?? 'Sin sucursal' }} — {{ \Carbon\Carbon::parse($cuenta->fecha_venta)->translatedFormat('d \d\e F \d\e Y') }}</div>
            </td>
            <td style="text-align: right;"><span class="pill">{{ strtoupper($statusMeta['label']) }}</span></td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td style="width: 25%;"><span class="label">Sucursal</span>{{ $cuenta->sucursal->name ?? '—' }}</td>
            <td style="width: 25%;"><span class="label">Fecha de venta</span>{{ \Carbon\Carbon::parse($cuenta->fecha_venta)->format('d/m/Y') }}</td>
            <td style="width: 25%;"><span class="label">Fecha de captura</span>{{ \Carbon\Carbon::parse($cuenta->fecha_captura)->format('d/m/Y') }}</td>
            <td style="width: 25%;"><span class="label">Cuenta #</span>{{ $cuenta->id }}</td>
        </tr>
    </table>

    <table class="stat-row">
        <tr>
            <td class="stat-box">
                <div class="label">Total venta</div>
                <div class="value">${{ number_format($cuenta->total_venta, 2) }}</div>
            </td>
            <td class="stat-box">
                <div class="label">Efectivo entregado</div>
                <div class="value">${{ number_format($cuenta->efectivo_entregado, 2) }}</div>
            </td>
            <td class="stat-box">
                <div class="label">Tarjeta</div>
                <div class="value">${{ number_format($cuenta->tarjeta, 2) }}</div>
            </td>
            <td class="stat-box" style="background-color: {{ $diferenciaBg }}; border-color: {{ $diferenciaBg }};">
                <div class="label" style="color: {{ $diferenciaColor }};">Diferencia</div>
                <div class="value" style="color: {{ $diferenciaColor }};">${{ number_format($cuenta->diferencia, 2) }}</div>
            </td>
            <td class="stat-box">
                <div class="label">Pollo / Marinado</div>
                <div class="value" style="font-size: 11px;">${{ number_format($cuenta->efectivo_pollo, 2) }} / ${{ number_format($cuenta->efectivo_marinado, 2) }}</div>
            </td>
        </tr>
    </table>

    <div class="section-title">Resumen por categoría</div>
    <table class="data">
        <thead>
            <tr>
                <th>Concepto</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Existencia</td>
                <td>${{ number_format($cuenta->itemsCuenta->sum('importe_existencia'), 2) }}</td>
            </tr>
            <tr>
                <td>Entradas</td>
                <td>${{ number_format($cuenta->itemsCuenta->sum('importe_entrada'), 2) }}</td>
            </tr>
            <tr>
                <td>Salidas</td>
                <td>${{ number_format($cuenta->salidas->sum('total'), 2) }}</td>
            </tr>
            <tr>
                <td>Sobrante</td>
                <td>${{ number_format($cuenta->itemsCuenta->sum('importe_sobrante'), 2) }}</td>
            </tr>
            <tr>
                <td>Gastos</td>
                <td>${{ number_format($cuenta->gastos->sum('precio'), 2) }}</td>
            </tr>
            <tr>
                <td>Merma</td>
                <td>${{ number_format($cuenta->mermas->sum('precio'), 2) }}</td>
            </tr>
        </tbody>
    </table>

    @php
        $itemsConMovimiento = $cuenta->itemsCuenta->filter(fn ($item) => $item->precio != 0
            || $item->cantidad_existencia != 0 || $item->cantidad_entrada != 0
            || $item->cantidad_salida != 0 || $item->cantidad_sobrante != 0);
    @endphp
    @if ($itemsConMovimiento->isNotEmpty())
        <div class="section-title">Detalle por producto</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Precio</th>
                    <th>Existencia</th>
                    <th>Entrada</th>
                    <th>Salida</th>
                    <th>Sobrante</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($itemsConMovimiento as $item)
                    <tr>
                        <td>{{ $item->producto->name ?? 'N/A' }}</td>
                        <td>${{ number_format($item->precio, 2) }}</td>
                        <td>{{ number_format($item->cantidad_existencia, 2) }} kg</td>
                        <td>{{ number_format($item->cantidad_entrada, 2) }} kg</td>
                        <td>{{ number_format($item->cantidad_salida, 2) }} kg</td>
                        <td>{{ number_format($item->cantidad_sobrante, 2) }} kg</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="section-title">Entradas de otra sucursal</div>
    <table class="data">
        <thead>
            <tr>
                <th>Producto</th>
                <th>Sucursal origen</th>
                <th>Precio</th>
                <th>Cantidad</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($cuenta->entradas as $entrada)
                <tr>
                    <td>{{ $entrada->producto->name ?? 'N/A' }}</td>
                    <td>{{ $entrada->sucursalOrigen->name ?? 'N/A' }}</td>
                    <td>${{ number_format($entrada->precio_envio, 2) }}</td>
                    <td>{{ number_format($entrada->cantidad, 2) }} kg</td>
                    <td>${{ number_format($entrada->total, 2) }}</td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="5">No hay datos para mostrar</td>
                </tr>
            @endforelse
        </tbody>
        @if ($cuenta->entradas->isNotEmpty())
            <tfoot>
                <tr>
                    <td colspan="5">Total: ${{ number_format($cuenta->entradas->sum('total'), 2) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>

    <div class="section-title">Salidas a otra sucursal</div>
    <table class="data">
        <thead>
            <tr>
                <th>Producto</th>
                <th>Sucursal destino</th>
                <th>Precio</th>
                <th>Cantidad</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($cuenta->salidas as $salida)
                <tr>
                    <td>{{ $salida->producto->name ?? 'N/A' }}</td>
                    <td>{{ $salida->sucursalDestino->name ?? 'N/A' }}</td>
                    <td>${{ number_format($salida->precio, 2) }}</td>
                    <td>{{ number_format($salida->cantidad, 2) }} kg</td>
                    <td>${{ number_format($salida->total, 2) }}</td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="5">No hay datos para mostrar</td>
                </tr>
            @endforelse
        </tbody>
        @if ($cuenta->salidas->isNotEmpty())
            <tfoot>
                <tr>
                    <td colspan="5">Total: ${{ number_format($cuenta->salidas->sum('total'), 2) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>

    <div class="section-title">Gastos</div>
    <table class="data">
        <thead>
            <tr>
                <th>Concepto</th>
                <th>Precio</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($cuenta->gastos as $gasto)
                <tr>
                    <td>{{ $gasto->concepto }}</td>
                    <td>${{ number_format($gasto->precio, 2) }}</td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="2">No hay datos para mostrar</td>
                </tr>
            @endforelse
        </tbody>
        @if ($cuenta->gastos->isNotEmpty())
            <tfoot>
                <tr>
                    <td colspan="2">Total: ${{ number_format($cuenta->gastos->sum('precio'), 2) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>

    <div class="section-title">Merma</div>
    <table class="data">
        <thead>
            <tr>
                <th>Concepto</th>
                <th>Precio</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($cuenta->mermas as $merma)
                <tr>
                    <td>{{ $merma->concepto }}</td>
                    <td>${{ number_format($merma->precio, 2) }}</td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="2">No hay datos para mostrar</td>
                </tr>
            @endforelse
        </tbody>
        @if ($cuenta->mermas->isNotEmpty())
            <tfoot>
                <tr>
                    <td colspan="2">Total: ${{ number_format($cuenta->mermas->sum('precio'), 2) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>

</body>

</html>
