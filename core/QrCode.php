<?php

declare(strict_types=1);

namespace Esse;

// Eigener QR-Code-Encoder (ISO/IEC 18004) — reines PHP, keine externe Bibliothek.
// Bewusst auf Byte-Modus und Versionen 1–10 begrenzt: reicht für otpauth://-URIs
// (typischerweise 80–150 Zeichen) bei Fehlerkorrekturlevel M, ohne Kanji-/
// Alphanumerik-Modus oder größere Versionen implementieren zu müssen.
class QrCode
{
    private const EC_BITS = ['L' => 0b01, 'M' => 0b00, 'Q' => 0b11, 'H' => 0b10];

    // Je (Version, EC-Level): [Datencodewörter gesamt, EC-Codewörter je Block,
    //   Blöcke Gruppe 1, Datencodewörter je Block (Gruppe 1),
    //   Blöcke Gruppe 2, Datencodewörter je Block (Gruppe 2)]
    // Quelle: ISO/IEC 18004 Tabelle 9 (Versionen 1–10).
    private const BLOCK_INFO = [
        1  => ['L' => [19, 7, 1, 19, 0, 0],   'M' => [16, 10, 1, 16, 0, 0],  'Q' => [13, 13, 1, 13, 0, 0],  'H' => [9, 17, 1, 9, 0, 0]],
        2  => ['L' => [34, 10, 1, 34, 0, 0],  'M' => [28, 16, 1, 28, 0, 0],  'Q' => [22, 22, 1, 22, 0, 0],  'H' => [16, 28, 1, 16, 0, 0]],
        3  => ['L' => [55, 15, 1, 55, 0, 0],  'M' => [44, 26, 1, 44, 0, 0],  'Q' => [34, 18, 2, 17, 0, 0],  'H' => [26, 22, 2, 13, 0, 0]],
        4  => ['L' => [80, 20, 1, 80, 0, 0],  'M' => [64, 18, 2, 32, 0, 0],  'Q' => [48, 26, 2, 24, 0, 0],  'H' => [36, 16, 4, 9, 0, 0]],
        5  => ['L' => [108, 26, 1, 108, 0, 0],'M' => [86, 24, 2, 43, 0, 0],  'Q' => [62, 18, 2, 15, 2, 16], 'H' => [46, 22, 2, 11, 2, 12]],
        6  => ['L' => [136, 18, 2, 68, 0, 0], 'M' => [108, 16, 4, 27, 0, 0], 'Q' => [76, 24, 4, 19, 0, 0],  'H' => [60, 28, 4, 15, 0, 0]],
        7  => ['L' => [156, 20, 2, 78, 0, 0], 'M' => [124, 18, 4, 31, 0, 0], 'Q' => [88, 18, 2, 14, 4, 15], 'H' => [66, 26, 4, 13, 1, 14]],
        8  => ['L' => [194, 24, 2, 97, 0, 0], 'M' => [154, 22, 2, 38, 2, 39],'Q' => [110, 22, 4, 18, 2, 19],'H' => [86, 26, 4, 14, 2, 15]],
        9  => ['L' => [232, 30, 2, 116, 0, 0],'M' => [182, 22, 3, 36, 2, 37],'Q' => [132, 20, 4, 16, 4, 17],'H' => [100, 24, 4, 12, 4, 13]],
        10 => ['L' => [274, 18, 2, 68, 2, 69],'M' => [216, 26, 4, 43, 1, 44],'Q' => [154, 24, 6, 19, 2, 20],'H' => [122, 28, 6, 15, 2, 16]],
    ];

