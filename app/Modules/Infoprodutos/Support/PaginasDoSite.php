<?php

namespace App\Modules\Infoprodutos\Support;

use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Infoprodutos\Models\InfoprodutoEvento;
use App\Modules\Infoprodutos\Models\InfoprodutoVenda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Lista de páginas da tela Comportamento (pedido do Lira em 2026-11-20):
 * TODA página acessada, com ou sem anúncio, com nome e número de visitas, e o
 * funil de cada uma (carrinho, checkout, pedido).
 *
 * Fontes (nada de coleta nova, só o "adicionar ao carrinho" no servidor):
 *  - KazaKora e FerraKora: site_visits (TrackSiteVisit e a tag da FerraKora);
 *  - Infoprodutos: infoproduto_eventos (m.js) e infoproduto_vendas;
 *  - mapa de cliques das lojas: o m.js também mede kz/<slug> e fk/<slug>, e a
 *    página de produto usa a mesma chave nas duas fontes.
 *
 * Fora da conta: robôs (já barrados na gravação, e o visitante com mais de
 * SiteVisit::VISITAS_DIA_ROBO páginas num dia) e a equipe (EquipeDaLoja:
 * usuário da equipe, o navegador dela e os IPs dela).
 *
 * Chave de página: produto + pagina, a mesma do m.js.
 *  - produto da loja: kz/<slug> ou fk/<slug>, pagina "produto";
 *  - outra página da loja: kzp[/caminho] ou fkp[/caminho], pagina "pagina"
 *    (o "/" é kzp; departamento kzp/departamento/<slug>; número ou código no
 *    caminho vira "_", ex.: kzp/rastreio/_);
 *  - infoproduto: <slug> + landing/oferta/oferta-2.
 *
 * Funil por página (visitantes da página no período que também...):
 *  - carrinho: na página de produto da KazaKora, quem pôs ESTE produto no
 *    carrinho ou clicou em COMPRAR AGORA (checkout_eventos produto_carrinho /
 *    produto_comprar, ou o clique medido pelo m.js, o que for maior); nas
 *    outras páginas e no total, quem pôs qualquer coisa, abriu o carrinho ou
 *    foi direto ao checkout. FerraKora: clique em Comprar/Carrinho medido
 *    pelo m.js ou, se maior, quem chegou ao checkout. Infoprodutos: não há.
 *  - checkout: abriu a finalização (KazaKora /finalizacao e /pagamento,
 *    FerraKora /seguro-pagamento) ou, nos infoprodutos, clicou em comprar
 *    (vai para a Kiwify);
 *  - pedidos: pedido criado (checkout_eventos pedido_criado); infoprodutos:
 *    vendas aprovadas (só na landing, principal).
 */
class PaginasDoSite
{
    public const LOJAS = ['kazakora', 'ferrakora'];

    /** checkout_eventos do servidor: adicionar ao carrinho e COMPRAR AGORA (campo = id do produto). */
    private const EVENTO_CARRINHO = 'produto_carrinho';

    private const EVENTO_COMPRAR = 'produto_comprar';

    /** Pedidos de dados (fetch) gravados como visita: não são páginas. */
    private const NAO_E_PAGINA = [
        '#^/busca/sugestoes#', '#^/frete/#', '#^/produtos/[^/]+/.+#', '#^/finalizacao/entrega-gratis#', '#^/finalizacao/[^/]+/status#',
        '#^/rastreio/captcha#', '#^/rastreio/[^/]+/pix#', '#^/livros/#', '#^/boleto/#', '#^/pedidos/[^/]+/(nota|livros)#',
        '#^/api/#', '#^/build/#', '#^/storage/#', '#\.(xml|txt|json|js|css|png|jpe?g|webp|gif|ico|svg|pdf|zip|map)$#',
    ];

