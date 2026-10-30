<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Http\Controllers\ArrearsController;
use Modules\Finance\Http\Controllers\CashierController;
use Modules\Finance\Http\Controllers\DiscountController;
use Modules\Finance\Http\Controllers\FeeSettingsController;
use Modules\Finance\Http\Controllers\InvoiceController;

/*
 * Route web modul Finance (PRD-003 §6). Dimuat oleh ModuleServiceProvider dengan middleware group "web".
 * Semua halaman Finance bekerja di dalam yayasan, jadi wajib memakai `work.context`.
 */
Route::middleware(['auth', 'work.context'])->group(function () {
    // Pengaturan keuangan (FN-1a): jenis biaya & tarif. Hanya finance.settings.manage tingkat Yayasan.
    Route::get('keuangan/pengaturan', [FeeSettingsController::class, 'index'])->name('finance.settings.index');
    Route::post('keuangan/jenis-biaya', [FeeSettingsController::class, 'store'])->name('finance.fee-types.store');
    Route::patch('keuangan/jenis-biaya/{type}', [FeeSettingsController::class, 'update'])->whereUuid('type')->name('finance.fee-types.update');
    Route::post('keuangan/jenis-biaya/{type}/nonaktifkan', [FeeSettingsController::class, 'deactivate'])->whereUuid('type')->name('finance.fee-types.deactivate');
    Route::post('keuangan/jenis-biaya/{type}/aktifkan', [FeeSettingsController::class, 'activate'])->whereUuid('type')->name('finance.fee-types.activate');
    Route::post('keuangan/jenis-biaya/{type}/tarif', [FeeSettingsController::class, 'setRate'])->whereUuid('type')->name('finance.fee-rates.set');
    Route::delete('keuangan/tarif/{rate}', [FeeSettingsController::class, 'removeRate'])->whereUuid('rate')->name('finance.fee-rates.remove');
    // Jatuh tempo tagihan bulanan per jenjang/lembaga (FN-2a, aturan berjenjang).
    Route::post('keuangan/jatuh-tempo', [FeeSettingsController::class, 'setDueDay'])->name('finance.due-day.set');

    // Tagihan (FN-2a). Lihat: finance.billing.view; buat & batalkan: finance.invoices.manage di lembaga itu.
    Route::get('keuangan/tagihan', [InvoiceController::class, 'index'])->name('finance.invoices.index');
    Route::get('keuangan/tagihan/pratinjau', [InvoiceController::class, 'preview'])->middleware('throttle:30,1')->name('finance.invoices.preview');
    Route::post('keuangan/tagihan/bulanan', [InvoiceController::class, 'generate'])->name('finance.invoices.generate');
    // Tagihan sekali bayar (FN-2b): seluruh lembaga, satu kelas, atau satu siswa.
    Route::get('keuangan/tagihan/pratinjau-sekali', [InvoiceController::class, 'previewOnce'])->middleware('throttle:30,1')->name('finance.invoices.preview-once');
    Route::post('keuangan/tagihan/sekali', [InvoiceController::class, 'generateOnce'])->name('finance.invoices.generate-once');
    Route::post('keuangan/tagihan/{invoice}/batalkan', [InvoiceController::class, 'void'])->whereUuid('invoice')->name('finance.invoices.void');

    // Kasir & kuitansi (FN-3). Lihat: finance.billing.view; catat & batal: finance.payments.manage di lembaga itu.
    Route::get('keuangan/kasir', [CashierController::class, 'index'])->name('finance.cashier.index');
    Route::get('keuangan/kasir/cari', [CashierController::class, 'searchStudents'])->middleware('throttle:30,1')->name('finance.cashier.search');
    Route::post('keuangan/pembayaran', [CashierController::class, 'store'])->name('finance.payments.store');
    Route::post('keuangan/pembayaran/{payment}/batalkan', [CashierController::class, 'void'])->whereUuid('payment')->name('finance.payments.void');
    Route::get('keuangan/kuitansi/{payment}', [CashierController::class, 'receipt'])->whereUuid('payment')->name('finance.receipts.show');

    // Tunggakan (FN-4a): finance.billing.view di lembaga itu.
    Route::get('keuangan/tunggakan', [ArrearsController::class, 'index'])->name('finance.arrears.index');
    Route::get('keuangan/tunggakan/unduh', [ArrearsController::class, 'export'])->middleware('throttle:10,1')->name('finance.arrears.export');

    // Potongan/beasiswa siswa (FN-1b). Lihat: finance.billing.view; kelola: finance.invoices.manage di lembaga itu.
    Route::get('keuangan/potongan', [DiscountController::class, 'index'])->name('finance.discounts.index');
    Route::post('keuangan/potongan', [DiscountController::class, 'store'])->name('finance.discounts.store');
    Route::delete('keuangan/potongan/{discount}', [DiscountController::class, 'destroy'])->whereUuid('discount')->name('finance.discounts.destroy');
    // Cari siswa aktif di lembaga untuk form keuangan; dibatasi 30 kali/menit.
    Route::get('keuangan/siswa/cari', [DiscountController::class, 'searchStudents'])->middleware('throttle:30,1')->name('finance.students.search');
});
