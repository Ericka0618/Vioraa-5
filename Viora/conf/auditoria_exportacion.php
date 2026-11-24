<?php

function auditoria_pdf(array $rows, string $title): string
{
    require_once __DIR__ . '/../mantenimientos/pdf_simple.php';
    $pdf = new PdfSimple();
    $page = 1;
    $y = 0;
    $header = static function () use ($pdf, $title, &$y, &$page): void {
        $pdf->relleno(0, 0, PdfSimple::ANCHO, 104, 0.94);
        $pdf->texto(35, 31, 'VIORA  /  AUDITORÍA Y TRAZABILIDAD', 10, true);
        $pdf->texto(35, 56, $title, 17, true);
        $pdf->texto(35, 80, 'Hora de Santo Domingo  ·  Página ' . $page++, 9);
        $y = 125;
    };
    $header();
    if (!$rows) $pdf->texto(35, $y, 'Sin operaciones para los filtros aplicados.');
    foreach ($rows as $row) {
        if ($y > 755) {
            $pdf->nuevaPagina();
            $header();
        }
        $pdf->texto(35, $y, '#' . $row['id'] . '  ·  ' . auditoria_fecha($row['fecha']) . '  ·  ' . $row['estado'], 10, true);
        $summary = implode('  ·  ', [$row['modulo'], $row['descripcion'], $row['actor_nombre'] ?: $row['origen'], $row['rol'] ?? '']);
        $lines = $pdf->envolver($summary, 9, 520);
        foreach ($lines as $line) {
            $y += 12;
            $pdf->texto(35, $y, $line);
        }
        $pdf->texto(35, $y += 12, ($row['tabla'] ?? 'Evento') . ' ' . ($row['registro'] ?? '') . '   /   ' . $row['seguimiento'], 8);
        $pdf->linea(35, $y + 9, 560, $y + 9);
        $y += 25;
    }
    return $pdf->salida();
}

function auditoria_xlsx(array $rows, string $title): string
{
    if (!class_exists(ZipArchive::class)) throw new RuntimeException('La extensión ZIP es necesaria.');
    $xml = static fn($v): string => htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)$v), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $packageRel = 'http://schemas.openxmlformats.org/package/2006/relationships';
    $xmlRows = '';
    $append = static function (array $values, int $style = 0) use (&$xmlRows, $xml): void {
        $xmlRows .= '<row>';
        foreach ($values as $value) {
            $xmlRows .= '<c s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $xml($value) . '</t></is></c>';
        }
        $xmlRows .= '</row>';
    };
    $append(['VIORA · ' . $title], 1);
    $append(['ID', 'Fecha y hora (Santo Domingo)', 'Usuario', 'Rol', 'Módulo', 'Acción', 'Descripción', 'Estado', 'Tabla', 'Registro', 'Seguimiento', 'Origen', 'Referencias', 'Antes', 'Después'], 1);
    foreach ($rows as $r) {
        $append([$r['id'], auditoria_fecha($r['fecha']), $r['actor_nombre'], $r['rol'], $r['modulo'], $r['accion'],
            $r['descripcion'], $r['estado'], $r['tabla'], $r['registro'], $r['seguimiento'], $r['origen'], $r['referencias'], $r['anterior'], $r['nuevo']]);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'viora_audit_');
    if ($tmp === false) throw new RuntimeException('No se pudo preparar el archivo.');
    try {
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('No se pudo abrir el archivo.');
        $files = [
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => "<Relationships xmlns=\"$packageRel\"><Relationship Id=\"rId1\" Type=\"$rel/officeDocument\" Target=\"xl/workbook.xml\"/></Relationships>",
            'xl/workbook.xml' => "<workbook xmlns=\"$ns\" xmlns:r=\"$rel\"><sheets><sheet name=\"Auditoría\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>",
            'xl/_rels/workbook.xml.rels' => "<Relationships xmlns=\"$packageRel\"><Relationship Id=\"rId1\" Type=\"$rel/worksheet\" Target=\"worksheets/sheet1.xml\"/><Relationship Id=\"rId2\" Type=\"$rel/styles\" Target=\"styles.xml\"/></Relationships>",
            'xl/styles.xml' => "<styleSheet xmlns=\"$ns\">" . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF123F2E"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            'xl/worksheets/sheet1.xml' => "<worksheet xmlns=\"$ns\"><cols><col min=\"1\" max=\"15\" width=\"24\" customWidth=\"1\"/></cols><sheetData>$xmlRows</sheetData><autoFilter ref=\"A2:O" . (count($rows) + 2) . "\"/></worksheet>",
        ];
        foreach ($files as $path => $contents) $zip->addFromString($path, $contents);
        if (!$zip->close()) throw new RuntimeException('No se pudo guardar el archivo.');
        $output = file_get_contents($tmp);
        if ($output === false) throw new RuntimeException('No se pudo leer el archivo.');
        return $output;
    } finally {
        unlink($tmp);
    }
}
