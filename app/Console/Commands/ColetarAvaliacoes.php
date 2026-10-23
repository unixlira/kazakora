<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Review;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\AnonimizaAvaliacao;
use App\Modules\Marketplace\Support\ReviewImportService;
use Illuminate\Console\Command;

/**
 * Pedido do Lira 2026-10-22: às 3h, coleta todas as avaliações dos anúncios
 * da KazaKora na Shopee e no Mercado Livre, com nome sorteado (único na
 * página do produto) no lugar do apelido e sem citar marketplace ou loja no
 * texto. Também passa a limpeza nas avaliações desses canais que já estavam
 * no banco antes disso (nome_original vazio = ainda não tratada).
 *
 * Diferente do reviews:sync (a cada 4 min, só Shopee, para a resposta
 * automática): aqui não responde ninguém, só importa e limpa.
 */
class ColetarAvaliacoes extends Command
{
    protected $signature = 'reviews:coletar';

    protected $description = 'Coleta as avaliações da Shopee e do Mercado Livre com nome sorteado e texto sem citar lojas.';

    public const CANAIS = [MarketplaceAccount::CHANNEL_SHOPEE, MarketplaceAccount::CHANNEL_MERCADO_LIVRE];

    public function handle(ReviewImportService $service, AnonimizaAvaliacao $anonimiza): int
    {
        $resumo = $service->importAll(self::CANAIS);

        $nomes = 0;
        $textos = 0;

        Review::query()
            ->whereIn('channel', self::CANAIS)
            ->whereNull('user_id')
            ->where(fn ($query) => $query->whereNull('nome_original')
                ->orWhere(fn ($query) => $query->whereNull('comentario_original')->whereNotNull('comment')))
            ->lazyById()
            ->each(function (Review $review) use ($anonimiza, &$nomes, &$textos) {
                if ($review->nome_original === null) {
                    $review->nome_original = (string) $review->reviewer_name;
                    $review->reviewer_name = $anonimiza->nomeUnico($review->product_id);
                    $nomes++;
                }

                if ($review->comentario_original === null) {
                    $review->comentario_original = $review->comment;
                    $limpo = AnonimizaAvaliacao::limparTexto($review->comment);
                    $textos += $limpo !== $review->comment ? 1 : 0;
                    $review->comment = $limpo;
                }

                $review->save();
            });

        $this->info(sprintf(
            'Avaliações: %d anúncios consultados, %d novas, %d atualizadas, %d ignoradas por variação, %d ignoradas por nota 1 ou 2. Já existentes: %d nomes sorteados, %d textos limpos.%s',
            $resumo['products_checked'],
            $resumo['imported'],
            $resumo['updated'],
            $resumo['ambiguous_skipped'],
            $resumo['low_rating_skipped'],
            $nomes,
            $textos,
            $resumo['channels_skipped'] ? ' Canais com falha: '.implode(', ', $resumo['channels_skipped']).'.' : '',
        ));

        return self::SUCCESS;
    }
}
