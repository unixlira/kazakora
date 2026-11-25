<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Support\Leads\LeadStatusResolver;
use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Cart\Mail\SequenciaCarrinho;
use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Cart\Support\RecuperacaoCarrinhoWhatsApp;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Lead;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

/**
 * Tela única de Leads (plano do Lira 25/11/2026), na rota temporária
 * /admin/leads-v2: junta Leads, Carrinhos abandonados e Sequência sem
 * repetir a sessão, com a situação calculada num lugar só
 * (LeadStatusResolver), os detalhes sob demanda e as duas ações de hoje.
 */
class LeadsUnicosTest extends TestCase
{
    use RefreshDatabase;

    private ?Product $produto = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-25 11:00', 'America/Sao_Paulo'));
        Mail::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.teste']]])]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function itens(float $valor = 100): array
    {
        $this->produto ??= Product::factory()->create(['name' => 'Luminária Teste', 'price' => 100, 'stock' => 10, 'is_active' => true]);

        return [['product_id' => $this->produto->id, 'nome' => $this->produto->name, 'quantidade' => 1, 'preco_unitario' => $valor, 'subtotal' => $valor]];
    }

    private function quando(object $modelo, int $minutosAtras): void
    {
        $modelo->forceFill(['created_at' => now()->subMinutes($minutosAtras + 2), 'updated_at' => now()->subMinutes($minutosAtras)])->saveQuietly();
    }

    private function lead(string $nome, int $minutosAtras, array $extra = []): Lead
    {
        $lead = Lead::create([
            'site' => 'kazakora', 'session_id' => 'sessao-'.$nome, 'visitor_id' => 'visitante-'.$nome, 'nome' => $nome,
            'email' => mb_strtolower($nome).'@exemplo.com', 'itens' => $this->itens(), 'quantidade_itens' => 1, 'subtotal' => 100,
            'status' => Lead::STATUS_ABERTO, ...$extra,
        ]);
        $this->quando($lead, $minutosAtras);

        return $lead;
    }

    private function carrinho(string $nome, int $minutosAtras, array $extra = []): LeadCheckout
    {
        $lead = LeadCheckout::create([
            'site' => 'kazakora', 'session_id' => 'sessao-'.$nome, 'visitor_id' => 'visitante-'.$nome, 'nome' => $nome,
            'email' => mb_strtolower($nome).'@exemplo.com', 'telefone' => '11999990000', 'itens' => $this->itens(), 'quantidade_itens' => 1,
            'subtotal' => 100, 'status' => LeadCheckout::STATUS_ABERTO, 'etapa' => LeadCheckout::ETAPA_ENTREGA, ...$extra,
        ]);
        $this->quando($lead, $minutosAtras);

        return $lead;
    }

    private function registro(LeadCheckout|string $origem, array $extra = []): Registro
    {
        $deLead = $origem instanceof LeadCheckout;

        return Registro::create([
            'site' => 'kazakora',
            'origem' => $deLead ? Registro::ORIGEM_LEAD : Registro::ORIGEM_SNAPSHOT,
            'origem_id' => $deLead ? $origem->id : 999,
            'nome' => $deLead ? $origem->nome : $origem,
            'email' => $deLead ? $origem->email : mb_strtolower($origem).'@exemplo.com',
            'telefone' => '5511999990000',
            'itens' => $this->itens(),
            'subtotal' => 100,
            'abandonado_em' => now()->subDays(2),
            'inicio_sequencia_em' => now()->subDays(2),
            'disparos_enviados' => 0,
            'proximo_disparo_em' => now()->addHours(3),
            'historico' => [],
            ...$extra,
        ]);
    }

