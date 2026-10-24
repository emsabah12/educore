<?php

namespace Modules\HR\Domain\Catalog;

/**
 * Jenis dokumen pegawai (PRD-001 §15, HR-2a), mis. SK Pengangkatan, Kontrak Kerja.
 *
 * Punya masa berlaku (`has_expiry`) → tanggal "berlaku sampai" wajib diisi di dokumennya dan dokumen
 * itu masuk daftar pengingat masa berlaku.
 *
 * @property bool $has_expiry
 */
class DocumentType extends CatalogItem
{
    protected $table = 'hr_document_types';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'has_expiry',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'has_expiry' => false,
        'is_active' => true,
    ];

    public function kind(): CatalogKind
    {
        return CatalogKind::DocumentType;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'has_expiry' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
