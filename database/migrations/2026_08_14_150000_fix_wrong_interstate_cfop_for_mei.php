<?php

use App\Modules\Fiscal\Models\ProductFiscalData;
use Illuminate\Database\Migrations\Migration;

/**
 * BUG REAL EM PRODUÇÃO 2026-08-14 (pedido #281, nota REJEITADA pela SEFAZ):
 * "337 - Rejeição: CFOP inválido para emitente MEI (CRT=4)". Pedido #281
 * vendeu pro Paraná (empresa é de SP — venda interestadual, usa
 * cfop_outros_estados) o produto #39, cadastrado com
 * cfop_outros_estados=6108 ("venda... destinada a não contribuinte,
 * interestadual") — MEI (CRT=4) paga o ICMS via DAS fixo, não participa do
 * cálculo de DIFAL que o CFOP 6108 pressupõe pra venda interestadual a não
 * contribuinte, e a SEFAZ rejeita esse CFOP especificamente pra emitente
 * MEI. Confirmado ao vivo: 6102 (mesma família, sem essa implicação) já
 * tem notas REAIS autorizadas em venda interestadual dessa mesma empresa
 * (pedidos #180 PR, #181 RJ, #182 SC) — é o valor usado em 17 dos 23
 * produtos ativos do catálogo. 6 produtos (21, 22, 32, 35, 38, 39) tinham
 * 6108 em vez de 6102, provavelmente todos cadastrados juntos por engano
 * na mesma sessão (o mesmo lote que já tinha CSOSN errado, corrigido na
 * migration 2026_08_13_223000 no dia anterior).
 *
 * down() não reverte pra 6108 — reverter significaria reintroduzir de
 * propósito um CFOP que já rejeitou uma nota real.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProductFiscalData::query()
            ->where('cfop_outros_estados', '6108')
            ->update(['cfop_outros_estados' => '6102']);
    }

    public function down(): void
    {
        // Ver comentário da classe — correção de dado real, não reversível
        // com sentido.
    }
};
