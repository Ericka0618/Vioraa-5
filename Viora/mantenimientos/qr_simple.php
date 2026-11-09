<?php
// Generador de códigos QR sin dependencias: modo byte, nivel de corrección M, versiones 1 a 10.
final class QrSimple
{
    private const ECC_M = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
    private const BLOQUES_M = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
    private const VERSION_MAX = 10;

    private int $version;
    private int $n;
    private array $modulos = [];
    private array $funcion = [];

    private function __construct(int $version)
    {
        $this->version = $version;
        $this->n = $version * 4 + 17;
        $this->modulos = array_fill(0, $this->n, array_fill(0, $this->n, false));
        $this->funcion = array_fill(0, $this->n, array_fill(0, $this->n, false));
    }

    /** Devuelve la matriz de módulos [fila][columna], true = oscuro (sin zona silenciosa). */
    public static function matriz(string $texto): array
    {
        $bytes = array_map('ord', str_split($texto));

        $version = null;
        for ($v = 1; $v <= self::VERSION_MAX; $v++) {
            $bitsCantidad = $v <= 9 ? 8 : 16;
            if (4 + $bitsCantidad + count($bytes) * 8 <= self::datosPorCodigo($v) * 8) {
                $version = $v;
                break;
            }
        }
        if ($version === null) {
            throw new RuntimeException('El texto es demasiado largo para el código QR.');
        }

        $qr = new self($version);
        $qr->dibujarFunciones();
        $qr->colocarDatos(self::agregarCorreccion(self::codificarDatos($bytes, $version), $version));

        $mejorMascara = 0;
        $mejorPenalidad = PHP_INT_MAX;
        for ($mascara = 0; $mascara < 8; $mascara++) {
            $copia = clone $qr;
            $copia->aplicarMascara($mascara);
            $copia->dibujarFormato($mascara);
            $penalidad = $copia->penalidad();
            if ($penalidad < $mejorPenalidad) {
                $mejorPenalidad = $penalidad;
                $mejorMascara = $mascara;
            }
        }

        $qr->aplicarMascara($mejorMascara);
        $qr->dibujarFormato($mejorMascara);

        return $qr->modulos;
    }

    private static function modulosDatos(int $v): int
    {
        $r = (16 * $v + 128) * $v + 64;
        if ($v >= 2) {
            $alin = intdiv($v, 7) + 2;
            $r -= (25 * $alin - 10) * $alin - 55;
        }
        if ($v >= 7) {
            $r -= 36;
        }
        return $r;
    }

    private static function datosPorCodigo(int $v): int
    {
        return intdiv(self::modulosDatos($v), 8) - self::ECC_M[$v] * self::BLOQUES_M[$v];
    }

    private static function codificarDatos(array $bytes, int $v): array
    {
        $cantidadBits = $v <= 9 ? 8 : 16;
        $capacidad = self::datosPorCodigo($v) * 8;

        $bits = '0100' . str_pad(decbin(count($bytes)), $cantidadBits, '0', STR_PAD_LEFT);
        foreach ($bytes as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $capacidad - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);

        $salida = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $salida[] = bindec(substr($bits, $i, 8));
        }
        for ($relleno = 0xEC; count($salida) < $capacidad / 8; $relleno = $relleno === 0xEC ? 0x11 : 0xEC) {
            $salida[] = $relleno;
        }

