<?php

namespace App\Modules\Fiscal\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use App\Services\Fiscal\NcmValidatorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class ProductFiscalData extends Model
{
    protected $table = 'product_fiscal_data';

    // Formato da embalagem na pré-postagem dos Correios (ver
    // AmazonCorreiosShipping): envelope só precisa de peso.
    public const EMBALAGEM_CAIXA = 'caixa';

    public const EMBALAGEM_ENVELOPE = 'envelope';

    /**
     * NCM que não existe na tabela oficial não grava, venha de onde vier
     * (2026-11-15). A tela do admin já validava, mas a clonagem da Kicool, a
     * importação da Shopee e escrita por tarefa gravavam direto: o produto 53
     * ficou com 84629920 e a nota do pedido #4162 foi rejeitada (778) com o
     * cliente já pago. Aqui o NCM inválido é descartado e fica o anterior
     * (ou vazio, em cadastro novo), com aviso no log. Fonte oficial fora do
     * ar não bloqueia nada (ver NcmValidatorService).
     */
    protected static function booted(): void
    {
        static::saving(function (self $fiscal) {
            if (! $fiscal->isDirty('ncm') || blank($fiscal->ncm)) {
                return;
            }

            // Nos testes a consulta à tabela oficial fica desligada, salvo
            // no teste desta trava (que liga a chave e simula o validador).
            if (! config('fiscal.validar_ncm_ao_gravar', ! app()->runningUnitTests())) {
                return;
            }

            if (app(NcmValidatorService::class)->isValid((string) $fiscal->ncm)) {
                return;
            }

            Log::warning('fiscal.ncm.inexistente_descartado', [
                'product_id' => $fiscal->product_id,
                'ncm_recusado' => $fiscal->ncm,
                'ncm_mantido' => $fiscal->getOriginal('ncm'),
            ]);

            $fiscal->ncm = $fiscal->getOriginal('ncm');
        });
    }

    protected $fillable = [
        'product_id',
        'ncm',
        'cest',
        'cfop',
        'cfop_outros_estados',
        'origem',
        'gtin',
        'unidade_tributavel',
        'icms_situacao_tributaria',
        'icms_aliquota',
        'ipi_situacao_tributaria',
        'ipi_aliquota',
        'pis_situacao_tributaria',
        'pis_aliquota',
        'cofins_situacao_tributaria',
        'cofins_aliquota',
        'tipo_operacao',
        'recopi_numero',
        'ex_tipi',
        'fci_numero',
        'informacoes_adicionais',
        'item_agrupavel',
        'percentual_aproximado_tributos',
        'peso_bruto',
        'peso_liquido',
        'altura_cm',
        'largura_cm',
        'profundidade_cm',
        'formato_embalagem',
    ];

    protected function casts(): array
    {
        return [
            'origem' => 'integer',
            'icms_aliquota' => 'decimal:2',
            'ipi_aliquota' => 'decimal:2',
            'pis_aliquota' => 'decimal:2',
            'cofins_aliquota' => 'decimal:2',
            'tipo_operacao' => 'integer',
            'item_agrupavel' => 'boolean',
            'percentual_aproximado_tributos' => 'decimal:2',
            'peso_bruto' => 'decimal:3',
            'peso_liquido' => 'decimal:3',
            'altura_cm' => 'decimal:2',
            'largura_cm' => 'decimal:2',
            'profundidade_cm' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Os campos fiscais que são iguais em TODO produto desta empresa, por
     * ela ser MEI e revender mercadoria comum.
     *
     * BUG REAL 2026-09-10 (venda 260910M2M4KAK5 da Shopee perdida): o
     * ShopeeDriver chamava este método desde 31/08 e ELE NÃO EXISTIA —
     * `Call to undefined method`. A chamada entrou no git pela
     * reconciliação com código que o outro agente tinha feito direto no
     * servidor (commit 7526e73), e o método ficou pelo caminho. Como o
     * dado fiscal só é importado quando aparece um produto/variação NOVO
     * da Shopee, a bomba ficou armada 10 dias e explodiu numa venda só —
     * que não entrou no sistema, não teve nota e não teve etiqueta, e o
     * usuário só descobriu abrindo o painel da Shopee.
     *
     * Os valores NÃO foram inventados aqui: são os que a migration de
     * 2026-08-13 (fix_product_fiscal_data_origem_and_csosn) fixou depois
     * de auditar o catálogo com o usuário, e são os mesmos que hoje já
     * emitem nota autorizada pela SEFAZ — prova empírica de que passam.
     *
     * - origem 2: mercadoria estrangeira ADQUIRIDA NO MERCADO INTERNO (a
     *   empresa nunca importa direto). Nem 0 (nacional) nem 1 (importação
     *   própria) descrevem o que ela faz.
     * - CSOSN 102: tributada pelo Simples Nacional sem permissão de
     *   crédito — venda comum de mercadoria. 300 ("imune") e 400 ("fora do
     *   campo de incidência") já estiveram no catálogo e estavam errados.
     * - CFOP 5102 dentro do estado / 6102 fora: venda de mercadoria
     *   adquirida de terceiros.
     * - PIS/COFINS 08 (sem incidência): é o que 65 dos 73 produtos com
     *   dado fiscal usam hoje, e o que as notas autorizadas carregam.
     *
     * NCM e CEST ficam DE FORA de propósito: são específicos do produto e
     * têm que vir do canal ou do cadastro — chutar NCM é errar imposto.
     *
     * @return array<string, string|int>
     */
    public static function defaultMeiAttributes(): array
    {
        return [
            'cfop' => '5102',
            'cfop_outros_estados' => '6102',
            'origem' => 2,
            'unidade_tributavel' => 'UN',
            'icms_situacao_tributaria' => '102',
            'pis_situacao_tributaria' => '08',
            'cofins_situacao_tributaria' => '08',
        ];
    }
}