    // Zentren der Ausrichtungsmuster je Version (kartesisches Produkt, Überlappungen
    // mit den Suchmustern werden beim Platzieren übersprungen). Version 1 hat keine.
    private const ALIGNMENT_POSITIONS = [
        2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    private const PAD_BYTES = [0xEC, 0x11];

    // Generatorpolynom für GF(256) gemäß QR-Spezifikation: x^8 + x^4 + x^3 + x^2 + 1
    private const GF_POLY = 0x11D;

    /**
     * Erzeugt die Modul-Matrix (true = dunkel) für $data im kleinsten Format,
     * das bei $ecLevel passt.
     *
     * @return bool[][]
     */
    public static function encode(string $data, string $ecLevel = 'M'): array
    {
        if (!isset(self::EC_BITS[$ecLevel])) {
            throw new \InvalidArgumentException("Unbekanntes EC-Level: {$ecLevel}");
        }

        $version = self::selectVersion($data, $ecLevel);
        $bits    = self::encodeData($data, $version, $ecLevel);
        $words   = self::bitsToCodewords($bits);
        $final   = self::interleaveWithEcc($words, $version, $ecLevel);

        $size   = $version * 4 + 17;
        $matrix = array_fill(0, $size, array_fill(0, $size, false));
        $marked = array_fill(0, $size, array_fill(0, $size, false)); // Funktionsmodule

        self::placeFunctionPatterns($matrix, $marked, $version);
        self::placeData($matrix, $marked, $final);

        return self::applyBestMask($matrix, $marked, $ecLevel);
    }

    public static function toSvg(array $matrix, int $moduleSize = 6, int $border = 4): string
    {
        $n     = count($matrix);
        $total = ($n + $border * 2) * $moduleSize;

        $path = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if (!$matrix[$y][$x]) continue;
                $px = ($x + $border) * $moduleSize;
                $py = ($y + $border) * $moduleSize;
                $path .= "M{$px} {$py}h{$moduleSize}v{$moduleSize}h-{$moduleSize}z";
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '" '
             . 'width="' . $total . '" height="' . $total . '" shape-rendering="crispEdges">'
             . '<rect width="100%" height="100%" fill="#ffffff"/>'
             . '<path d="' . $path . '" fill="#000000"/>'
             . '</svg>';
    }

    // -- Versionswahl --

    private static function selectVersion(string $data, string $ecLevel): int
    {
        $len = strlen($data);
        for ($v = 1; $v <= 10; $v++) {
            $capacity = self::dataCapacityBytes($v, $ecLevel);
            $ccBits   = self::charCountBits($v);
            // Mode (4) + Zeichenzähler + Daten muss in capacity*8 Bit passen,
            // mindestens 1 Byte Reserve für Terminator/Padding einplanen.
            if (4 + $ccBits + $len * 8 <= $capacity * 8) {
                return $v;
            }
        }
        throw new \InvalidArgumentException('Daten zu lang für unterstützte QR-Versionen (max. 10).');
    }

    private static function dataCapacityBytes(int $version, string $ecLevel): int
    {
        return self::BLOCK_INFO[$version][$ecLevel][0];
    }

    private static function charCountBits(int $version): int
    {
        return $version <= 9 ? 8 : 16;
    }

    // -- Daten-Encodierung (Byte-Modus) --

    private static function encodeData(string $data, int $version, string $ecLevel): string
    {
        $bits  = '0100'; // Mode-Indikator: Byte-Modus
        $bits .= str_pad(decbin(strlen($data)), self::charCountBits($version), '0', STR_PAD_LEFT);
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $capacityBits = self::dataCapacityBytes($version, $ecLevel) * 8;

        // Terminator (bis zu 4 Nullbits)
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));
        // Auf volles Byte auffüllen
        $bits = str_pad($bits, (int) (ceil(strlen($bits) / 8) * 8), '0', STR_PAD_RIGHT);

        // Mit Füllbytes 0xEC/0x11 alternierend auffüllen
        $i = 0;
        while (strlen($bits) < $capacityBits) {
            $bits .= str_pad(decbin(self::PAD_BYTES[$i % 2]), 8, '0', STR_PAD_LEFT);
            $i++;
        }