    private function pedido(string $status): Order
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        return Order::withoutEvents(fn () => Order::create([
            'user_id' => $cliente->id, 'status' => $status, 'shipping_name' => $cliente->name, 'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000', 'shipping_street' => 'Rua Teste', 'shipping_number' => '100', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => 100, 'total' => 100,
        ]));
    }

    /** As seis situações, uma por linha. @return array<string, string> nome => chave da linha */
    private function seisSituacoes(): array
    {
        $pago = $this->lead('Paula', 40, ['status' => Lead::STATUS_CONVERTIDO, 'order_id' => $this->pedido(Order::STATUS_PAID)->id, 'convertido_em' => now()->subMinutes(40)]);
        $aguardando = $this->lead('Alice', 2, ['order_id' => $this->pedido(Order::STATUS_AWAITING_PAYMENT)->id]);
        $agora = $this->lead('Nina', 1);

        $remarketing = $this->lead('Rita', 3000);
        $this->registro($this->carrinho('Rita', 3000), ['disparos_enviados' => 2]);

        $parou = $this->lead('Pedro', 4000);
        $this->registro($this->carrinho('Pedro', 4000), ['parado' => true, 'motivo_parada' => Registro::PAROU_PEDIU, 'proximo_disparo_em' => null, 'disparos_enviados' => 1]);

        $abandonou = $this->lead('Bia', 30);

        return [
            'pago' => 'l'.$pago->id, 'aguardando' => 'l'.$aguardando->id, 'agora' => 'l'.$agora->id,
            'remarketing' => 'l'.$remarketing->id, 'parou' => 'l'.$parou->id, 'abandonou' => 'l'.$abandonou->id,
        ];
    }

    private function props(string $url): array
    {
        $props = [];
        $this->actingAs($this->admin())->get($url)->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props) {
                $page->component('Admin/LeadsUnicos/Index');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    public function test_as_seis_situacoes_saem_do_resolvedor_na_ordem_certa(): void
    {
        $chaves = $this->seisSituacoes();

        $linhas = collect($this->props('/admin/leads-v2')['leads']['data'])->keyBy('chave');

        $this->assertCount(6, $linhas);
        foreach ($chaves as $codigo => $chave) {
            $this->assertSame($codigo, $linhas[$chave]['situacao']['codigo'], "linha {$chave}");
        }
        $this->assertSame('Pago', $linhas[$chaves['pago']]['situacao']['rotulo']);
        $this->assertSame('green', $linhas[$chaves['pago']]['situacao']['tom']);
        $this->assertSame('Pedido aguardando pgto', $linhas[$chaves['aguardando']]['situacao']['rotulo']);
        $this->assertNotNull($linhas[$chaves['aguardando']]['pedido']);
        $this->assertSame('No checkout agora', $linhas[$chaves['agora']]['situacao']['rotulo']);
        $this->assertSame('Em remarketing 2/6', $linhas[$chaves['remarketing']]['situacao']['rotulo']);
        $this->assertNotNull($linhas[$chaves['remarketing']]['proximo_contato']);
        $this->assertSame('pediu para parar', $linhas[$chaves['parou']]['situacao']['motivo']);
        $this->assertNull($linhas[$chaves['parou']]['proximo_contato']);
        $this->assertSame('Abandonou', $linhas[$chaves['abandonou']]['situacao']['rotulo']);
    }

    public function test_prioridade_do_resolvedor(): void
    {
        $resolver = new LeadStatusResolver;
        $ativo = new Registro(['parado' => false, 'disparos_enviados' => 3]);
        $agora = now()->subMinute();

        $pago = new Order(['status' => Order::STATUS_PAID]);
        $aguardando = new Order(['status' => Order::STATUS_AWAITING_PAYMENT]);
        $cancelado = new Order(['status' => Order::STATUS_CANCELLED]);

        $this->assertSame('pago', $resolver->resolver($pago, false, $agora, $ativo)['codigo']);
        $this->assertSame('aguardando', $resolver->resolver($aguardando, false, $agora, $ativo)['codigo']);
        // Lead "convertido" de antes da regra nova com pedido só gerado: aguardando, não pago.
        $this->assertSame('aguardando', $resolver->resolver($aguardando, true, null, null)['codigo']);
        $this->assertSame('agora', $resolver->resolver(null, false, $agora, $ativo)['codigo']);
        $this->assertSame('remarketing', $resolver->resolver($cancelado, false, now()->subHour(), $ativo)['codigo']);
        $this->assertSame('Em remarketing 3/6', $resolver->resolver(null, false, now()->subHour(), $ativo)['rotulo']);
        $parado = new Registro(['parado' => true, 'motivo_parada' => Registro::PAROU_SEM_PROMOCOES]);
        $this->assertSame(['parou', 'saiu das promoções'], array_values(array_intersect_key($resolver->resolver(null, false, now()->subHour(), $parado), ['codigo' => 1, 'motivo' => 1])));
        $this->assertSame('abandonou', $resolver->resolver(null, false, now()->subMinutes(6), null)['codigo']);
    }

    public function test_mesma_sessao_vira_uma_linha_so_e_ninguem_some(): void
    {
        // Lead digitado + carrinho da mesma sessão + sequência do carrinho: uma linha.
        $lead = $this->lead('Maria', 200);
        $carrinho = $this->carrinho('Maria', 200);
        $registro = $this->registro($carrinho, ['disparos_enviados' => 1]);
        // Carrinho sem lead digitado (antes de 06/11): linha própria.
        $antigo = $this->carrinho('Joana', 300, ['session_id' => 'sessao-que-nao-tem-lead']);
        // Só na sequência (carrinho da conta, cart_snapshot): linha própria.
        $soSequencia = $this->registro('Clara');
        // Carrinho de outra sessão, mesmo visitante e horário: entra no lead pelo visitor_id.
        $visitante = $this->lead('Vera', 50);
        $carrinhoVisitante = $this->carrinho('Vera', 50, ['session_id' => 'outra-sessao']);

        $linhas = collect($this->props('/admin/leads-v2')['leads']['data'])->keyBy('chave');

        $this->assertCount(4, $linhas);
        $this->assertSame(['lead' => $lead->id, 'carrinho' => $carrinho->id, 'sequencia' => $registro->id], $linhas['l'.$lead->id]['fontes']);
        $this->assertSame(['lead' => null, 'carrinho' => $antigo->id, 'sequencia' => null], $linhas['c'.$antigo->id]['fontes']);
        $this->assertSame(['lead' => null, 'carrinho' => null, 'sequencia' => $soSequencia->id], $linhas['s'.$soSequencia->id]['fontes']);
        $this->assertSame($carrinhoVisitante->id, $linhas['l'.$visitante->id]['fontes']['carrinho']);
    }

    public function test_cards_batem_com_os_filtros_e_respeitam_a_loja(): void
    {
        $this->seisSituacoes();
        $this->lead('Fernanda', 30, ['site' => 'ferrakora']);
        $this->lead('Gabi', 3, ['site' => 'ferrakora']);

        $todas = $this->props('/admin/leads-v2');
        $resumo = $todas['resumo'];
        $this->assertSame(8, $todas['leads']['total']);
        $this->assertSame(2, $resumo['agora']);

        foreach (['agora' => 'agora', 'abertos' => 'abertos', 'remarketing' => 'remarketing', 'pagos' => 'pagos', 'parados' => 'parados'] as $chip => $card) {
            $this->assertSame($resumo[$card], $this->props("/admin/leads-v2?status={$chip}")['leads']['total'], "chip {$chip}");
        }
        $abertos = collect($this->props('/admin/leads-v2?status=abertos')['leads']['data']);
        $this->assertEquals($resumo['valor_aberto'], $abertos->sum('total'));
        $this->assertSame(6, $resumo['abertos']);

        $ferrakora = $this->props('/admin/leads-v2?loja=ferrakora');
        $this->assertSame(2, $ferrakora['leads']['total']);
        $this->assertSame(1, $ferrakora['resumo']['agora']);
        $this->assertSame(0, $ferrakora['resumo']['pagos']);
        foreach (['agora', 'abertos'] as $chip) {
            $this->assertSame($ferrakora['resumo'][$chip], $this->props("/admin/leads-v2?loja=ferrakora&status={$chip}")['leads']['total']);
        }

        // Busca por e-mail e por telefone (dígitos).
        $this->assertSame(1, $this->props('/admin/leads-v2?q=paula@')['leads']['total']);
        $this->assertSame(2, $this->props('/admin/leads-v2?q=(11) 99999-0000')['leads']['total']);
    }

    public function test_remarketing_mostra_os_seis_passos_por_canal(): void
    {
        $lead = $this->lead('Rita', 3000);
        $carrinho = $this->carrinho('Rita', 3000);
        $this->registro($carrinho, [
            'disparos_enviados' => 1,
            'ultimo_disparo_em' => now()->subDay(),
            'historico' => [
                ['numero' => 1, 'dia' => 1, 'canal' => 'whatsapp', 'status' => 'enviado', 'quando' => now()->subDay()->toIso8601String()],
                ['numero' => 1, 'dia' => 1, 'canal' => 'email', 'status' => 'falhou', 'erro' => 'caixa cheia', 'quando' => now()->subDay()->toIso8601String()],
                ['numero' => 2, 'dia' => 3, 'canal' => 'email', 'status' => 'falhou', 'erro' => 'tempo esgotado', 'quando' => now()->subHour()->toIso8601String()],
            ],
        ]);
        $sozinho = $this->lead('Bia', 30);

        $admin = $this->admin();
        $r = $this->actingAs($admin)->getJson("/admin/leads-v2/l{$lead->id}/remarketing")->assertOk();
        $this->assertCount(6, $r->json('passos'));
        $this->assertSame(['D+1', 'D+3', 'D+5', 'D+7', 'D+20', 'D+30'], array_column($r->json('passos'), 'rotulo'));
        $r->assertJsonPath('sequencia.estado', 'andamento')
            ->assertJsonPath('passos.0.canais.whatsapp.estado', 'enviado')
            ->assertJsonPath('passos.0.canais.email.estado', 'falhou')
            ->assertJsonPath('passos.0.canais.email.erro', 'caixa cheia')
            ->assertJsonPath('passos.1.atual', true)
            ->assertJsonPath('passos.1.canais.whatsapp.estado', 'agendado')
            ->assertJsonPath('passos.1.canais.email.estado', 'falhou')
            ->assertJsonPath('passos.5.canais.email.estado', 'agendado')
            ->assertJsonPath('total', 100);

        $this->actingAs($admin)->getJson("/admin/leads-v2/l{$sozinho->id}/remarketing")->assertOk()
            ->assertJsonPath('sequencia.estado', 'nao_inscrito')
            ->assertJsonPath('passos.0.canais.whatsapp.estado', 'pulado');
        $this->assertStringStartsWith('Não inscrito — ', $this->actingAs($admin)->getJson("/admin/leads-v2/l{$sozinho->id}/remarketing")->json('sequencia.texto'));

        $this->actingAs($admin)->getJson('/admin/leads-v2/l999999/remarketing')->assertNotFound();
    }

    public function test_jornada_traz_dados_origem_e_onde_parou(): void
    {
        $lead = $this->lead('Maria', 60, ['cpf' => '12345678901', 'cep' => '01001000', 'telefone' => '11988887777', 'utm_source' => 'facebook', 'utm_medium' => 'cpc', 'utm_campaign' => 'black', 'origem' => 'meta_ads']);
        $v = 'visitante-Maria';
        foreach ([['abriu', null, 64], ['preencheu', 'nome', 63], ['preencheu', 'cep', 62], ['pagamento', 'pix', 61]] as [$evento, $campo, $min]) {
            (new CheckoutEvento)->forceFill(['visitor_id' => $v, 'evento' => $evento, 'campo' => $campo, 'created_at' => now()->subMinutes($min)])->save();
        }
        $pago = $this->lead('Paula', 40, ['status' => Lead::STATUS_CONVERTIDO, 'order_id' => $this->pedido(Order::STATUS_PAID)->id, 'convertido_em' => now()->subMinutes(40)]);

        $admin = $this->admin();
        $this->actingAs($admin)->getJson("/admin/leads-v2/l{$lead->id}/jornada")->assertOk()
            ->assertJsonPath('cliente.cpf', '12345678901')
            ->assertJsonPath('cliente.cep', '01001000')
            ->assertJsonPath('cliente.loja', 'KazaKora')
            ->assertJsonPath('origem.utm_medium', 'cpc')
            ->assertJsonPath('origem.rotulo', 'Meta Ads')
            ->assertJsonPath('resumo.pago', false)
            ->assertJsonPath('resumo.texto', 'Parou em: pagamento (escolheu Pix)')
            ->assertJsonPath('passos.0.evento', 'abriu');

        $r = $this->actingAs($admin)->getJson("/admin/leads-v2/l{$pago->id}/jornada")->assertOk()->assertJsonPath('resumo.pago', true);
        $this->assertStringStartsWith('Pago em ', $r->json('resumo.texto'));
        $this->assertContains('pagamento_confirmado', array_column($r->json('passos'), 'evento'));
        $this->assertContains('pedido_criado', array_column($r->json('passos'), 'evento'));
    }

    public function test_antecipar_um_e_todos_chamam_o_servico_de_hoje(): void
    {
        $elegivel = $this->lead('Maria', 200);
        $carrinho = $this->carrinho('Maria', 200);
        $outro = $this->carrinho('Joana', 300, ['session_id' => 'sem-lead']);
        $this->carrinho('Ja Recebeu', 300, ['session_id' => 'x1', 'whatsapp_enviado_em' => now()]);
        $this->carrinho('Comprou', 300, ['session_id' => 'x2', 'status' => LeadCheckout::STATUS_CONVERTIDO]);
        $this->carrinho('Beauty', 300, ['session_id' => 'x3', 'site' => 'beautykora']);

        $props = $this->props('/admin/leads-v2');
        $this->assertSame(2, $props['resumo']['antecipar']);
        $linhas = collect($props['leads']['data'])->keyBy('chave');
        $this->assertTrue($linhas['l'.$elegivel->id]['pode_antecipar']);
        $this->assertTrue($linhas['c'.$outro->id]['pode_antecipar']);

        $servico = Mockery::mock(RecuperacaoCarrinhoWhatsApp::class);
        $servico->shouldReceive('bloqueio')->andReturnUsing(fn (LeadCheckout $l) => (new RecuperacaoCarrinhoWhatsApp(app(\App\Modules\Cart\Support\Sequencia\ImportadorSequencia::class), app(\App\Modules\Cart\Support\Sequencia\DisparadorSequencia::class)))->bloqueio($l));
        $servico->shouldReceive('enviar')->once()->withArgs(fn (LeadCheckout $l) => $l->id === $carrinho->id)->andReturn(RecuperacaoCarrinhoWhatsApp::OK);
        $this->app->instance(RecuperacaoCarrinhoWhatsApp::class, $servico);

        $admin = $this->admin();
        $this->actingAs($admin)->post("/admin/leads-v2/l{$elegivel->id}/antecipar")->assertSessionHas('success');

        $servico->shouldReceive('enviar')->twice()->andReturn(RecuperacaoCarrinhoWhatsApp::OK, 'A mensagem já foi enviada para este carrinho.');
        $this->actingAs($admin)->post('/admin/leads-v2/antecipar-todos')->assertSessionHas('success', 'Mensagens enviadas: 1. Não enviadas: 1 (ainda fora da sequência, já recebidas ou recusadas).');
    }

    public function test_antecipar_de_verdade_manda_whatsapp_e_email_com_fakes_e_registra(): void
    {
        config([
            'loja.carrinho_sequencia_ativa' => true,
            'services.gemini.api_key' => null,
            'services.whatsapp.access_token' => 'token-teste',
            'services.whatsapp.phone_number_id' => '123456',
            'services.whatsapp.templates.carrinho_lembrete' => 'carrinho_lembrete_kazakora',
            'services.whatsapp.templates.carrinho_novidade' => 'carrinho_novidade_kazakora',
        ]);
        $lead = $this->lead('Maria', 90);
        $carrinho = $this->carrinho('Maria', 90);

        $admin = $this->admin();
        $this->actingAs($admin)->post("/admin/leads-v2/l{$lead->id}/antecipar")->assertSessionHas('success');

        Http::assertSent(fn (HttpRequest $r) => ($r['template']['name'] ?? null) === 'carrinho_lembrete_kazakora');
        Mail::assertSent(SequenciaCarrinho::class, 1);
        $this->assertNotNull($carrinho->fresh()->whatsapp_enviado_em);
        $registro = Registro::query()->where('origem', Registro::ORIGEM_LEAD)->where('origem_id', $carrinho->id)->firstOrFail();
        $this->assertSame(1, $registro->disparos_enviados);

        // Agora a linha mostra a sequência e o provider id do WhatsApp no passo 1.
        $this->actingAs($admin)->getJson("/admin/leads-v2/l{$lead->id}/remarketing")
            ->assertJsonPath('passos.0.canais.whatsapp.estado', 'enviado')
            ->assertJsonPath('passos.0.canais.whatsapp.provider_id', 'wamid.teste')
            ->assertJsonPath('pode_antecipar', false);

        // Segunda vez: o serviço recusa, nada sai de novo.
        $this->actingAs($admin)->post("/admin/leads-v2/l{$lead->id}/antecipar")->assertSessionHas('error');
        Http::assertSentCount(1);
    }

    public function test_sem_login_nao_ve_nem_dispara(): void
    {
        $lead = $this->lead('Maria', 90);
        $this->carrinho('Maria', 90);

        $this->get('/admin/leads-v2')->assertRedirect();
        $this->post("/admin/leads-v2/l{$lead->id}/antecipar")->assertRedirect();
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
