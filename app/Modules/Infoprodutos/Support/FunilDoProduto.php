<?php

namespace App\Modules\Infoprodutos\Support;

use App\Http\Middleware\TrackSiteVisit;
use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Lead;
use App\Modules\Checkout\Models\Order;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\FerraKora\Services\CatalogoFerraKora;
use App\Modules\Infoprodutos\Models\InfoprodutoEvento;
use App\Modules\Infoprodutos\Models\InfoprodutoVenda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Funil completo da página de produto até o pedido pago (pedido do Lira em
 * 2026-11-23: "os passos preenchidos no checkout, temos que cruzar essas
 * infos"). Só contagens: nome, e-mail, telefone e CPF nunca saem daqui.
 *
 * COMO LIGA A PESSOA AO PRODUTO (lojas KazaKora e FerraKora)
 *
 *  1. Visitou: visitantes da página no período (site_visits), no aparelho da
 *     tela (pelo navegador gravado na visita; tablet conta como celular), sem
 *     robô (mais de SiteVisit::VISITAS_DIA_ROBO páginas num dia) e sem a
 *     equipe (PaginasDoSite::filtroDaEquipe). A pessoa é o visitor_id da
 *     visita; na FerraKora também o md5 do IP, que é o código que o checkout
 *     de lá grava (CheckoutEvento::visitanteDeOutroSite, LeadsDigitados).
 *  2. Pôs ESTE produto no carrinho / COMPRAR AGORA (só KazaKora): evento do
 *     servidor produto_carrinho com o id do produto ou de uma variação dele.
 *     FerraKora: o site não avisa o servidor; vale o clique em Comprar
 *     medido pelo m.js, que é por ABA (não dá para ligar à pessoa).
 *  3. Abriu o checkout com este produto: linha da tabela leads (nasce quando
 *     o checkout abre, com os itens do carrinho) com o produto nos itens; ou,
 *     na KazaKora, quem fez a etapa 2 e abriu a finalização (evento "abriu"
 *     ou visita a /finalizacao, /pagamento).
 *  4 em diante: eventos do checkout (checkout_eventos: preencheu, pagamento,
 *     finalizar, pedido_criado) e os campos do lead, SEMPRE só de quem teve
 *     este produto no carrinho/checkout (etapas 2 e 3). O evento do checkout
 *     não guarda o produto; o produto vem do carrinho da mesma pessoa.
 *  Pago: lead convertido (order_id) num pedido pago com este produto.
 *
 * Quem chegou a uma etapa mais à frente conta nas anteriores (ex.: cliente
 * logado com endereço salvo não digita CEP, mas fez o pedido): o funil nunca
 * sobe de uma etapa para a outra.
 *
 * Infoprodutos: o checkout é da Kiwify, que não conta os campos. Visitou e
 * clicou em comprar são por aba (m.js); pedido gerado e pago são TODAS as
 * vendas do produto no período (não dá para ligar à visita).
 */
class FunilDoProduto
{
    /** Escopo de cada etapa (a tela explica). */
    public const PESSOA = 'pessoa';

    public const ABA = 'aba';

    public const PRODUTO = 'produto';

    public const LOJA = 'loja';

    private const CONTATO = ['nome', 'email', 'celular', 'cpf'];

    private const ENDERECO = ['cep', 'endereco', 'numero', 'complemento', 'bairro', 'cidade', 'uf'];

    private const PAGOS = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];

    /**
     * @return array{etapas: list<array<string, mixed>>, maior_queda: ?string, erros: list<array{nome: string, pessoas: int}>, nota: string}|null
     */
    public function montar(string $produto, string $pagina, string $aparelho, Carbon $de, Carbon $ate): ?array
    {
        if (! PaginasDoSite::paginaDeProduto($produto)) {
            return null;
        }

        return match (PaginasDoSite::loja($produto)) {
            'kazakora', 'ferrakora' => $this->daLoja(PaginasDoSite::loja($produto), $produto, $aparelho, $de, $ate),
            default => $this->doInfoproduto($produto, $pagina, $aparelho, $de, $ate),
        };
    }

    // ------------------------------------------------------------------

    private function daLoja(string $loja, string $produto, string $aparelho, Carbon $de, Carbon $ate): array
    {
        $fk = $loja === 'ferrakora';
        $slug = substr($produto, 3);
        $pessoas = $this->visitantes($loja, $slug, $aparelho, $de, $ate);
        $ids = $this->idsDoProduto($loja, $slug);
        $apelidos = collect($pessoas)->flatten()->unique()->values()->all();

        $eventos = $apelidos === [] ? collect() : collect(array_chunk($apelidos, 1000))->flatMap(fn ($lote) => CheckoutEvento::query()
            ->when($fk, fn ($q) => $q->where('site', $loja), fn ($q) => $q->whereNull('site'))
            ->whereIn('visitor_id', $lote)
            ->whereIn('evento', ['abriu', 'preencheu', 'cep_respondido', 'pagamento', 'finalizar', 'erro', CheckoutEvento::PEDIDO_CRIADO, CheckoutEvento::PRODUTO_CARRINHO, 'produto_comprar'])
            ->whereBetween('created_at', [$de, $ate])
            ->toBase()->get(['visitor_id', 'evento', 'campo']));
        $quem = fn (callable $filtro) => $eventos->filter($filtro)->pluck('visitor_id')->map(fn ($v) => (string) $v)->flip()->all();

        // Leads com este produto nos itens (o checkout já abriu com ele).
        $leads = ($apelidos === [] || $ids === []) ? collect() : collect(array_chunk($apelidos, 1000))->flatMap(fn ($lote) => Lead::query()
            ->where('site', $loja)->whereIn('visitor_id', $lote)
            ->where('created_at', '<=', $ate)->where('updated_at', '>=', $de)
            ->get(['visitor_id', 'order_id', 'itens', 'nome', 'email', 'telefone', 'cpf', 'cep']))
            ->filter(fn (Lead $l) => collect($l->itens ?? [])->contains(fn ($i) => in_array((int) ($i['product_id'] ?? 0), $ids, true)
                || in_array((int) ($i['parent_product_id'] ?? 0), $ids, true)));
        $leadQuem = fn (callable $filtro) => $leads->filter($filtro)->pluck('visitor_id')->map(fn ($v) => (string) $v)->flip()->all();

        $idsTexto = array_map('strval', $ids);
        $carrinho = $fk ? [] : $quem(fn ($e) => in_array($e->evento, [CheckoutEvento::PRODUTO_CARRINHO, 'produto_comprar'], true) && in_array((string) $e->campo, $idsTexto, true));
        $abriuFinalizacao = $quem(fn ($e) => $e->evento === 'abriu') + ($fk ? [] : $this->abriuAFinalizacao(array_keys($carrinho), $de, $ate));
        $leadComProduto = $leadQuem(fn () => true);
        // Teve este produto no carrinho ou no checkout: só essas pessoas contam do checkout em diante.
        $comProduto = $leadComProduto + $carrinho;
        $abriu = $leadComProduto + array_intersect_key($abriuFinalizacao, $carrinho);

        $pagos = $this->leadsPagos($leads, $ids);

        $brutas = [
            'visitou' => array_fill_keys(array_keys($pessoas), true),
            'carrinho' => $carrinho,
            'checkout' => $abriu,
            'contato' => $quem(fn ($e) => $e->evento === 'preencheu' && in_array($e->campo, self::CONTATO, true))
                + $leadQuem(fn ($l) => filled($l->nome) || filled($l->email) || filled($l->telefone) || filled($l->cpf)),
            'endereco' => $quem(fn ($e) => ($e->evento === 'preencheu' && in_array($e->campo, self::ENDERECO, true)) || $e->evento === 'cep_respondido')
                + $leadQuem(fn ($l) => filled($l->cep)),
            'pagamento' => $quem(fn ($e) => in_array($e->evento, ['pagamento', 'finalizar'], true)),
            'pedido' => $quem(fn ($e) => $e->evento === CheckoutEvento::PEDIDO_CRIADO) + $leadQuem(fn ($l) => $l->order_id !== null),
            'pago' => $pagos,
        ];

        // Cada pessoa da tela (com todos os apelidos) em cada etapa.
        $tem = fn (array $apelidosDaPessoa, array $conjunto) => (bool) array_filter($apelidosDaPessoa, fn ($a) => isset($conjunto[$a]));
        $daEtapa = [];
        foreach ($brutas as $etapa => $conjunto) {
            $daEtapa[$etapa] = [];
            foreach ($pessoas as $v => $aps) {
                $ligada = $etapa === 'visitou' || $etapa === 'carrinho' || $etapa === 'checkout' || $tem($aps, $comProduto);
                if ($ligada && $tem($aps, $etapa === 'visitou' ? [$v => true] : $conjunto)) {
                    $daEtapa[$etapa][$v] = true;
                }
            }
        }
        // Quem chegou mais à frente passou pelas anteriores.
        $ordem = array_keys($brutas);
        for ($i = count($ordem) - 2; $i >= 1; $i--) {
            $daEtapa[$ordem[$i]] += $daEtapa[$ordem[$i + 1]];
        }

        $nomes = [
            'visitou' => 'Visitou a página',
            'carrinho' => $fk ? 'Clicou em Comprar' : 'Pôs no carrinho / Comprar agora',
            'checkout' => 'Abriu o checkout com o produto',
            'contato' => 'Preencheu o contato (nome, e-mail, WhatsApp ou CPF)',
            'endereco' => 'Preencheu CEP / endereço',
            'pagamento' => 'Escolheu o pagamento ou clicou em Finalizar',
            'pedido' => 'Pedido criado (Pix/boleto gerado ou cartão enviado)',
            'pago' => 'Pago',
        ];
        $etapas = [];
        foreach ($ordem as $etapa) {
            $n = count($daEtapa[$etapa]);
            $escopo = self::PESSOA;
            if ($fk && $etapa === 'carrinho') {
                // Clique medido por aba; nunca menos do que quem abriu o checkout com o produto.
                $n = max($this->cliquesDeCompra($produto, $aparelho, $de, $ate), count($daEtapa['checkout']));
                $escopo = self::ABA;
            }
            $etapas[] = ['chave' => $etapa, 'nome' => $nomes[$etapa], 'pessoas' => $n, 'escopo' => $escopo];
        }

        // Erros mostrados no checkout para quem estava com o produto (ex.: cartão recusado).
        $nomesErro = ['cartao' => 'Erro no cartão (recusado ou dados)', 'pagamento' => 'Erro no pagamento', 'entrega' => 'Erro na entrega/frete', 'cep' => 'CEP com erro', 'cpf' => 'CPF com erro', 'email' => 'E-mail com erro', 'celular' => 'WhatsApp com erro'];
        $erros = [];
        foreach ($eventos->where('evento', 'erro')->groupBy('campo') as $campo => $lista) {
            $quemErrou = $lista->pluck('visitor_id')->map(fn ($v) => (string) $v)->flip()->all();
            $total = count(array_filter($pessoas, fn ($aps) => $tem($aps, $comProduto) && $tem($aps, $quemErrou)));
            if ($total > 0) {
                $erros[] = ['nome' => $nomesErro[$campo] ?? 'Erro: '.$campo, 'pessoas' => $total];
            }
        }
        usort($erros, fn ($a, $b) => $b['pessoas'] <=> $a['pessoas']);

        return $this->fechar($etapas, $erros, $fk
            ? 'Mesma pessoa (visitante ou IP) do produto ao pedido; o clique em Comprar é contado por aba (m.js).'
            : 'Mesma pessoa (visitante) do produto ao pedido; do checkout em diante só quem teve este produto no carrinho.');
    }

    /** Infoproduto (landing = principal, oferta = upsell, oferta-2 = downsell). */
    private function doInfoproduto(string $produto, string $pagina, string $aparelho, Carbon $de, Carbon $ate): array
    {
        $sessoes = $this->sessoesDoMjs($produto, $pagina, $aparelho, $de, $ate);
        $compraram = $sessoes === [] ? 0 : collect(array_chunk($sessoes, 1000))->sum(fn ($lote) => InfoprodutoEvento::query()
            ->where('produto', $produto)->where('pagina', $pagina)->where('tipo', 'buy')->dePessoas(Comportamento::$comTestes)
            ->whereIn('sessao', $lote)->whereBetween('created_at', [$de, $ate])->distinct()->count('sessao'));
        $tipo = ['landing' => 'principal', 'oferta' => 'upsell', 'oferta-2' => 'downsell'][$pagina] ?? 'principal';
        $vendas = InfoprodutoVenda::query()->where('produto', $produto)->where('tipo', $tipo)->whereBetween('criada_em', [$de, $ate]);

        $etapas = [
            ['chave' => 'visitou', 'nome' => 'Visitou a página', 'pessoas' => count($sessoes), 'escopo' => self::ABA],
            ['chave' => 'carrinho', 'nome' => 'Clicou em comprar (vai para a Kiwify)', 'pessoas' => (int) $compraram, 'escopo' => self::ABA],
            ['chave' => 'pedido', 'nome' => 'Pedido gerado na Kiwify (todos os aparelhos)', 'pessoas' => (int) (clone $vendas)->count(), 'escopo' => self::PRODUTO],
            ['chave' => 'pago', 'nome' => 'Pago (todos os aparelhos)', 'pessoas' => (int) (clone $vendas)->where('status', InfoprodutoVenda::APROVADA)->count(), 'escopo' => self::PRODUTO],
        ];

        return $this->fechar($etapas, [], 'O checkout é da Kiwify: os campos preenchidos lá não chegam até nós. Pedidos e vendas são todos os do produto no período, sem ligar à visita.');
    }

    /** % de passagem de cada etapa e a maior queda (em pessoas perdidas, com a taxa como desempate). */
    private function fechar(array $etapas, array $erros, string $nota): array
    {
        $maior = null;
        foreach ($etapas as $i => &$e) {
            $anterior = $i > 0 ? $etapas[$i - 1]['pessoas'] : null;
            $e['taxa'] = $anterior ? round(min(100, $e['pessoas'] / $anterior * 100), 1) : null;
            $e['perdeu'] = $anterior !== null ? max(0, $anterior - $e['pessoas']) : null;
            if ($e['perdeu'] > 0 && ($maior === null || $e['perdeu'] > $maior['perdeu'] || ($e['perdeu'] === $maior['perdeu'] && $e['taxa'] < $maior['taxa']))) {
                $maior = $e;
            }
        }
        unset($e);
        $maior = $maior['chave'] ?? null;

        return ['etapas' => $etapas, 'maior_queda' => $maior, 'erros' => $erros, 'nota' => $nota];
    }

    /**
     * Visitantes da página de produto: visitor_id => apelidos (o próprio id e,
     * na FerraKora, o md5 do IP), já sem robô, sem equipe e no aparelho.
     *
     * @return array<string, list<string>>
     */
    private function visitantes(string $loja, string $slug, string $aparelho, Carbon $de, Carbon $ate): array
    {
        $prefixo = $loja === 'ferrakora' ? '/produto/' : '/produtos/';
        $linhas = SiteVisit::query()->where('site', $loja)->whereBetween('created_at', [$de, $ate])
            ->where('path', 'like', $prefixo.$slug.'%')
            ->toBase()->get(['visitor_id', 'ip', 'user_id', 'path', 'user_agent'])
            ->filter(fn ($l) => PaginasDoSite::chave($loja, (string) $l->path) === [($loja === 'ferrakora' ? 'fk/' : 'kz/').$slug, 'produto']);

        $daEquipe = PaginasDoSite::filtroDaEquipe();
        $pessoas = [];
        foreach ($linhas as $l) {
            $v = (string) $l->visitor_id;
            $disp = TrackSiteVisit::dispositivo((string) $l->user_agent) === 'computador' ? Comportamento::COMPUTADOR : Comportamento::CELULAR;
            if ($disp !== $aparelho || $daEquipe($v, $l->ip, $l->user_id)) {
                continue;
            }
            $pessoas[$v] ??= [$v];
            if ($loja === 'ferrakora' && $l->ip !== null) {
                $pessoas[$v][] = substr(md5((string) $l->ip), 0, 32);
            }
        }
        if ($pessoas === []) {
            return [];
        }
        // Equipe pelo navegador também em outras visitas do mesmo visitante (logou depois), e robô.
        $todas = collect(array_chunk(array_keys($pessoas), 1000))->flatMap(fn ($lote) => SiteVisit::query()->where('site', $loja)
            ->whereIn('visitor_id', $lote)->whereBetween('created_at', [$de, $ate])->toBase()->get(['visitor_id', 'ip', 'user_id', 'created_at']));
        $fora = $todas->countBy(fn ($l) => $l->visitor_id.'|'.substr((string) $l->created_at, 0, 10))
            ->filter(fn ($n) => $n > SiteVisit::VISITAS_DIA_ROBO)->keys()->map(fn ($k) => explode('|', $k)[0])->flip()->all();
        foreach ($todas as $l) {
            if ($daEquipe((string) $l->visitor_id, $l->ip, $l->user_id)) {
                $fora[(string) $l->visitor_id] = true;
            }
        }

        return array_map(fn ($aps) => array_values(array_unique($aps)), array_diff_key($pessoas, $fora));
    }

    /** @return list<int> id do produto pai e das variações */
    private function idsDoProduto(string $loja, string $slug): array
    {
        if ($loja === 'ferrakora') {
            $catalogo = app(CatalogoFerraKora::class);
            $pais = FerraKoraProduto::query()->get()->filter(fn ($r) => $catalogo->slugPublicado($r) === $slug)->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        } else {
            $pais = Product::query()->withTrashed()->where('slug', $slug)->whereNull('parent_product_id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        if ($pais === []) {
            return [];
        }

        return array_values(array_unique([...$pais, ...Product::query()->withTrashed()->whereIn('parent_product_id', $pais)->pluck('id')->map(fn ($id) => (int) $id)->all()]));
    }

    /** KazaKora: abriu a finalização (visita a /finalizacao ou /pagamento), para quem pôs o produto no carrinho. */
    private function abriuAFinalizacao(array $visitantes, Carbon $de, Carbon $ate): array
    {
        if ($visitantes === []) {
            return [];
        }

        return SiteVisit::query()->where('site', 'kazakora')->whereIn('visitor_id', $visitantes)->whereBetween('created_at', [$de, $ate])
            ->where(fn ($q) => $q->where('path', 'like', '/finalizacao%')->orWhere('path', '/pagamento')->orWhere('path', 'like', '/pagamento?%'))
            ->pluck('visitor_id')->map(fn ($v) => (string) $v)->flip()->all();
    }

    /** Pago: leads com este produto convertidos num pedido pago que tem o produto. */
    private function leadsPagos(Collection $leads, array $ids): array
    {
        $pedidos = $leads->pluck('order_id')->filter()->unique()->values();
        if ($pedidos->isEmpty()) {
            return [];
        }
        $pagos = Order::query()->whereIn('id', $pedidos->all())->whereIn('status', self::PAGOS)
            ->whereHas('items', fn ($q) => $q->whereIn('product_id', $ids))->pluck('id')->flip()->all();

        return $leads->filter(fn ($l) => $l->order_id !== null && isset($pagos[$l->order_id]))
            ->pluck('visitor_id')->map(fn ($v) => (string) $v)->flip()->all();
    }

    /** Abas (m.js) que clicaram em Comprar/Carrinho na página, no aparelho da tela. */
    private function cliquesDeCompra(string $produto, string $aparelho, Carbon $de, Carbon $ate): int
    {
        $sessoes = $this->sessoesDoMjs($produto, 'produto', $aparelho, $de, $ate);

        return $sessoes === [] ? 0 : (int) collect(array_chunk($sessoes, 1000))->sum(fn ($lote) => InfoprodutoEvento::query()
            ->where('produto', $produto)->dePessoas(Comportamento::$comTestes)->whereIn('sessao', $lote)
            ->whereBetween('created_at', [$de, $ate])
            ->where(fn ($q) => $q->where('tipo', 'buy')
                ->orWhere(fn ($q) => $q->where('tipo', 'clk')->where(fn ($q) => $q->where('nome', 'carrinho')->orWhere('nome', 'like', 'comprar%'))))
            ->distinct()->count('sessao'));
    }

    /** Abas do m.js que viram a página no aparelho (mesma regra da tela: mobile/tablet = celular). @return list<string> */
    private function sessoesDoMjs(string $produto, string $pagina, string $aparelho, Carbon $de, Carbon $ate): array
    {
        return InfoprodutoEvento::query()->where('tipo', 'pageview')->dePessoas(Comportamento::$comTestes)
            ->where('produto', $produto)->where('pagina', $pagina)->whereBetween('created_at', [$de, $ate])
            ->toBase()->get(['sessao', 'dispositivo'])
            ->filter(fn ($p) => in_array($p->dispositivo, ['mobile', 'tablet', 'celular'], true) === ($aparelho === Comportamento::CELULAR))
            ->pluck('sessao')->unique()->values()->all();
    }
}