        return $salida;
    }

    private static function agregarCorreccion(array $datos, int $v): array
    {
        $bloques = self::BLOQUES_M[$v];
        $ecc = self::ECC_M[$v];
        $total = intdiv(self::modulosDatos($v), 8);
        $cortos = $bloques - $total % $bloques;
        $longitudCorta = intdiv($total, $bloques);
        $divisor = self::divisor($ecc);

        $bloquesDatos = [];
        $bloquesEcc = [];
        $k = 0;
        for ($i = 0; $i < $bloques; $i++) {
            $largo = $longitudCorta - $ecc + ($i < $cortos ? 0 : 1);
            $bloque = array_slice($datos, $k, $largo);
            $k += $largo;
            $bloquesDatos[] = $bloque;
            $bloquesEcc[] = self::resto($bloque, $divisor);
        }

        $resultado = [];
        $maximo = max(array_map('count', $bloquesDatos));
        for ($i = 0; $i < $maximo; $i++) {
            foreach ($bloquesDatos as $bloque) {
                if ($i < count($bloque)) {
                    $resultado[] = $bloque[$i];
                }
            }
        }
        for ($i = 0; $i < $ecc; $i++) {
            foreach ($bloquesEcc as $bloque) {
                $resultado[] = $bloque[$i];
            }
        }

        return $resultado;
    }

    private static function divisor(int $grado): array
    {
        $r = array_fill(0, $grado, 0);
        $r[$grado - 1] = 1;
        $raiz = 1;

        for ($i = 0; $i < $grado; $i++) {
            for ($j = 0; $j < $grado; $j++) {
                $r[$j] = self::multiplicar($r[$j], $raiz);
                if ($j + 1 < $grado) {
                    $r[$j] ^= $r[$j + 1];
                }
            }
            $raiz = self::multiplicar($raiz, 2);
        }

        return $r;
    }

    private static function resto(array $datos, array $divisor): array
    {
        $n = count($divisor);
        $r = array_fill(0, $n, 0);

        foreach ($datos as $byte) {
            $factor = $byte ^ $r[0];
            array_shift($r);
            $r[] = 0;
            for ($i = 0; $i < $n; $i++) {
                $r[$i] ^= self::multiplicar($divisor[$i], $factor);
            }
        }

        return $r;
    }

    private static function multiplicar(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    private function dibujarFunciones(): void
    {
        for ($i = 0; $i < $this->n; $i++) {
            $this->fijar(6, $i, $i % 2 === 0);
            $this->fijar($i, 6, $i % 2 === 0);
        }

        $this->buscador(3, 3);
        $this->buscador($this->n - 4, 3);
        $this->buscador(3, $this->n - 4);

        $posiciones = $this->posicionesAlineacion();
        $ultimo = count($posiciones) - 1;
        foreach ($posiciones as $i => $x) {
            foreach ($posiciones as $j => $y) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $ultimo) || ($i === $ultimo && $j === 0)) {
                    continue;
                }
                $this->alineacion($x, $y);
            }
        }

        $this->dibujarFormato(0);
        if ($this->version >= 7) {
            $this->dibujarVersion();
        }
    }

    private function fijar(int $x, int $y, bool $oscuro): void
    {
        $this->modulos[$y][$x] = $oscuro;
        $this->funcion[$y][$x] = true;
    }

    private function buscador(int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x < 0 || $x >= $this->n || $y < 0 || $y >= $this->n) {
                    continue;
                }
                $distancia = max(abs($dx), abs($dy));
                $this->fijar($x, $y, $distancia !== 2 && $distancia !== 4);
            }
        }
    }

    private function alineacion(int $cx, int $cy): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->fijar($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    private function posicionesAlineacion(): array
    {
        if ($this->version === 1) {
            return [];
        }

        $cantidad = intdiv($this->version, 7) + 2;
        $paso = $this->version === 32
            ? 26
            : intdiv($this->version * 4 + $cantidad * 2 + 1, $cantidad * 2 - 2) * 2;

        $posiciones = array_fill(0, $cantidad, 0);
        $posiciones[0] = 6;
        for ($i = $cantidad - 1, $pos = $this->n - 7; $i >= 1; $i--, $pos -= $paso) {
            $posiciones[$i] = $pos;
        }

        return $posiciones;
    }

    private function dibujarFormato(int $mascara): void
    {
        $datos = $mascara;
        $resto = $datos;
        for ($i = 0; $i < 10; $i++) {
            $resto = ($resto << 1) ^ (($resto >> 9) * 0x537);
        }
        $bits = (($datos << 10) | $resto) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            $this->fijar(8, $i, $bit($i));
        }
        $this->fijar(8, 7, $bit(6));
        $this->fijar(8, 8, $bit(7));
        $this->fijar(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->fijar(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->fijar($this->n - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->fijar(8, $this->n - 15 + $i, $bit($i));
        }
        $this->fijar(8, $this->n - 8, true);
    }

    private function dibujarVersion(): void
    {
        $resto = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $resto = ($resto << 1) ^ (($resto >> 11) * 0x1F25);
        }
        $bits = ($this->version << 12) | $resto;

        for ($i = 0; $i < 18; $i++) {
            $oscuro = (($bits >> $i) & 1) === 1;
            $a = $this->n - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->fijar($a, $b, $oscuro);
            $this->fijar($b, $a, $oscuro);
        }
    }

    private function colocarDatos(array $codigo): void
    {
        $bits = [];
        foreach ($codigo as $byte) {
            for ($b = 7; $b >= 0; $b--) {
                $bits[] = ($byte >> $b) & 1;
            }
        }

        $i = 0;
        for ($derecha = $this->n - 1; $derecha >= 1; $derecha -= 2) {
            if ($derecha === 6) {
                $derecha = 5;
            }
            for ($vertical = 0; $vertical < $this->n; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $derecha - $j;
                    $subiendo = (($derecha + 1) & 2) === 0;
                    $y = $subiendo ? $this->n - 1 - $vertical : $vertical;
                    if (!$this->funcion[$y][$x] && $i < count($bits)) {
                        $this->modulos[$y][$x] = $bits[$i] === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function aplicarMascara(int $mascara): void
    {
        for ($y = 0; $y < $this->n; $y++) {
            for ($x = 0; $x < $this->n; $x++) {
                if (!$this->funcion[$y][$x] && self::enMascara($mascara, $x, $y)) {
                    $this->modulos[$y][$x] = !$this->modulos[$y][$x];
                }
            }
        }
    }

    private static function enMascara(int $mascara, int $x, int $y): bool
    {
        return match ($mascara) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
            6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
            default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
        };
    }

    private function penalidad(): int
    {
        $m = $this->modulos;
        $n = $this->n;
        $penalidad = 0;

        for ($k = 0; $k < $n; $k++) {
            $penalidad += self::penalidadLinea($m[$k]) + self::penalidadLinea(array_column($m, $k));
        }

        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $penalidad += 3;
                }
            }
        }

        $oscuros = 0;
        foreach ($m as $fila) {
            foreach ($fila as $c) {
                if ($c) {
                    $oscuros++;
                }
            }
        }
        $total = $n * $n;
        $penalidad += (intdiv(abs($oscuros * 20 - $total * 10) + $total - 1, $total) - 1) * 10;

        return $penalidad;
    }

    private static function penalidadLinea(array $linea): int
    {
        $penalidad = 0;
        $racha = 1;
        $n = count($linea);

        for ($i = 1; $i < $n; $i++) {
            if ($linea[$i] === $linea[$i - 1]) {
                $racha++;
                if ($racha === 5) {
                    $penalidad += 3;
                } elseif ($racha > 5) {
                    $penalidad += 1;
                }
            } else {
                $racha = 1;
            }
        }

        $patron1 = [true, false, true, true, true, false, true, false, false, false, false];
        $patron2 = [false, false, false, false, true, false, true, true, true, false, true];
        for ($i = 0; $i + 11 <= $n; $i++) {
            $coincide1 = true;
            $coincide2 = true;
            for ($j = 0; $j < 11; $j++) {
                if ($linea[$i + $j] !== $patron1[$j]) {
                    $coincide1 = false;
                }
                if ($linea[$i + $j] !== $patron2[$j]) {
                    $coincide2 = false;
                }
            }
            if ($coincide1 || $coincide2) {
                $penalidad += 40;
            }
        }

        return $penalidad;
    }
}
