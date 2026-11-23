<?php

namespace Tests\Feature\Infoprodutos;

use App\Models\User;
use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Lead;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\Infoprodutos\Support\EquipeDaLoja;
use App\Modules\Infoprodutos\Support\FunilDoProduto;
use App\Modules\Infoprodutos\Support\FunilInfoprodutos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Tela Comportamento (2026-11-23): funil da página de produto até o pedido pago, cruzando o checkout. */
class FunilDoProdutoTest extends TestCase
{
    use RefreshDatabase;

    private const CELULAR = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';

    private const PC = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-19 15:00:00', 'America/Sao_Paulo'));
        EquipeDaLoja::esquecer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        EquipeDaLoja::esquecer();
        parent::tearDown();
    }

    private function pedido(string $status, Product $produto, ?string $site = null): Order
    {
        $order = Order::create(['status' => $status, 'origin' => Order::ORIGIN_STORE, 'site_origem' => $site, 'shipping_name' => 'X', 'shipping_phone' => '1',
            'shipping_zip' => '1', 'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'C',
            'shipping_state' => 'SP', 'subtotal' => 10, 'shipping_cost' => 0, 'total' => 10]);
        $order->items()->create(['product_id' => $produto->id, 'product_name' => 'x', 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10, 'item_type' => OrderItem::TYPE_PRODUCT]);

        return $order;
    }

    private function etapas(array $funil): array
    {
        return collect($funil['etapas'])->mapWithKeys(fn ($e) => [$e['chave'] => $e['pessoas']])->all();
    }

    public function test_kazakora_cruza_visita_carrinho_e_campos_do_checkout_por_pessoa_e_produto(): void
    {
        $pai = Product::factory()->create(['slug' => 'arvore', 'name' => 'Árvore']);
        $variacao = Product::factory()->create(['slug' => 'arvore-2m', 'parent_product_id' => $pai->id]);
        $outro = Product::factory()->create(['slug' => 'pisca']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $visita = fn (string $v, string $ua = self::CELULAR, array $extra = []) => SiteVisit::create($extra + ['site' => 'kazakora', 'visitor_id' => $v,
            'path' => '/produtos/arvore', 'ip' => '1.1.1.1', 'user_agent' => $ua, 'consentiu' => true]);
        $evento = fn (string $v, string $evento, ?string $campo = null) => CheckoutEvento::create(['visitor_id' => $v, 'evento' => $evento, 'campo' => $campo]);
        $lead = fn (string $v, Product $p, array $extra = []) => Lead::create($extra + ['site' => 'kazakora', 'visitor_id' => $v, 'status' => 'aberto',
            'itens' => [['product_id' => $p->id, 'parent_product_id' => $p->parent_product_id, 'nome' => 'x', 'quantidade' => 1, 'subtotal' => 10]]]);

        // A: tudo, até pagar (a variação no carrinho conta para a página do pai).
        $visita('a');
        $evento('a', CheckoutEvento::PRODUTO_CARRINHO, (string) $variacao->id);
        $evento('a', 'abriu');
        $evento('a', 'preencheu', 'email');
        $evento('a', 'preencheu', 'cep');
        $evento('a', 'pagamento', 'pix');
        $evento('a', 'erro', 'cartao');
        $evento('a', CheckoutEvento::PEDIDO_CRIADO);
        $lead('a', $variacao, ['email' => 'a@x.com', 'cep' => '01001000', 'status' => 'convertido', 'order_id' => $this->pedido(Order::STATUS_PAID, $variacao)->id]);
        // B: pôs no carrinho, abriu e só deu o nome.
        $visita('b');
        $evento('b', CheckoutEvento::PRODUTO_CARRINHO, (string) $pai->id);
        $lead('b', $pai, ['nome' => 'Fulano']);
        // F: logado com endereço salvo, não digitou CEP mas fez o pedido (Pix não pago).
        $visita('f');
        $evento('f', CheckoutEvento::PRODUTO_CARRINHO, (string) $pai->id);
        $lead('f', $pai, ['email' => 'f@x.com']);
        $evento('f', CheckoutEvento::PEDIDO_CRIADO);
        // C: só olhou.
        $visita('c');
        // D: olhou, mas comprou OUTRO produto: o checkout dela não entra aqui.
        $visita('d');
        $evento('d', CheckoutEvento::PRODUTO_CARRINHO, (string) $outro->id);
        $evento('d', 'abriu');
        $evento('d', 'preencheu', 'email');
        $evento('d', CheckoutEvento::PEDIDO_CRIADO);
        // Computador: fora do funil do celular.
        $visita('pc', self::PC);
        $evento('pc', CheckoutEvento::PRODUTO_CARRINHO, (string) $pai->id);
        // Equipe: fora.
        $visita('adm', self::CELULAR, ['user_id' => $admin->id, 'ip' => '5.5.5.5']);
        $evento('adm', CheckoutEvento::PRODUTO_CARRINHO, (string) $pai->id);

        [$de, $ate] = FunilInfoprodutos::intervalo('7d');
        $funil = app(FunilDoProduto::class)->montar('kz/arvore', 'produto', 'celular', $de, $ate);

        $this->assertSame([
            'visitou' => 5, 'carrinho' => 3, 'checkout' => 3, 'contato' => 3,
            'endereco' => 2, 'pagamento' => 2, 'pedido' => 2, 'pago' => 1,
        ], $this->etapas($funil));
        $this->assertSame('carrinho', $funil['maior_queda']);
        $this->assertSame(60.0, $funil['etapas'][1]['taxa']);
        $this->assertSame([['nome' => 'Erro no cartão (recusado ou dados)', 'pessoas' => 1]], $funil['erros']);
        $this->assertTrue(collect($funil['etapas'])->every(fn ($e) => $e['escopo'] === FunilDoProduto::PESSOA));
        // Dado pessoal nunca sai na resposta.
        $json = json_encode($funil);
        $this->assertStringNotContainsString('a@x.com', $json);
        $this->assertStringNotContainsString('Fulano', $json);

        $pc = app(FunilDoProduto::class)->montar('kz/arvore', 'produto', 'computador', $de, $ate);
        $this->assertSame(['visitou' => 1, 'carrinho' => 1], array_slice($this->etapas($pc), 0, 2, true));
    }

    public function test_ferrakora_liga_visita_ao_lead_e_aos_eventos_pelo_ip(): void
    {
        $pai = Product::factory()->create(['name' => 'Serra Mármore', 'is_active' => true]);
        FerraKoraProduto::create(['product_id' => $pai->id, 'slug' => 'serra-fk', 'ordem' => 1, 'ativo' => true]);
        $visita = fn (string $v, string $ip) => SiteVisit::create(['site' => 'ferrakora', 'visitor_id' => $v, 'path' => '/produto/serra-fk/',
            'ip' => $ip, 'user_agent' => self::CELULAR, 'consentiu' => true]);
        $codigo = fn (string $ip) => substr(md5($ip), 0, 32);
        $evento = fn (string $ip, string $evento, ?string $campo = null) => CheckoutEvento::create(['site' => 'ferrakora', 'visitor_id' => $codigo($ip), 'evento' => $evento, 'campo' => $campo]);
        $itens = [['product_id' => $pai->id, 'parent_product_id' => null, 'nome' => 'Serra', 'quantidade' => 1, 'subtotal' => 10]];

        // Pessoa 1: abriu, WhatsApp, CEP, Pix e pagou.
        $visita('fa', '2.2.2.2');
        $evento('2.2.2.2', 'abriu');
        $evento('2.2.2.2', 'preencheu', 'celular');
        $evento('2.2.2.2', 'pagamento', 'pix');
        $evento('2.2.2.2', CheckoutEvento::PEDIDO_CRIADO);
        $pedido = $this->pedido(Order::STATUS_PAID, $pai, Order::SITE_FERRAKORA);
        Lead::create(['site' => 'ferrakora', 'visitor_id' => $codigo('2.2.2.2'), 'telefone' => '11999999999', 'cep' => '01001000',
            'itens' => $itens, 'status' => 'convertido', 'order_id' => $pedido->id]);
        // Pessoa 2: abriu o checkout com a serra e saiu sem digitar.
        $visita('fb', '2.2.2.3');
        Lead::create(['site' => 'ferrakora', 'visitor_id' => $codigo('2.2.2.3'), 'itens' => $itens, 'status' => 'aberto']);
        // Pessoa 3: só olhou.
        $visita('fc', '2.2.2.4');

        [$de, $ate] = FunilInfoprodutos::intervalo('7d');
        $funil = app(FunilDoProduto::class)->montar('fk/serra-fk', 'produto', 'celular', $de, $ate);

        $this->assertSame([
            'visitou' => 3, 'carrinho' => 2, 'checkout' => 2, 'contato' => 1,
            'endereco' => 1, 'pagamento' => 1, 'pedido' => 1, 'pago' => 1,
        ], $this->etapas($funil));
        // Empate em pessoas perdidas (1 e 1): a maior queda é a de menor taxa (50%).
        $this->assertSame('contato', $funil['maior_queda']);
        $this->assertSame(FunilDoProduto::ABA, $funil['etapas'][1]['escopo']);
        $this->assertStringNotContainsString('11999999999', json_encode($funil));
    }

    public function test_pagina_que_nao_e_produto_nao_tem_funil(): void
    {
        [$de, $ate] = FunilInfoprodutos::intervalo('7d');

        $this->assertNull(app(FunilDoProduto::class)->montar('kzp/pagamento', 'pagina', 'celular', $de, $ate));
    }
}