        return $bits;
    }

    private static function bitsToCodewords(string $bits): array
    {
        $words = [];
        foreach (str_split($bits, 8) as $byte) {
            $words[] = bindec($byte);
        }
        return $words;
    }

    // -- Fehlerkorrektur (Reed-Solomon über GF(256)) + Interleaving --

    private static function interleaveWithEcc(array $dataWords, int $version, string $ecLevel): array
    {
        [, $ecPerBlock, $blocks1, $dataPer1, $blocks2, $dataPer2] = self::BLOCK_INFO[$version][$ecLevel];

        $blocks  = [];
        $ecBlocks = [];
        $offset  = 0;

        $defs = [];
        for ($i = 0; $i < $blocks1; $i++) $defs[] = $dataPer1;
        for ($i = 0; $i < $blocks2; $i++) $defs[] = $dataPer2;

        foreach ($defs as $count) {
            $block = array_slice($dataWords, $offset, $count);
            $offset += $count;
            $blocks[]   = $block;
            $ecBlocks[] = self::reedSolomonEcc($block, $ecPerBlock);
        }

        // Datencodewörter interleaven (spaltenweise über alle Blöcke)
        $result  = self::interleave($blocks);
        $result  = array_merge($result, self::interleave($ecBlocks));

        return $result;
    }

    private static function interleave(array $blocks): array
    {
        $maxLen = max(array_map('count', $blocks));
        $out    = [];
        for ($i = 0; $i < $maxLen; $i++) {
            foreach ($blocks as $block) {
                if ($i < count($block)) $out[] = $block[$i];
            }
        }
        return $out;
    }

    // GF(256) Multiplikation über Logarithmus-/Antilog-Tabellen
    private static ?array $gfExp = null;
    private static ?array $gfLog = null;

    private static function initGfTables(): void
    {
        if (self::$gfExp !== null) return;

        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) $x ^= self::GF_POLY;
        }
        for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];

        self::$gfExp = $exp;
        self::$gfLog = $log;
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) return 0;
        return self::$gfExp[self::$gfLog[$a] + self::$gfLog[$b]];
    }

    // Erzeugt das Generatorpolynom vom Grad $degree (Koeffizienten, höchster Grad zuerst)
    private static function rsGeneratorPoly(int $degree): array
    {
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coef) {
                $next[$j]     ^= self::gfMul($coef, 1);
                $next[$j + 1] ^= self::gfMul($coef, self::$gfExp[$i]);
            }
            $poly = $next;
        }
        return $poly;
    }

    private static function reedSolomonEcc(array $dataWords, int $ecCount): array
    {
        self::initGfTables();

        $generator = self::rsGeneratorPoly($ecCount);
        $remainder = array_fill(0, $ecCount, 0);

        foreach ($dataWords as $word) {
            $factor    = $word ^ $remainder[0];
            $remainder = array_slice(array_merge($remainder, [0]), 1);
            for ($i = 0; $i < $ecCount; $i++) {
                $remainder[$i] ^= self::gfMul($generator[$i + 1], $factor);
            }
        }

        return $remainder;
    }

    // -- Funktionsmuster (Suchmuster, Trenner, Timing, Ausrichtung, Dunkelmodul, reservierte Bereiche) --

    private static function placeFunctionPatterns(array &$matrix, array &$marked, int $version): void
    {
        $size = count($matrix);

        self::placeFinderPattern($matrix, $marked, 0, 0);
        self::placeFinderPattern($matrix, $marked, $size - 7, 0);
        self::placeFinderPattern($matrix, $marked, 0, $size - 7);

        // Trennstreifen um die Suchmuster (helle Module)
        self::markRect($marked, 0, 0, 8, 8);
        self::markRect($marked, $size - 8, 0, 8, 8);
        self::markRect($marked, 0, $size - 8, 8, 8);

        // Timing-Muster (Reihe 6 / Spalte 6, alternierend ab Position 8)
        for ($i = 8; $i < $size - 8; $i++) {
            $dark = ($i % 2) === 0;
            $matrix[6][$i] = $dark; $marked[6][$i] = true;
            $matrix[$i][6] = $dark; $marked[$i][6] = true;
        }

        self::placeAlignmentPatterns($matrix, $marked, $version);

        // Dunkelmodul — immer dunkel, Position (4*version + 9, 8)
        $dm = 4 * $version + 9;
        $matrix[$dm][8] = true;
        $marked[$dm][8] = true;

        // Format-Info-Bereiche reservieren (Inhalt erst nach Maskenwahl bekannt)
        self::reserveFormatAreas($marked, $size);

        // Versions-Info-Bereiche (ab Version 7)
        if ($version >= 7) {
            self::placeVersionInfo($matrix, $marked, $version, $size);
        }
    }

    private static function placeFinderPattern(array &$matrix, array &$marked, int $top, int $left): void
    {
        for ($y = -1; $y <= 7; $y++) {
            for ($x = -1; $x <= 7; $x++) {
                $ay = $top + $y;
                $ax = $left + $x;
                if ($ay < 0 || $ax < 0 || $ay >= count($matrix) || $ax >= count($matrix)) continue;

                $dark = false;
                if ($x >= 0 && $x <= 6 && $y >= 0 && $y <= 6) {
                    $isBorder = ($x === 0 || $x === 6 || $y === 0 || $y === 6);
                    $isCore   = ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4);
                    $dark = $isBorder || $isCore;
                }
                $matrix[$ay][$ax] = $dark;
                $marked[$ay][$ax] = true;
            }
        }
    }

    private static function markRect(array &$marked, int $top, int $left, int $h, int $w): void
    {
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $marked[$top + $y][$left + $x] = true;
            }
        }
    }

    private static function placeAlignmentPatterns(array &$matrix, array &$marked, int $version): void
    {
        if (!isset(self::ALIGNMENT_POSITIONS[$version])) return;
        $positions = self::ALIGNMENT_POSITIONS[$version];
        $size = count($matrix);

        foreach ($positions as $row) {
            foreach ($positions as $col) {
                // Überlappung mit den drei Suchmustern überspringen
                if (($row <= 8 && $col <= 8)
                    || ($row <= 8 && $col >= $size - 9)
                    || ($row >= $size - 9 && $col <= 8)) {
                    continue;
                }

                for ($y = -2; $y <= 2; $y++) {
                    for ($x = -2; $x <= 2; $x++) {
                        $isBorder = (abs($x) === 2 || abs($y) === 2);
                        $isCenter = ($x === 0 && $y === 0);
                        $matrix[$row + $y][$col + $x] = $isBorder || $isCenter;
                        $marked[$row + $y][$col + $x] = true;
                    }
                }
            }
        }
    }

    private static function reserveFormatAreas(array &$marked, int $size): void
    {
        // Horizontal/vertikal um das obere linke Suchmuster (inkl. Timing-Schnittpunkt)
        for ($i = 0; $i <= 8; $i++) {
            $marked[8][$i] = true;
            $marked[$i][8] = true;
        }
        // Horizontal neben dem oberen rechten Suchmuster
        for ($i = $size - 8; $i < $size; $i++) {
            $marked[8][$i] = true;
        }
        // Vertikal unter dem unteren linken Suchmuster
        for ($i = $size - 7; $i < $size; $i++) {
            $marked[$i][8] = true;
        }
    }

    private static function placeVersionInfo(array &$matrix, array &$marked, int $version, int $size): void
    {
        $bits = self::bchVersionInfo($version); // 18 Bit, MSB zuerst

        // Zwei 6x3- bzw. 3x6-Blöcke, jeweils oben rechts und unten links
        for ($i = 0; $i < 18; $i++) {
            $bit = ((int) $bits >> $i) & 1;
            $row = intdiv($i, 3);
            $col = $i % 3;

            // Block unten links (6 Zeilen x 3 Spalten)
            $matrix[$size - 11 + $col][$row] = (bool) $bit;
            $marked[$size - 11 + $col][$row] = true;

            // Block oben rechts (3 Zeilen x 6 Spalten)
            $matrix[$row][$size - 11 + $col] = (bool) $bit;
            $marked[$row][$size - 11 + $col] = true;
        }
    }

    // BCH(18,6)-Kodierung der Versionsnummer, Generatorpolynom 0x1F25 (Grad 12)
    private static function bchVersionInfo(int $version): int
    {
        return self::bchEncode($version, 0x1F25, 12);
    }

    // BCH(15,5)-Kodierung von Format-Info (EC-Level + Maskenmuster), Generatorpolynom 0x537 (Grad 10),
    // anschließend mit der Spec-Maske 0x5412 verknüpft.
    private static function bchFormatInfo(string $ecLevel, int $maskPattern): int
    {
        $data    = (self::EC_BITS[$ecLevel] << 3) | $maskPattern;
        $encoded = self::bchEncode($data, 0x537, 10);
        return $encoded ^ 0x5412;
    }

    private static function bchEncode(int $data, int $generator, int $degree): int
    {
        $value = $data << $degree;
        $msbGen = self::bitLength($generator);

        for ($shifted = $value; self::bitLength($shifted) >= $msbGen; ) {
            $shifted ^= $generator << (self::bitLength($shifted) - $msbGen);
        }
        return $value | $shifted;
    }

    private static function bitLength(int $n): int
    {
        return $n === 0 ? 0 : (int) floor(log($n, 2)) + 1;
    }

    // -- Daten-Platzierung (Zickzack von unten rechts nach oben links, Spalte 6 überspringen) --

    private static function placeData(array &$matrix, array &$marked, array $codewords): void
    {
        $bits = '';
        foreach ($codewords as $word) {
            $bits .= str_pad(decbin($word), 8, '0', STR_PAD_LEFT);
        }

        $size = count($matrix);
        $bitIndex = 0;
        $bitLen   = strlen($bits);

        $col = $size - 1;
        $upward = true;

        while ($col > 0) {
            if ($col === 6) $col--; // vertikales Timing-Muster überspringen

            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? ($size - 1 - $i) : $i;

                foreach ([$col, $col - 1] as $c) {
                    if ($marked[$row][$c]) continue;
                    $bit = $bitIndex < $bitLen ? ($bits[$bitIndex] === '1') : false;
                    $matrix[$row][$c] = $bit;
                    $bitIndex++;
                }
            }

            $upward = !$upward;
            $col -= 2;
        }
    }

    // -- Maskierung (alle 8 Muster testen, beste Strafbewertung wählen) --

    private static function applyBestMask(array $matrix, array $marked, string $ecLevel): array
    {
        $best       = null;
        $bestPenalty = PHP_INT_MAX;
        $bestPattern = 0;

        for ($pattern = 0; $pattern < 8; $pattern++) {
            $candidate = self::maskMatrix($matrix, $marked, $pattern);
            self::placeFormatInfo($candidate, $marked, $ecLevel, $pattern);

            $penalty = self::penaltyScore($candidate);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best        = $candidate;
                $bestPattern = $pattern;
            }
        }

        return $best;
    }

    private static function maskMatrix(array $matrix, array $marked, int $pattern): array
    {
        $size = count($matrix);
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($marked[$y][$x]) continue;
                if (self::maskBit($pattern, $y, $x)) {
                    $matrix[$y][$x] = !$matrix[$y][$x];
                }
            }
        }
        return $matrix;
    }

    private static function maskBit(int $pattern, int $row, int $col): bool
    {
        return match ($pattern) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
            5 => (($row * $col) % 2) + (($row * $col) % 3) === 0,
            6 => ((($row * $col) % 2) + (($row * $col) % 3)) % 2 === 0,
            7 => ((($row + $col) % 2) + (($row * $col) % 3)) % 2 === 0,
        };
    }

    private static function placeFormatInfo(array &$matrix, array $marked, string $ecLevel, int $pattern): void
    {
        $size = count($matrix);
        $bits = self::bchFormatInfo($ecLevel, $pattern); // 15 Bit, MSB zuerst

        // Reihenfolge der Modul-Positionen gemäß ISO/IEC 18004 Abbildung 25
        // Kopie A: rund um das obere linke Suchmuster
        $copyA = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
        ];
        // Kopie B: unten links / oben rechts
        $copyB = [
            [$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8],
            [$size - 5, 8], [$size - 6, 8], [$size - 7, 8],
            [8, $size - 8], [8, $size - 7], [8, $size - 6], [8, $size - 5],
            [8, $size - 4], [8, $size - 3], [8, $size - 2], [8, $size - 1],
        ];

        for ($i = 0; $i < 15; $i++) {
            $bit = (($bits >> (14 - $i)) & 1) === 1;
            [$ay, $ax] = $copyA[$i];
            [$by, $bx] = $copyB[$i];
            $matrix[$ay][$ax] = $bit;
            $matrix[$by][$bx] = $bit;
        }
    }

    // -- Penalty-Bewertung (ISO/IEC 18004 Abschnitt 8.8.2, Regeln 1–4) --

    private static function penaltyScore(array $matrix): int
    {
        $size = count($matrix);
        $penalty = 0;

        // Regel 1: 5+ gleiche Module in Folge (Zeilen und Spalten)
        for ($y = 0; $y < $size; $y++) {
            $penalty += self::runPenalty(array_map(fn($x) => $matrix[$y][$x], range(0, $size - 1)));
        }
        for ($x = 0; $x < $size; $x++) {
            $penalty += self::runPenalty(array_map(fn($y) => $matrix[$y][$x], range(0, $size - 1)));
        }

        // Regel 2: 2x2-Blöcke gleicher Farbe
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $v = $matrix[$y][$x];
                if ($v === $matrix[$y][$x + 1] && $v === $matrix[$y + 1][$x] && $v === $matrix[$y + 1][$x + 1]) {
                    $penalty += 3;
                }
            }
        }

        // Regel 3: Suchmuster-ähnliche Sequenzen (1:1:3:1:1 mit 4 hellen Modulen davor/danach)
        $pattern1 = [true, false, true, true, true, false, true, false, false, false, false];
        $pattern2 = [false, false, false, false, true, false, true, true, true, false, true];
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x <= $size - 11; $x++) {
                $row = array_map(fn($i) => $matrix[$y][$x + $i], range(0, 10));
                if ($row === $pattern1 || $row === $pattern2) $penalty += 40;
            }
        }
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y <= $size - 11; $y++) {
                $col = array_map(fn($i) => $matrix[$y + $i][$x], range(0, 10));
                if ($col === $pattern1 || $col === $pattern2) $penalty += 40;
            }
        }

        // Regel 4: Verhältnis dunkler zu heller Module
        $dark = 0;
        foreach ($matrix as $row) $dark += count(array_filter($row));
        $total = $size * $size;
        $percent = (int) ($dark * 100 / $total);
        $prevMultipleOf5 = (int) (floor($percent / 5) * 5);
        $nextMultipleOf5 = $prevMultipleOf5 + 5;
        $penalty += min(abs($prevMultipleOf5 - 50), abs($nextMultipleOf5 - 50)) / 5 * 10;

        return (int) $penalty;
    }

    private static function runPenalty(array $bits): int
    {
        $penalty = 0;
        $count   = 1;
        for ($i = 1; $i < count($bits); $i++) {
            if ($bits[$i] === $bits[$i - 1]) {
                $count++;
            } else {
                if ($count >= 5) $penalty += 3 + ($count - 5);
                $count = 1;
            }
        }
        if ($count >= 5) $penalty += 3 + ($count - 5);
        return $penalty;
    }
}
