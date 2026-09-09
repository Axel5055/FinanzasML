<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Reporte de cuentas — {{ $sucursal->name }}</title>
    <style>
        body {
            font-family: 'Helvetica', Arial, sans-serif;
            color: #1C211D;
            font-size: 11px;
        }

        .header {
            width: 100%;
            margin-bottom: 16px;
        }

        .header .brand {
            font-size: 15px;
            font-weight: bold;
        }

        .header .sub {
            font-size: 10px;
            color: #5C6961;
        }

        .stat-row {
            width: 100%;
            border-collapse: separate;
            border-spacing: 6px 0;
            margin-bottom: 16px;
        }

        .stat-box {
            border: 1px solid #DDE3D8;
            border-radius: 6px;
            padding: 8px 10px;
            width: 20%;
        }

        .stat-box .label {
            font-size: 8.5px;
            text-transform: uppercase;
            font-weight: bold;
            color: #8A968E;
        }

        .stat-box .value {
            font-size: 13px;
            font-weight: bold;
            color: #1C211D;
        }

        .section-title {
            font-size: 11.5px;
            font-weight: bold;
            margin: 4px 0 6px;
            padding-bottom: 4px;
            border-bottom: 1.5px solid #1C211D;
        }

        table.data {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        table.data col.col-fecha {
            width: 15%;
        }

        table.data col.col-efectivo,
        table.data col.col-tarjeta,
        table.data col.col-total,
        table.data col.col-diferencia {
            width: 17%;
        }

        table.data col.col-status {
            width: 17%;
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
            border-top: 1.5px solid #1C211D;
            border-bottom: none;
            background-color: #E9ECE6;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 8px;
            font-size: 9px;
            font-weight: bold;
        }

        .empty-row td {
            text-align: center;
            color: #8A968E;
            padding: 14px;
        }
    </style>
</head>

<body>

    @php
        $totalVenta = $cuentas->sum('total_venta');
        $totalFaltantes = $cuentas->where('diferencia', '>', 0)->sum('diferencia');
        $totalSobrantes = abs($cuentas->where('diferencia', '<', 0)->sum('diferencia'));
        $statusMeta = [
            1 => ['label' => 'Pendiente', 'bg' => '#F6E4E1', 'color' => '#B23B2E'],
            2 => ['label' => 'Pago parcial', 'bg' => '#F3E3CE', 'color' => '#C1721E'],
            3 => ['label' => 'Pagado', 'bg' => '#E1EFE5', 'color' => '#2F7A4F'],
        ];
    @endphp

    <table class="header">
        <tr>
            <td>
                <div class="brand">Reporte de cuentas</div>
                <div class="sub">{{ $sucursal->name }} — del {{ \Carbon\Carbon::parse($start_date)->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($end_date)->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>

    <table class="stat-row">
        <tr>
            <td class="stat-box">
                <div class="label">Cuentas</div>
                <div class="value">{{ $cuentas->count() }}</div>
            </td>
            <td class="stat-box">
                <div class="label">Total vendido</div>
                <div class="value">${{ number_format($totalVenta, 2) }}</div>
            </td>
            <td class="stat-box" style="background-color: #F6E4E1; border-color: #F6E4E1;">
                <div class="label" style="color: #B23B2E;">Faltantes</div>
                <div class="value" style="color: #B23B2E;">${{ number_format($totalFaltantes, 2) }}</div>
            </td>
            <td class="stat-box" style="background-color: #E1EFE5; border-color: #E1EFE5;">
                <div class="label" style="color: #2F7A4F;">Sobrantes</div>
                <div class="value" style="color: #2F7A4F;">${{ number_format($totalSobrantes, 2) }}</div>
            </td>
            <td class="stat-box">
                <div class="label">Pendientes</div>
                <div class="value">{{ $cuentas->where('status_cuenta_id', 1)->count() }}</div>
            </td>
        </tr>
    </table>

    <div class="section-title">Detalle de cuentas</div>
    <table class="data">
        <colgroup>
            <col class="col-fecha">
            <col class="col-efectivo">
            <col class="col-tarjeta">
            <col class="col-total">
            <col class="col-diferencia">
            <col class="col-status">
        </colgroup>
        <thead>
            <tr>
                <th>Fecha de venta</th>
                <th>Efectivo</th>
                <th>Tarjeta</th>
                <th>Total venta</th>
                <th>Diferencia</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($cuentas as $cuenta)
                @php $meta = $statusMeta[$cuenta->status_cuenta_id] ?? ['label' => $cuenta->status_cuenta->name ?? '—', 'bg' => '#F3E3CE', 'color' => '#C1721E']; @endphp
                <tr>
                    <td>{{ \Carbon\Carbon::parse($cuenta->fecha_venta)->format('d/m/Y') }}</td>
                    <td>${{ number_format($cuenta->efectivo_entregado, 2) }}</td>
                    <td>${{ number_format($cuenta->tarjeta, 2) }}</td>
                    <td>${{ number_format($cuenta->total_venta, 2) }}</td>
                    <td style="color: {{ $cuenta->diferencia > 0 ? '#B23B2E' : '#2F7A4F' }};">
                        ${{ number_format($cuenta->diferencia, 2) }}
                    </td>
                    <td><span class="pill" style="background-color: {{ $meta['bg'] }}; color: {{ $meta['color'] }};">{{ strtoupper($meta['label']) }}</span></td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="6">No hay cuentas para mostrar</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td>${{ number_format($cuentas->sum('efectivo_entregado'), 2) }}</td>
                <td>${{ number_format($cuentas->sum('tarjeta'), 2) }}</td>
                <td>${{ number_format($totalVenta, 2) }}</td>
                <td>${{ number_format($cuentas->sum('diferencia'), 2) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

</body>

</html>
