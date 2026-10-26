<?php

namespace App\Support;

use App\Models\mst_tagihan;
use App\Models\scctbill;
use Auth;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ManualPaymentBuilder
{
    private const SALDO_FIDBANK = '1140002';

    public function payBill(
        scctbill $tagihan,
        int $nominal,
        string $fidBank,
        string $paidAt,
        ?string $transno,
        Request $request
    ): void {
        $custId = (string) $tagihan->CUSTID;
        $aa = (string) $tagihan->AA;
        $billCd = (string) ($tagihan->BILLCD ?? '');
        $userId = $this->resolveCyberKeyUserId();

        try {
            if ($fidBank === self::SALDO_FIDBANK) {
                $va = MultiVa::resolveFromBill($tagihan) ?? MultiVa::OPEN;
                $this->callBuilderPaymentBill($aa, $nominal, $va);

                return;
            }

            $this->callBuilderPaymentCash(
                $custId,
                $fidBank,
                $userId,
                $this->formatPaymentDate($paidAt),
                $billCd,
                $aa,
                $nominal
            );
        } catch (\Throwable $e) {
            $saldoInsufficient = $e instanceof RuntimeException
                && str_contains($e->getMessage(), 'tidak mencukupi');

            if ($e instanceof RuntimeException && !$saldoInsufficient) {
                throw $e;
            }

            if (!$saldoInsufficient && !$this->shouldPhpFallback($e)) {
                throw $e;
            }

            Log::warning('manual-payment.builder.php_fallback', [
                'aa' => $aa,
                'fidbank' => $fidBank,
                'reason' => $e->getMessage(),
            ]);

            if ($fidBank === self::SALDO_FIDBANK) {
                $this->payBillViaPhp($tagihan, $nominal);
            } else {
                $this->payCashViaPhp($tagihan, $nominal, $fidBank, $paidAt, $transno, $userId);
            }
        }
    }

    /**
     * BuilderPaymentCash_MultiVAPerTagihan membaca SCCTBILL.VA.
     * Client open-only / tanpa kolom VA → BuilderPaymentCash.
     */
    private function callBuilderPaymentCash(
        string $custId,
        string $fidBank,
        string $userId,
        string $paymentDate,
        string $billCd,
        string $aa,
        int $nominal
    ): void {
        $params = [
            $custId,
            $fidBank,
            $userId,
            $paymentDate,
            $billCd,
            $aa,
            $nominal,
        ];
        $context = [
            'custid' => $custId,
            'fidbank' => $fidBank,
            'users' => $userId,
            'date' => $paymentDate,
            'billcd' => $billCd,
            'aa' => $aa,
            'nominal' => $nominal,
        ];

        $candidates = [];
        if (MultiVa::closeEnabled() && MultiVa::billVaColumn() !== null) {
            $candidates[] = MultiVa::cashPaymentFunction();
        }
        $candidates[] = 'BuilderPaymentCash';

        $this->invokeWithFallbacks($candidates, $params, $context);
    }

    /** BuilderPaymentBill_MultiVAPerTagihan1|2, lalu 93|94. */
    private function callBuilderPaymentBill(string $aa, int $nominal, string $va): void
    {
        $candidates = [
            MultiVa::paymentFunction($va),
            MultiVa::paymentFunctionLegacy($va),
            'BuilderPaymentBill_BankBayar_MultiVAPerTagihan' . MultiVa::paymentSuffix($va),
            'BuilderPaymentBill',
        ];

        $this->invokeWithFallbacks(
            $candidates,
            [$aa, $nominal],
            ['aa' => $aa, 'nominal' => $nominal, 'va' => $va]
        );
    }

    /** Coba daftar function berurutan sampai ada yang OK. */
    private function invokeWithFallbacks(array $candidates, array $params, array $context): void
    {
        $candidates = array_values(array_unique(array_filter($candidates)));
        $lastError = null;

        foreach ($candidates as $index => $functionName) {
            try {
                Log::info('manual-payment.builder.call', array_merge([
                    'function' => $functionName,
                    'attempt' => $index + 1,
                ], $context));

                $result = $this->invokeStoredFunction($functionName, $params);
                $this->assertBuilderResult($functionName, $result, $context);

                return;
            } catch (RuntimeException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning('manual-payment.builder.candidate_failed', [
                    'function' => $functionName,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        throw $lastError ?? new RuntimeException('Pembayaran gagal diproses oleh sistem.');
    }

    /** MySQL FUNCTION (fx) — pakai SELECT, bukan CALL (procedure). */
    private function invokeStoredFunction(string $functionName, array $params): string
    {
        $placeholders = implode(', ', array_fill(0, count($params), '?'));

        $rows = DB::connection('DATA_MYSQL')->select(
            "SELECT {$functionName}({$placeholders}) AS builder_result",
            $params
        );

        return trim((string) (($rows[0] ?? null)->builder_result ?? ''));
    }

    private function assertBuilderResult(string $functionName, string $result, array $context = []): void
    {
        if ($result === 'OK') {
            Log::info('manual-payment.builder.ok', array_merge(['function' => $functionName], $context));

            return;
        }

        Log::warning('manual-payment.builder.failed', array_merge([
            'function' => $functionName,
            'result' => $result,
        ], $context));

        throw new RuntimeException($this->mapBuilderResultMessage($result));
    }

    private function mapBuilderResultMessage(string $result): string
    {
        return match ($result) {
            'NOMINAL_SALAH_TAGIHAN_TIDAK_BOLEH_DICICIL' => 'Nominal pembayaran salah. Tagihan ini tidak boleh dicicil — harus dibayar lunas.',
            'MELEBIHI_TAGIHAN' => 'Nominal pembayaran melebihi sisa tagihan.',
            'Insufficient_Balance' => 'Saldo VA siswa tidak mencukupi.',
            'NOT_FOUND' => 'Tagihan tidak ditemukan atau sudah tidak bisa dibayar.',
            '' => 'Pembayaran gagal diproses oleh sistem (tidak ada respons dari database).',
            default => "Pembayaran gagal diproses oleh sistem ({$result}).",
        };
    }

    private function formatPaymentDate(string $paidAt): string
    {
        return Carbon::parse($paidAt)->format('Ymd');
    }

    private function resolveCyberKeyUserId(): string
    {
        $user = Auth::user();

        if ($user === null) {
            return '';
        }

        return (string) ($user->urut ?? Auth::id() ?? '');
    }

    private function shouldPhpFallback(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unknown column')
            || str_contains($message, "doesn't exist")
            || str_contains($message, 'does not exist')
            || str_contains($message, 'unknown function')
            || str_contains($message, 'unknown procedure')
            || str_contains($message, 'sm_topup')
            || str_contains($message, 'definer')
            || str_contains($message, 'access denied')
            || str_contains($message, '1305')
            || str_contains($message, '1449')
            || str_contains($message, '1054')
            || str_contains($message, '42s22');
    }

    /** Cadangan tunai/bank jika function MySQL gagal. */
    private function payCashViaPhp(
        scctbill $tagihan,
        int $nominal,
        string $fidBank,
        string $paidAt,
        ?string $transno,
        string $userId
    ): void {
        $custId = (string) $tagihan->CUSTID;
        $aa = (string) $tagihan->AA;
        $billAm = (int) $tagihan->BILLAM;
        $billPaid = (int) ($tagihan->BILLPAID ?? 0);
        $sisa = max(0, $billAm - $billPaid);

        if ($nominal > $sisa) {
            throw new RuntimeException('Nominal pembayaran melebihi sisa tagihan.');
        }

        $bisaCicil = (string) ($tagihan->isINSTALLABLE ?? '') === '1'
            || mst_tagihan::canInstallment($tagihan->BILLNM);
        if ($nominal < $sisa && !$bisaCicil) {
            throw new RuntimeException(
                'Nominal pembayaran salah. Tagihan ini tidak boleh dicicil — harus dibayar lunas.'
            );
        }

        $newPaid = $billPaid + $nominal;
        $newLeft = max(0, $billAm - $newPaid);
        $cicil = (int) ($tagihan->INSTALLMENT ?? 0) + 1;
        $lunas = $newLeft <= 0;
        $paidDt = $lunas ? Carbon::parse($paidAt)->format('Y-m-d H:i:s') : null;
        $billCd = (string) ($tagihan->BILLCD ?? '');
        $reffBank = $this->resolveCashReffBank($custId);

        DB::connection('DATA_MYSQL')->table('scctbill')
            ->where('CUSTID', $custId)
            ->where('AA', $aa)
            ->update($this->existingColumns('scctbill', [
                'BILLPAID' => $newPaid,
                'PAYMENTLEFT' => $newLeft,
                'INSTALLMENT' => $cicil,
                'PAIDST' => $lunas ? 1 : 0,
                'PAIDDT' => $paidDt,
                'PAIDDT_ACTUAL' => now()->format('Y-m-d H:i:s'),
                'FRecID' => $lunas ? $userId : null,
                'FIDBANK' => $lunas ? $fidBank : null,
                'NOREFF' => $lunas ? 'MNL' : null,
                'TRANSNO' => $transno,
            ]));

        DB::connection('DATA_MYSQL')->table('sccttran')
            ->insert($this->existingColumns('sccttran', [
                'CUSTID' => $custId,
                'NOREFF' => $billCd !== '' ? "{$billCd}({$aa})" : $aa,
                'FIDBANK' => $fidBank,
                'TRXDATE' => now(),
                'KDCHANNEL' => 11,
                'KREDIT' => $nominal,
                'DEBET' => $nominal,
                'METODE' => 'CASH',
                'TRANSNO' => $transno ?: $userId,
                'BILLID' => $aa,
                'BILLTARGET' => $tagihan->BILLNM,
                'INSTALLMENT' => $cicil,
                'REFFBANK' => $reffBank,
            ]));

        $this->insertSmTopup($custId, $nominal, $userId, $aa);

        Log::info('manual-payment.builder.php_cash.ok', [
            'aa' => $aa,
            'custid' => $custId,
            'nominal' => $nominal,
            'fidbank' => $fidBank,
        ]);
    }

    /** Cadangan bayar dari Saldo VA jika function Bill gagal. */
    private function payBillViaPhp(scctbill $tagihan, int $nominal): void
    {
        $custId = (string) $tagihan->CUSTID;
        $aa = (string) $tagihan->AA;
        $billAm = (int) $tagihan->BILLAM;
        $billPaid = (int) ($tagihan->BILLPAID ?? 0);
        $sisa = max(0, $billAm - $billPaid);
        $reffBank = MultiVa::paymentReffBank(MultiVa::OPEN);

        $saldo = (int) DB::connection('DATA_MYSQL')
            ->table('sccttran')
            ->where('CUSTID', $custId)
            ->where('REFFBANK', $reffBank)
            ->selectRaw('COALESCE(SUM(KREDIT - DEBET), 0) AS saldo')
            ->value('saldo');

        if ($saldo < $nominal) {
            $saldoView = MultiVa::custSaldo($custId, MultiVa::OPEN);
            if ($saldoView >= $nominal) {
                $saldo = $saldoView;
            }
        }

        if ($saldo < $nominal) {
            throw new RuntimeException('Saldo VA siswa tidak mencukupi.');
        }

        $bayar = min($nominal, $sisa);
        if ($bayar <= 0) {
            throw new RuntimeException('Tagihan tidak ditemukan atau sudah tidak bisa dibayar.');
        }

        $newPaid = $billPaid + $bayar;
        $newLeft = max(0, $billAm - $newPaid);
        $cicil = (int) ($tagihan->INSTALLMENT ?? 0) + 1;
        $lunas = $newLeft <= 0;

        DB::connection('DATA_MYSQL')->table('scctbill')
            ->where('CUSTID', $custId)
            ->where('AA', $aa)
            ->update($this->existingColumns('scctbill', [
                'BILLPAID' => $newPaid,
                'PAYMENTLEFT' => $newLeft,
                'INSTALLMENT' => $cicil,
                'PAIDST' => $lunas ? 1 : 0,
                'PAIDDT' => $lunas ? now()->format('Y-m-d H:i:s') : null,
                'FIDBANK' => $lunas ? self::SALDO_FIDBANK : null,
                'NOREFF' => $lunas ? 'TELLER' : null,
            ]));

        $transno = 'TEL' . now()->format('Ymd') . str_pad((string) (
            (int) DB::connection('DATA_MYSQL')->table('sccttran')
                ->whereDate('TRXDATE', now()->toDateString())
                ->count() + 1
        ), 5, '0', STR_PAD_LEFT);

        DB::connection('DATA_MYSQL')->table('sccttran')
            ->insert($this->existingColumns('sccttran', [
                'CUSTID' => $custId,
                'NOREFF' => 'TELLER',
                'TRXDATE' => now(),
                'KDCHANNEL' => '',
                'FIDBANK' => self::SALDO_FIDBANK,
                'DEBET' => $bayar,
                'TRANSNO' => $transno,
                'METODE' => 'FROM TELLER',
                'BILLID' => $aa,
                'BILLTARGET' => $tagihan->BILLNM,
                'INSTALLMENT' => $cicil,
                'REFFBANK' => $reffBank,
            ]));

        Log::info('manual-payment.builder.php_bill.ok', [
            'aa' => $aa,
            'custid' => $custId,
            'nominal' => $bayar,
        ]);
    }

    private function resolveCashReffBank(string $custId): string
    {
        try {
            $code = (string) DB::connection('DATA_MYSQL')
                ->table('scctcust')
                ->where('CUSTID', $custId)
                ->value('CODE01');
            if (trim($code) !== '') {
                return $code;
            }
        } catch (\Throwable) {
            // pakai default
        }

        return MultiVa::paymentReffBank(MultiVa::OPEN);
    }

    private function insertSmTopup(string $custId, int $nominal, string $userId, string $aa): void
    {
        if (!MultiVa::tableExists('sm_topup')) {
            return;
        }

        try {
            DB::connection('DATA_MYSQL')->table('sm_topup')->insert($this->existingColumns('sm_topup', [
                'CUSTID' => $custId,
                'NOMINAL' => $nominal,
                'TRXDATE' => now(),
                'user' => $userId,
                'TOPUPNO' => $aa,
            ]));
        } catch (\Throwable $e) {
            Log::warning('manual-payment.sm_topup.skipped', [
                'aa' => $aa,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function existingColumns(string $table, array $payload): array
    {
        $filtered = [];
        foreach ($payload as $column => $value) {
            try {
                if (Schema::connection('DATA_MYSQL')->hasColumn($table, $column)) {
                    $filtered[$column] = $value;
                }
            } catch (\Throwable) {
                // kolom tidak dicek → dilewati
            }
        }

        return $filtered;
    }
}
