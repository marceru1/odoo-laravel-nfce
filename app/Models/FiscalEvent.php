<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalEvent extends Model
{
    protected $fillable = [
        'fiscal_document_id',
        'tipo',
        'status',
        'codigo_sefaz',
        'mensagem',
        'payload',
        'meta',
    ];

    protected $casts = [
        'payload' => 'array',
        'meta'    => 'array',
    ];

    // --- Relationships ---

    public function document(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }
}
