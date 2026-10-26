<?php

namespace App\Support;

use App\Models\scctbill;
use App\Models\sccttran;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class TagihanPaymentReversal
{
    private const SALDO_FIDBANK = '1140002';

    /** Manual Pembayaran teller/cash. */
    private const TELLER_FIDBANKS = ['1140000', '1140001', '1140003', '1140004', '1140005', '1200001', '1200002'];

    /**
     * Batalkan pembayaran terakhir tanpa stored procedure.
     * Procedure CancelPaymentSaldo(_MultiVA...) di DB ini membaca kolom
     * yang tidak ada (VA / isreversal) → SQLSTATE 1054.
     */
    public function reverseLastPayment(scctbill $tagihan, Request $request): void
    {
        if (!$this->hasBillPayments($tagihan)) {
            return;
        }

        $username = (string) (Auth::user()->username ?? Auth::id() ?? 'system');
        $billAm = (int) ($tagihan->BILLAM ?? 0);
        $nominal = $this->resolvePaidAmount($tagihan);
        $isCash = $this->shouldCancelAsTellerPayment($tagihan);

        $this->resetBillToUnpaid($tagihan, $billAm);

        try {
            $this->markPaymentRowsReversed($tagihan);
        } catch (Throwable $e) {
            Log::warning('tagihan-payment.mark_reversed.skipped', [
                'aa' => $tagihan->AA,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            if ($isCash) {
                $this->insertAuditRow($tagihan, 'REVERSAL', $username, 0, 0, null);
            } elseif ($nominal > 0) {
                $reffBank = MultiVa::paymentReffBank(MultiVa::resolveFromBill($tagihan) ?? MultiVa::OPEN);
                $this->insertAuditRow($tagihan, 'JURNAL SALDO', $username, 0, $nominal, $reffBank);
            }
        } catch (Throwable $e) {
            Log::warning('tagihan-payment.audit.skipped', [
                'aa' => $tagihan->AA,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function reverseAllPayments(scctbill $tagihan, Request $request): void
    {
        $guard = 0;

        while ($this->hasBillPayments($tagihan) && $guard < 50) {
            $this->reverseLastPayment($tagihan, $request);
            $tagihan->refresh();
            $guard++;
        }
    }

    public function deleteUnpaidTagihan(scctbill $tagihan, Request $request): void
    {
        if (!$this->canDeleteUnpaidTagihan($tagihan)) {
            throw new RuntimeException(
                'Tagihan tidak dapat dihapus karena sudah ada pembayaran cicilan. Gunakan reversal di menu Data Tagihan.'
            );
        }

        $tagihan->update([
            'FSTSBolehBayar' => 0,
        ]);
    }

    public function canDeleteUnpaidTagihan(scctbill $tagihan): bool
    {
        if ((int) ($tagihan->INSTALLMENT ?? 0) > 0) {
            return false;
        }

        return !$this->hasBillPayments($tagihan);
    }

    public function applyWithoutPaymentScope($query, string $billAlias = 'scctbill')
    {
        return $query
            ->where(function ($q) use ($billAlias) {
                $q->whereNull("{$billAlias}.BILLPAID")
                    ->orWhere("{$billAlias}.BILLPAID", '<=', 0);
            })
            ->where(function ($q) use ($billAlias) {
                $q->whereNull("{$billAlias}.INSTALLMENT")
                    ->orWhere("{$billAlias}.INSTALLMENT", '<=', 0);
            })
            ->whereNotExists(function ($sub) use ($billAlias) {
                $sub->select(DB::raw(1))
                    ->from('sccttran')
                    ->whereColumn('sccttran.BILLID', "{$billAlias}.AA")
                    ->whereColumn('sccttran.CUSTID', "{$billAlias}.CUSTID")
                    ->where('sccttran.DEBET', '>', 0)
                    ->where(function ($q) {
                        $q->whereRaw('UPPER(TRIM(COALESCE(sccttran.METODE, ""))) IN (?, ?)', ['FROM TELLER', 'CASH'])
                            ->orWhere('sccttran.FIDBANK', self::SALDO_FIDBANK)
                            ->orWhereIn('sccttran.FIDBANK', self::TELLER_FIDBANKS);
                    });
            });
    }

    public function hasBillPayments(scctbill $tagihan): bool
    {
        if ((int) ($tagihan->BILLPAID ?? 0) > 0) {
            return true;
        }

        if ((int) ($tagihan->PAIDST ?? 0) === 1) {
            return true;
        }

        try {
            return $this->paymentTransactionQuery($tagihan)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function shouldCancelAsTellerPayment(scctbill $tagihan): bool
    {
        $fidBank = $this->normalizeFidBank($tagihan->FIDBANK ?? '');

        if ($fidBank === self::SALDO_FIDBANK) {
            return false;
        }

        if ($fidBank === '' || in_array($fidBank, self::TELLER_FIDBANKS, true)) {
            return true;
        }

        try {
            return sccttran::query()
                ->where('CUSTID', $tagihan->CUSTID)
                ->where('BILLID', $tagihan->AA)
                ->whereRaw('UPPER(TRIM(COALESCE(METODE, ""))) IN (?, ?)', ['FROM TELLER', 'CASH'])
                ->where(function ($q) {
                    $q->whereNull('FIDBANK')
                        ->orWhereRaw("TRIM(COALESCE(CAST(FIDBANK AS CHAR), '')) != ?", [self::SALDO_FIDBANK]);
                })
                ->exists();
        } catch (Throwable) {
            return $fidBank !== self::SALDO_FIDBANK;
        }
    }

    private function normalizeFidBank(?string $fidBank): string
    {
        return (string) preg_replace('/\D/', '', (string) $fidBank);
    }

    private function paymentTransactionQuery(scctbill $tagihan)
    {
        $query = sccttran::query()
            ->where('CUSTID', $tagihan->CUSTID)
            ->where('BILLID', $tagihan->AA)
            ->where('DEBET', '>', 0)
            ->where(function ($q) {
                $q->whereRaw('UPPER(TRIM(COALESCE(METODE, ""))) IN (?, ?)', ['FROM TELLER', 'CASH'])
                    ->orWhere('FIDBANK', self::SALDO_FIDBANK)
                    ->orWhereIn('FIDBANK', self::TELLER_FIDBANKS);
            })
            ->whereRaw('UPPER(TRIM(COALESCE(METODE, ""))) NOT IN (?, ?)', ['REVERSAL', 'JURNAL SALDO']);

        if ($this->tranHasColumn('isreversal')) {
            $query->where(function ($q) {
                $q->whereNull('isreversal')
                    ->orWhere('isreversal', 0)
                    ->orWhere('isreversal', '0');
            });
        }

        return $query;
    }

    private function resolvePaidAmount(scctbill $tagihan): int
    {
        $billPaid = (int) ($tagihan->BILLPAID ?? 0);
        if ($billPaid > 0) {
            return $billPaid;
        }

        try {
            $fromTran = (int) $this->paymentTransactionQuery($tagihan)->sum('DEBET');
            if ($fromTran > 0) {
                return $fromTran;
            }
        } catch (Throwable) {
            // pakai nominal tagihan
        }

        return max(0, (int) ($tagihan->BILLAM ?? 0));
    }

    private function markPaymentRowsReversed(scctbill $tagihan): void
    {
        if (!$this->tranHasColumn('isreversal')) {
            return;
        }

        $this->paymentTransactionQuery($tagihan)->update(['isreversal' => 1]);
    }

    private function resetBillToUnpaid(scctbill $tagihan, int $billAm): void
    {
        $core = [
            'PAIDST' => 0,
            'PAIDDT' => null,
            'BILLPAID' => 0,
            'PAYMENTLEFT' => $billAm,
            'INSTALLMENT' => 0,
            'FIDBANK' => null,
            'NOREFF' => null,
        ];

        $payload = $this->existingColumns('scctbill', array_merge($core, [
            'PAIDDT_ACTUAL' => null,
            'FRecID' => null,
            'TRANSNO' => null,
        ]));

        if ($payload === []) {
            $payload = $core;
        }

        $query = DB::connection('DATA_MYSQL')
            ->table('scctbill')
            ->where('AA', $tagihan->AA)
            ->where('CUSTID', $tagihan->CUSTID);

        try {
            $query->update($payload);
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'Unknown column') === false
                && stripos($e->getMessage(), '1054') === false) {
                throw $e;
            }

            $query->update($core);
            $payload = $core;
        }

        $tagihan->forceFill($payload);
    }

    private function insertAuditRow(
        scctbill $tagihan,
        string $metode,
        string $username,
        int $debet,
        int $kredit,
        ?string $reffBank
    ): void {
        $installment = 0;
        if ($this->tranHasColumn('INSTALLMENT')) {
            $installment = (int) DB::connection('DATA_MYSQL')
                ->table('sccttran')
                ->where('BILLID', $tagihan->AA)
                ->where('CUSTID', $tagihan->CUSTID)
                ->max('INSTALLMENT');
        }

        $payload = $this->existingColumns('sccttran', [
            'CUSTID' => $tagihan->CUSTID,
            'METODE' => $metode,
            'TRXDATE' => now()->format('Y-m-d H:i:s'),
            'NOREFF' => 'REVERSAL',
            'FIDBANK' => (string) ($tagihan->FIDBANK ?? ''),
            'DEBET' => $debet,
            'KREDIT' => $kredit,
            'REFFBANK' => $reffBank,
            'BILLID' => $tagihan->AA,
            'BILLTARGET' => $tagihan->BILLNM,
            'INSTALLMENT' => $installment,
            'TRANSNO' => $tagihan->TRANSNO ?? $username,
            'isreversal' => 1,
        ]);

        if ($this->tranHasColumn('urut') && !isset($payload['urut'])) {
            $payload['urut'] = ((int) DB::connection('DATA_MYSQL')->table('sccttran')->max('urut')) + 1;
        }

        if ($payload === [] || !isset($payload['CUSTID'])) {
            return;
        }

        DB::connection('DATA_MYSQL')->table('sccttran')->insert($payload);
    }

    private function existingColumns(string $table, array $payload): array
    {
        $filtered = [];
        foreach ($payload as $column => $value) {
            if ($this->tableHasColumn($table, $column)) {
                $filtered[$column] = $value;
            }
        }

        return $filtered;
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $cache[$key] = Schema::connection('DATA_MYSQL')->hasColumn($table, $column);
        } catch (Throwable) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }

    private function tranHasColumn(string $column): bool
    {
        return $this->tableHasColumn('sccttran', $column);
    }
}
