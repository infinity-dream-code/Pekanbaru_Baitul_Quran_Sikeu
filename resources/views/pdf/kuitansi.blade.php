@extends('layouts.export.kop_file')
@section('title', 'Kuitansi Pembayaran')
@section('style')
    <style>
        .kuitansi-wrap { position: relative; }
        .stempel-lunas {
            position: absolute;
            right: 24px;
            top: 180px;
            width: 210px;
            opacity: 0.85;
            z-index: 2;
        }
        .ttd-block { margin-top: 28px; width: 100%; }
        .ttd-block td { text-align: center; vertical-align: top; }
        .ttd-block img { max-width: 140px; max-height: 70px; }
    </style>
@endsection
@section('content')
    @php
        $nis = $siswa->NOCUST ?? null;
        $hasNis = $nis && $nis !== '-';
        $nova = $hasNis
            ? \App\Support\MultiVa::formatNoVaFromBills($nis, $tagihans ?? [])
            : \App\Support\MultiVa::formatNoVaFromBills($siswa->NUM2ND ?? '', $tagihans ?? []);
        $total = collect($tagihans ?? [])->sum(fn ($item) => (int) ($item->NOMINAL_BAYAR ?? $item->BILLAM ?? 0));
        $biayaLayanan = (int) ($biayaLayanan ?? 0);
        $grandTotal = $total + $biayaLayanan;
        $stempelLunas = \App\Support\KuitansiAssets::stempelLunas();
        $tandaTangan = \App\Support\KuitansiAssets::tandaTangan();
        $petugas = auth()->user()->name ?? config('app.nama_instansi');
    @endphp
    <div class="kuitansi-wrap">
        @if($stempelLunas)
            <img class="stempel-lunas" src="{{ $stempelLunas }}" alt="LUNAS">
        @endif
        <table width="100%">
            <tr>
                <td colspan="4" align="center"><h4>KUITANSI PEMBAYARAN</h4></td>
            </tr>
        </table>
        <table width="100%" class="main-table">
            <tr>
                <td class="border-right-0">{{ $hasNis ? 'NIS' : 'No. Pendaftaran' }}</td>
                <td class="border-left-0">: <strong>{{ $hasNis ? $nis : ($siswa->NUM2ND ?? '-') }}</strong></td>
                <td class="border-right-0">Unit</td>
                <td class="border-left-0">: <strong>{{ $siswa->CODE02 ?? '' }}</strong></td>
            </tr>
            <tr>
                <td class="border-right-0">No. VA</td>
                <td class="border-left-0">: <strong>{{ $nova ?? '-' }}</strong></td>
                <td class="border-right-0">Kelas</td>
                <td class="border-left-0">: <strong>{{ trim(($siswa->DESC02 ?? '') . ' ' . ($siswa->DESC03 ?? '')) }}</strong></td>
            </tr>
            <tr>
                <td class="border-right-0">Nama Siswa</td>
                <td class="border-left-0">: <strong>{{ $siswa->NMCUST ?? '' }}</strong></td>
                <td class="border-right-0"></td>
                <td class="border-left-0"></td>
            </tr>
        </table>
        <table width="100%" class="table-border main-table" style="margin-top: 12px;">
            <thead style="background-color: #ededed;">
            <tr>
                <th>#</th>
                <th>Nama Tagihan</th>
                <th>Periode</th>
                <th align="right">Nominal</th>
            </tr>
            </thead>
            <tbody>
            @foreach($tagihans as $tagihan)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $tagihan->BILLNM }}</td>
                    <td>{{ $tagihan->BTA }}</td>
                    <td align="right">@rupiah($tagihan->BILLAM)</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            @if($biayaLayanan > 0)
                <tr>
                    <td colspan="3" align="right"><strong>Total Tagihan</strong></td>
                    <td align="right"><strong>@rupiah($total)</strong></td>
                </tr>
                <tr>
                    <td colspan="3" align="right"><strong>Biaya Admin</strong></td>
                    <td align="right"><strong>@rupiah($biayaLayanan)</strong></td>
                </tr>
            @endif
            <tr>
                <td colspan="3" align="right"><strong>Total</strong></td>
                <td align="right"><strong>@rupiah($grandTotal)</strong></td>
            </tr>
            </tfoot>
        </table>

        <table class="ttd-block">
            <tr>
                <td width="55%"></td>
                <td width="45%">
                    {{ config('app.domisili') }}, {{ \Carbon\Carbon::now()->isoFormat('D MMMM YYYY') }}<br>
                    @if($tandaTangan)
                        <img src="{{ $tandaTangan }}" alt="Tanda tangan"><br>
                    @else
                        <br><br><br>
                    @endif
                    <u>{{ $petugas }}</u>
                </td>
            </tr>
        </table>
    </div>
@endsection
