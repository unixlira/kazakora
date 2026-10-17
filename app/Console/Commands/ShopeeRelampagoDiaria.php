<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Mail\RelampagoShopeeResumo;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ShopeeRelampagoExecucao;
use App\Modules\Marketplace\Models\ShopeeRelampagoItem;
use App\Services\Shopee\ShopeeRelampagoService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Oferta Relâmpago diária da Shopee (pedido 2026-10-17: "sempre haja
 * ofertas ativas"). Cria a Relâmpago de amanhã (e dos próximos dias, pelo
 * SHOPEE_RELAMPAGO_DIAS) com a lista do Lira (shopee:relampago-itens) e
 * avisa por e-mail o que entrou e o que a Shopee recusou.
 *
 *   php artisan shopee:relampago-diaria --seco          (só simula)
 *   php artisan shopee:relampago-diaria --dia=2026-10-20
 *
 * Só cria de verdade com SHOPEE_RELAMPAGO_ATIVA=true (produção).
 */
class ShopeeRelampagoDiaria extends Command
{
    protected $signature = 'shopee:relampago-diaria
        {--seco : Só mostra o que faria, sem criar nada na Shopee}
        {--dias= : Quantos dias a partir de amanhã (padrão SHOPEE_RELAMPAGO_DIAS)}
        {--dia= : Um dia específico (AAAA-MM-DD)}
        {--para= : Manda o resumo para outro e-mail (teste)}';

    protected $description = 'Cria a Oferta Relâmpago da Shopee do dia seguinte com os produtos da lista do Lira';

    public function handle(ShopeeRelampagoService $relampago): int
    {
        $seco = (bool) $this->option('seco');
        if (! config('shopee_relampago.ativa') && ! $seco) {
            $this->info('Relâmpago automática desligada (SHOPEE_RELAMPAGO_ATIVA=false). Use --seco para simular.');

            return self::SUCCESS;
        }

        $conta = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first();
        if (! $conta?->isConnected()) {
            $this->warn('Shopee não conectada: nada feito.');

            return self::SUCCESS;
        }

        $itens = ShopeeRelampagoItem::query()->where('ativo', true)->with('product')->orderBy('id')->get();
        if ($itens->isEmpty()) {
            $this->warn('Lista da Relâmpago vazia (shopee:relampago-itens): nada feito.');

            return self::SUCCESS;
        }

        if ($this->option('dia')) {
            $dias = [Carbon::parse($this->option('dia'))->startOfDay()];
        } else {
            $quantos = max(1, (int) ($this->option('dias') ?: config('shopee_relampago.dias_a_frente', 2)));
            $dias = array_map(fn ($i) => Carbon::tomorrow()->addDays($i), range(0, $quantos - 1));
        }

        $resultados = [];
        $erro = false;
        foreach ($dias as $dia) {
            $r = $relampago->programar($dia, $itens, $seco);
            $resultados[] = $r;
            $erro = $erro || $r['erro'];
            $this->mostrar($r);

            if (! $seco) {
                ShopeeRelampagoExecucao::create([
                    'dia' => $r['dia'], 'timeslot_id' => $r['timeslot_id'], 'flash_sale_id' => $r['flash_sale_id'],
                    'status' => $r['erro'] && ! $r['adicionados'] ? 'falha' : ($r['erro'] || $r['recusados'] || $r['pulados'] ? 'parcial' : 'ok'),
                    'adicionados' => count($r['adicionados']), 'recusados' => count($r['recusados']),
                    'ativos_na_shopee' => (int) $r['ativos_na_shopee'], 'detalhe' => $r,
                ]);
            }
        }

        // E-mail só quando algo mudou ou deu errado; a segunda rodada do dia
        // sem novidade fica quieta.
        $novidade = collect($resultados)->contains(fn ($r) => $r['criada'] || $r['adicionados'] || $r['recusados'] || $r['erro']);
        if (! $seco && $novidade) {
            $para = filled($this->option('para'))
                ? [$this->option('para')]
                : array_filter(array_map('trim', explode(',', (string) config('loja.email_relatorios'))));
            Mail::to($para)->send(new RelampagoShopeeResumo($resultados));
            $this->info('Resumo enviado para '.implode(', ', $para).'.');
        }

        return $erro ? self::FAILURE : self::SUCCESS;
    }

    private function mostrar(array $r): void
    {
        $dia = Carbon::parse($r['dia'])->format('d/m');
        if ($r['erro']) {
            $this->error("{$dia}: {$r['erro']}");
        }
        $this->info(sprintf('%s: Relâmpago %s%s, %d já estavam, %d %s, %d recusado(s), %d fora%s.',
            $dia, $r['flash_sale_id'] ?? '(a criar)', $r['criada'] ? ' criada agora' : '', count($r['ja_estavam']),
            count($r['adicionados']), $r['seco'] ? 'entrariam' : 'adicionado(s)', count($r['recusados']), count($r['pulados']),
            $r['ativos_na_shopee'] !== null ? ", {$r['ativos_na_shopee']} ativos na Shopee" : ''));

        $linhas = [];
        foreach (['adicionados' => $r['seco'] ? 'entraria' : 'entrou', 'recusados' => 'recusado', 'pulados' => 'fora'] as $grupo => $rotulo) {
            foreach ($r[$grupo] as $l) {
                $linhas[] = [$rotulo, $l['item_id'].($l['model_id'] ? '/'.$l['model_id'] : ''), mb_strimwidth($l['nome'], 0, 40, '…'),
                    isset($l['preco']) ? number_format($l['preco'], 2, ',', '.').(! empty($l['ajustado']) ? ' (1%)' : '') : '',
                    $l['estoque'] ?? '', isset($l['lucro']) ? number_format($l['lucro'], 2, ',', '.') : '', $l['motivo'] ?? ''];
            }
        }
        if ($linhas) {
            $this->table(['', 'Item', 'Produto', 'Preço', 'Un.', 'Lucro/un', 'Motivo'], $linhas);
        }
    }
}
