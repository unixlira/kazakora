<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Services\Bling\BlingClient;
use App\Services\Fiscal\NcmValidatorService;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Alinhamento do catálogo (2026-10-11, aprovado pelo Lira): copia o dado
 * fiscal do CADASTRO DA KAZAKORA (fonte da verdade) para o anúncio da Shopee
 * e/ou o produto do Bling. NCM sempre validado na tabela oficial antes.
 *
 *   --shopee=ITEM:PRODUTO   (tax_info: ncm, origin, csosn, cest)
 *   --bling=CODIGO:PRODUTO  (tributacao: ncm, origem, cest)
 *
 * Sem --aplicar, só mostra o que mudaria.
 */
class AlinharFiscalNosCanais extends Command
{
    protected $signature = 'catalogo:fiscal-canais
        {--shopee=* : ITEM_ID:PRODUTO_ID}
        {--bling=* : CODIGO:PRODUTO_ID}
        {--aplicar : Grava nos canais}';

    protected $description = 'Copia NCM/origem/CSOSN do cadastro da Kazakora para Shopee e Bling (com NCM validado)';

    public function handle(ShopeeClient $shopee, BlingClient $bling, NcmValidatorService $ncms): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $this->info($aplicar ? 'APLICANDO' : 'SIMULAÇÃO (use --aplicar)');

        foreach ((array) $this->option('shopee') as $par) {
            [$item, $produtoId] = array_pad(explode(':', $par, 2), 2, null);
            $fiscal = $this->fiscal((int) $produtoId, $ncms);
            if (! $fiscal) {
                continue;
            }

            try {
                $atual = $shopee->get('/api/v2/product/get_item_base_info', ['item_id_list' => $item, 'need_tax_info' => 'true'])['response']['item_list'][0]['tax_info'] ?? [];
                $novo = array_merge($atual, array_filter([
                    'ncm' => $fiscal['ncm'],
                    'origin' => (string) $fiscal['origem'],
                    'csosn' => $fiscal['csosn'],
                    'cest' => $fiscal['cest'],
                    'same_state_cfop' => $atual['same_state_cfop'] ?? '5102' ?: '5102',
                    'diff_state_cfop' => $atual['diff_state_cfop'] ?? '6102' ?: '6102',
                    'measure_unit' => $atual['measure_unit'] ?? 'UN' ?: 'UN',
                ], fn ($v) => $v !== null && $v !== ''));
                $this->line("Shopee {$item}: ncm ".($atual['ncm'] ?? '-')."→{$novo['ncm']}, origem ".($atual['origin'] ?? '-')."→{$novo['origin']}, csosn ".($atual['csosn'] ?? '-').'→'.($novo['csosn'] ?? '-'));

                if ($aplicar) {
                    $r = $shopee->post('/api/v2/product/update_item', ['item_id' => (int) $item, 'tax_info' => $novo]);
                    $this->info('   gravado'.(($r['error'] ?? '') ? ' — '.$r['error'].' '.($r['message'] ?? '') : ''));
                }
            } catch (Throwable $e) {
                $this->error("   Shopee {$item}: ".mb_substr($e->getMessage(), 0, 200));
            }
        }

        foreach ((array) $this->option('bling') as $par) {
            [$codigo, $produtoId] = array_pad(explode(':', $par, 2), 2, null);
            $fiscal = $this->fiscal((int) $produtoId, $ncms);
            if (! $fiscal) {
                continue;
            }

            try {
                $achados = collect($bling->get('produtos', ['codigo' => $codigo])['data'] ?? [])->where('codigo', $codigo)->values();
                if ($achados->count() !== 1) {
                    $this->warn("Bling {$codigo}: {$achados->count()} produto(s) com esse código — não mexo");

                    continue;
                }
                usleep(400_000);
                $id = (int) $achados[0]['id'];
                $atual = $bling->get("produtos/{$id}")['data'] ?? [];
                $ncmAtual = $atual['tributacao']['ncm'] ?? '';
                $ncmBling = substr($fiscal['ncm'], 0, 4).'.'.substr($fiscal['ncm'], 4, 2).'.'.substr($fiscal['ncm'], 6, 2);
                $this->line("Bling {$codigo}: ncm ".($ncmAtual ?: '-')."→{$ncmBling}, origem ".($atual['tributacao']['origem'] ?? '-')."→{$fiscal['origem']}");

                if ($aplicar) {
                    $atual['tributacao'] = array_merge($atual['tributacao'] ?? [], array_filter(['ncm' => $ncmBling, 'origem' => (int) $fiscal['origem'], 'cest' => $fiscal['cest']], fn ($v) => $v !== null && $v !== ''));
                    unset($atual['id']);
                    $bling->put("produtos/{$id}", $atual);
                    $this->info('   gravado');
                }
                usleep(400_000);
            } catch (Throwable $e) {
                $this->error("   Bling {$codigo}: ".mb_substr($e->getMessage(), 0, 200));
            }
        }

        return self::SUCCESS;
    }

    /** @return array{ncm: string, origem: int, csosn: ?string, cest: ?string}|null */
    private function fiscal(int $produtoId, NcmValidatorService $ncms): ?array
    {
        $produto = Product::query()->with('fiscalData')->find($produtoId);
        $f = $produto?->fiscalData;
        $ncm = preg_replace('/\D/', '', (string) $f?->ncm);

        if (! $f || ! $ncms->isValid($ncm)) {
            $this->error("Produto {$produtoId}: sem NCM válido no cadastro — corrija o cadastro antes");

            return null;
        }

        return ['ncm' => $ncm, 'origem' => (int) $f->origem, 'csosn' => $f->icms_situacao_tributaria, 'cest' => $f->cest ? preg_replace('/\D/', '', $f->cest) : null];
    }
}
