<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FiscalDocument extends Model
{
    protected $fillable = [
        'origem',
        'tipo',
        'pdv_id',
        'numero_pedido',
        'status',
        'payload_envio',
        'payload_resposta',
        'chave_acesso',
        'protocolo',
        'numero_fiscal',
        'serie',
        'qr_code_url',
        'xml_url',
        'danfe_url',
        'mensagem_sefaz',
        'codigo_sefaz',
        'tentativas',
        'ultima_tentativa_em',
        'em_contingencia',
        'contingencia_em',
        'xml_offline',
    ];

    protected $casts = [
        'payload_envio'    => 'array',
        'payload_resposta' => 'array',
        'em_contingencia'  => 'boolean',
        'contingencia_em'  => 'datetime',
        'xml_offline'      => 'array',
        'meta'             => 'array',
    ];

    // --- Relationships ---

    public function events(): HasMany
    {
        return $this->hasMany(FiscalEvent::class, 'fiscal_document_id');
    }

    // --- Accessors ---

    /**
     * Returns a truncated version of the SEFAZ message for display in lists.
     */
    public function getMensagemCurtaAttribute(): string
    {
        return Str::limit($this->mensagem_sefaz ?? '-', 40);
    }
}