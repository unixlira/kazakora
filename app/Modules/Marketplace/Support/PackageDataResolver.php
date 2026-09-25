<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\Correios\CorreiosPrePostagemService;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Formato, peso e medidas do pacote de um pedido, pra pré-postagem
 * automática dos Correios (pedido explícito 2026-09-25).
 *
 * Regras do usuário:
 * - Caixa ou envelope vem do produto (ProductFiscalData::formato_embalagem).
 *   Um item de caixa já põe o pedido inteiro em caixa.
 * - Envelope só precisa de PESO; caixa precisa de peso E medidas.
 * - Produto sem o dado aqui: busca no anúncio da Shopee; sem sucesso, no
 *   do Mercado Livre — todo produto está nos três canais com o mesmo SKU.
 *   O que for achado fica gravado no produto (só os campos vazios), pra
 *   próxima venda não depender de outra chamada.
 */
class PackageDataResolver
{
    // Mínimos da caixa nos Correios (altura, largura, comprimento em cm):
    // declarar menor que isso é recusado.
    private const CAIXA_MINIMA = ['altura' => 2.0, 'largura' => 11.0, 'comprimento' => 16.0];

    private const CANAIS_DE_CONSULTA = [
        MarketplaceAccount::CHANNEL_SHOPEE,
        MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
    ];

    public function __construct(private readonly MarketplaceDriverManager $drivers) {}

    /**
     * @return array{format: string, weight_grams: int, height: ?float, width: ?float, length: ?float}
     */
    public function forOrder(Order $order): array
    {
        $order->loadMissing('items.product.fiscalData');

        if ($order->items->isEmpty()) {
            throw new RuntimeException("Pedido #{$order->id} sem itens — não há o que pesar pra pré-postagem.");
        }

        $itens = $order->items->map(function ($item) {
            if (! $item->product) {
                throw new RuntimeException("Item \"{$item->product_name}\" sem produto vinculado — vincule o SKU pra calcular peso e medidas.");
            }

            return ['produto' => $item->product, 'quantidade' => (int) $item->quantity];
        });

        $caixa = $itens->contains(fn ($i) => $this->formato($i['produto']) === ProductFiscalData::EMBALAGEM_CAIXA);

        $pesoKg = 0.0;
        $altura = 0.0;
        $largura = 0.0;
        $comprimento = 0.0;

        foreach ($itens as $i) {
            $dados = $this->completar($i['produto'], $caixa);

            $pesoKg += $dados['peso_bruto'] * $i['quantidade'];

            // Caixa única pro pedido inteiro: empilha o comprimento e fica
            // com a maior altura/largura — mesma aproximação do
            // AmazonDriver::resolvePackageMeasurements().
            if ($caixa) {
                $altura = max($altura, $dados['altura_cm']);
                $largura = max($largura, $dados['largura_cm']);
                $comprimento += $dados['profundidade_cm'] * $i['quantidade'];
            }
        }

        return [
            'format' => $caixa ? CorreiosPrePostagemService::FORMATO_CAIXA : CorreiosPrePostagemService::FORMATO_ENVELOPE,
            'weight_grams' => max(1, (int) round($pesoKg * 1000)),
            'height' => $caixa ? round(max($altura, self::CAIXA_MINIMA['altura']), 1) : null,
            'width' => $caixa ? round(max($largura, self::CAIXA_MINIMA['largura']), 1) : null,
            'length' => $caixa ? round(max($comprimento, self::CAIXA_MINIMA['comprimento']), 1) : null,
        ];
    }

    private function formato(Product $produto): string
    {
        return $produto->fiscalData?->formato_embalagem === ProductFiscalData::EMBALAGEM_ENVELOPE
            ? ProductFiscalData::EMBALAGEM_ENVELOPE
            : ProductFiscalData::EMBALAGEM_CAIXA;
    }

    /**
     * @return array{peso_bruto: float, altura_cm: float, largura_cm: float, profundidade_cm: float}
     */
    private function completar(Product $produto, bool $precisaMedidas): array
    {
        $campos = $precisaMedidas ? ['peso_bruto', 'altura_cm', 'largura_cm', 'profundidade_cm'] : ['peso_bruto'];
        $atual = $this->lidos($produto);
        $faltando = array_values(array_filter($campos, fn ($c) => ! $atual[$c]));

        if ($faltando === []) {
            return $atual;
        }

        $achados = [];

        foreach (self::CANAIS_DE_CONSULTA as $canal) {
            foreach ($this->consultarCanal($produto, $canal) as $campo => $valor) {
                if (in_array($campo, $faltando, true) && ! isset($achados[$campo]) && $valor) {
                    $achados[$campo] = round((float) $valor, 3);
                }
            }

            if (count($achados) === count($faltando)) {
                break;
            }
        }

        if ($achados !== []) {
            // Só preenche o que estava vazio — nunca sobrescreve cadastro.
            $produto->fiscalData()->updateOrCreate(['product_id' => $produto->id], $achados);
            $produto->unsetRelation('fiscalData');
            Log::info('correios.pacote.dados_do_canal', ['product_id' => $produto->id, 'sku' => $produto->sku, 'gravados' => $achados]);
        }

        $atual = array_merge($atual, $achados);
        $aindaFalta = array_values(array_filter($campos, fn ($c) => ! $atual[$c]));

        if ($aindaFalta !== []) {
            throw new RuntimeException(sprintf(
                'Produto %s (%s) sem %s — nem aqui, nem no anúncio da Shopee ou do Mercado Livre. Preencha em Produtos > Logística.',
                $produto->sku,
                $produto->name,
                implode(', ', $aindaFalta),
            ));
        }

        return $atual;
    }

    /**
     * @return array{peso_bruto: float, altura_cm: float, largura_cm: float, profundidade_cm: float}
     */
    private function lidos(Product $produto): array
    {
        $fiscal = $produto->fiscalData;

        return [
            'peso_bruto' => (float) ($fiscal?->peso_bruto ?? 0),
            'altura_cm' => (float) ($fiscal?->altura_cm ?? 0),
            'largura_cm' => (float) ($fiscal?->largura_cm ?? 0),
            'profundidade_cm' => (float) ($fiscal?->profundidade_cm ?? 0),
        ];
    }

    /**
     * Anúncio já vinculado ao produto primeiro; sem vínculo, busca pelo SKU.
     *
     * @return array<string, ?float>
     */
    private function consultarCanal(Product $produto, string $canal): array
    {
        try {
            $driver = $this->drivers->driver($canal);
            $anuncio = ProductChannelListing::query()
                ->where('product_id', $produto->id)
                ->where('channel', $canal)
                ->value('external_id');

            $anuncio ??= $produto->sku ? $driver->findItemIdBySku($produto->sku) : null;

            return $anuncio ? ($driver->fetchPackageData((string) $anuncio) ?? []) : [];
        } catch (Throwable $exception) {
            Log::warning('correios.pacote.consulta_canal_falhou', ['product_id' => $produto->id, 'canal' => $canal, 'message' => $exception->getMessage()]);

            return [];
        }
    }
}
