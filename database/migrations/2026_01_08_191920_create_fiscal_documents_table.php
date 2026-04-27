<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();

            // Identificadores
            $table->string('origem')->default('odoo');
            $table->string('pdv_id')->nullable()->index();
            $table->string('numero_pedido')->index();

            // Dados Fiscais
            $table->enum('tipo', ['nfce', 'nfe']);
            $table->enum('ambiente', ['homologacao', 'producao'])->default('homologacao');
            
            // Status
            $table->enum('status', [
                'recebido',
                'processando',
                'autorizado',
                'rejeitado',
                'erro_comunicacao',
                'erro_fiscal', // Adicionei este que usamos no código
                'cancelado',
                'contingencia',
                'contingencia_pendente',
                
                'offline',
                'aguardando_transmissao',
                'inutilizado'
            ])->default('recebido')->index();

            // Numeração
            $table->string('serie')->nullable();
            $table->string('numero_fiscal')->nullable()->index();

            // Chaves
            $table->string('chave_acesso')->nullable()->index();
            $table->string('protocolo')->nullable();

            // --- CORREÇÃO DE TAMANHO AQUI ---
            // JSON aguenta bastante dado, mas se o driver reclamar, o problema geralmente é na mensagem
            $table->json('payload_envio');
            $table->json('payload_resposta')->nullable();

            $table->string('codigo_sefaz')->nullable();
            
            // Mudei para longText: logs de erro podem ser gigantes
            $table->longText('mensagem_sefaz')->nullable();

            // Mudei para text: URLs com tokens podem passar de 255 caracteres
            $table->text('qr_code_url')->nullable();
            $table->text('xml_url')->nullable();
            $table->longText('xml_offline')->nullable();
            $table->text('danfe_url')->nullable();

            // Controle e Contingência
            $table->unsignedTinyInteger('tentativas')->default(0);
            $table->timestamp('ultima_tentativa_em')->nullable();
            $table->boolean('em_contingencia')->default(false);
            $table->timestamp('contingencia_em')->nullable();

            $table->timestamps(); 
            $table->index('created_at'); 

            // Índices compostos
            $table->unique(['origem', 'numero_pedido'], 'uniq_pedido_origem');
            $table->index(['status', 'created_at']); 
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');
    }
};