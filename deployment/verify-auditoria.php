<?php
// Verify the row seals and reference index. Archive the JSON output outside the database.
if (PHP_SAPI !== 'cli' || empty($argv[1]) || getenv('DB_NAME') !== $argv[1]) exit(2);
require __DIR__ . '/../Viora/conf/conexion.php';

$previous = isset($argv[2]) ? json_decode((string)file_get_contents($argv[2]), true, 10, JSON_THROW_ON_ERROR) : null;
if (isset($argv[2]) && (!is_array($previous) || ($previous['base'] ?? null) !== $argv[1]
    || !is_int($previous['hasta'] ?? null) || !is_int($previous['filas'] ?? null) || !is_string($previous['sha256'] ?? null))) {
    throw new InvalidArgumentException('Punto de control inválido.');
}

$conexion->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$conexion->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
$last = 0;
$count = 0;
$oldCount = 0;
$hash = hash_init('sha256');
$oldHash = hash_init('sha256');

do {
    $rows = $conexion->execute_query('SELECT id,evento,huella,referencias,huella=' . auditoria_huella_sql()
        . ' AS integra FROM auditoria WHERE id>? ORDER BY id LIMIT 500', [$last])->fetch_all(MYSQLI_ASSOC);
    if (!$rows) break;
    $end = (int)$rows[count($rows) - 1]['id'];
    $actualReferences = [];
    $refRows = $conexion->execute_query('SELECT auditoria_id,tipo,valor FROM auditoria_referencia
        WHERE auditoria_id>? AND auditoria_id<=?', [$last, $end]);
    while ($ref = $refRows->fetch_assoc()) {
        $actualReferences[$ref['auditoria_id']][json_encode([$ref['tipo'],$ref['valor']], JSON_THROW_ON_ERROR)] = true;
    }
    foreach ($rows as $row) {
        $last = (int)$row['id'];
        $expected = [];
        foreach (json_decode($row['referencias'], true, 20, JSON_THROW_ON_ERROR) as [$type, $value]) {
            if (is_array($value)) $value = json_encode($value, JSON_THROW_ON_ERROR);
            if (!in_array((string)$value, ['','0','null'], true)) {
                $expected[json_encode([$type,(string)$value], JSON_THROW_ON_ERROR)] = true;
            }
        }
        $actual = $actualReferences[$last] ?? [];
        if (!$row['integra'] || array_diff_key($expected, $actual) || array_diff_key($actual, $expected)) {
            fwrite(STDERR, "Integridad inválida en evento $last\n");
            exit(1);
        }
        $line = "$last {$row['evento']} {$row['huella']}\n";
        hash_update($hash, $line);
        $count++;
        if ($previous && $last <= $previous['hasta']) {
            hash_update($oldHash, $line);
            $oldCount++;
        }
    }
} while (count($rows) === 500);

if ($previous && ($oldCount !== $previous['filas'] || !hash_equals($previous['sha256'], hash_final($oldHash)))) {
    fwrite(STDERR, "El historial no coincide con el punto de control archivado.\n");
    exit(1);
}
$conexion->commit();
echo json_encode(['base'=>$argv[1], 'hasta'=>$last, 'filas'=>$count, 'sha256'=>hash_final($hash)], JSON_THROW_ON_ERROR) . PHP_EOL;
