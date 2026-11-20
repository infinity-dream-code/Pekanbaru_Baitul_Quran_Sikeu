<?php

namespace App\Support;

class KuitansiAssets
{
    public static function dataUri(?string $relativePath): ?string
    {
        $relativePath = trim((string) $relativePath);
        if ($relativePath === '') {
            return null;
        }

        $path = public_path($relativePath);
        if (!is_file($path)) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    }

    /** Logo BQ biru (dipakai di samping TTD). */
    public static function logo(): ?string
    {
        return self::dataUri((string) config('app.logo_kuitansi', 'baitul-quran.png'))
            ?: self::dataUri((string) config('app.logo', 'logo_baitul_quran.jpeg'));
    }

    /** Logo resmi sidebar (dipakai di header kuitansi). */
    public static function logoHeader(): ?string
    {
        return self::dataUri((string) config('app.logo', 'logo_baitul_quran.jpeg'))
            ?: self::logo();
    }

    public static function tandaTangan(): ?string
    {
        return self::dataUri((string) config('app.ttd_kuitansi', 'tanda-tangan.png'));
    }

    /** Stempel resmi di atas TTD (Diterima Oleh). */
    public static function stempel(): ?string
    {
        return self::dataUri((string) config('app.stempel_ttd', 'stampel.png'))
            ?: self::dataUri('stempel.png')
            ?: self::logo();
    }

    public static function stempelLunas(): ?string
    {
        return self::dataUri((string) config('app.stempel_lunas', 'lunas.jpeg'));
    }

    public static function stempelLunasUrl(): string
    {
        return asset((string) config('app.stempel_lunas', 'lunas.jpeg'));
    }
}
