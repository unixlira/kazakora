<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\ShopeeRelampagoItem;
use Illuminate\Console\Command;

/**
 * Lista da Oferta Relâmpago da Shopee (pedido 2026-10-17): o que o
 * shopee:relampago-agendar põe, às 22h, na Relâmpago de amanhã.
 *
 *   shopee:relampago-itens                       mostra a lista e o último resultado
 *   shopee:relampago-itens incluir 58218948968 --preco=59.90 --unidades=40 [--dia=2026-10-19]
 *   shopee:relampago-itens incluir 58218948968 --desconto=10 --unidades=40
 *   shopee:relampago-itens desligar 7            (id da lista; não apaga)
 *   shopee:relampago-itens ligar 7
 */
class ListaRelampagoShopee extends Command
{
    protected $signature = 'shopee:relampago-itens
        {acao=listar : listar | incluir | desligar | ligar}
        {alvo? : incluir: item da Shopee; desligar/ligar: id da lista}
        {--modelo= : model_id da variação na Shopee}
        {--preco= : Preço fixo na Relâmpago}
        {--desconto= : Ou desconto % sobre o preço atual do anúncio}
        {--unidades= : Unidades na Relâmpago}
        {--limite=0 : Limite por comprador (0 = sem limite)}
        {--dia= : Só neste dia (AAAA-MM-DD); vazio = todo dia}
        {--produto= : product_id da KazaKora (referência)}
        {--nome= : Nome para a lista}';

    protected $description = 'Lista da Oferta Relâmpago da Shopee agendada às 22h (listar, incluir, desligar, ligar)';

    public function handle(): int
    {
        return match ($this->argument('acao')) {
            'incluir' => $this->incluir(),
            'desligar' => $this->ligar(false),
            'ligar' => $this->ligar(true),
            default => $this->listar(),
        };
    }

    private function listar(): int
    {
        $itens = ShopeeRelampagoItem::query()->orderByDesc('ativo')->orderBy('id')->get();
        if ($itens->isEmpty()) {
            $this->info('Lista da Relâmpago vazia.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'item/modelo', 'nome', 'preço', 'un.', 'limite', 'dia', 'ativo', 'último'],
            $itens->map(fn (ShopeeRelampagoItem $i) => [
                $i->id,
                $i->shopee_item_id.($i->shopee_model_id ? '/'.$i->shopee_model_id : ''),
                mb_strimwidth((string) $i->nome, 0, 40, '…'),
                $i->preco !== null ? number_format($i->preco, 2, ',', '.') : '-'.$i->desconto_pct.'%',
                $i->unidades,
                $i->limite_comprador ?: 'sem',
                $i->dia?->format('d/m/Y') ?? 'todo dia',
                $i->ativo ? 'sim' : 'não',
                $i->ultimo_resultado ? $i->ultimo_dia?->format('d/m').' '.$i->ultimo_resultado.': '.mb_strimwidth((string) $i->ultima_mensagem, 0, 50, '…') : '',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function incluir(): int
    {
        $item = (int) $this->argument('alvo');
        $preco = $this->option('preco');
        $desconto = $this->option('desconto');
        $unidades = (int) $this->option('unidades');

        if ($item <= 0 || $unidades <= 0 || ($preco === null) === ($desconto === null)) {
            $this->error('Informe o item da Shopee, --unidades e só um entre --preco e --desconto.');

            return self::FAILURE;
        }
        if ($desconto !== null && ((float) $desconto < 1 || (float) $desconto > 99)) {
            $this->error('A Shopee aceita desconto de 1% a 99%.');

            return self::FAILURE;
        }

        $novo = ShopeeRelampagoItem::query()->create([
            'shopee_item_id' => $item,
            'shopee_model_id' => $this->option('modelo') ? (int) $this->option('modelo') : null,
            'product_id' => $this->option('produto') ? (int) $this->option('produto') : null,
            'nome' => $this->option('nome'),
            'preco' => $preco !== null ? round((float) str_replace(',', '.', $preco), 2) : null,
            'desconto_pct' => $desconto !== null ? (float) $desconto : null,
            'unidades' => $unidades,
            'limite_comprador' => max(0, (int) $this->option('limite')),
            'dia' => $this->option('dia') ?: null,
            'ativo' => true,
        ]);

        $this->info("Incluído na lista da Relâmpago (id {$novo->id}).");

        return self::SUCCESS;
    }

    private function ligar(bool $ativo): int
    {
        $item = ShopeeRelampagoItem::query()->find((int) $this->argument('alvo'));
        if (! $item) {
            $this->error('Item da lista não encontrado.');

            return self::FAILURE;
        }

        $item->update(['ativo' => $ativo]);
        $this->info(($ativo ? 'Ligado' : 'Desligado')." o item {$item->id} da lista.");

        return self::SUCCESS;
    }
}
