<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Finance\Application\Online\ManageOnlinePayments;
use Modules\Finance\Application\Online\MidtransException;

/**
 * Notifikasi HTTP Midtrans (PRD-006 §5, MG-2): tanpa login & tanpa CSRF; dibatasi laju di route. Tanda tangan diperiksa,
 * lalu status dicek ulang ke API Midtrans sebelum apa pun diubah. Jawaban tidak membuka data apa pun.
 *
 * - 200: diterima (termasuk duplikat & order asing) → Midtrans berhenti mengirim ulang.
 * - 403: tanda tangan tidak cocok.
 * - 503: Midtrans tidak bisa dihubungi saat cek ulang → Midtrans mengirim ulang nanti.
 */
final class MidtransWebhookController
{
    public function __invoke(Request $request, ManageOnlinePayments $online): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        try {
            $accepted = $online->handleNotification($payload);
        } catch (MidtransException) {
            return response()->json(['status' => 'retry'], 503);
        }

        return $accepted ? response()->json(['status' => 'ok']) : response()->json(['status' => 'invalid'], 403);
    }
}
