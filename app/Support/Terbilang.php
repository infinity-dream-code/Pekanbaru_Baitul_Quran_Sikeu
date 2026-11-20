<?php

namespace App\Support;

class Terbilang
{
    public static function make(int|float|string|null $nilai): string
    {
        $n = (int) abs((float) $nilai);
        if ($n === 0) {
            return 'nol';
        }

        return trim(self::tulis($n));
    }

    private static function tulis(int $x): string
    {
        $satuan = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

        if ($x < 12) {
            return $satuan[$x];
        }
        if ($x < 20) {
            return self::tulis($x - 10) . ' belas';
        }
        if ($x < 100) {
            return self::tulis(intdiv($x, 10)) . ' puluh' . ($x % 10 ? ' ' . self::tulis($x % 10) : '');
        }
        if ($x < 200) {
            return 'seratus' . ($x - 100 ? ' ' . self::tulis($x - 100) : '');
        }
        if ($x < 1000) {
            return self::tulis(intdiv($x, 100)) . ' ratus' . ($x % 100 ? ' ' . self::tulis($x % 100) : '');
        }
        if ($x < 2000) {
            return 'seribu' . ($x - 1000 ? ' ' . self::tulis($x - 1000) : '');
        }
        if ($x < 1000000) {
            return self::tulis(intdiv($x, 1000)) . ' ribu' . ($x % 1000 ? ' ' . self::tulis($x % 1000) : '');
        }
        if ($x < 1000000000) {
            return self::tulis(intdiv($x, 1000000)) . ' juta' . ($x % 1000000 ? ' ' . self::tulis($x % 1000000) : '');
        }

        return self::tulis(intdiv($x, 1000000000)) . ' milyar' . ($x % 1000000000 ? ' ' . self::tulis($x % 1000000000) : '');
    }
}
