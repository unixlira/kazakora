<?php

namespace App\Console\Commands;

use App\Modules\FerraKora\Services\ClonagemKikool;
use Illuminate\Console\Command;

/**
 * Clona o catálogo do fornecedor Kikool para o KazaKora, ligado ao FerraKora
 * (pedido 2026-10-13). O arquivo é a lista de produtos levantada da loja da
 * Kikool na Shopee e no Mercado Livre (ver ClonagemKikool). Pode rodar de
 * novo: o que já existe é pulado.
 *
 *   php artisan kikool:clonar /tmp/kikool-clonar.json --limite=20
 */
class ClonarKikool extends Command
{
    protected $signature = 'kikool:clonar
        {arquivo : JSON com a lista de produtos (sku, nome, custo, fotos, descricao)}
        {--limite=0 : máximo de produtos clonados nesta execução (0 = todos)}
        {--relatorio= : grava o resultado de cada item neste arquivo JSON}';

    protected $description = 'Clona os produtos da Kikool (Shopee + Mercado Livre) para o KazaKora e o FerraKora, com preço +35% e estoque 10';

    public function handle(ClonagemKikool $clonagem): int
    {
        $arquivo = (string) $this->argument('arquivo');
        $itens = is_readable($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;

        if (! is_array($itens)) {
            $this->error("Arquivo inválido ou não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        $limite = (int) $this->option('limite');
        $resultado = [];
        $clonados = 0;

        foreach ($itens as $item) {
            if ($limite > 0 && $clonados >= $limite) {
                break;
            }

            $r = ['sku' => $item['sku'] ?? null, ...$clonagem->clonar($item)];
            $resultado[] = $r;
            $clonados += $r['situacao'] === 'clonado' ? 1 : 0;
            $this->line(sprintf('%s %-10s %s', $r['sku'], $r['situacao'], $r['nome'] ?? $r['erro'] ?? ''));
        }

        if ($relatorio = $this->option('relatorio')) {
            file_put_contents($relatorio, json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        $contagem = collect($resultado)->countBy('situacao')->map(fn ($n, $s) => "{$s}: {$n}")->implode(', ');
        $this->info("Fim. {$contagem}");

        return self::SUCCESS;
    }
}
