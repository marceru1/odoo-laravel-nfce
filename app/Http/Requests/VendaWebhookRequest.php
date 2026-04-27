<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the incoming sale webhook payload from Odoo POS.
 *
 * Enforces that all required fiscal fields are present and correctly
 * typed before any business logic runs, preventing silent failures
 * deep inside the service layer.
 */
class VendaWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'confirmacao_venda'       => ['required', 'boolean'],
            'venda'                   => ['required', 'array'],
            'venda.numero_ordem'      => ['required', 'string'],
            'venda.numero_caixa'      => ['required'],

            'produtos'                => ['required', 'array', 'min:1'],
            'produtos.*.numero_item'  => ['required', 'integer'],
            'produtos.*.descricao'    => ['required', 'string'],
            'produtos.*.codigo_ncm'   => ['nullable', 'string'],
            'produtos.*.cfop'         => ['nullable', 'string'],
            'produtos.*.quantidade_comercial'      => ['required', 'numeric'],
            'produtos.*.quantidade_tributavel'     => ['required', 'numeric'],
            'produtos.*.valor_unitario_comercial'  => ['required', 'numeric'],
            'produtos.*.valor_unitario_tributavel' => ['required', 'numeric'],
            'produtos.*.icms_situacao_tributaria'  => ['nullable', 'string'],

            'pagamentos'              => ['required', 'array', 'min:1'],
            'pagamentos.*.tipo'       => ['required', 'string'],
            'pagamentos.*.valor'      => ['required', 'numeric'],

            // Optional: contingency payload sent by Odoo POS when offline
            'contingencia'            => ['sometimes', 'array'],
            'contingencia.ativa'      => ['sometimes', 'boolean'],
            'contingencia.payload'    => ['sometimes', 'nullable', 'string'],

            // Optional: customer CPF
            'cliente'                 => ['sometimes', 'array'],
            'cliente.cpf'             => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'produtos.required'     => 'A lista de produtos é obrigatória.',
            'produtos.min'          => 'A venda deve conter ao menos 1 produto.',
            'pagamentos.required'   => 'As formas de pagamento são obrigatórias.',
            'venda.numero_ordem.required' => 'O número do pedido (numero_ordem) é obrigatório.',
        ];
    }
}
