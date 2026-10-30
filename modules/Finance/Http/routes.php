<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Http\Controllers\ArrearsController;
use Modules\Finance\Http\Controllers\CashCategoryController;
use Modules\Finance\Http\Controllers\CashController;
use Modules\Finance\Http\Controllers\CashierController;
use Modules\Finance\Http\Controllers\CashReportController;
use Modules\Finance\Http\Controllers\ChildBillingController;
use Modules\Finance\Http\Controllers\DiscountController;
use Modules\Finance\Http\Controllers\ExpenseController;
use Modules\Finance\Http\Controllers\FeeSettingsController;
use Modules\Finance\Http\Controllers\InvoiceController;
use Modules\Finance\Http\Controllers\ReceiptRecapController;

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

    // Rekap penerimaan (FN-4b): finance.billing.view, lembaga dalam cakupan.
    Route::get('keuangan/rekap-penerimaan', [ReceiptRecapController::class, 'index'])->name('finance.receipt-recap.index');
    Route::get('keuangan/rekap-penerimaan/unduh', [ReceiptRecapController::class, 'export'])->middleware('throttle:10,1')->name('finance.receipt-recap.export');

    // Portal orang tua/wali (FN-5): tagihan & kuitansi anak sendiri; akses dari tautan orang tua/wali, tanpa izin.
    Route::get('anak-saya/{student}/tagihan', [ChildBillingController::class, 'index'])->whereUuid('student')->name('finance.child-billing.index');
    Route::get('anak-saya/{student}/kuitansi/{payment}', [ChildBillingController::class, 'receipt'])->whereUuid(['student', 'payment'])->name('finance.child-billing.receipt');

    // Kas umum (PRD-004 KS-1). Lihat: finance.cash.view; kelola: finance.cash.manage di kas itu (lembaga / Yayasan).
    Route::get('keuangan/kas', [CashController::class, 'index'])->name('finance.cash.index');
    Route::post('keuangan/kas/rekening', [CashController::class, 'storeAccount'])->name('finance.cash-accounts.store');
    Route::patch('keuangan/kas/rekening/{account}', [CashController::class, 'updateAccount'])->whereUuid('account')->name('finance.cash-accounts.update');
    Route::post('keuangan/kas/rekening/{account}/penerima-transfer', [CashController::class, 'setTransferReceiver'])->whereUuid('account')->name('finance.cash-accounts.transfer-receiver');
    Route::post('keuangan/kas/pemasukan', [CashController::class, 'storeIncome'])->name('finance.cash-income.store');
    // Mutasi antar-rekening / antar-kas (KS-4): finance.cash.manage di kas rekening asal.
    Route::post('keuangan/kas/mutasi', [CashController::class, 'storeTransfer'])->name('finance.cash-transfers.store');
    Route::post('keuangan/kas/mutasi/{transfer}/batalkan', [CashController::class, 'voidTransfer'])->whereUuid('transfer')->name('finance.cash-transfers.void');
    Route::post('keuangan/kas/transaksi/{entry}/batalkan', [CashController::class, 'voidEntry'])->whereUuid('entry')->name('finance.cash-entries.void');
    // Pengeluaran (PRD-004 KS-3): ajukan → setujui/tolak → realisasi. Lihat: cash.view; ajukan/realisasi: cash.manage;
    // setujui/tolak: expenses.approve (bukan pengajuan sendiri).
    Route::get('keuangan/pengeluaran', [ExpenseController::class, 'index'])->name('finance.expenses.index');
    Route::post('keuangan/pengeluaran', [ExpenseController::class, 'store'])->name('finance.expenses.store');
    Route::post('keuangan/pengeluaran/{expense}/batalkan', [ExpenseController::class, 'cancel'])->whereUuid('expense')->name('finance.expenses.cancel');
    Route::post('keuangan/pengeluaran/{expense}/setujui', [ExpenseController::class, 'approve'])->whereUuid('expense')->name('finance.expenses.approve');
    Route::post('keuangan/pengeluaran/{expense}/tolak', [ExpenseController::class, 'reject'])->whereUuid('expense')->name('finance.expenses.reject');
    Route::post('keuangan/pengeluaran/{expense}/realisasi', [ExpenseController::class, 'realize'])->whereUuid('expense')->name('finance.expenses.realize');
    Route::post('keuangan/pengeluaran/{expense}/batalkan-realisasi', [ExpenseController::class, 'voidRealization'])->whereUuid('expense')->name('finance.expenses.void-realization');
    Route::get('keuangan/pengeluaran/{expense}/{kind}', [ExpenseController::class, 'file'])->whereUuid('expense')->whereIn('kind', ['lampiran', 'bukti'])->name('finance.expenses.file');
    // Laporan arus kas (KS-4): finance.cash.view, kas dalam cakupan.
    Route::get('keuangan/laporan-kas', [CashReportController::class, 'index'])->name('finance.cash-report.index');
    Route::get('keuangan/laporan-kas/unduh', [CashReportController::class, 'export'])->middleware('throttle:10,1')->name('finance.cash-report.export');
    // Kategori kas: finance.settings.manage tingkat Yayasan.
    Route::get('keuangan/kategori-kas', [CashCategoryController::class, 'index'])->name('finance.cash-categories.index');
    Route::post('keuangan/kategori-kas', [CashCategoryController::class, 'store'])->name('finance.cash-categories.store');
    Route::patch('keuangan/kategori-kas/{category}', [CashCategoryController::class, 'update'])->whereUuid('category')->name('finance.cash-categories.update');
    Route::post('keuangan/kategori-kas/{category}/nonaktifkan', [CashCategoryController::class, 'deactivate'])->whereUuid('category')->name('finance.cash-categories.deactivate');
    Route::post('keuangan/kategori-kas/{category}/aktifkan', [CashCategoryController::class, 'activate'])->whereUuid('category')->name('finance.cash-categories.activate');

    // Potongan/beasiswa siswa (FN-1b). Lihat: finance.billing.view; kelola: finance.invoices.manage di lembaga itu.
    Route::get('keuangan/potongan', [DiscountController::class, 'index'])->name('finance.discounts.index');
    Route::post('keuangan/potongan', [DiscountController::class, 'store'])->name('finance.discounts.store');
    Route::delete('keuangan/potongan/{discount}', [DiscountController::class, 'destroy'])->whereUuid('discount')->name('finance.discounts.destroy');
    // Cari siswa aktif di lembaga untuk form keuangan; dibatasi 30 kali/menit.
    Route::get('keuangan/siswa/cari', [DiscountController::class, 'searchStudents'])->middleware('throttle:30,1')->name('finance.students.search');
});
