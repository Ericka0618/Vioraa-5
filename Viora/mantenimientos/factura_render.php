<?php
require_once __DIR__ . '/ecf_service.php';
require_once __DIR__ . '/pdf_simple.php';
require_once __DIR__ . '/qr_simple.php';
function fecha_corta(?string $fecha): string
{
    if (!$fecha) return '';
    $obj = DateTime::createFromFormat('Y-m-d', substr($fecha, 0, 10));
    return $obj ? $obj->format('d/m/Y') : $fecha;
}

function dinero_pdf(float $monto): string
{
    return number_format($monto, 2, '.', ',');
}

function factura_render(array $factura, array $lineas, ?array $nota = null): string
{
$cfg = require __DIR__ . '/../conf/ecf.php';
$emisor = $cfg['emisor'];

if ($nota) {
    $lineasNota = json_decode((string)$nota['lineas_json'], true) ?: [];
    $lineas = [];
    foreach ($lineasNota as $indice => $linea) {
        $lineas[] = [
            'concepto' => 'Línea ' . ($indice + 1),
            'descripcion' => (string)$linea['descripcion'],
            'periodo' => (string)$factura['periodo'],
            'monto' => (int)$linea['centavos'] / 100,
        ];
    }

    $totalesNota = ecf_calcular_totales(array_map('intval', array_column($lineasNota, 'centavos')), $cfg['itbis_porcentaje']);
    $subtotal = $totalesNota['base'] / 100;
    $itbis = $totalesNota['itbis'] / 100;
    $total = $totalesNota['total'] / 100;
    $pagado = 0.0;
    $saldo = 0.0;

    $ecf = [
        'tipo' => 'Nota de Crédito Electrónica',
        'encf' => $nota['encf'],
        'estado' => $nota['estado'],
        'codigo' => $nota['codigo_seguridad'],
        'url' => $nota['dgii_url'],
        'fechaEtiqueta' => 'Fecha de envío',
        'fecha' => $nota['fecha_envio'],
    ];
} else {
    $subtotal = (float)$factura['subtotal'];
    $total = (float)$factura['total'];
    $itbis = round($total - $subtotal, 2);
    $pagado = (float)$factura['monto_pagado'];
    $saldo = (float)$factura['saldo_pendiente'];

    $ecf = [
        'tipo' => $factura['tipo_ecf'] === 'E31' ? 'Factura de Crédito Fiscal Electrónica' : 'Factura de Consumo Electrónica',
        'encf' => $factura['encf'],
        'estado' => $factura['ecf_estado'],
        'codigo' => $factura['codigo_seguridad'],
        'url' => $factura['dgii_url'],
        'fechaEtiqueta' => 'Fecha de firma',
        'fecha' => $factura['fecha_emision_ecf'],
    ];
}

$tieneEcf = $nota ? true : !empty($factura['encf']);

$pdf = new PdfSimple();
$margen = 40;
$derecha = 555;

$pdf->imagenJpeg(__DIR__ . '/../imagenes/viora.jpg', $margen, 10, 22.3, 28);
$pdf->texto($margen + 35, 30, 'VIORA', 18, true);
$pdf->texto($margen, 48, $emisor['RazonSocialEmisor'] ?: 'Emisor pendiente de configurar', 12, true);
$pdf->texto($margen, 66, 'RNC: ' . $emisor['RNCEmisor'], 9);
$pdf->texto($margen, 79, $emisor['DireccionEmisor'], 9);
$pdf->texto($margen, 92, 'Tel: ' . $emisor['TelefonoEmisor'] . '   Correo: ' . $emisor['CorreoEmisor'], 9);

$pdf->texto($derecha, 48, $nota ? 'NOTA DE CRÉDITO' : 'FACTURA', 15, true, 'D');
$pdf->texto($derecha, 66, ($nota ? 'Factura: ' : 'No. ') . $factura['numero_documento'], 9, true, 'D');
if ($tieneEcf) {
    $pdf->texto($derecha, 79, 'e-NCF: ' . $ecf['encf'], 9, false, 'D');
}
$pdf->texto($derecha, 92, 'Fecha: ' . fecha_corta($nota ? $nota['fecha_envio'] : $factura['fecha']) . '   Período: ' . $factura['periodo'], 9, false, 'D');

$pdf->linea($margen, 106, $derecha, 106, 0.8);

$y = 124;
$pdf->texto($margen, $y, 'CLIENTE', 9, true);
if (!empty($factura['rnc_comprador'])) {
    $pdf->texto(320, $y, 'COMPRADOR FISCAL', 9, true);
}
$y += 15;

$nombre = trim(($factura['nombres'] ?? '') . ' ' . ($factura['apellidos'] ?? ''));
$pdf->texto($margen, $y, 'Nombre: ' . ($nombre ?: '—'), 9);
$pdf->texto($margen, $y + 14, 'Cédula: ' . ($factura['cedula'] ?: '—'), 9);
$pdf->texto($margen, $y + 28, 'Teléfono: ' . ($factura['telefono'] ?: '—'), 9);
$pdf->texto($margen, $y + 42, 'Propiedad: ' . ($factura['edificio'] ?: '—') . ', Apto ' . ($factura['apto'] ?: '—'), 9);
$pdf->texto($margen, $y + 56, 'Contrato: ' . ($factura['numero_contrato'] ?: '—'), 9);

if (!empty($factura['rnc_comprador'])) {
    $pdf->texto(320, $y, 'RNC: ' . $factura['rnc_comprador'], 9);
    $razon = $pdf->envolver('Razón social: ' . $factura['razon_social_comprador'], 9, 235);
    foreach ($razon as $i => $linea) {
        $pdf->texto(320, $y + 14 + $i * 12, $linea, 9);
    }
}

$y += 80;
$pdf->rectangulo($margen, $y, $derecha - $margen, 20, 0.92);
$pdf->texto($margen + 8, $y + 13.5, 'Concepto', 9, true);
$pdf->texto(150, $y + 13.5, 'Descripción', 9, true);
$pdf->texto(400, $y + 13.5, 'Período', 9, true);
$pdf->texto($derecha - 8, $y + 13.5, 'Monto (RD$)', 9, true, 'D');
$y += 20;

foreach ($lineas as $linea) {
    $descripcion = $pdf->envolver((string)$linea['descripcion'], 9, 235);
    $alto = max(18, count($descripcion) * 12 + 6);

    $pdf->texto($margen + 8, $y + 13, (string)$linea['concepto'], 9);
    foreach ($descripcion as $i => $parte) {
        $pdf->texto(150, $y + 13 + $i * 12, $parte, 9);
    }
    $pdf->texto(400, $y + 13, (string)$linea['periodo'], 9);
    $pdf->texto($derecha - 8, $y + 13, dinero_pdf((float)$linea['monto']), 9, false, 'D');

    $y += $alto;
    $pdf->linea($margen, $y, $derecha, $y, 0.3);
}

$y += 18;
$etiquetaX = 430;
$valorX = $derecha - 8;
$resumen = [
    ['Subtotal', dinero_pdf($subtotal), false],
    ['ITBIS', dinero_pdf($itbis), false],
    ['Total', dinero_pdf($total), true],
    ['Monto pagado', dinero_pdf($pagado), false],
    ['Saldo pendiente', dinero_pdf($saldo), false],
];
if ($nota) {
    $resumen = array_slice($resumen, 0, 3);
}

foreach ($resumen as [$etiqueta, $valor, $negrita]) {
    $pdf->texto($etiquetaX, $y, $etiqueta, 9, $negrita, 'D');
    $pdf->texto($valorX, $y, 'RD$ ' . $valor, 9, $negrita, 'D');
    $y += 16;
}

$y += 10;
$pdf->linea($margen, $y, $derecha, $y, 0.5);
$y += 14;

if ($tieneEcf && $y + 200 > 780) {
    $pdf->nuevaPagina();
    $y = 48;
}

if ($tieneEcf) {
    $pdf->texto($margen, $y, 'COMPROBANTE FISCAL ELECTRÓNICO (e-CF)', 9, true);
    $pdf->texto($derecha, $y, $ecf['tipo'], 9, false, 'D');
    $y += 18;

    $qrTop = $y;
    $url = (string)($ecf['url'] ?? '');
    $ambiente = str_contains($url, '/testecf/') ? 'Pruebas' : 'Producción';

    $seccion = function (string $titulo) use ($pdf, $margen, &$y): void {
        $pdf->texto($margen, $y, $titulo, 8.5, true);
        $y += 14;
    };
    $campo = function (string $etiqueta, string $valor) use ($pdf, $margen, &$y): void {
        $lineas = $pdf->envolver($valor, 8.5, 260);
        $pdf->texto($margen + 8, $y, $etiqueta, 8.5);
        foreach ($lineas as $i => $parte) {
            $pdf->texto($margen + 120, $y + $i * 11, $parte, 8.5);
        }
        $y += count($lineas) * 11 + 3;
    };

    $seccion('INFORMACION DEL DOCUMENTO');
    $campo('eNCF', (string)$ecf['encf']);
    $campo('Tipo', $ecf['tipo']);
    $campo('Estado', (string)($ecf['estado'] ?: '—'));
    $campo('Ambiente', $ambiente);
    $campo('Origen', 'Api');
    $y += 6;

    if ($nota) {
        $seccion('DOCUMENTO MODIFICADO');
        $campo('eNCF modificado', (string)$nota['encf_modificado']);
        $campo('Código modificación', (int)$nota['codigo_modificacion'] === 1 ? '1 · Anulación total' : '3 · Corrección de montos');
        $y += 6;
    }

    $seccion('RESPUESTA DGII');
    $campo('Cód. seguridad', (string)($ecf['codigo'] ?: '—'));
    if ($url !== '') {
        $campo('URL DGII', $url);
    }
    $y += 6;

    $seccion('FECHAS');
    $campo($ecf['fechaEtiqueta'], fecha_corta($ecf['fecha']) ?: '—');

    if ($url !== '') {
        try {
            $matriz = QrSimple::matriz($url);
            $n = count($matriz);
            $modulo = 2.1;
            $origenX = $derecha - ($n + 8) * $modulo;

            foreach ($matriz as $fila => $columnas) {
                $c = 0;
                while ($c < $n) {
                    if (!$columnas[$c]) {
                        $c++;
                        continue;
                    }
                    $inicio = $c;
                    while ($c < $n && $columnas[$c]) {
                        $c++;
                    }
                    $pdf->relleno($origenX + (4 + $inicio) * $modulo, $qrTop + (4 + $fila) * $modulo, ($c - $inicio) * $modulo, $modulo);
                }
            }

            $y = max($y, $qrTop + ($n + 8) * $modulo) + 6;
        } catch (Throwable $error) {
            error_log('factura_pdf QR: ' . $error->getMessage());
        }
    }
} else {
    $pdf->texto($margen, $y, 'Esta factura no tiene comprobante fiscal electrónico registrado.', 9);
}

if (!empty($factura['observaciones'])) {
    $y += 24;
    $pdf->texto($margen, $y, 'Observaciones:', 9, true);
    foreach ($pdf->envolver((string)$factura['observaciones'], 9, 515) as $i => $parte) {
        $pdf->texto($margen, $y + 14 + $i * 12, $parte, 9);
    }
}

$pdf->texto(297.6, 800, 'Factura registrada en VIORA el ' . (new DateTime($factura['fecha_registro'], new DateTimeZone('America/Santo_Domingo')))->format('d/m/Y H:i'), 7.5, false, 'C');

return $pdf->salida();
}
