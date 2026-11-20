<?php

namespace App\Console\Commands;

use App\Modules\Infoprodutos\Models\InfoprodutoEvento;
use App\Modules\Infoprodutos\Support\PaginasMedidas;
use App\Modules\Infoprodutos\Support\PrintDaPagina;
use Illuminate\Console\Command;
use Throwable;

/**
 * Prints das páginas para o fundo do mapa de cliques (tela Comportamento),
 * em 390 px (celular) e 1024 px (computador), com fatias e miniatura.
 *
 * - de hora em hora: as páginas com visita nos últimos 7 dias;
 * - de madrugada (--todas): todo produto ativo da KazaKora e da FerraKora e
 *   todas as landings, para o print já estar pronto quando o Lira abrir.
 *
 * Refaz quando não existe, está no formato antigo, a página mudou
 * (PaginasMedidas::assinatura) ou o print tem mais de --dias dias.
 */
class ComportamentoPrints extends Command
{
    protected $signature = 'comportamento:prints {--todas : todas as páginas das lojas e landings, não só as visitadas}
        {--limite=40} {--dias=7 : refaz o print mais velho que isto} {--forcar} {--produto= : só esta página (produto)}';

    protected $description = 'Atualiza os prints das páginas do mapa de cliques';

    public function handle(PrintDaPagina $print, PaginasMedidas $medidas): int
    {
        $paginas = $this->option('todas')
            ? collect($medidas->todas())
            : InfoprodutoEvento::query()->where('tipo', 'pageview')->where('teste', false)
                ->where('created_at', '>=', now()->subDays(7))
                ->selectRaw('produto, pagina, COUNT(*) as n')->groupBy('produto', 'pagina')
                ->orderByDesc('n')->get()->map(fn ($p) => ['produto' => $p->produto, 'pagina' => $p->pagina]);
        $paginas = $paginas->when($this->option('produto'), fn ($c, $p) => $c->where('produto', $p))->take((int) $this->option('limite'));

        $feitos = 0;
        foreach ($paginas as $p) {
            if (PrintDaPagina::url($p['produto'], $p['pagina']) === null) {
                continue;
            }
            $assinatura = $medidas->assinatura($p['produto'], $p['pagina']);
            if ($assinatura === null) {
                $this->line("fora do ar {$p['produto']} {$p['pagina']}");

                continue;
            }
            foreach (array_keys(PrintDaPagina::LARGURAS) as $aparelho) {
                $destino = PrintDaPagina::arquivo($p['produto'], $p['pagina'], $aparelho);
                $info = is_file($destino.'.json') ? json_decode((string) file_get_contents($destino.'.json'), true) : null;
                $velho = ! $info || ! is_file($destino.'.webp') || (int) ($info['versao'] ?? 1) < PrintDaPagina::VERSAO
                    || ($info['hash'] ?? null) !== $assinatura
                    || now()->subDays((int) $this->option('dias'))->greaterThan($info['em'] ?? '2000-01-01');
                if (! $velho && ! $this->option('forcar')) {
                    continue;
                }
                try {
                    $novo = $print->gerar($p['produto'], $p['pagina'], $aparelho, $assinatura);
                    $feitos++;
                    $this->line("ok {$p['produto']} {$p['pagina']} {$aparelho} ({$novo['altura']} px, ".count($novo['fatias']).' fatias)');
                } catch (Throwable $e) {
                    $this->warn("falhou {$p['produto']} {$p['pagina']} {$aparelho}: {$e->getMessage()}");
                }
            }
        }
        $this->info("{$feitos} prints refeitos de {$paginas->count()} páginas.");

        return self::SUCCESS;
    }
}
