<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Models\ShopeeRelampagoItem;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Console\Command;

/**
 * Lista do Lira para a Oferta Relâmpago diária da Shopee (2026-10-17).
 *
 *   php artisan shopee:relampago-itens                                   (mostra a lista)
 *   php artisan shopee:relampago-itens --importar=515649898234622        (copia uma Relâmpago: preço, unidades, limite)
 *   php artisan shopee:relampago-itens --item=58216811452 --preco=134.90 --estoque=6
 *   php artisan shopee:relampago-itens --item=58265922084 --desconto=1             (unidades: % do estoque)
 *   php artisan shopee:relampago-itens --item=58265922084 --desconto-pct=10
 *   php artisan shopee:relampago-itens --desativar=58265922084 | --ativar=58265922084
 */
class ShopeeRelampagoItens extends Command
{
    protected $signature = 'shopee:relampago-itens
        {--importar= : Copia os itens ligados de uma Relâmpago da Shopee (flash_sale_id)}
        {--item= : item_id do anúncio na Shopee para incluir ou alterar}
        {--model= : model_id da variação (anúncio com variação)}
        {--preco= : Preço fixo da Relâmpago}
        {--desconto= : Desconto em reais sobre o preço atual do anúncio}
        {--desconto-pct= : Desconto em % sobre o preço atual do anúncio}
        {--estoque= : Unidades na Relâmpago (limitado ao estoque livre); "auto" = % do estoque do anúncio}
        {--limite= : Limite por comprador (padrão 0 = sem limite)}
        {--custo= : Custo por unidade, se o cadastro não tiver}
        {--lucro-minimo= : Lucro mínimo próprio por unidade}
        {--nome= : Nome para a lista}
        {--desativar= : item_id que sai da lista (fica guardado)}
        {--ativar= : item_id que volta para a lista}';

    protected $description = 'Mostra e altera a lista de produtos da Oferta Relâmpago diária da Shopee';

    public function handle(ShopeeClient $shopee): int
    {
        if ($id = $this->option('importar')) {
            $this->importar($shopee, (int) $id);
        } elseif ($id = $this->option('desativar') ?: $this->option('ativar')) {
            $n = ShopeeRelampagoItem::query()->where('item_id', (int) $id)
                ->when($this->option('model'), fn ($q, $m) => $q->where('model_id', (int) $m))
                ->update(['ativo' => (bool) $this->option('ativar')]);
            $this->info($n ? "{$n} item(ns) ".($this->option('ativar') ? 'ativado(s)' : 'desativado(s)').'.' : 'Item não está na lista.');
        } elseif ($this->option('item')) {
            if (! $this->salvar($shopee)) {
                return self::FAILURE;
            }
        }

        $this->listar();

        return self::SUCCESS;
    }

    private function importar(ShopeeClient $shopee, int $flashSaleId): void
    {
        $resp = $shopee->get('/api/v2/shop_flash_sale/get_shop_flash_sale_items', ['flash_sale_id' => $flashSaleId, 'offset' => 0, 'limit' => 50]);
        $itens = collect($resp['response']['item_info'] ?? [])->keyBy('item_id');
        $modelos = collect($resp['response']['models'] ?? []);
        $n = 0;

        foreach ($modelos->where('status', 1) as $m) {
            $this->gravar((int) $m['item_id'], (int) $m['model_id'], [
                'nome' => trim(($itens[$m['item_id']]['item_name'] ?? 'Item '.$m['item_id']).' '.($m['model_name'] ?? '')),
                'modo' => ShopeeRelampagoItem::MODO_PRECO, 'valor' => $m['input_promotion_price'],
                'estoque' => $m['campaign_stock'], 'limite_por_comprador' => $itens[$m['item_id']]['purchase_limit'] ?? 0,
            ]);
            $n++;
        }
        foreach ($itens->where('status', 1) as $i) {
            if ($modelos->where('item_id', $i['item_id'])->isNotEmpty()) {
                continue;
            }
            $this->gravar((int) $i['item_id'], null, [
                'nome' => $i['item_name'], 'modo' => ShopeeRelampagoItem::MODO_PRECO, 'valor' => $i['input_promotion_price'],
                'estoque' => $i['campaign_stock'], 'limite_por_comprador' => $i['purchase_limit'] ?? 0,
            ]);
            $n++;
        }

        $this->info("{$n} item(ns) copiado(s) da Relâmpago {$flashSaleId} com o preço, as unidades e o limite de lá.");
    }