    private const NOMES = [
        'kzp' => 'Início', 'kzp/busca' => 'Busca', 'kzp/ofertas' => 'Ofertas', 'kzp/carrinho' => 'Carrinho',
        'kzp/finalizacao' => 'Checkout · entrega', 'kzp/finalizacao/pagamento' => 'Checkout · pagamento',
        'kzp/finalizacao/_/confirmacao' => 'Checkout · pedido confirmado', 'kzp/pagamento' => 'Checkout seguro',
        'kzp/rastreio' => 'Rastreio', 'kzp/rastreio/_' => 'Rastreio de um pedido', 'kzp/entrar' => 'Entrar', 'kzp/cadastro' => 'Cadastro',
        'kzp/esqueci-senha' => 'Esqueci a senha', 'kzp/redefinir-senha' => 'Redefinir senha', 'kzp/pedidos' => 'Meus pedidos',
        'kzp/pedidos/_' => 'Um pedido (cliente)', 'kzp/favoritos' => 'Favoritos', 'kzp/fale-conosco' => 'Fale conosco',
        'kzp/quem-somos' => 'Quem somos', 'kzp/perfil' => 'Perfil', 'kzp/avaliar' => 'Avaliar compra',
        'fkp' => 'Início', 'fkp/produtos' => 'Todos os produtos', 'fkp/seguro-pagamento' => 'Checkout (pagamento seguro)',
        'fkp/meus-pedidos' => 'Meus pedidos', 'fkp/rastreio' => 'Rastreio', 'fkp/entrar' => 'Entrar', 'fkp/cadastro' => 'Cadastro',
        'fkp/perfil' => 'Perfil', 'fkp/rastreio/_' => 'Rastreio de um pedido', 'fkp/meus-pedidos/_' => 'Um pedido (cliente)', 'fkp/quem-somos' => 'Quem somos', 'fkp/nova-senha' => 'Nova senha',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $paginas = [];

    /** @var array<string, array<string, mixed>> loja => totais do período */
    private array $totais = [];

    /**
     * Páginas do período, mais visitadas primeiro, e o funil de cada loja.
     *
     * @return array{paginas: list<array<string, mixed>>, totais: array<string, array<string, mixed>>}
     */
    public function montar(Carbon $de, Carbon $ate): array
    {
        $this->paginas = [];
        $this->totais = [];
        $this->doMjs($de, $ate);
        foreach (self::LOJAS as $loja) {
            $this->daLoja($loja, $de, $ate);
        }
        $this->dosInfoprodutos($de, $ate);
        $this->nomear();

        $paginas = collect($this->paginas)->map(function ($p) {
            unset($p['_quem']);

            return $p;
        })->sortByDesc('visitas')->values()->all();

        return ['paginas' => $paginas, 'totais' => $this->totais];
    }

    /** Chaves das páginas das lojas visitadas desde $de (para os prints). */
    public function visitadas(Carbon $de): Collection
    {
        return SiteVisit::query()->whereIn('site', self::LOJAS)->where('created_at', '>=', $de)
            ->selectRaw('site, path, COUNT(*) as n')->groupBy('site', 'path')->orderByDesc('n')->limit(2000)->toBase()->get()
            ->map(fn ($l) => self::chave((string) $l->site, (string) $l->path))->filter()
            ->unique(fn ($c) => $c[0].'|'.$c[1])->map(fn ($c) => ['produto' => $c[0], 'pagina' => $c[1]])->values();
    }

    /**
     * Caminho gravado em site_visits => [produto, pagina], ou null quando não
     * é página (pedido de dados, arquivo).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function chave(string $site, string $path): ?array
    {
        $prefixo = $site === 'ferrakora' ? 'fkp' : 'kzp';
        if (preg_match('#^/\?categoria=([a-z0-9-]{1,80})$#', $path, $m) === 1) {
            return [$prefixo.'/departamento/'.$m[1], 'pagina'];
        }
        if ($path === '/?busca') {
            return [$prefixo.'/busca', 'pagina'];
        }
        $path = strtolower((string) preg_replace('/[?#].*$/', '', $path));
        $path = '/'.trim($path, '/');
        foreach (self::NAO_E_PAGINA as $re) {
            if (preg_match($re, $path) === 1) {
                return null;
            }
        }
        if ($site === 'kazakora' && preg_match('#^/produtos/([a-z0-9-]{1,120})$#', $path, $m) === 1) {
            return ['kz/'.$m[1], 'produto'];
        }
        if ($site === 'ferrakora' && preg_match('#^/produto/([a-z0-9-]{1,120})$#', $path, $m) === 1) {
            return ['fk/'.$m[1], 'produto'];
        }
        $partes = array_slice(array_values(array_filter(explode('/', $path), fn ($p) => $p !== '')), 0, 3);
        $partes = array_map(function ($p) {
            $p = (string) preg_replace('/[^a-z0-9-]/', '', $p);

            // Número de pedido, código de rastreio, token: "_".
            return $p === '' || (preg_match('/\d/', $p) === 1 && (ctype_digit($p) || strlen($p) >= 6)) ? '_' : substr($p, 0, 40);
        }, $partes);

        return [rtrim($prefixo.'/'.implode('/', $partes), '/'), 'pagina'];
    }

    public static function loja(string $produto): string
    {
        return match (true) {
            str_starts_with($produto, 'kz/'), $produto === 'kzp', str_starts_with($produto, 'kzp/') => 'kazakora',
            str_starts_with($produto, 'fk/'), $produto === 'fkp', str_starts_with($produto, 'fkp/') => 'ferrakora',
            default => 'infoprodutos',
        };
    }

    /** Página da loja que não é produto (kzp/fkp). */
    public static function paginaDeLoja(string $produto): bool
    {
        return in_array($produto, ['kzp', 'fkp'], true) || str_starts_with($produto, 'kzp/') || str_starts_with($produto, 'fkp/');
    }

    /** Endereço público de uma página kzp/fkp (null: busca ou caminho com código). */
    public static function url(string $produto): ?string
    {
        $base = str_starts_with($produto, 'fkp') ? 'https://ferrakora.com' : 'https://kazakora.com';
        $resto = substr($produto, 3);
        if (str_contains($resto, '_') || $resto === '/busca') {
            return null;
        }
        if (preg_match('#^/departamento/([a-z0-9-]+)$#', $resto, $m) === 1) {
            return $base.'/?categoria='.$m[1];
        }

        return $base.($resto === '' ? '/' : $resto.($base === 'https://ferrakora.com' ? '/' : ''));
    }

    // ------------------------------------------------------------------

    private function &pagina(string $produto, string $pagina): array
    {
        $k = $produto.'|'.$pagina;
        if (! isset($this->paginas[$k])) {
            $this->paginas[$k] = [
                'produto' => $produto, 'pagina' => $pagina, 'loja' => self::loja($produto), 'rotulo' => $produto,
                'visitas' => 0, 'medidas' => 0, 'carrinho' => null, 'checkout' => null, 'pedidos' => null, '_quem' => [],
            ];
        }

        return $this->paginas[$k];
    }

    /** Visitas medidas pelo m.js (mapa de cliques). */
    private function doMjs(Carbon $de, Carbon $ate): void
    {
        $linhas = InfoprodutoEvento::query()
            ->where('tipo', 'pageview')->dePessoas(Comportamento::$comTestes)
            ->whereBetween('created_at', [$de, $ate])
            ->selectRaw('produto, pagina, COUNT(DISTINCT sessao) as visitas')
            ->groupBy('produto', 'pagina')->limit(1000)->toBase()->get();
        foreach ($linhas as $l) {
            $p = &$this->pagina((string) $l->produto, (string) $l->pagina);
            $p['medidas'] = (int) $l->visitas;
            $p['visitas'] = max($p['visitas'], (int) $l->visitas);
            unset($p);
        }
    }

    private function daLoja(string $loja, Carbon $de, Carbon $ate): void
    {
        $usuarios = EquipeDaLoja::usuarios();
        $visitantesEquipe = array_flip(EquipeDaLoja::visitantes());
        $ips = EquipeDaLoja::ips();
        $ipsExatos = array_flip(array_filter($ips, fn ($ip) => ! str_contains($ip, '/')));
        $faixas = array_values(array_filter($ips, fn ($ip) => str_contains($ip, '/')));
        $daEquipe = fn (?string $visitante, ?string $ip, $userId) => ($userId !== null && in_array((int) $userId, $usuarios, true))
            || ($visitante !== null && isset($visitantesEquipe[$visitante]))
            || ($ip !== null && (isset($ipsExatos[$ip]) || ($faixas !== [] && IpUtils::checkIp($ip, $faixas))));

        $linhas = SiteVisit::query()->where('site', $loja)->whereBetween('created_at', [$de, $ate])
            ->toBase()->get(['visitor_id', 'ip', 'user_id', 'path', 'created_at']);

        // Robô: mais de VISITAS_DIA_ROBO páginas num dia.
        $robos = $linhas->countBy(fn ($l) => $l->visitor_id.'|'.substr((string) $l->created_at, 0, 10))
            ->filter(fn ($n) => $n > SiteVisit::VISITAS_DIA_ROBO)->keys()->map(fn ($k) => explode('|', $k)[0])->flip()->all();

        $checkout = [];
        $abriuCarrinho = [];
        $todos = [];
        $porPagina = [];
        foreach ($linhas as $l) {
            $v = (string) $l->visitor_id;
            if (isset($robos[$v]) || $daEquipe($v, $l->ip, $l->user_id)) {
                continue;
            }
            // A FerraKora identifica a mesma pessoa pelo IP no checkout (CheckoutEvento::visitanteDeOutroSite).
            $apelidos = [$v];
            if ($loja === 'ferrakora' && $l->ip !== null) {
                $apelidos[] = substr(md5((string) $l->ip), 0, 32);
            }
            $todos[$v] = $apelidos;
            $chave = self::chave($loja, (string) $l->path);
            $caminho = (string) preg_replace('/[?#].*$/', '', (string) $l->path);
            if ($loja === 'kazakora' ? (str_starts_with($caminho, '/finalizacao') || $caminho === '/pagamento') : str_starts_with($caminho, '/seguro-pagamento')) {
                foreach ($apelidos as $a) {
                    $checkout[$a] = true;
                }
            }
            if ($caminho === '/carrinho') {
                $abriuCarrinho[$v] = true;
            }
            if ($chave !== null) {
                $porPagina[$chave[0].'|'.$chave[1]][$v] = $apelidos;
            }
        }

        $site = $loja === 'kazakora' ? null : $loja;
        $eventos = CheckoutEvento::query()
            ->when($site === null, fn ($q) => $q->whereNull('site'), fn ($q) => $q->where('site', $site))
            ->whereIn('evento', ['abriu', CheckoutEvento::PEDIDO_CRIADO, self::EVENTO_CARRINHO, self::EVENTO_COMPRAR])
            ->whereBetween('created_at', [$de, $ate])
            ->toBase()->get(['visitor_id', 'user_id', 'evento', 'campo']);
        $eventos = $eventos->reject(fn ($e) => $daEquipe((string) $e->visitor_id, null, $e->user_id));

        $pedidos = $eventos->where('evento', CheckoutEvento::PEDIDO_CRIADO)->pluck('visitor_id')->map(fn ($v) => (string) $v)->flip()->all();
        foreach ($eventos->where('evento', 'abriu') as $e) {
            $checkout[(string) $e->visitor_id] = true;
        }
        $adicoes = $eventos->whereIn('evento', [self::EVENTO_CARRINHO, self::EVENTO_COMPRAR]);
        // Carrinho: pôs algo no carrinho, abriu o carrinho ou foi direto ao checkout (COMPRAR AGORA).
        $carrinho = $adicoes->pluck('visitor_id')->map(fn ($v) => (string) $v)->flip()->all() + $abriuCarrinho + $checkout;

        // Adições por produto: id (variação inclusive) => slug do produto pai.
        $slugDoId = [];
        $ids = $adicoes->pluck('campo')->filter()->unique()->values();
        if ($ids->isNotEmpty()) {
            $produtos = Product::query()->withTrashed()->whereIn('id', $ids->all())->get(['id', 'slug', 'parent_product_id']);
            $pais = Product::query()->withTrashed()->whereIn('id', $produtos->pluck('parent_product_id')->filter()->all())->pluck('slug', 'id');
            foreach ($produtos as $pr) {
                $slugDoId[(string) $pr->id] = $pr->parent_product_id ? ($pais[$pr->parent_product_id] ?? $pr->slug) : $pr->slug;
            }
        }
        $carrinhoPorProduto = [];
        foreach ($adicoes as $e) {
            if (isset($slugDoId[(string) $e->campo])) {
                $carrinhoPorProduto['kz/'.$slugDoId[(string) $e->campo]][(string) $e->visitor_id] = true;
            }
        }

        $tem = fn (array $apelidos, array $conjunto) => (bool) array_filter($apelidos, fn ($a) => isset($conjunto[$a]));
        // Clique em Comprar/Carrinho medido pelo m.js: a FerraKora não tem outro
        // sinal; na KazaKora cobre o período antes do evento do servidor.
        $mjsCarrinho = $this->cliquesDeCompra($loja === 'ferrakora' ? 'fk/' : 'kz/', $de, $ate);

        foreach ($porPagina as $k => $quem) {
            [$produto, $pagina] = explode('|', $k, 2);
            $p = &$this->pagina($produto, $pagina);
            $p['visitas'] = count($quem);
            $p['checkout'] = count(array_filter($quem, fn ($a) => $tem($a, $checkout)));
            $p['pedidos'] = count(array_filter($quem, fn ($a) => $tem($a, $pedidos)));
            $p['carrinho'] = match (true) {
                $loja === 'kazakora' && $pagina === 'produto' => max(count($carrinhoPorProduto[$produto] ?? []), $mjsCarrinho[$produto] ?? 0),
                $loja === 'kazakora' => count(array_filter($quem, fn ($a) => $tem($a, $carrinho))),
                $pagina === 'produto' => max($mjsCarrinho[$produto] ?? 0, $p['checkout']),
                default => null,
            };
            unset($p);
        }

        $this->totais[$loja] = [
            'visitas' => count($todos),
            'carrinho' => $loja === 'kazakora'
                ? count(array_filter($todos, fn ($a) => $tem($a, $carrinho)))
                : max(array_sum($mjsCarrinho), count(array_filter($todos, fn ($a) => $tem($a, $checkout)))),
            'checkout' => count(array_filter($todos, fn ($a) => $tem($a, $checkout))),
            'pedidos' => count($pedidos),
            'paginas' => count($porPagina),
        ];
    }

    /** Sessões do m.js que clicaram em Comprar/Carrinho, por página de produto (FerraKora). */
    private function cliquesDeCompra(string $prefixo, Carbon $de, Carbon $ate): array
    {
        return InfoprodutoEvento::query()->where('produto', 'like', $prefixo.'%')->dePessoas()
            ->whereBetween('created_at', [$de, $ate])
            ->where(fn ($q) => $q->where('tipo', 'buy')
                ->orWhere(fn ($q) => $q->where('tipo', 'clk')->where(fn ($q) => $q->where('nome', 'carrinho')->orWhere('nome', 'like', 'comprar%'))))
            ->selectRaw('produto, COUNT(DISTINCT sessao) as n')->groupBy('produto')->pluck('n', 'produto')
            ->map(fn ($n) => (int) $n)->all();
    }

    private function dosInfoprodutos(Carbon $de, Carbon $ate): void
    {
        $base = InfoprodutoEvento::query()->where('produto', 'not like', 'kz/%')->where('produto', 'not like', 'fk/%')
            ->dePessoas(Comportamento::$comTestes)
            ->whereBetween('created_at', [$de, $ate]);
        $compra = (clone $base)->where('tipo', 'buy')->selectRaw('produto, pagina, COUNT(DISTINCT sessao) as n')
            ->groupBy('produto', 'pagina')->toBase()->get();
        $vendas = InfoprodutoVenda::query()->where('status', InfoprodutoVenda::APROVADA)->where('tipo', 'principal')
            ->whereBetween('aprovada_em', [$de, $ate])->selectRaw('produto, COUNT(*) as n')->groupBy('produto')->pluck('n', 'produto');

        foreach ($this->paginas as $k => $p) {
            if ($p['loja'] !== 'infoprodutos') {
                continue;
            }
            $this->paginas[$k]['checkout'] = (int) ($compra->first(fn ($c) => $c->produto === $p['produto'] && $c->pagina === $p['pagina'])->n ?? 0);
            $this->paginas[$k]['pedidos'] = $p['pagina'] === 'landing' ? (int) ($vendas[$p['produto']] ?? 0) : null;
        }

        $this->totais['infoprodutos'] = [
            'visitas' => (int) (clone $base)->where('tipo', 'pageview')->distinct()->count('sessao'),
            'carrinho' => null,
            'checkout' => (int) (clone $base)->where('tipo', 'buy')->distinct()->count('sessao'),
            'pedidos' => (int) $vendas->sum(),
            'paginas' => collect($this->paginas)->where('loja', 'infoprodutos')->count(),
        ];
    }

    /** Nome amigável: título do produto, nome do departamento ou da página. */
    private function nomear(): void
    {
        $kz = collect($this->paginas)->pluck('produto')->filter(fn ($p) => str_starts_with($p, 'kz/'))->map(fn ($p) => substr($p, 3))->unique()->values();
        $nomesKz = $kz->isEmpty() ? collect() : Product::query()->withTrashed()->whereIn('slug', $kz->all())->pluck('name', 'slug');
        $temFk = collect($this->paginas)->contains(fn ($p) => str_starts_with($p['produto'], 'fk/'));
        $nomesFk = $temFk ? rescue(fn () => app(PaginasMedidas::class)->nomesFerraKora(), [], false) : [];
        $deps = collect($this->paginas)->pluck('produto')->map(fn ($p) => preg_match('#^(kzp|fkp)/departamento/(.+)$#', $p, $m) ? $m[2] : null)->filter()->unique();
        $nomesDep = $deps->isEmpty() ? collect() : Category::query()->whereIn('slug', $deps->all())->pluck('name', 'slug');

        foreach ($this->paginas as $k => $p) {
            $produto = $p['produto'];
            $this->paginas[$k]['rotulo'] = match (true) {
                str_starts_with($produto, 'kz/') => $nomesKz[substr($produto, 3)] ?? Comportamento::rotuloDaPagina($produto, $p['pagina']),
                str_starts_with($produto, 'fk/') => $nomesFk[substr($produto, 3)] ?? Comportamento::rotuloDaPagina($produto, $p['pagina']),
                self::paginaDeLoja($produto) => self::nomeDaPagina($produto, $nomesDep->all()),
                default => Comportamento::rotuloDaPagina($produto, $p['pagina']),
            };
        }
    }

    /** @param  array<string, string>  $departamentos */
    public static function nomeDaPagina(string $produto, array $departamentos = []): string
    {
        if (isset(self::NOMES[$produto])) {
            return self::NOMES[$produto];
        }
        if (preg_match('#^(kzp|fkp)/departamento/(.+)$#', $produto, $m) === 1) {
            return 'Departamento: '.($departamentos[$m[2]] ?? ucfirst(str_replace('-', ' ', $m[2])));
        }
        if (preg_match('#^fkp/produtos/([^/]+)$#', $produto, $m) === 1) {
            return 'Departamento: '.ucfirst(str_replace('-', ' ', $m[1]));
        }
        if (preg_match('#^kzp/promocoes/(.+)$#', $produto, $m) === 1) {
            return 'Promoção: '.ucfirst(str_replace('-', ' ', $m[1]));
        }
        $resto = trim(substr($produto, 3), '/');
        $texto = str_replace(['/', '-', '_'], [' · ', ' ', 'código'], $resto);

        return ucfirst($texto);
    }
}
