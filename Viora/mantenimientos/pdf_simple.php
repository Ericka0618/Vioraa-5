<?php
// Generador de PDF mínimo sin dependencias: texto y líneas con fuentes Helvetica estándar (WinAnsi).
class PdfSimple
{
    const ANCHO = 595.28;
    const ALTO = 841.89;

    private array $paginas = [''];

    public function nuevaPagina(): void
    {
        $this->paginas[] = '';
    }

    public function texto(float $x, float $y, string $texto, float $size = 9, bool $negrita = false, string $alineacion = 'I'): void
    {
        $ancho = $this->anchoTexto($texto, $size, $negrita);

        if ($alineacion === 'D') {
            $x -= $ancho;
        } elseif ($alineacion === 'C') {
            $x -= $ancho / 2;
        }

        $fuente = $negrita ? 'F2' : 'F1';
        $this->agregar(sprintf(
            'BT /%s %s Tf %s %s Td (%s) Tj ET',
            $fuente,
            $this->n($size),
            $this->n($x),
            $this->n(self::ALTO - $y),
            $this->escapar($texto)
        ));
    }

    public function linea(float $x1, float $y1, float $x2, float $y2, float $grosor = 0.5): void
    {
        $this->agregar(sprintf(
            '%s w %s %s m %s %s l S',
            $this->n($grosor),
            $this->n($x1),
            $this->n(self::ALTO - $y1),
            $this->n($x2),
            $this->n(self::ALTO - $y2)
        ));
    }

    public function rectangulo(float $x, float $y, float $ancho, float $alto, ?float $gris = null): void
    {
        $base = $this->n(self::ALTO - $y - $alto);
        $op = sprintf('%s %s %s %s re', $this->n($x), $base, $this->n($ancho), $this->n($alto));

        if ($gris !== null) {
            $this->agregar(sprintf('%s g %s f 0 g', $this->n($gris), $op));
        }

        $this->agregar('0.5 w ' . $op . ' S');
    }

    public function relleno(float $x, float $y, float $ancho, float $alto, float $gris = 0.0): void
    {
        $this->agregar(sprintf(
            '%s g %s %s %s %s re f 0 g',
            $this->n($gris),
            $this->n($x),
            $this->n(self::ALTO - $y - $alto),
            $this->n($ancho),
            $this->n($alto)
        ));
    }

    public function envolver(string $texto, float $size, float $maxAncho, bool $negrita = false): array
    {
        $palabras = [];

        foreach (preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $bruta) {
            $trozo = '';

            foreach (mb_str_split($bruta) as $caracter) {
                if ($trozo !== '' && $this->anchoTexto($trozo . $caracter, $size, $negrita) > $maxAncho) {
                    $palabras[] = $trozo;
                    $trozo = '';
                }
                $trozo .= $caracter;
            }

            if ($trozo !== '') {
                $palabras[] = $trozo;
            }
        }

        $lineas = [];
        $actual = '';

        foreach ($palabras as $palabra) {
            $candidata = $actual === '' ? $palabra : $actual . ' ' . $palabra;

            if ($actual !== '' && $this->anchoTexto($candidata, $size, $negrita) > $maxAncho) {
                $lineas[] = $actual;
                $actual = $palabra;
            } else {
                $actual = $candidata;
            }
        }

        if ($actual !== '') {
            $lineas[] = $actual;
        }

        return $lineas ?: [''];
    }

    public function anchoTexto(string $texto, float $size, bool $negrita = false): float
    {
        $total = 0;

        foreach (str_split($this->aCp1252($texto)) as $caracter) {
            $total += $this->anchoCaracter($caracter, $negrita);
        }

        return $total * $size / 1000;
    }

    public function salida(): string
    {
        $cantidad = count($this->paginas);
        $objetos = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];

        $hijas = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $hijas[] = (5 + 2 * $i) . ' 0 R';
        }
        $objetos[2] = '<< /Type /Pages /Kids [' . implode(' ', $hijas) . '] /Count ' . $cantidad . ' >>';

        foreach ($this->paginas as $i => $contenido) {
            $objetos[5 + 2 * $i] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $this->n(self::ANCHO),
                $this->n(self::ALTO),
                6 + 2 * $i
            );
            $objetos[6 + 2 * $i] = '<< /Length ' . strlen($contenido) . " >>\nstream\n" . $contenido . 'endstream';
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objetos as $numero => $cuerpo) {
            $offsets[$numero] = strlen($pdf);
            $pdf .= $numero . " 0 obj\n" . $cuerpo . "\nendobj\n";
        }

        $total = count($objetos) + 1;
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . $total . "\n0000000000 65535 f \n";

        for ($i = 1; $i < $total; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size " . $total . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

        return $pdf;
    }

    private function agregar(string $operacion): void
    {
        $ultima = count($this->paginas) - 1;
        $this->paginas[$ultima] .= $operacion . "\n";
    }

    private function aCp1252(string $texto): string
    {
        return mb_convert_encoding(str_replace(["\r", "\n"], ' ', $texto), 'Windows-1252', 'UTF-8');
    }

    private function escapar(string $texto): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $this->aCp1252($texto));
    }

    private function anchoCaracter(string $caracter, bool $negrita): int
    {
        if (ctype_digit($caracter)) {
            return 556;
        }

        $tabla = [
            ' ' => 278, '.' => 278, ',' => 278, ':' => 278, '/' => 278, '-' => 333,
            '$' => 556, '(' => 333, ')' => 333, 'i' => 222, 'l' => 222, 'I' => 278,
            't' => 278, 'f' => 278, 'r' => 333, 'm' => 833, 'M' => 833, 'W' => 944,
        ];

        return $tabla[$caracter] ?? ($negrita ? 611 : 556);
    }

    private function n(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, '.', ''), '0'), '.') ?: '0';
    }
}
