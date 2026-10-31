<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Support\NomeBrasileiro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Avaliações do carrossel do checkout (modelo Mavo): o da loja e o dos outros
 * sites (FerraKora e Beauty Kora) pela API de resumo. Só as que existem no
 * banco, nota 4 ou 5 e com texto, só dos produtos do carrinho (anúncio pai e
 * variações); com foto antes. As "melhores da loja" não se misturam com as do
 * produto (pedido 2026-10-24, depois do PR #1080), mas voltam sozinhas quando
 * o produto não tem nenhuma: sem isso o carrossel sumia (2026-10-25). O nome segue a mesma regra da página do produto
 * (reviewer_display_name) e a UF só aparece quando é a real (reviews.uf).
 *
 * Foto redonda (pedido 2026-10-24): avatar do pacote da vitrine em
 * public/images/avaliacoes/avatares, do mesmo gênero do nome, escolhido
 * pelo id da avaliação (a mesma avaliação mostra sempre o mesmo rosto) e sem
 * repetir rosto no mesmo carrossel. Máximo de 5 avaliações (pedido 2026-10-24):
 * se o gênero do nome já usou todos os rostos dele, a avaliação é pulada e
 * entra a próxima, então nunca há duas pessoas com a mesma foto.
 *
 * Só elogio claro (pedido 2026-10-25, "nada meia duvidoso"): nota 5 e texto
 * que elogia sem ressalva, dúvida ou reclamação (ver elogioClaro). Vale para
 * o checkout e para a página do produto; a lista completa de avaliações da
 * página continua mostrando todas.
 */
class AvaliacoesDoCheckout
{
    public const LIMITE = 5;

    // Candidatas lidas do banco: avaliação com ressalva ou cujo rosto já saiu é pulada, então precisa sobrar.
    private const CANDIDATAS = 300;

    // Texto até este tamanho aparece inteiro no carrossel (sem "..." escondendo o resto).
    public const TAMANHO_MAXIMO = 220;

    /** Ressalva, dúvida ou reclamação: um destes tira a avaliação do carrossel (texto sem acento, minúsculo). */
    private const RESSALVAS = [
        // contraste
        '\\bmas\\b', '\\bporem\\b', '(^|[.,;!])\\s*mais\\b', '\\bmais (nao|n|consegui|veio|faltou|so|tem|cabe|eu|ainda|achei|da|e|precisa)\\b',
        '\\b(gostei|bom|boa|bonit[oa]|lind[oa]) mais\\b',
        '\\bso que\\b', '(?<!vale a )(?<!valeu a )\\bpena\\b', '\\bapesar\\b', '\\bentretanto\\b', '\\bcontudo\\b', '\\bno entanto\\b',
        '\\binfelizmente\\b', '\\bexceto\\b', '\\btirando\\b', '\\bfora isso\\b', '\\bobs\\b', '\\bunic[oa] (coisa|problema|detalhe|diferenca)\\b',
        // dúvida ou ainda sem usar
        '\\?', '\\bainda (nao|n|testando|vou|em teste|sem)\\b', '\\b(nao|n) (testei|usei|abri|montei|instalei|liguei|cheguei|aprendi|sei|creio)\\b',
        '\\btestarei\\b', '\\btestando\\b', '\\bem teste\\b', '\\bvou (testar|ver|usar|instalar|montar|ligar|abri|abrir)\\b', '\\bvamos (ver|aguardar|testar)\\b',
        '\\bver se\\b', '\\bespero\\b', '\\bparec(e|em|ia)\\b', '\\baparent', '\\bacho que\\b', '\\bcreio\\b', '\\bsera que\\b',
        '\\bduvid', '\\bmedo de\\b', '\\bveremos\\b', '\\bate agora\\b', '\\bpor enquanto\\b', '\\bdurar\\b', '\\bnem\\b',
        // reclamação
        '\\b(nao|n) (funciona|funcionou|veio|vem|chegou|gostei|recomendo|e|tem|serve|liga|ligou|carrega|encaixa|acompanha|condiz|corresponde)\\b',
        '\\bdefeit', '\\bquebr', '\\bfalt(a|ou|ando|aram)\\b', '\\berrad', '\\btrocad', '\\boutra cor\\b', '\\bpedi\\b.*\\b(veio|chegou|mandaram)\\b', '\\bmandaram\\b', '\\batras', '\\bdemor',
        '\\bruim\\b', '\\bpessim', '\\bhorrivel\\b', '\\bfrac[oa]s?\\b', '\\bfragil\\b', '\\bdevolv', '\\breclam', '\\bproblema',
        '\\bdecepc', '\\bdiferent', '\\bmenor\\b', '\\bbarulh', '\\bcomplicad', '\\bdificil\\b', '\\bdificuldade', '\\ba desejar\\b',
        '\\bpoderia\\b', '\\bpodia\\b', '\\brazoavel\\b', '\\bmediano\\b', '\\bregular\\b', '\\bok\\b', '\\bnormal\\b', '\\bcaro\\b',
        '\\bdescasc', '\\bamassad', '\\briscad', '\\bvaza', '\\bsujo\\b', '\\bcheiro forte\\b', '\\bmole\\b', '\\btort[oa]\\b', '\\bcurt[oa]\\b',
        '\\bpouco\\b', '\\bmeio\\b', '\\bcuidado\\b', '\\bperd(i|eu)\\b',
        // promessa no lugar de experiência ("vai amar", "vou tentar instalar")
        '\\bvai (amar|adorar|gostar)\\b', '\\bvou (tentar|colocar)\\b', '\\baprender\\b', '\\btestar\\b',
        '\\ba principio\\b', '\\bsaber se\\b', '\\bse vai\\b', '\\bpecas pequenas\\b',
        '\\bda trabalho\\b', '\\bpaciencia\\b', '\\bprovisori',
    ];

    /** "Não" só passa nestas expressões de elogio; qualquer outro "não" tira a avaliação. */
    private const NAO_ELOGIO = '/\\b(nao|n) (decepciona|se arrepende|vai se arrepender|tem erro|balanca)\\b/';

    /** Elogio direto: o texto precisa ter pelo menos um (texto sem acento, minúsculo). */
    private const ELOGIOS = [
        '\\bexcelent', '\\botim[oa]s?\\b', '\\bperfeit', '\\bame+i+\\b', '\\badorei\\b', '\\badorou\\b', '\\bamou\\b', '\\bamando\\b',
        '\\bgost(ei|ou|amos)\\b', '\\brecomend', '\\bmaravilh', '\\blind[oa]s?\\b', '\\btop\\b', '\\bshow\\b', '\\bsuperou\\b', '\\bsurpreend',
        '\\bvale a pena\\b', '\\bvaleu\\b', '\\bsatisfeit', '\\bnota 10\\b', '\\bsensacional\\b', '\\bincrivel\\b', '\\bespetacular\\b',
        '\\b(muito|bem|super) (bo[am]|bem)\\b', '\\bde qualidade\\b', '\\bqualidade (excelente|otima|muito boa)\\b', '\\bparabens\\b',
        '\\b(pode|podem|comprem?) (comprar )?sem medo\\b', '\\bencantad', '\\bapaixonad',
    ];

    public const AVATARES_FEMININOS = 16;

    public const AVATARES_MASCULINOS = 3;

    /**
     * $catalogo (ids de produto pai publicados no site): os outros sites só
     * mostram avaliações do próprio catálogo (2026-10-24: a Beauty Kora
     * mostrava "Torque forte" de parafusadeira).
     *
     * @param  Collection<int, int>|array<int, int>  $productIds  produtos do carrinho
     * @return list<array{id: int, nota: int, nome: string, uf: ?string, texto: string, avatar: string}>
     */
    public function paraProdutos(Collection|array $productIds, ?Builder $catalogo = null): array
    {
        $ids = collect($productIds)->filter()->unique();
        $grupos = $ids->isEmpty() ? collect() : Product::query()->whereIn('id', $ids)->get(['id', 'parent_product_id'])
            ->map(fn (Product $product) => $product->parent_product_id ?: $product->id)->unique()->values();

        if ($grupos->isEmpty()) {
            return [];
        }

        $base = fn () => Review::query()
            ->where('rating', 5)
            // Vinda de marketplace só aparece na seção do produto, com o aviso (2026-10-31).
            ->where('outra_loja', false)
            ->whereNotNull('comment')
            ->whereRaw('LENGTH(TRIM(comment)) >= 15')
            ->with(['user:id,name', 'images'])
            ->withCount('images')
            ->orderByRaw('images_count > 0 desc')
            ->latest()
            ->orderByDesc('id')
            ->when($catalogo, fn ($q) => $q->whereIn('product_id', Product::query()->whereIn('id', $catalogo)->orWhereIn('parent_product_id', clone $catalogo)->select('id')))
            ->limit(self::CANDIDATAS);

        $avaliacoes = $base()
            ->whereIn('product_id', Product::query()->whereIn('id', $grupos)->orWhereIn('parent_product_id', $grupos)->select('id'))
            ->get()
            ->filter(fn (Review $review) => self::elogioClaro((string) $review->comment));

        // Produto do carrinho sem avaliação (ou só com as que têm ressalva): o carrossel não some, mostra as
        // melhores da loja (pedido 2026-10-25). Com avaliação, só as do produto.
        // Só na KazaKora: os outros sites ($catalogo) seguem sem completar.
        if ($avaliacoes->isEmpty() && $catalogo === null) {
            $avaliacoes = $base()->get()->filter(fn (Review $review) => self::elogioClaro((string) $review->comment));
        }

        $usados = [];
        $resultado = [];

        foreach ($avaliacoes as $review) {
            if (count($resultado) >= self::LIMITE) {
                break;
            }
            if ($review->ocultar_nome) {
                $review->setRelation('user', null);
            }
            $nome = $review->reviewer_display_name;
            $arquivo = self::avatar($review->id, $nome, $usados, repetir: false);
            if ($arquivo === null) {
                continue;
            }

            $resultado[] = [
                'id' => $review->id,
                'nota' => (int) $review->rating,
                'nome' => $nome,
                'uf' => $review->uf ? strtoupper($review->uf) : null,
                'texto' => trim((string) $review->comment),
                'avatar' => asset('images/avaliacoes/avatares/'.$arquivo),
            ];
        }

        return $resultado;
    }

    /**
     * Elogio claro: até TAMANHO_MAXIMO caracteres, pelo menos um elogio direto
     * e nenhuma ressalva, dúvida ou reclamação ("parece bom", "ainda não
     * testei", "mas faltou...", "veio outra cor"). Na dúvida fica fora: o
     * catálogo tem milhares de nota 5 e sempre sobra elogio.
     */
    public static function elogioClaro(string $texto): bool
    {
        $texto = trim(preg_replace('/\\s+/u', ' ', $texto) ?? '');
        if (mb_strlen($texto) < 15 || mb_strlen($texto) > self::TAMANHO_MAXIMO) {
            return false;
        }
        $normal = mb_strtolower(Str::ascii($texto));
        $semNaoElogio = preg_replace(self::NAO_ELOGIO, ' ', $normal);
        if (preg_match('/\\b(nao|n)\\b/', $semNaoElogio)) {
            return false;
        }

        foreach (self::RESSALVAS as $padrao) {
            if (preg_match('/'.$padrao.'/', $semNaoElogio)) {
                return false;
            }
        }
        foreach (self::ELOGIOS as $padrao) {
            if (preg_match('/'.$padrao.'/', $normal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Arquivo do avatar ("f03.webp"). $usados guarda os já mostrados no
     * carrossel; quando todos do gênero já saíram, volta a repetir (ou devolve
     * null com $repetir = false).
     *
     * @param  array<string, true>  $usados
     */
    public static function avatar(int $semente, string $nome, array &$usados = [], bool $repetir = true): ?string
    {
        [$prefixo, $total] = NomeBrasileiro::feminino($nome)
            ? ['f', self::AVATARES_FEMININOS]
            : ['m', self::AVATARES_MASCULINOS];

        $inicio = hexdec(substr(sha1('avatar-avaliacao:'.$semente), 0, 8)) % $total;
        // Todos do gênero já saíram: começa outra rodada (revezando, sem cair sempre no mesmo).
        if (count(array_filter(array_keys($usados), fn (string $usado) => $usado[0] === $prefixo)) >= $total) {
            if (! $repetir) {
                return null;
            }
            $usados = array_filter($usados, fn (string $usado) => $usado[0] !== $prefixo, ARRAY_FILTER_USE_KEY);
        }
        $arquivo = null;
        for ($passo = 0; $passo < $total; $passo++) {
            $candidato = sprintf('%s%02d.webp', $prefixo, (($inicio + $passo) % $total) + 1);
            if (! isset($usados[$candidato])) {
                $arquivo = $candidato;
                break;
            }
        }
        $usados[$arquivo] = true;

        return $arquivo;
    }
}
