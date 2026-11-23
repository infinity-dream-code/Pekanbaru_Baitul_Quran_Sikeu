<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class MetodeBayarHelper
{
    public const ANDROID_FIDBANK = '6';
    public const SALDO_FIDBANK = '1140002';
    public const VA_FILTER = 'VA';

    /** Kode FIDBANK channel H2H VA BMI. */
    public const VA_FIDBANKS = ['1', '2', '3', '4', '5'];

    public static function isMobileNoreff(?string $noreff): bool
    {
        return strcasecmp(trim((string) $noreff), 'Mobile') === 0;
    }

    public static function isMnlNoreff(?string $noreff): bool
    {
        return strcasecmp(trim((string) $noreff), 'MNL') === 0;
    }

    /**
     * Aturan tampilan metode (sumber utama: scctbill):
     * - scctbill.NOREFF = Mobile → ANDROID (FIDBANK apa saja)
     * - selain itu pakai scctbill.FIDBANK
     *   1140000 + MNL → Manual Cash
     *   1140001 + MNL → Manual BMI
     *   1140002 + MNL → Manual SALDO
     *   1140003 → Transfer Bank Lain
     */
    public static function resolveDisplayFidBank(?string $fidBank, ?string $billNoreff = null): string
    {
        if (self::isMobileNoreff($billNoreff)) {
            return self::ANDROID_FIDBANK;
        }

        return (string) ($fidBank ?? '');
    }

    /**
     * Filter Bank = ANDROID: hanya scctbill.NOREFF Mobile ATAU FIDBANK 6.
     * (Jangan pakai sccttran.NOREFF — sering "Mobile" padahal bukan ANDROID di bill.)
     *
     * @param  Builder|QueryBuilder  $query
     * @return Builder|QueryBuilder
     */
    public static function applyAndroidBankFilter($query, string $tranAlias = 'sccttran', string $billAlias = 'scctbill')
    {
        return $query->where(function ($q) use ($billAlias) {
            $q->where("{$billAlias}.FIDBANK", self::ANDROID_FIDBANK)
                ->orWhereRaw('UPPER(TRIM(COALESCE(' . $billAlias . '.NOREFF, ""))) = ?', ['MOBILE']);
        });
    }

    /**
     * Filter Bank selain ANDROID: jangan ikutkan baris scctbill.NOREFF Mobile.
     *
     * @param  Builder|QueryBuilder  $query
     * @return Builder|QueryBuilder
     */
    public static function excludeMobileNoreff($query, string $tranAlias = 'sccttran', string $billAlias = 'scctbill')
    {
        return $query->whereRaw(
            'UPPER(TRIM(COALESCE(' . $billAlias . '.NOREFF, ""))) <> ?',
            ['MOBILE']
        );
    }

    /**
     * Filter Bank = VA: FIDBANK H2H VA (1–5), bukan ANDROID/Mobile.
     *
     * @param  Builder|QueryBuilder  $query
     * @return Builder|QueryBuilder
     */
    public static function applyVaBankFilter($query, string $tranAlias = 'sccttran', string $billAlias = 'scctbill')
    {
        $query->where(function ($q) use ($tranAlias, $billAlias) {
            $q->whereIn("{$billAlias}.FIDBANK", self::VA_FIDBANKS);

            // Data Pembayaran: VA bisa tercatat di jurnal transaksi
            if ($tranAlias !== '' && $tranAlias !== $billAlias) {
                $q->orWhereIn("{$tranAlias}.FIDBANK", self::VA_FIDBANKS);
            }
        });

        return self::excludeMobileNoreff($query, $tranAlias, $billAlias);
    }

    /**
     * Filter berdasarkan pilihan bank dropdown.
     *
     * @param  Builder|QueryBuilder  $query
     * @return Builder|QueryBuilder
     */
    public static function applyBankFilter($query, ?string $bank, string $fidColumn, string $tranAlias = 'sccttran', string $billAlias = 'scctbill')
    {
        $bank = trim((string) $bank);
        if ($bank === '' || strtolower($bank) === 'all') {
            return $query;
        }

        if ($bank === self::ANDROID_FIDBANK) {
            return self::applyAndroidBankFilter($query, $tranAlias, $billAlias);
        }

        if (strcasecmp($bank, self::VA_FILTER) === 0) {
            return self::applyVaBankFilter($query, $tranAlias, $billAlias);
        }

        $query->where($fidColumn, $bank);

        return self::excludeMobileNoreff($query, $tranAlias, $billAlias);
    }
}
