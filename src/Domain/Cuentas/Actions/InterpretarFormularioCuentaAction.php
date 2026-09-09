<?php

namespace Domain\Cuentas\Actions;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class InterpretarFormularioCuentaAction
{
    /**
     * @param  array<int, array{contenido: string, mime_type: string}>  $imagenes  una entrada por cada foto/hoja
     * @param  array<int, string>  $catalogoProductos  producto_id => nombre
     * @return array<string, mixed>
     */
    public function __invoke(array $imagenes, array $catalogoProductos): array
    {
        if (empty($imagenes)) {
            throw new RuntimeException('No se recibió ninguna foto para analizar.');
        }

        $apiKey = config('services.gemini.key');

        if (blank($apiKey)) {
            throw new RuntimeException('No se configuró GEMINI_API_KEY en el sistema.');
        }

        $modelos = array_filter([
            config('services.gemini.model', 'gemini-flash-latest'),
            config('services.gemini.fallback_model', 'gemini-flash-lite-latest'),
        ]);

        $response = $this->llamarConReintentos($apiKey, array_values(array_unique($modelos)), $catalogoProductos, $imagenes);

        $response = $response->json();

        $texto = data_get($response, 'candidates.0.content.parts.0.text');

        if (blank($texto)) {
            throw new RuntimeException('La IA no devolvió una respuesta interpretable.');
        }

        $datos = json_decode($texto, true);

        if (! is_array($datos)) {
            throw new RuntimeException('La respuesta de la IA no es un JSON válido.');
        }

        return $datos;
    }

