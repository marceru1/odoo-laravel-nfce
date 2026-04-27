<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('fiscal_document_id')
                ->constrained('fiscal_documents')
                ->cascadeOnDelete();

            $table->enum('tipo', [
                'envio',            
                'retorno',          
                'reenvio',          
                'contingencia',     
                'efetivacao',       
                'cancelamento',     
                'inutilizacao',     
                'erro'
            ]);

            $table->string('status')->nullable();
            $table->string('codigo_sefaz')->nullable();

            // --- CORREÇÃO DE TAMANHO AQUI ---
            // Mudado para longText para aguentar Stack Trace de erros do PHP/Laravel
            $table->longText('mensagem')->nullable();

            // JSON geralmente é seguro, mas se preferir garantir compatibilidade total,
            // pode usar longText aqui também. Vou manter json pois é o padrão Laravel.
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['fiscal_document_id', 'tipo']);
            $table->index('codigo_sefaz');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_events');
    }
};