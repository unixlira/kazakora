<?php

namespace App\Console\Commands;

use App\Services\Kalodata\Exceptions\KalodataException;
use App\Services\Kalodata\KalodataService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Produtos mais vendidos do TikTok Shop numa categoria, pelo Kalodata.
 * Aceita o id da categoria (1 chamada) ou uma palavra (busca a categoria
 * primeiro: 2 chamadas, ou 1 se a busca já estiver no cache).
 *
 *   php artisan kalodata:produtos 910728
 *   php artisan kalodata:produtos "power bank" --periodo=last7Day --limite=20
 */
class KalodataProdutos extends Command
{
    protected $signature = 'kalodata:produtos
        {categoria : id da categoria ou palavra para achar a categoria}
        {--regiao= : região (padrão KALODATA_REGIAO, BR)}
        {--periodo=last30Day : last7Day, last30Day, AAAA-MM ou AAAA-MM-DD~AAAA-MM-DD}
        {--limite=10 : quantos produtos mostrar (1 a 100)}
        {--ordem= : campo de ordenação da API, ex. revenue, sales_volumn, revenue_growth_rate}';

    protected $description = 'Ranking de produtos do TikTok Shop numa categoria (Kalodata)';

    public function handle(KalodataService $kalodata): int
    {
        $termo = trim((string) $this->argument('categoria'));
        $regiao = $this->option('regiao') ?: null;
        $limite = max(1, min(100, (int) $this->option('limite')));

        try {
            if (ctype_digit($termo)) {
                $categoriaId = (int) $termo;
                $this->line("Categoria {$categoriaId}");
            } else {
                $categorias = $kalodata->buscarCategorias($termo, $regiao);
                if ($categorias === [] || ! isset($categorias[0]['id'])) {
                    $this->error("Nenhuma categoria encontrada para \"{$termo}\".");

                    return self::FAILURE;
                }
                $categoriaId = (int) $categorias[0]['id'];
                $this->line("Categoria: {$categorias[0]['name']} ({$categoriaId})");
                if (count($categorias) > 1) {
                    $outras = collect(array_slice($categorias, 1, 4))->map(fn ($c) => ($c['name'] ?? '?').' ('.($c['id'] ?? '?').')')->implode(', ');
                    $this->line("  outras: {$outras}");
                }
            }

            $ordem = $this->option('ordem') ? ['field' => (string) $this->option('ordem'), 'type' => 'DESC'] : null;

            $produtos = $kalodata->rank(
                'product',
                ['category_ids' => [$categoriaId]],
                (string) $this->option('periodo'),
                1,
                max(5, $limite),
                $ordem,
                $regiao,
            );
        } catch (KalodataException $e) {
            $this->error("ERRO ({$e->tipo}): {$e->getMessage()}");

            return self::FAILURE;
        }

        $produtos = array_slice($produtos, 0, $limite);
        if ($produtos === []) {
            $this->warn('Nenhum produto no período.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Produto', 'Receita', 'Vendas', 'Preço', 'Comissão', 'Cresc.', 'Lançado'],
            array_map(fn ($p) => [
                $p['product_id'] ?? '-',
                Str::limit((string) ($p['product_name'] ?? '-'), 50),
                $this->valor($p['revenue'] ?? null),
                $this->valor($p['sales_volumn'] ?? null, 0),
                $this->valor($p['unit_price'] ?? null),
                $p['commission_rate'] ?? '-',
                $p['revenue_growth_rate'] ?? '-',
                $p['launch_date'] ?? '-',
            ], $produtos),
        );

        $this->line("Chamadas reais hoje: {$kalodata->chamadasHoje()} de {$kalodata->tetoDiario()}.");

        return self::SUCCESS;
    }

    private function valor(mixed $v, int $casas = 2): string
    {
        if ($v === null || $v === '') {
            return '-';
        }

        return is_numeric($v) ? number_format((float) $v, $casas, ',', '.') : (string) $v;
    }
}