    /**
     * @param  array<int, string>  $modelos  modelos a probar en orden; se pasa al siguiente si el actual
     *                                       está saturado (429/503) tras agotar sus reintentos
     * @param  array<int, string>  $catalogoProductos
     * @param  array<int, array{contenido: string, mime_type: string}>  $imagenes
     */
    private function llamarConReintentos(string $apiKey, array $modelos, array $catalogoProductos, array $imagenes): Response
    {
        $intentosPorModelo = 2;
        $statusReintentables = [429, 503];

        $partesImagenes = array_map(fn (array $imagen) => [
            'inline_data' => [
                'mime_type' => $imagen['mime_type'],
                'data' => base64_encode($imagen['contenido']),
            ],
        ], $imagenes);

        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $this->prompt($catalogoProductos, count($imagenes))],
                    ...$partesImagenes,
                ],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->schema(),
                'temperature' => 0,
            ],
        ];

        foreach ($modelos as $modelo) {
            for ($intento = 1; $intento <= $intentosPorModelo; $intento++) {
                $response = Http::timeout(90)
                    ->withHeaders(['x-goog-api-key' => $apiKey])
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$modelo}:generateContent", $payload);

                if ($response->successful()) {
                    return $response;
                }

                $reintentable = in_array($response->status(), $statusReintentables, true);

                if (! $reintentable) {
                    $response->throw();
                }

                if ($intento < $intentosPorModelo) {
                    usleep(700_000 * $intento);
                }
            }
        }

        throw new RuntimeException('El servicio de IA está saturado en este momento. Intenta de nuevo en unos segundos.');
    }

    private function schema(): array
    {
        $itemSchema = [
            'type' => 'OBJECT',
            'properties' => [
                'producto_id' => ['type' => 'INTEGER', 'nullable' => true],
                'nombre_detectado' => ['type' => 'STRING'],
                'cantidad' => ['type' => 'NUMBER'],
            ],
            'required' => ['nombre_detectado', 'cantidad'],
        ];

        $conceptoSchema = [
            'type' => 'OBJECT',
            'properties' => [
                'concepto' => ['type' => 'STRING'],
                'precio' => ['type' => 'NUMBER'],
            ],
            'required' => ['concepto', 'precio'],
        ];

        return [
            'type' => 'OBJECT',
            'properties' => [
                'fecha_venta' => ['type' => 'STRING', 'nullable' => true, 'description' => 'Formato YYYY-MM-DD'],
                'sucursal_detectada' => ['type' => 'STRING', 'nullable' => true],
                'entradas' => ['type' => 'ARRAY', 'items' => $itemSchema],
                'salidas' => ['type' => 'ARRAY', 'items' => $itemSchema],
                'sobrantes' => ['type' => 'ARRAY', 'items' => $itemSchema],
                'gastos' => ['type' => 'ARRAY', 'items' => $conceptoSchema],
                'mermas' => ['type' => 'ARRAY', 'items' => $conceptoSchema],
                'efectivo_entregado' => ['type' => 'NUMBER', 'nullable' => true],
                'tarjeta' => ['type' => 'NUMBER', 'nullable' => true],
                'observaciones' => ['type' => 'STRING', 'nullable' => true],
            ],
            'required' => ['entradas', 'salidas', 'sobrantes', 'gastos', 'mermas'],
        ];
    }

    /**
     * @param  array<int, string>  $catalogoProductos
     */
    private function prompt(array $catalogoProductos, int $totalImagenes): string
    {
        $catalogoTexto = collect($catalogoProductos)
            ->map(fn ($nombre, $id) => "{$id}: {$nombre}")
            ->implode("\n");

        $notaMultiplesFotos = $totalImagenes > 1
            ? "Vas a recibir {$totalImagenes} fotos, una por cada hoja impresa del mismo formulario, en el orden en que fueron subidas (no necesariamente en el mismo orden que las secciones numeradas más abajo, ya que una hoja impresa puede cortar una sección a la mitad). Trata todas las fotos como un solo formulario: combina lo que encuentres en cada una en un único resultado, sin duplicar ni repetir un mismo renglón si por error aparece fotografiado dos veces."
            : 'Vas a recibir una sola foto con el formulario completo.';

        return <<<PROMPT
        Eres un asistente que digitaliza el "Formulario de captura diaria" de una pollería, llenado a mano por
        un capturista a partir de una plantilla impresa por el propio sistema. {$notaMultiplesFotos}

        La plantilla tiene siempre estas 7 secciones numeradas, en este orden:

        1. ENTRADA Y SOBRANTE POR PRODUCTO — POLLO: una tabla con el nombre del producto ya impreso en cada
           fila, y dos casillas en blanco por fila: "Entrada (Kg)" y "Sobrante (Kg)".
        2. ENTRADA Y SOBRANTE POR PRODUCTO — MARINADO / OTROS: misma estructura que la sección 1, con los
           productos de la otra categoría.
        3. SALIDAS A OTRA SUCURSAL: tabla en blanco con columnas Producto, Cantidad y Sucursal destino
           (el nombre de la sucursal destino debe coincidir con uno de los nombres de referencia impresos al
           pie de esta sección).
        4. GASTOS: tabla en blanco con columnas Concepto y Monto $.
        5. MERMA: tabla en blanco con columnas Concepto y Monto $.
        6. CIERRE DE CAJA: tres recuadros para escribir un monto: "Efectivo entregado", "Pago con tarjeta" y
           "Total en ticket / caja".
        7. OBSERVACIONES: líneas en blanco para notas libres.

        Como el nombre de cada producto en las secciones 1 y 2 ya viene impreso (no manuscrito), asocia cada
        fila con el "producto_id" del catálogo cuyo nombre coincida EXACTAMENTE con el texto impreso de esa
        fila; solo usa null si la fila está vacía o no puedes leer el número escrito junto a ella.

        Catálogo de productos del sistema, en el mismo orden en que aparecen impresos en las secciones 1 y 2
        (id: nombre):
        {$catalogoTexto}

        Reglas:
        - Ignora casillas vacías o completamente ilegibles; no inventes valores.
        - Los números pueden tener coma o punto decimal; normalízalos a notación decimal con punto.
        - Si la foto no sigue exactamente este formato (por ejemplo, es una hoja anterior llenada a mano libre),
          interpreta lo que puedas de la misma manera, ubicando cada dato en la sección que le corresponda por
          significado.
        - Todo lo que no puedas clasificar en las secciones anteriores va en "observaciones" como texto libre.
        - Responde únicamente con el JSON solicitado, sin explicaciones.
        PROMPT;
    }
}
