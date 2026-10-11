<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\Bling\BlingClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Empurra o dado fiscal do nosso catálogo para o produto correspondente no
 * Bling (NCM, origem, CEST, unidade).
 *
 * BUG REAL 2026-09-02 (nota 26759176098, pedido #1216 — primeira venda que
 * passou pelo caminho novo): o Bling gerou a nota a partir do pedido, mas
 * o envio à SEFAZ falhou com "Há informações obrigatórias faltando na
 * nota". A causa concreta: o produto criado lá pela integração do TikTok
 * nasce com `tributacao.ncm` VAZIO e sem unidade, e NCM é obrigatório na
 * NF-e. O dado existe aqui (produto #18, NCM 85076000) — só nunca tinha
 * caminho pra chegar lá.
 *
 * Sem isto, cada produto novo do TikTok trava a emissão da primeira venda
 * dele, e alguém precisa preencher na mão no Bling.
 *
 * Só PREENCHE o que está vazio no Bling — nunca sobrescreve dado fiscal
 * que alguém já ajustou lá, que é justamente o tipo de campo em que a
 * conta do contador manda mais que o nosso cadastro.
 */
class SyncBlingProductFiscalData extends Command
{
    protected $signature = 'bling:sync-fiscal {--canal=tiktok_shop : Canal cujos produtos serão conferidos} {--forcar : Sobrescreve NCM já preenchido no Bling}';

    protected $description = 'Preenche no Bling o dado fiscal (NCM, origem, CEST, unidade) dos produtos vindos do canal';

    public function handle(BlingClient $client): int
    {
        $canal = (string) $this->option('canal');

        $listings = ProductChannelListing::query()
            ->where('channel', $canal)
            ->with('product.fiscalData')
            ->get();

        $this->info($listings->count()." produto(s) do canal {$canal} para conferir no Bling.");

        $atualizados = 0;
        $semDado = 0;

        foreach ($listings as $listing) {
            $fiscal = $listing->product?->fiscalData;

            if (! $fiscal?->ncm) {
                $semDado++;

                continue;
            }

            try {
                $encontrado = $client->get('produtos', ['codigo' => $listing->external_id])['data'][0] ?? null;

                if (! $encontrado) {
                    $this->line("  {$listing->external_id}: não achado no Bling");

                    continue;
                }

                $produto = $client->get('produtos/'.$encontrado['id'])['data'] ?? null;

                if (! $produto) {
                    continue;
                }

                $ncmAtual = trim((string) ($produto['tributacao']['ncm'] ?? ''));

                if ($ncmAtual !== '' && ! $this->option('forcar')) {
                    continue;
                }

                // PUT do Bling substitui o recurso: manda o produto inteiro
                // de volta, só com os campos fiscais completados.
                $produto['tributacao']['ncm'] = preg_replace('/\D/', '', (string) $fiscal->ncm);
                $produto['tributacao']['origem'] = (int) ($fiscal->origem ?? 0);

                if ($fiscal->cest) {
                    $produto['tributacao']['cest'] = preg_replace('/\D/', '', (string) $fiscal->cest);
                }

                if (trim((string) ($produto['unidade'] ?? '')) === '') {
                    $produto['unidade'] = $fiscal->unidade_tributavel ?: 'UN';
                }

                $client->put('produtos/'.$produto['id'], $produto);

                $atualizados++;
                $this->line("  {$listing->external_id}: NCM {$produto['tributacao']['ncm']} gravado no Bling");
            } catch (Throwable $exception) {
                $this->error("  {$listing->external_id}: ".mb_substr($exception->getMessage(), 0, 140));
            }
        }

        $this->info("Concluído: {$atualizados} produto(s) atualizados no Bling, {$semDado} sem NCM cadastrado aqui.");

        return self::SUCCESS;
    }
}
