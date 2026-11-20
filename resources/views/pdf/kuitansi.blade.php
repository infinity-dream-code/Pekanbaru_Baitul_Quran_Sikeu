@extends('layouts.export.kop_file')
@section('title', 'Kwitansi Pembayaran')
@section('style')
    <style>
        .kuitansi-wrap { position: relative; }
        .info-table td { padding: 3px 4px; vertical-align: top; }
        .ttd-block { margin-top: 24px; width: 100%; }
        .ttd-block td { text-align: center; vertical-align: top; }
        .ttd-block img.ttd { max-width: 120px; max-height: 60px; position: relative; z-index: 2; margin-left: -24px; }
        .ttd-block img.stempel-ttd { max-width: 85px; position: relative; z-index: 1; vertical-align: middle; }
        .ttd-block img.stempel { max-width: 170px; margin-top: 8px; }
        .ttd-overlay { white-space: nowrap; }
        .terbilang { margin-top: 8px; font-size: 10pt; }
    </style>
@endsection
@section('content')
    @php
        $nis = $siswa->NOCUST ?? null;
        $hasNis = $nis && $nis !== '-';
        $total = collect($tagihans ?? [])->sum(fn ($item) => (int) ($item->NOMINAL_BAYAR ?? $item->BILLAM ?? 0));
        $biayaLayanan = (int) ($biayaLayanan ?? 0);
        $grandTotal = $total + $biayaLayanan;
        $stempelLunas = \App\Support\KuitansiAssets::stempelLunas();
        $stempelTtd = \App\Support\KuitansiAssets::stempel();
        $tandaTangan = \App\Support\KuitansiAssets::tandaTangan();
        $petugas = auth()->user()->name ?? config('app.nama_instansi');
        $kelas = trim(($siswa->DESC02 ?? '') . ' ' . ($siswa->DESC03 ?? ''));
        $tanggalBayar = collect($tagihans ?? [])->pluck('PAIDDT')->filter()->first()
            ?: ($siswa->PAIDDT ?? null);
        $tanggalLabel = $tanggalBayar
            ? \Carbon\Carbon::parse($tanggalBayar)->isoFormat('D MMMM YYYY')
            : \Carbon\Carbon::now()->isoFormat('D MMMM YYYY');
    @endphp
    <div class="kuitansi-wrap">
        <table width="100%">
            <tr>
                <td colspan="4" align="center"><h4>KWITANSI PEMBAYARAN</h4></td>
            </tr>
        </table>

        <table width="100%" class="info-table main-table">
            <tr>
                <td width="22%"><strong>Nama Penyetor</strong></td>
                <td width="3%">:</td>
                <td colspan="4"><strong>{{ $siswa->NMCUST ?? '' }}</strong></td>
            </tr>
            <tr>
                <td><strong>Santri Atas Nama</strong></td>
                <td>:</td>
                <td width="30%">{{ $siswa->NMCUST ?? '' }}</td>
                <td width="12%"><strong>Kelas</strong></td>
                <td width="3%">:</td>
                <td>{{ $kelas !== '' ? $kelas : '-' }}</td>
            </tr>
            <tr>
                <td><strong>Tanggal Pembayaran</strong></td>
                <td>:</td>
                <td colspan="4">{{ $tanggalLabel }}</td>
            </tr>
        </table>

        <table width="100%" class="table-border main-table" style="margin-top: 12px;">
            <thead style="background-color: #ededed;">
            <tr>
                <th width="8%">No</th>
                <th>Jenis Pembayaran</th>
                <th>Keterangan</th>
                <th align="right" width="22%">Jumlah</th>
            </tr>
            </thead>
            <tbody>
            @foreach($tagihans as $tagihan)
                <tr>
                    <td align="center">{{ $loop->iteration }}</td>
                    <td>{{ $tagihan->BILLNM }}</td>
                    <td>{{ $tagihan->BTA ?? $tagihan->BILLAC ?? '-' }}</td>
                    <td align="right">@rupiah($tagihan->NOMINAL_BAYAR ?? $tagihan->BILLAM)</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            @if($biayaLayanan > 0)
                <tr>
                    <td></td>
                    <td>Biaya Admin</td>
                    <td>-</td>
                    <td align="right"><strong>@rupiah($biayaLayanan)</strong></td>
                </tr>
            @endif
            <tr>
                <td colspan="3" align="right"><strong>Total</strong></td>
                <td align="right"><strong>@rupiah($grandTotal)</strong></td>
            </tr>
            </tfoot>
        </table>

        <div class="terbilang">
            <strong>Terbilang :</strong>
            <em>{{ ucfirst(trim(\App\Support\Terbilang::make($grandTotal) ?? '')) }} rupiah</em>
        </div>

        <table class="ttd-block">
            <tr>
                <td width="33%">
                    Penyetor,<br><br><br><br>
                    (........................)
                </td>
                <td width="34%">
                    @if($stempelLunas)
                        <img class="stempel" src="{{ $stempelLunas }}" alt="LUNAS">
                    @endif
                </td>
                <td width="33%">
                    Diterima Oleh,<br>
                    <div class="ttd-overlay">
                        @if($stempelTtd)
                            <img class="stempel-ttd" src="{{ $stempelTtd }}" alt="Stempel">
                        @endif
                        @if($tandaTangan)
                            <img class="ttd" src="{{ $tandaTangan }}" alt="Tanda tangan">
                        @else
                            <br><br><br>
                        @endif
                    </div>
                    ({{ $petugas }})
                </td>
            </tr>
        </table>
    </div>
@endsection
