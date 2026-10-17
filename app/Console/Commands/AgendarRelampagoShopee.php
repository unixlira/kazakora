<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ShopeeRelampagoItem;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Oferta Relâmpago da Shopee automática (pedido 2026-10-17): roda às 22h e
 * põe os itens da lista (shopee_relampago_itens) na Relâmpago do dia
 * seguinte, horário de 00h a 24h, para começar à meia-noite.
 *
 * Fluxo: horário do dia (get_time_slot_id) → Relâmpago desse horário que já
 * exista (get_shop_flash_sale_list) ou cria uma (create_shop_flash_sale) →
 * inclui só os itens que ainda não estão nela → relê os itens na Shopee e
 * grava em cada item da lista agendado / recusado / erro com o motivo.
 * Item que já está na Relâmpago não é mexido (preço posto à mão fica).
 * A Shopee não aceita item numa Relâmpago em andamento, por isso o dia de
 * hoje não serve.
 */
class AgendarRelampagoShopee extends Command
{
    protected $signature = 'shopee:relampago-agendar
        {--dia= : Dia da Relâmpago (AAAA-MM-DD); padrão amanhã}
        {--simular : Só mostra o que faria, sem mexer na Shopee}';

    protected $description = 'Agenda os itens da lista na Oferta Relâmpago da Shopee do dia seguinte (00h a 24h)';

    /** Limite de itens por Relâmpago na Shopee. */
    private const MAX_ITENS = 50;

    /** @var array<string, string> "item:modelo" => motivo de ficar de fora antes de enviar */
    private array $deFora = [];

