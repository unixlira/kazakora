<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Drivers\AmazonDriver;
use App\Modules\Marketplace\Support\ImportadorRelatorioAmazon;
use App\Modules\Marketplace\Support\TaxasAmazonSpApi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Taxa real de cada venda da Amazon. Desde 2026-10-17 vem direto da SP-API
 * (Finances API, ver TaxasAmazonSpApi), no lugar da coleta pela tela da
 * Seller Central na Cora Box. Idempotente: reexecutar não duplica nem
 * regrava taxa igual; taxa manual nunca é sobrescrita.
 *
 * --seco: só lê a API e mostra o que gravaria (nada é gravado).
 * --so-relatorio: o comportamento antigo, recalcula a taxa de todos os
 * pedidos a partir das linhas já gravadas, sem chamar a API.
 */
class TaxasReaisAmazon extends Command
{
    public const CACHE_ULTIMA = 'amazon:taxas-reais:ultima';

    protected $signature = 'amazon:taxas-reais
        {--dias=30 : Janela das transações (e dos pedidos) a conferir}
        {--seco : Simula: lê a API e mostra o que mudaria, sem gravar}
        {--so-relatorio : Não chama a API; recalcula pelas linhas já gravadas}';

    protected $description = 'Taxa real das vendas da Amazon pela SP-API (Finances), gravada como a do relatório de pagamentos';

    public function handle(TaxasAmazonSpApi $api, ImportadorRelatorioAmazon $importador): int
    {
        if ($this->option('so-relatorio')) {
            $this->info($importador->aplicarTaxasReais().' pedido(s) com taxa real da Amazon.');

            return self::SUCCESS;
        }

        $seco = (bool) $this->option('seco');
        $dias = max(1, (int) $this->option('dias'));

        if (! app(AmazonDriver::class)->isConfigured()) {
            $this->registrar(false, 'SP-API da Amazon não conectada', $seco);
            $this->warn('SP-API da Amazon não conectada: nada a coletar.');

            return self::SUCCESS;
        }

        try {
            $r = $api->sincronizar($dias, $seco);
        } catch (Throwable $e) {
            $erro = mb_substr($e->getMessage(), 0, 300);
            $this->registrar(false, $erro, $seco);
            Log::channel('amazon')->error('amazon.taxas_api.falhou', ['erro' => $erro, 'dias' => $dias]);
            $this->error("Falha ao ler a Finances API da Amazon: {$erro}");

            return self::FAILURE;
        }

        $t = $r['taxas'];
        $this->line(($seco ? '[SIMULAÇÃO, nada gravado] ' : '')."Amazon SP-API desde {$r['desde']}: {$r['transacoes']} transações ".json_encode($r['por_tipo'], JSON_UNESCAPED_UNICODE));
        $this->line("Pedidos com venda na API: {$r['pedidos_api']}. Linhas ".($seco ? 'a gravar' : 'gravadas').": {$r['linhas_gravadas']} (iguais: {$r['linhas_iguais']}).");
        $this->info('Taxas: '.$t['novas'].' nova(s), '.$t['alteradas'].' alterada(s), '.$t['iguais'].' igual(is), '.$t['manuais'].' manual(is) mantida(s).');

        $mudancas = $seco
            ? array_filter($r['conferencia'], fn ($c) => ! $c['igual'])
            : array_map(fn ($m) => ['order_id' => $m['order_id'], 'pedido' => $m['pedido'], 'sistema' => $m['antes'], 'fonte' => $m['fonte_antes'], 'api' => $m['depois'], 'status' => null], $t['mudancas']);
        if ($mudancas !== []) {
            $this->table(['Pedido', 'Amazon', 'Taxa antes', 'Fonte', 'Taxa API'], array_map(fn ($c) => [
                "#{$c['order_id']}", $c['pedido'], $c['sistema'] === null ? '-' : number_format($c['sistema'], 2, ',', '.'), $c['fonte'] ?? '-', number_format($c['api'], 2, ',', '.'),
            ], array_values($mudancas)));
        }

        foreach (['sem_pedido' => 'Na API sem pedido no sistema', 'fora_da_janela' => 'Pedido criado antes da janela (não mexido)', 'com_liquidacao' => 'Já tem liquidação oficial importada (não mexido)', 'reembolsos_contando_como_venda' => 'Reembolsados na Amazon que ainda contam como venda (conferir estorno/devolução)'] as $chave => $titulo) {
            if ($r[$chave] !== []) {
                $this->warn("{$titulo}: ".count($r[$chave]).' ('.implode(', ', array_slice($r[$chave], 0, 15)).(count($r[$chave]) > 15 ? '...' : '').')');
            }
        }

        if ($this->option('verbose')) {
            $this->table(['Pedido', 'Amazon', 'Status', 'Taxa sistema', 'Fonte', 'Taxa API', 'Igual'], array_map(fn ($c) => [
                "#{$c['order_id']}", $c['pedido'], $c['status'] ?? '-', $c['sistema'] === null ? '-' : number_format($c['sistema'], 2, ',', '.'), $c['fonte'] ?? '-', number_format($c['api'], 2, ',', '.'), $c['igual'] ? 'sim' : 'NÃO',
            ], $r['conferencia']));
        }

        $this->registrar(true, null, $seco);
        Log::channel('amazon')->info('amazon.taxas_api', [
            'seco' => $seco, 'dias' => $dias, 'transacoes' => $r['transacoes'], 'pedidos_api' => $r['pedidos_api'],
            'linhas_gravadas' => $r['linhas_gravadas'], 'novas' => $t['novas'], 'alteradas' => $t['alteradas'], 'iguais' => $t['iguais'], 'manuais' => $t['manuais'],
            'mudancas' => $t['mudancas'] ?? [], 'sem_pedido' => $r['sem_pedido'], 'reembolsos_contando_como_venda' => $r['reembolsos_contando_como_venda'],
        ]);

        return self::SUCCESS;
    }

    /** Última execução (lida pelo financeiro:vigiar-coletas). Simulação não conta. */
    private function registrar(bool $ok, ?string $erro, bool $seco): void
    {
        if ($seco) {
            return;
        }
        $anterior = Cache::get(self::CACHE_ULTIMA, []);
        Cache::put(self::CACHE_ULTIMA, [
            'ok' => $ok,
            'em' => now()->toIso8601String(),
            'erro' => $erro,
            'ultimo_ok' => $ok ? now()->toIso8601String() : ($anterior['ultimo_ok'] ?? null),
        ], now()->addDays(30));
    }
}
