@php
    $archivoLogo = public_path('img/logo-pdf.png');
@endphp
@if (is_file($archivoLogo))
    <img src="data:image/png;base64,{{ base64_encode(file_get_contents($archivoLogo)) }}" alt="ML Grupo Comercial" style="height: {{ $alto ?? 40 }}px; width: auto;">
@else
    <span style="font-size: 14px; font-weight: bold;">ML Grupo Comercial</span>
@endif
