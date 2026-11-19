<?php

namespace App\Console\Commands;

use App\Modules\Infoprodutos\Models\InfoprodutoEvento;
use App\Modules\Infoprodutos\Support\PrintDaPagina;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Prints das páginas medidas para o fundo do mapa de cliques (tela
 * Comportamento). Tira de novo quando a página mudou (hash do HTML), quando
 * o print tem mais de 24 h ou quando não existe. Só as páginas com visita
 * nos últimos 7 dias (até --limite), em 390 px e 1280 px.
 */
class ComportamentoPrints extends Command
{
    protected $signature = 'comportamento:prints {--limite=40} {--forcar} {--produto= : só esta página (produto)}';

    protected $description = 'Atualiza os prints das páginas do mapa de cliques';

    public function handle(PrintDaPagina $print): int
    {
        $paginas = InfoprodutoEvento::query()->where('tipo', 'pageview')->where('teste', false)
            ->where('created_at', '>=', now()->subDays(7))
            ->when($this->option('produto'), fn ($q, $p) => $q->where('produto', $p))
            ->selectRaw('produto, pagina, COUNT(*) as n')->groupBy('produto', 'pagina')
            ->orderByDesc('n')->limit((int) $this->option('limite'))->get();

        foreach ($paginas as $p) {
            $url = PrintDaPagina::url($p->produto, $p->pagina);
            if ($url === null) {
                continue;
            }
            $hash = $this->hash($url);
            foreach (PrintDaPagina::LARGURAS as $aparelho => $largura) {
                $destino = PrintDaPagina::arquivo($p->produto, $p->pagina, $aparelho);
                $info = is_file($destino.'.json') ? json_decode((string) file_get_contents($destino.'.json'), true) : null;
                $velho = ! $info || ! is_file($destino.'.webp') || now()->subDay()->greaterThan($info['em'] ?? '2000-01-01')
                    || ($hash !== null && ($info['hash'] ?? null) !== $hash);
                if (! $velho && ! $this->option('forcar')) {
                    continue;
                }
                try {
                    $novo = $print->tirar($url, $largura, $destino);
                    file_put_contents($destino.'.json', json_encode($novo + ['hash' => $hash]));
                    $this->line("ok {$url} {$aparelho} ({$novo['altura']} px)");
                } catch (Throwable $e) {
                    $this->warn("falhou {$url} {$aparelho}: {$e->getMessage()}");
                }
            }
        }

        return self::SUCCESS;
    }

    private function hash(string $url): ?string
    {
        try {
            $r = Http::timeout(15)->withUserAgent('KazaKoraPrint/1.0 (bot)')->get($url);

            return $r->successful() ? md5((string) preg_replace('/nonce="[^"]*"|csrf[^>]*>/i', '', $r->body())) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
