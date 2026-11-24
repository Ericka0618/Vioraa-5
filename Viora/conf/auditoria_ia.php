<?php

function auditoria_plan_gemini(string $question, array $input): array
{
    $config = require __DIR__ . '/gemini.php';
    if (!$config['api_key']) throw new RuntimeException('Gemini no está configurado.');
    if ($question === '' || mb_strlen($question) > $config['max_caracteres_mensaje']) {
        throw new InvalidArgumentException('Escriba una pregunta de hasta 2000 caracteres.');
    }
    $today = new DateTimeImmutable('now', new DateTimeZone('America/Santo_Domingo'));
    $keys = ['desde', 'hasta', 'actor_id', 'rol', 'modulo', 'accion', 'estado', 'seguimiento', 'q'];
    $keys = array_merge($keys, array_keys(auditoria_entidades()));
    $properties = array_fill_keys($keys, ['type' => 'STRING']);
    $properties['reporte'] = ['type' => 'INTEGER'];
    $properties['entidad'] = ['type' => 'STRING', 'enum' => array_keys(auditoria_entidades())];
    $properties['referencia'] = ['type' => 'STRING'];
    $reports = array_map(static fn($report) => $report[0], auditoria_reportes());
    $modules = array_values(array_unique(array_merge(['Autenticación', 'Seguridad', 'Auditoría', 'Cuentas por cobrar'],
        array_column(auditoria_catalogo(), 0))));
    $instruction = 'Traduce preguntas sobre auditoría de VIORA a filtros de solo lectura. '
        . 'Devuelve únicamente filtros que la pregunta justifique. Fechas inclusivas YYYY-MM-DD. '
        . 'Usa entidad y referencia conjuntamente para solicitar una línea de tiempo con ID explícito o seleccionado. '
        . 'Nunca inventes IDs. Usa q solo para texto específico buscable como el nombre de una persona usuaria. '
        . 'Acciones: Crear, Modificar, Eliminar, Inicio de sesión, Cierre de sesión, Consultar, Procesar. '
        . 'Estados: Exitosa, Fallida. Reportes y módulos autorizados: '
        . json_encode([$reports, $modules], JSON_UNESCAPED_UNICODE)
        . '. Hoy en Santo Domingo es ' . $today->format('Y-m-d') . '.';
    // Only the submitted question and selected entity identifiers are sent.
    $context = array_intersect_key($input, array_flip(array_merge(array_keys(auditoria_entidades()), ['entidad', 'referencia'])));
    $request = [
        'system_instruction' => ['parts' => [['text' => $instruction]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => json_encode(['pregunta' => $question, 'seleccion' => $context], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]]],
        'generationConfig' => [
            'temperature' => 0,
            'responseMimeType' => 'application/json',
            'responseSchema' => ['type' => 'OBJECT', 'properties' => $properties],
        ],
    ];
    $ch = curl_init($config['base_url'] . rawurlencode($config['model']) . ':generateContent');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $config['timeout'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $config['api_key']],
        CURLOPT_POSTFIELDS => json_encode($request, JSON_THROW_ON_ERROR),
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $transport = curl_errno($ch);
    curl_close($ch);
    if (!is_string($raw) || $status !== 200) {
        error_log("VIORA auditoría Gemini: HTTP $status, transporte $transport");
        throw new RuntimeException('Gemini no pudo interpretar la consulta.');
    }
    $response = json_decode($raw, true);
    $plan = json_decode($response['candidates'][0]['content']['parts'][0]['text'] ?? '', true);
    if (!is_array($plan)) throw new RuntimeException('Gemini no devolvió filtros válidos.');
    $plan = array_filter(array_intersect_key($plan, $properties), static fn($v) => is_string($v) || is_int($v));
    if (!$plan) throw new InvalidArgumentException('Indique un módulo, período, tipo de operación o identificador.');
    return $plan;
}
