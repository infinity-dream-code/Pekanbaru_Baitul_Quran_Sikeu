<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sikeu:hide-reversed-payments', function () {
    $updated = app(\App\Support\TagihanPaymentReversal::class)->hideStaleActivePayments();
    $this->info("Ditandai {$updated} jurnal pembayaran lama (sudah di-reversal / diganti bayar ulang).");
})->purpose('Sembunyikan jurnal bayar lama yang sudah di-reversal dari Data Pembayaran');