    public function handle(ShopeeClient $shopee): int
    {
        $dia = $this->option('dia')
            ? Carbon::parse($this->option('dia'))->startOfDay()
            : Carbon::tomorrow();
        $simular = (bool) $this->option('simular');

        if ($dia->lte(Carbon::today())) {
            $this->error("Relâmpago de {$dia->format('d/m/Y')}: a Shopee só aceita item em Relâmpago futura.");

            return self::FAILURE;
        }

        $itens = ShopeeRelampagoItem::query()->doDia($dia)->orderBy('id')->get();
        if ($itens->isEmpty()) {
            $this->info("Relâmpago de {$dia->format('d/m/Y')}: nenhum item na lista.");

            return self::SUCCESS;
        }

        $conta = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first();
        if (! $conta?->isConnected()) {
            $this->error('Shopee não conectada: Relâmpago não agendada.');

            return self::FAILURE;
        }

        try {
            $horario = $this->horarioDoDia($shopee, $dia);
            if ($horario === null) {
                $this->marcar($itens, $dia, ShopeeRelampagoItem::ERRO, 'A Shopee não ofereceu o horário de 00h a 24h deste dia.');
                $this->error("Relâmpago de {$dia->format('d/m/Y')}: a Shopee não ofereceu o horário de 00h a 24h.");

                return self::FAILURE;
            }

            $flashSaleId = $this->relampagoDoHorario($shopee, $horario);
            $jaNaRelampago = $flashSaleId ? $this->itensDaRelampago($shopee, $flashSaleId) : collect();
            $novos = $itens->reject(fn (ShopeeRelampagoItem $i) => $jaNaRelampago->has($i->chave()));
            $vagas = self::MAX_ITENS - $jaNaRelampago->keys()->map(fn ($k) => strtok($k, ':'))->unique()->count();

            $payload = $this->montar($shopee, $novos, $dia);

            if ($simular) {
                $this->info("SIMULAÇÃO Relâmpago de {$dia->format('d/m/Y')} (horário {$horario}): "
                    .($flashSaleId ? "Relâmpago {$flashSaleId} já existe, " : 'seria criada, ')
                    .$jaNaRelampago->count().' já nela, '.count($payload).' anúncio(s) a incluir.');
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }

            if ($payload !== [] && $flashSaleId === null) {
                $criada = $shopee->post('/api/v2/shop_flash_sale/create_shop_flash_sale', ['timeslot_id' => $horario]);
                $flashSaleId = (int) ($criada['response']['flash_sale_id'] ?? 0) ?: null;
                if ($flashSaleId === null) {
                    throw new \RuntimeException('a Shopee criou a Relâmpago sem devolver o flash_sale_id.');
                }
            }

            $falhas = [];
            if ($payload !== []) {
                if (count($payload) > $vagas) {
                    $this->warn('Só cabem mais '.max(0, $vagas).' anúncio(s) nesta Relâmpago (limite '.self::MAX_ITENS.'); o resto fica de fora.');
                    $payload = array_slice($payload, 0, max(0, $vagas));
                }
                if ($payload !== []) {
                    $resposta = $shopee->post('/api/v2/shop_flash_sale/add_shop_flash_sale_items', [
                        'flash_sale_id' => $flashSaleId,
                        'items' => $payload,
                    ]);
                    foreach ($resposta['response']['failed_items'] ?? [] as $f) {
                        $falhas[($f['item_id'] ?? 0).':'.($f['model_id'] ?? 0)] = $f['err_msg'] ?? $f['unqualified_reason'] ?? $f['err_code'] ?? 'recusado';
                        $falhas[($f['item_id'] ?? 0).':*'] = $falhas[($f['item_id'] ?? 0).':'.($f['model_id'] ?? 0)];
                    }
                }
            }

            // Conferência de fora: o que está na Relâmpago segundo a própria Shopee.
            $conferidos = $flashSaleId ? $this->itensDaRelampago($shopee, $flashSaleId) : collect();
        } catch (Throwable $e) {
            $this->marcar($itens, $dia, ShopeeRelampagoItem::ERRO, $e->getMessage());
            $this->error("Relâmpago de {$dia->format('d/m/Y')}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $ok = 0;
        foreach ($itens as $item) {
            $lido = $conferidos->get($item->chave());
            if ($lido && $lido['ligado'] && $lido['motivo'] === '') {
                $this->gravar($item, $dia, ShopeeRelampagoItem::AGENDADO, sprintf('R$ %s, %d un.', number_format($lido['preco'], 2, ',', '.'), $lido['unidades']), $flashSaleId);
                $ok++;

                continue;
            }

            $motivo = $lido['motivo'] ?? '';
            $motivo = $motivo !== '' ? $motivo
                : ($falhas[$item->chave()] ?? $falhas[$item->shopee_item_id.':*'] ?? $this->deFora[$item->chave()] ?? 'não apareceu na Relâmpago depois de incluir');
            $this->gravar($item, $dia, ShopeeRelampagoItem::RECUSADO, (string) $motivo, $flashSaleId);
            $this->warn("Recusado: {$this->nome($item)}: {$motivo}");
        }

        $this->info("Relâmpago {$flashSaleId} de {$dia->format('d/m/Y')} (00h a 24h): {$ok} de {$itens->count()} item(ns) agendado(s), conferido na Shopee.");

        return $ok === $itens->count() ? self::SUCCESS : self::FAILURE;
    }

    /** timeslot_id do horário que começa às 00h do dia e acaba às 00h do dia seguinte. */
    private function horarioDoDia(ShopeeClient $shopee, Carbon $dia): ?int
    {
        $inicio = $dia->copy()->startOfDay();
        $fim = $inicio->copy()->addDay();
        $resposta = $shopee->get('/api/v2/shop_flash_sale/get_time_slot_id', [
            'start_time' => $inicio->timestamp,
            'end_time' => $fim->timestamp,
        ]);

        foreach ($resposta['response'] ?? [] as $h) {
            if ((int) ($h['start_time'] ?? 0) === $inicio->timestamp && (int) ($h['end_time'] ?? 0) === $fim->timestamp) {
                return (int) $h['timeslot_id'];
            }
        }

        return null;
    }

    /** Relâmpago futura (type 1) já criada para o horário, se houver. */
    private function relampagoDoHorario(ShopeeClient $shopee, int $horario): ?int
    {
        for ($offset = 0; $offset < 1000; $offset += 100) {
            $lista = $shopee->get('/api/v2/shop_flash_sale/get_shop_flash_sale_list', ['type' => 1, 'offset' => $offset, 'limit' => 100])['response'] ?? [];
            foreach ($lista['flash_sale_list'] ?? [] as $r) {
                if ((int) ($r['timeslot_id'] ?? 0) === $horario) {
                    return (int) $r['flash_sale_id'];
                }
            }
            if (count($lista['flash_sale_list'] ?? []) < 100) {
                break;
            }
        }

        return null;
    }

    /**
     * Itens da Relâmpago segundo a Shopee, por "item:modelo" (modelo 0 = sem variação).
     *
     * @return Collection<string, array{ligado: bool, motivo: string, preco: float, unidades: int}>
     */
    private function itensDaRelampago(ShopeeClient $shopee, int $flashSaleId): Collection
    {
        $itens = collect();
        for ($offset = 0; $offset < 1000; $offset += 100) {
            $r = $shopee->get('/api/v2/shop_flash_sale/get_shop_flash_sale_items', ['flash_sale_id' => $flashSaleId, 'offset' => $offset, 'limit' => 100])['response'] ?? [];
            $comModelo = collect($r['models'] ?? [])->pluck('item_id')->map(fn ($id) => (int) $id)->all();

            foreach ($r['item_info'] ?? [] as $i) {
                if (in_array((int) $i['item_id'], $comModelo, true)) {
                    continue;
                }
                $itens->put($i['item_id'].':0', $this->lido($i, $i['input_promotion_price'] ?? 0));
            }
            foreach ($r['models'] ?? [] as $m) {
                $itens->put($m['item_id'].':'.$m['model_id'], $this->lido($m, $m['input_promotion_price'] ?? 0));
            }

            if (count($r['item_info'] ?? []) < 100) {
                break;
            }
        }

        return $itens;
    }

    /** @return array{ligado: bool, motivo: string, preco: float, unidades: int} */
    private function lido(array $linha, mixed $preco): array
    {
        return [
            'ligado' => (int) ($linha['status'] ?? $linha['item_status'] ?? 0) === 1,
            'motivo' => (string) ($linha['reject_reason'] ?? ''),
            'preco' => (float) $preco,
            'unidades' => (int) ($linha['campaign_stock'] ?? 0),
        ];
    }

    /**
     * Corpo do add_shop_flash_sale_items. Item sem preço possível (desconto %
     * sem preço atual) fica de fora e é marcado recusado com o motivo.
     *
     * @param  Collection<int, ShopeeRelampagoItem>  $itens
     * @return list<array<string, mixed>>
     */
    private function montar(ShopeeClient $shopee, Collection $itens, Carbon $dia): array
    {
        $precos = $this->precosAtuais($shopee, $itens->filter(fn (ShopeeRelampagoItem $i) => $i->preco === null));
        $payload = [];

        foreach ($itens->groupBy('shopee_item_id') as $itemId => $grupo) {
            $modelos = [];
            foreach ($grupo as $item) {
                $preco = $item->precoPara($precos[$item->chave()] ?? null);
                if ($preco === null || $preco <= 0) {
                    $this->deFora[$item->chave()] = 'sem preço: preencha o preço ou confira o preço atual do anúncio';

                    continue;
                }

                if ($item->shopee_model_id === null) {
                    $payload[] = [
                        'item_id' => (int) $itemId,
                        'purchase_limit' => $item->limite_comprador,
                        'item_input_promo_price' => $preco,
                        'item_stock' => $item->unidades,
                    ];

                    continue;
                }

                $modelos[] = ['model_id' => $item->shopee_model_id, 'input_promo_price' => $preco, 'stock' => $item->unidades];
            }

            if ($modelos !== []) {
                $payload[] = [
                    'item_id' => (int) $itemId,
                    'purchase_limit' => (int) $grupo->max('limite_comprador'),
                    'models' => $modelos,
                ];
            }
        }

        return $payload;
    }

    /**
     * Preço atual na Shopee, por "item:modelo", só para os itens com desconto %.
     *
     * @param  Collection<int, ShopeeRelampagoItem>  $itens
     * @return array<string, float>
     */
    private function precosAtuais(ShopeeClient $shopee, Collection $itens): array
    {
        $precos = [];
        $semModelo = $itens->whereNull('shopee_model_id')->pluck('shopee_item_id')->unique();

        foreach ($semModelo->chunk(50) as $lote) {
            $r = $shopee->get('/api/v2/product/get_item_base_info', ['item_id_list' => $lote->implode(',')]);
            foreach ($r['response']['item_list'] ?? [] as $i) {
                if (isset($i['price_info'][0]['current_price'])) {
                    $precos[$i['item_id'].':0'] = (float) $i['price_info'][0]['current_price'];
                }
            }
        }

        foreach ($itens->whereNotNull('shopee_model_id')->pluck('shopee_item_id')->unique() as $itemId) {
            $r = $shopee->get('/api/v2/product/get_model_list', ['item_id' => $itemId]);
            foreach ($r['response']['model'] ?? [] as $m) {
                if (isset($m['price_info'][0]['current_price'])) {
                    $precos[$itemId.':'.$m['model_id']] = (float) $m['price_info'][0]['current_price'];
                }
            }
        }

        return $precos;
    }

    /** @param  Collection<int, ShopeeRelampagoItem>  $itens */
    private function marcar(Collection $itens, Carbon $dia, string $resultado, string $mensagem): void
    {
        $itens->each(fn (ShopeeRelampagoItem $i) => $this->gravar($i, $dia, $resultado, $mensagem, null));
    }

    private function gravar(ShopeeRelampagoItem $item, Carbon $dia, string $resultado, string $mensagem, ?int $flashSaleId): void
    {
        $item->update([
            'ultimo_dia' => $dia->toDateString(),
            'ultimo_resultado' => $resultado,
            'ultima_mensagem' => mb_substr($mensagem, 0, 1000),
            'ultimo_flash_sale_id' => $flashSaleId,
        ]);
    }

    private function nome(ShopeeRelampagoItem $item): string
    {
        return $item->nome ?: 'item '.$item->shopee_item_id.($item->shopee_model_id ? '/'.$item->shopee_model_id : '');
    }
}
