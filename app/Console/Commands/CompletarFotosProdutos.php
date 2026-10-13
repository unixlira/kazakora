<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Services\CompletarFotosProduto;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Traz foto para produto que ficou sem nenhuma (ver CompletarFotosProduto).
 * Sempre rode primeiro com --dry-run.
 *
 *   php artisan produtos:completar-fotos --prefixo=KIC- --dry-run
 *   php artisan produtos:completar-fotos --prefixo=KIC- --fonte=arquivo,bling,ml-catalogo --relatorio=/tmp/fotos.json
 */
class CompletarFotosProdutos extends Command
{
    protected $signature = 'produtos:completar-fotos
        {--prefixo= : só produtos com SKU começando assim (ex.: KIC-)}
        {--sku=* : só estes SKUs}
        {--fonte=arquivo,bling : fontes, na ordem: arquivo, bling, ml-catalogo}
        {--arquivo= : JSON [{sku, fotos: [urls]}] usado pela fonte "arquivo"}
        {--similaridade=0.8 : semelhança mínima de nome para a fonte ml-catalogo (0 a 1)}
        {--limite=0 : máximo de produtos preenchidos (0 = todos)}
        {--relatorio= : grava o resultado de cada produto neste arquivo JSON}
        {--dry-run : só mostra o que faria, não grava nada}';

    protected $description = 'Preenche as fotos de produtos sem nenhuma imagem (arquivo do fornecedor, Bling ou catálogo do ML)';

    public function handle(CompletarFotosProduto $servico): int
    {
        $fontes = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('fonte')))));
        if ($fontes === [] || array_diff($fontes, CompletarFotosProduto::FONTES) !== []) {
            $this->error('Fonte inválida. Use: '.implode(', ', CompletarFotosProduto::FONTES));

            return self::FAILURE;
        }

        if ($arquivo = $this->option('arquivo')) {
            $itens = is_readable($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;
            if (! is_array($itens)) {
                $this->error("Arquivo inválido ou não encontrado: {$arquivo}");

                return self::FAILURE;
            }
            $servico->usarArquivo($itens);
        }

        $seco = (bool) $this->option('dry-run');
        $limite = (int) $this->option('limite');
        $produtos = $servico->semFoto($this->option('prefixo') ?: null, (array) $this->option('sku'))->get();

        $this->info(sprintf('%d produto(s) sem foto. Fontes: %s.%s', $produtos->count(), implode(', ', $fontes), $seco ? ' SIMULAÇÃO (nada será gravado).' : ''));

        $resultado = [];
        $preenchidos = 0;

        foreach ($produtos as $produto) {
            if ($limite > 0 && $preenchidos >= $limite) {
                break;
            }

            $achado = $servico->procurar($produto, $fontes, (float) $this->option('similaridade'));
            $linha = [
                'product_id' => $produto->id,
                'sku' => $produto->sku,
                'nome' => $produto->name,
                'ativo' => (bool) $produto->is_active,
                'fonte' => $achado['fonte'],
                'fotos' => count($achado['fotos']),
                'detalhe' => $achado['detalhe'],
            ];

            if ($achado['fonte'] === null) {
                $linha['situacao'] = 'sem_fonte';
            } elseif ($seco) {
                $linha['situacao'] = 'preencheria';
                $linha['urls'] = $achado['fotos'];
            } else {
                $gravadas = $servico->gravar($produto, $achado['fotos']);
                $linha['situacao'] = $gravadas > 0 ? 'preenchido' : 'download_falhou';
                $linha['fotos'] = $gravadas;
                $preenchidos += $gravadas > 0 ? 1 : 0;
            }

            $resultado[] = $linha;
            $this->line(sprintf('#%d %-18s %-15s %-12s %d foto(s) %s %s', $produto->id, $produto->sku, $linha['situacao'],
                $achado['fonte'] ?? '-', $linha['fotos'], Str::limit((string) $produto->name, 50), $achado['detalhe'] ? "| {$achado['detalhe']}" : ''));
        }

        if ($relatorio = $this->option('relatorio')) {
            file_put_contents($relatorio, json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }

        $contagem = collect($resultado)->countBy('situacao')->map(fn ($n, $s) => "{$s}: {$n}")->implode(', ');
        $this->info('Fim. '.($contagem ?: 'nada a fazer'));

        return self::SUCCESS;
    }
}
