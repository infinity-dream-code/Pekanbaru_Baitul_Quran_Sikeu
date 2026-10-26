<?php

namespace App\Imports\Keuangan\TagihanSiswa;

use App\Models\scctcust;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ImportTagihanExcel implements WithMultipleSheets, ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public function sheets(): array
    {
        return [
            0 => $this,
        ];
    }

    public function collection(Collection $collection): void
    {
        $cacheKey = 'import_tagihan_excel';
        $processedData = [];

        foreach ($collection as $row) {
            if ($row->filter()->isEmpty()) {
                continue;
            }

            $raw = $row->toArray();
            $nis = trim((string) ($raw['nis'] ?? ''));
            $nominalRaw = $raw['nominal'] ?? null;

            if ($nis === '' && ($nominalRaw === null || $nominalRaw === '')) {
                continue;
            }

            $rowData = [
                'nis' => $nis !== '' ? $nis : null,
                'nominal' => $nominalRaw,
                'status' => 1,
                'keterangan' => null,
            ];
            $statusKet = null;

            if ($rowData['nis'] === null) {
                $rowData['status'] = 0;
                $statusKet = 'NIS tidak boleh kosong';
            } else {
                $checkData = scctcust::where('NOCUST', $rowData['nis'])->first();
                if (!$checkData) {
                    $rowData['status'] = 0;
                    $statusKet = "NIS {$rowData['nis']} tidak ditemukan";
                }
            }

            if ($rowData['nominal'] === null || $rowData['nominal'] === '') {
                $rowData['status'] = 0;
                $statusKet = $statusKet ? $statusKet . ', Nominal tidak boleh kosong' : 'Nominal tidak boleh kosong';
            }

            $rowData['keterangan'] = $statusKet;
            $processedData[] = $rowData;
        }

        if (!empty($processedData)) {
            Cache::put($cacheKey, $processedData, now()->addMinutes(60));
        }
    }

    public function headingRow(): int
    {
        return 1;
    }
}
