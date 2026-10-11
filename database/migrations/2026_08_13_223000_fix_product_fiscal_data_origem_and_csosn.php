<?php

use App\Modules\Fiscal\Models\ProductFiscalData;
use Illuminate\Database\Migrations\Migration;

/**
 * Correção de dado, não de schema — pedido explícito 2026-08-13, achado
 * auditando os dados fiscais do catálogo a pedido do usuário (empresa é
 * MEI, todo produto é mercadoria estrangeira comprada de fornecedor
 * nacional, nunca importada diretamente pela própria empresa):
 *
 * 1) origem: 12 dos 23 produtos ativos estavam com 0 (nacional) ou 1
 *    (importação direta) — nenhum dos dois reflete a realidade. O código
 *    certo da tabela CAMEX/ICMS pra "mercadoria estrangeira adquirida no
 *    mercado interno" é 2. Uniformiza pra todos os produtos com dado
 *    fiscal cadastrado (não só os 23 ativos de hoje — um produto
 *    desativado/reativado no futuro não deveria voltar com origem errada).
 *
 * 2) icms_situacao_tributaria (CSOSN): 6 produtos estavam com 300
 *    ("Imune" — reservado a livro/jornal/periódico, CF art. 150 VI) ou 400
 *    ("Não tributado pelo Simples Nacional" — fora do campo de incidência)
 *    num pente alisador, mini soprador, carregador magsafe, bebedouro pet,
 *    frigideira e organizador de pia — nenhum desses é imune nem fora do
 *    campo de incidência, é mercadoria comum. O resto do catálogo (17
 *    produtos) já usa 102 (Tributada pelo Simples Nacional sem permissão
 *    de crédito), o código certo pra venda comum de mercadoria sob
 *    Simples Nacional/MEI — padroniza os 6 errados pra 102 também.
 *
 * 3) Produto #40 (Ring Light 8", Oberon OR-PL08) não tinha NENHUM dado
 *    fiscal cadastrado — mesmo tipo exato de produto do #34 (Ring Light
 *    2.10m, mesma categoria Eletrônicos), só um modelo/tamanho diferente,
 *    então espelha o registro do #34 (mesmo NCM 94054200 — heading 9405,
 *    aparelhos de iluminação/luminárias —, mesmo CFOP, unidade e CSOSN),
 *    trocando só product_id. Guardado atrás de um `exists()` check: se
 *    essa migration rodar de novo ou o #40 já tiver ganhado dado fiscal
 *    por outro caminho antes disso, não duplica a linha.
 *
 * down() não tenta reverter pra "0"/"1"/"300"/"400" — não há registro de
 * qual produto tinha qual valor errado antes, e reverter significaria
 * reintroduzir de propósito um dado que já foi confirmado errado.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProductFiscalData::query()->update(['origem' => 2]);

        ProductFiscalData::query()
            ->whereIn('icms_situacao_tributaria', ['300', '400'])
            ->update(['icms_situacao_tributaria' => '102']);

        $reference = ProductFiscalData::query()->where('product_id', 34)->first();

        if ($reference && ! ProductFiscalData::query()->where('product_id', 40)->exists()) {
            ProductFiscalData::query()->create([
                ...$reference->only([
                    'ncm', 'cest', 'cfop', 'cfop_outros_estados', 'origem', 'gtin',
                    'unidade_tributavel', 'icms_situacao_tributaria', 'icms_aliquota',
                    'ipi_situacao_tributaria', 'ipi_aliquota', 'pis_situacao_tributaria',
                    'pis_aliquota', 'cofins_situacao_tributaria', 'cofins_aliquota',
                    'tipo_operacao', 'item_agrupavel', 'percentual_aproximado_tributos',
                ]),
                'product_id' => 40,
            ]);
        }
    }

    public function down(): void
    {
        // Ver comentário da classe — correção de dado real, não reversível
        // com sentido (não existe "estado anterior correto" pra voltar).
    }
};
