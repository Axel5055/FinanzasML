<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>{{ $reporte['titulo'] }} — ML Grupo Comercial</title>
    <style>
        @page {
            margin: 28px 30px 36px;
        }

        body {
            font-family: 'Helvetica', Arial, sans-serif;
            color: #1a2038;
            font-size: 10.5px;
        }

        .brand {
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #6c63e8;
        }

        .titulo {
            font-size: 18px;
            font-weight: bold;
            margin: 2px 0 2px;
        }

        .sub {
            font-size: 10px;
            color: #6b7390;
            margin-bottom: 14px;
        }

        table.kpis {
            width: 100%;
            border-collapse: separate;
            border-spacing: 6px 0;
            margin: 0 -6px 16px;
        }

        table.kpis td {
            border: 1px solid #e3e6f2;
            border-radius: 6px;
            padding: 8px 10px;
            width: 25%;
        }

        .kpi-label {
            font-size: 8px;
            text-transform: uppercase;
            font-weight: bold;
            color: #8a90ab;
        }

        .kpi-valor {
            font-size: 13px;
            font-weight: bold;
            margin-top: 2px;
        }

        .negative { color: #cf3f55; }
        .positive { color: #23885a; }

        table.data {
            width: 100%;
            border-collapse: collapse;
        }

        table.data th {
            background-color: #1a2038;
            color: #ffffff;
            font-size: 8.5px;
            text-transform: uppercase;
            padding: 6px 7px;
            text-align: left;
        }

        table.data td {
            padding: 5px 7px;
            border-bottom: 1px solid #eceef6;
            font-size: 9.5px;
        }

        table.data tr:nth-child(even) td {
            background-color: #f8f9fd;
        }

        table.data tfoot td {
            font-weight: bold;
            background-color: #ecebfd;
            border-top: 1.5px solid #1a2038;
            border-bottom: none;
        }

        .right { text-align: right; }

        .pill {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 8px;
            font-size: 8.5px;
            font-weight: bold;
        }

        .vacio {
            text-align: center;
            color: #8a90ab;
            padding: 24px;
        }

        .pie {
            position: fixed;
            bottom: -22px;
            left: 0;
            right: 0;
            font-size: 8px;
            color: #8a90ab;
            text-align: center;
        }
    </style>
</head>

<body>
    @php
        use App\Actions\Reportes\GenerarReporte;

        $alineadoDerecha = fn (string $tipo) => in_array($tipo, ['dinero', 'numero', 'porcentaje'], true);
        $tonos = [
            'positive' => ['#e1efe5', '#23885a'],
            'negative' => ['#fbe4e8', '#cf3f55'],
            'warning' => ['#fbefd9', '#b8741a'],
            'neutral' => ['#ecebfd', '#5f56dd'],
        ];
    @endphp

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px; border-bottom: 2px solid #6c63e8;">
        <tr>
            <td style="padding-bottom: 8px;">@include('reportes._logo', ['alto' => 42])</td>
            <td style="padding-bottom: 8px; text-align: right; vertical-align: bottom;">
                <div class="brand">ML Grupo Comercial</div>
                <div style="font-size: 8.5px; color: #6b7390;">Generado el {{ \App\Actions\Fechas\DiaDeNegocio::ahora()->format('d/m/Y H:i') }}</div>
            </td>
        </tr>
    </table>
    <div class="titulo">{{ $reporte['titulo'] }}</div>
    <div class="sub">{{ $reporte['periodo'] }} · {{ $reporte['sucursal'] }}</div>

    <table class="kpis">
        <tr>
            @foreach ($reporte['resumen'] as $indicador)
                <td>
                    <div class="kpi-label">{{ $indicador['etiqueta'] }}</div>
                    <div class="kpi-valor {{ in_array($indicador['tono'], ['positive', 'negative'], true) ? $indicador['tono'] : '' }}">
                        {{ GenerarReporte::formatear($indicador['valor'], $indicador['tipo']) }}
                    </div>
                </td>
            @endforeach
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                @foreach ($reporte['columnas'] as $columna)
                    <th class="{{ $alineadoDerecha($columna['tipo']) ? 'right' : '' }}">{{ $columna['etiqueta'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($reporte['filas'] as $fila)
                <tr>
                    @foreach ($reporte['columnas'] as $columna)
                        @php $valor = $fila[$columna['clave']] ?? null; @endphp
                        <td class="{{ $alineadoDerecha($columna['tipo']) ? 'right' : '' }}">
                            @if ($columna['tipo'] === 'etiqueta')
                                @php [$fondo, $texto] = $tonos[GenerarReporte::tonoDeEtiqueta((string) $valor)]; @endphp
                                <span class="pill" style="background-color: {{ $fondo }}; color: {{ $texto }};">{{ $valor }}</span>
                            @else
                                {{ GenerarReporte::formatear($valor, $columna['tipo']) }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td class="vacio" colspan="{{ count($reporte['columnas']) }}">No hay información para el periodo seleccionado.</td>
                </tr>
            @endforelse
        </tbody>
        @if ($reporte['filas'] !== [] && $reporte['totales'] !== [])
            <tfoot>
                <tr>
                    @foreach ($reporte['columnas'] as $indice => $columna)
                        <td class="{{ $alineadoDerecha($columna['tipo']) ? 'right' : '' }}">
                            @if ($indice === 0)
                                Total
                            @elseif (array_key_exists($columna['clave'], $reporte['totales']))
                                {{ GenerarReporte::formatear($reporte['totales'][$columna['clave']], $columna['tipo']) }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>

    <div class="pie">Generado el {{ \App\Actions\Fechas\DiaDeNegocio::ahora()->format('d/m/Y H:i') }} · ML Grupo Comercial</div>
</body>

</html>
