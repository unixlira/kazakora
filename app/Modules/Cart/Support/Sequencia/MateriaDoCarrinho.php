<?php

namespace App\Modules\Cart\Support\Sequencia;

use App\Modules\Catalog\Models\Product;
use App\Modules\Editorial\Models\EditorialPost;
use App\Modules\WhatsApp\Support\WhatsAppNumeros;
use App\Support\SiteAtual;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * A matéria do blog do e-mail do D+5 da sequência de carrinho abandonado
 * (Caderno KazaKora, pedido do Lira 26/11/2026): a mais aderente ao produto
 * do carrinho, só do blog da própria loja (KazaKora e FerraKora não se
 * misturam).
 *
 * Pontos: matéria ligada ao produto (ou ao pai, na variação) +10, mesma
 * categoria +3, cada palavra do nome/categoria do produto no título +2 e no
 * resumo +1. Notícia genérica de compras (Black Friday, Procon, Senacon,
 * varejo, direitos do consumidor) -6: não fala do produto. Empate: a mais
 * recente. Nenhuma com ponto: a mais recente do blog que não é genérica.
 */
class MateriaDoCarrinho
{
    private const PALAVRAS_FRACAS = [
        'para', 'como', 'mais', 'voce', 'seus', 'suas', 'sem', 'com', 'unidades', 'unidade', 'gratis', 'guia', 'kit',
        'pdf', 'novo', 'nova', 'mesmo', 'ritmo', 'depender', 'dentro', 'cada', 'todo', 'toda', 'isso', 'esse', 'essa',
    ];

    /** Notícia de compras em geral, que não fala de produto nenhum. */
    public const GENERICA = '/black ?friday|procon|senacon|varejo|direitos|consumidor/iu';

    /** @return array{post: EditorialPost, titulo: string, resumo: string, imagem: ?string, link: string, tipo: string, pontos: int}|null */
    public function escolher(string $site, ?Product $produto): ?array
    {
        try {
            $site = $site === SiteAtual::FERRAKORA ? EditorialPost::FERRAKORA : EditorialPost::KAZAKORA;
            $posts = EditorialPost::query()->doSite($site)->visible()->with('products:id')
                ->orderByDesc('published_at')->orderByDesc('id')->limit(300)->get();
        } catch (Throwable) {
            return null;
        }

        if ($posts->isEmpty()) {
            return null;
        }

        [$post, $pontos] = $this->melhor($posts, $produto);

        return [
            'post' => $post,
            'titulo' => (string) $post->title,
            'resumo' => Str::limit(trim((string) ($post->excerpt ?: preg_replace('/\s+/u', ' ', strip_tags((string) $post->body)))), 260, '...'),
            'imagem' => $this->imagem($site, $post->poster_url),
            'link' => $this->link($site, (string) $post->slug),
            'tipo' => match ($post->type) {
                'video' => 'Vídeo',
                'recipe' => 'Receita',
                default => 'Matéria',
            },
            'pontos' => $pontos,
        ];
    }

    /** @return array{0: EditorialPost, 1: int} */
    private function melhor(Collection $posts, ?Product $produto): array
    {
        if (! $produto) {
            return [$posts->first(fn ($p) => ! preg_match(self::GENERICA, (string) $p->title)) ?? $posts->first(), 0];
        }

        $ids = array_filter([(int) $produto->id, (int) $produto->parent_product_id]);
        $categoria = Str::slug((string) $produto->category?->name);
        $palavras = self::palavras($produto->name.' '.$produto->category?->name);

        $melhor = null;
        $maior = null;
        foreach ($posts as $post) {
            $pontos = preg_match(self::GENERICA, (string) $post->title) ? -6 : 0;
            if ($post->products->pluck('id')->intersect($ids)->isNotEmpty()) {
                $pontos += 10;
            }
            if ($categoria !== '' && Str::slug((string) $post->category) === $categoria) {
                $pontos += 3;
            }
            $titulo = self::palavras((string) $post->title);
            $resumo = self::palavras((string) $post->excerpt.' '.$post->category);
            foreach ($palavras as $palavra) {
                $pontos += in_array($palavra, $titulo, true) ? 2 : (in_array($palavra, $resumo, true) ? 1 : 0);
            }
            // Posts vêm do mais novo para o mais velho: só troca com ponto maior.
            if ($maior === null || $pontos > $maior) {
                $maior = $pontos;
                $melhor = $post;
            }
        }

        return [$melhor ?? $posts->first(), max(0, (int) $maior)];
    }

    /** @return list<string> palavras de 4+ letras, sem acento, sem as fracas, com o singular simples */
    public static function palavras(string $texto): array
    {
        $partes = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($texto))) ?: [];

        return array_values(array_unique(array_filter(array_map(
            fn ($p) => strlen($p) > 4 && str_ends_with($p, 's') ? substr($p, 0, -1) : $p,
            array_filter($partes, fn ($p) => strlen($p) >= 4 && ! in_array($p, self::PALAVRAS_FRACAS, true)),
        ))));
    }

    private function link(string $site, string $slug): string
    {
        $base = $site === EditorialPost::FERRAKORA
            ? (WhatsAppNumeros::site(SiteAtual::FERRAKORA) ?: rtrim((string) config('app.url'), '/'))
            : (WhatsAppNumeros::site(SiteAtual::KAZAKORA) ?: rtrim((string) config('app.url'), '/'));

        return $base.'/blog/'.$slug.'?'.http_build_query([
            'utm_source' => 'email',
            'utm_medium' => 'carrinho_abandonado',
            'utm_campaign' => 'd5_caderno',
        ]);
    }

    private function imagem(string $site, ?string $caminho): ?string
    {
        if (! $caminho) {
            return null;
        }
        if (str_starts_with($caminho, 'https://')) {
            return $caminho;
        }

        return str_starts_with($caminho, '/') ? rtrim((string) config('app.url'), '/').$caminho : null;
    }
}
