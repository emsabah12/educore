<?php

namespace Modules\Core\Application\Context;

/**
 * Nama kunci session untuk pilihan konteks kerja.
 *
 * Isi session hanyalah PILIHAN pengguna; keabsahannya selalu dicek ulang
 * oleh WorkContextResolver di setiap request (PRD-000 §6).
 */
final class WorkContextSession
{
    public const MEMBERSHIP = 'educore.context.membership_id';

    /** Berisi ID penugasan, atau WorkContext::WORKSPACE_TENANT untuk "Seluruh Yayasan". */
    public const WORKSPACE = 'educore.context.workspace';
}