    private function salvar(ShopeeClient $shopee): bool
    {
        $itemId = (int) $this->option('item');
        $modelId = $this->option('model') ? (int) $this->option('model') : null;
        $existe = ShopeeRelampagoItem::query()->where('item_id', $itemId)->where('model_id', $modelId)->first();

        $dados = [];
        foreach (['preco' => ShopeeRelampagoItem::MODO_PRECO, 'desconto' => ShopeeRelampagoItem::MODO_DESCONTO_REAIS, 'desconto-pct' => ShopeeRelampagoItem::MODO_DESCONTO_PCT] as $opcao => $modo) {
            if ($this->option($opcao) !== null) {
                $dados += ['modo' => $modo, 'valor' => (float) str_replace(',', '.', $this->option($opcao))];
            }
        }
        if ($this->option('estoque') !== null) {
            $dados['estoque'] = $this->option('estoque') === 'auto' ? null : (int) $this->option('estoque');
        }
        if ($this->option('limite') !== null) {
            $dados['limite_por_comprador'] = (int) $this->option('limite');
        }
        foreach (['custo' => 'custo', 'lucro-minimo' => 'lucro_minimo'] as $opcao => $campo) {
            if ($this->option($opcao) !== null) {
                $dados[$campo] = (float) str_replace(',', '.', $this->option($opcao));
            }
        }
        if ($this->option('nome')) {
            $dados['nome'] = $this->option('nome');
        }

        if (! $existe) {
            if (! isset($dados['modo'])) {
                $this->error('Item novo precisa de --preco, --desconto ou --desconto-pct.');

                return false;
            }
            if (! isset($dados['nome'])) {
                $base = $shopee->get('/api/v2/product/get_item_base_info', ['item_id_list' => (string) $itemId]);
                $dados['nome'] = $base['response']['item_list'][0]['item_name'] ?? "Item {$itemId}";
            }
        }

        $this->gravar($itemId, $modelId, $dados + ['ativo' => true]);
        $this->info(($existe ? 'Alterado' : 'Incluído').": {$itemId}".($modelId ? "/{$modelId}" : '').'.');

        return true;
    }

    private function gravar(int $itemId, ?int $modelId, array $dados): void
    {
        $item = ShopeeRelampagoItem::query()->firstOrNew(['item_id' => $itemId, 'model_id' => $modelId]);
        $item->fill($dados);
        $item->product_id ??= ProductChannelListing::query()
            ->where('channel', 'shopee')->where('external_id', (string) $itemId)
            ->when($modelId, fn ($q) => $q->where('external_model_id', (string) $modelId))
            ->value('product_id');
        $item->save();
    }

    private function listar(): void
    {
        $itens = ShopeeRelampagoItem::query()->with('product')->orderByDesc('ativo')->orderBy('id')->get();
        if ($itens->isEmpty()) {
            $this->line('Lista vazia.');

            return;
        }

        $modo = fn (ShopeeRelampagoItem $i) => match ($i->modo) {
            ShopeeRelampagoItem::MODO_DESCONTO_REAIS => '− R$ '.number_format((float) $i->valor, 2, ',', '.'),
            ShopeeRelampagoItem::MODO_DESCONTO_PCT => '− '.rtrim(rtrim(number_format((float) $i->valor, 2, ',', ''), '0'), ',').'%',
            default => 'R$ '.number_format((float) $i->valor, 2, ',', '.'),
        };

        $this->table(['Ativo', 'Item', 'Produto', 'Relâmpago', 'Un.', 'Limite', 'Custo', 'KZ'], $itens->map(fn ($i) => [
            $i->ativo ? 'sim' : 'não', $i->item_id.($i->model_id ? '/'.$i->model_id : ''), mb_strimwidth($i->nome, 0, 45, '…'),
            $modo($i), $i->estoque ?? 'auto', $i->limite_por_comprador ?: 'sem',
            $i->custoUnidade() !== null ? number_format($i->custoUnidade(), 2, ',', '.') : '—', $i->product_id ?? '—',
        ])->all());
    }
}
