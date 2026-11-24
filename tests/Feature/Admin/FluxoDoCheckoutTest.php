<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Lead;
use App\Modules\Checkout\Models\LeadCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Parou em" e janela "Ver fluxo" em Admin > Carrinhos abandonados e
 * Admin > Leads (pedido do Lira 2026-11-23): o passo a passo do checkout vem
 * de checkout_eventos pelo visitor_id, na janela do lead, em ordem, e nunca
 * mostra o que a pessoa digitou.
 */
class FluxoDoCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const CPF = '12345678901';

    private const EMAIL = 'maria.fluxo@exemplo.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-23 12:00', 'America/Sao_Paulo'));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function evento(string $visitante, string $hora, string $evento, ?string $campo = null, ?int $valor = null, array $extra = []): void
    {
        (new CheckoutEvento)->forceFill([
            'visitor_id' => $visitante,
            'evento' => $evento,
            'campo' => $campo,
            'valor' => $valor,
            'created_at' => Carbon::parse($hora, 'America/Sao_Paulo'),
            ...$extra,
        ])->save();
    }

    private function datas(LeadCheckout|Lead $lead, string $criado, string $atualizado): void
    {
        $lead->forceFill([
            'created_at' => Carbon::parse($criado, 'America/Sao_Paulo'),
            'updated_at' => Carbon::parse($atualizado, 'America/Sao_Paulo'),
        ])->saveQuietly();
    }

    private function carrinhoQueParouNoPagamento(): LeadCheckout
    {
        $produto = Product::factory()->create(['name' => 'Organizador Teste', 'price' => 100, 'stock' => 10, 'is_active' => true]);
        $lead = LeadCheckout::create([
            'site' => 'kazakora', 'visitor_id' => 'visitante-fluxo', 'nome' => 'MARIA FLUXO', 'email' => self::EMAIL,
            'cpf' => self::CPF, 'telefone' => '11999990000',
            'itens' => [['product_id' => $produto->id, 'nome' => $produto->name, 'quantidade' => 1, 'preco_unitario' => 100, 'subtotal' => 100]],
            'subtotal' => 100, 'status' => LeadCheckout::STATUS_ABERTO, 'etapa' => LeadCheckout::ETAPA_ENTREGA,
            'utm_source' => 'facebook', 'utm_campaign' => 'campanha-x',
        ]);
        $this->datas($lead, '2026-11-23 10:02', '2026-11-23 10:06');

        $v = 'visitante-fluxo';
        $this->evento($v, '2026-11-23 09:58:00', CheckoutEvento::PRODUTO_CARRINHO, (string) $produto->id);
        $this->evento($v, '2026-11-23 10:00:00', 'abriu', null, null, ['origem' => 'meta_ads', 'dispositivo' => 'celular']);
        $this->evento($v, '2026-11-23 10:00:10', 'rolagem', null, 50);
        $this->evento($v, '2026-11-23 10:00:20', 'foco', 'nome');
        $this->evento($v, '2026-11-23 10:00:30', 'preencheu', 'nome');
        $this->evento($v, '2026-11-23 10:00:40', 'preencheu', 'email');
        $this->evento($v, '2026-11-23 10:00:50', 'foco', 'cpf');
        $this->evento($v, '2026-11-23 10:01:00', 'preencheu', 'cpf');
        $this->evento($v, '2026-11-23 10:01:10', 'preencheu', 'celular');
        $this->evento($v, '2026-11-23 10:02:00', 'preencheu', 'cep');
        $this->evento($v, '2026-11-23 10:02:05', 'cep_respondido');
        $this->evento($v, '2026-11-23 10:02:30', 'preencheu', 'numero');
        $this->evento($v, '2026-11-23 10:02:40', 'preencheu', 'complemento');
        $this->evento($v, '2026-11-23 10:03:00', 'pagamento', 'cartao');
        $this->evento($v, '2026-11-23 10:05:00', 'saiu', null, 300, ['rolagem' => 75]);

        // Fora do fluxo: outro visitante, outra loja e dias antes.
        $this->evento('outro-visitante', '2026-11-23 10:01:00', 'pagamento', 'pix');
        $this->evento($v, '2026-11-23 10:01:30', 'pagamento', 'boleto', null, ['site' => 'ferrakora']);
        $this->evento($v, '2026-11-20 10:00:00', 'finalizar');

        return $lead;
    }

    public function test_fluxo_do_carrinho_vem_em_ordem_com_a_etapa_e_sem_valores(): void
    {
        $lead = $this->carrinhoQueParouNoPagamento();

        $resposta = $this->actingAs($this->admin())->getJson("/admin/carrinhos-abandonados/{$lead->id}/fluxo")->assertOk();

        $this->assertSame([
            'produto_carrinho', 'abriu', 'rolagem', 'foco', 'preencheu', 'preencheu', 'foco', 'preencheu',
            'preencheu', 'preencheu', 'cep_respondido', 'preencheu', 'preencheu', 'pagamento', 'saiu',
        ], array_column($resposta->json('passos'), 'evento'));
        $this->assertSame(
            [null, null, null, 'nome', 'nome', 'email', 'cpf', 'cpf', 'celular', 'cep', null, 'numero', 'complemento', 'cartao', null],
            array_column($resposta->json('passos'), 'campo'),
        );
        $horas = array_column($resposta->json('passos'), 'hora');
        $ordenadas = $horas;
        sort($ordenadas);
        $this->assertSame($ordenadas, $horas);

        $resposta
            ->assertJsonPath('tem_registro', true)
            ->assertJsonPath('passos.0.titulo', 'Pôs no carrinho: Organizador Teste')
            ->assertJsonPath('passos.1.titulo', 'Abriu o checkout')
            ->assertJsonPath('passos.7.titulo', 'Preencheu CPF')
            ->assertJsonPath('passos.13.titulo', 'Escolheu pagamento: cartão')
            ->assertJsonPath('passos.14.detalhe', 'Ficou 5 min 00 s · rolou até 75%')
            ->assertJsonPath('parou.etapa', 'pagamento')
            ->assertJsonPath('parou.texto', 'Parou no pagamento (escolheu cartão)')
            ->assertJsonPath('resumo.segundos_ativos', 300);
        $this->assertStringContainsString('Meta Ads', $resposta->json('passos.1.detalhe'));
        $this->assertStringContainsString('facebook', $resposta->json('passos.1.detalhe'));

        // Nada do que foi digitado nem dado pessoal do lead.
        $corpo = $resposta->getContent();
        $this->assertStringNotContainsString(self::CPF, $corpo);
        $this->assertStringNotContainsString(self::EMAIL, $corpo);
        $this->assertStringNotContainsString('MARIA', $corpo);
        $this->assertStringNotContainsString('11999990000', $corpo);
    }

    public function test_lista_de_carrinhos_mostra_onde_parou(): void
    {
        $this->carrinhoQueParouNoPagamento();

        $this->actingAs($this->admin())->get('/admin/carrinhos-abandonados')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/LeadsCheckout/Index', false)
                ->where('leads.data.0.parou.etapa', 'pagamento')
                ->where('leads.data.0.parou.texto', 'Parou no pagamento (escolheu cartão)')
                ->where('leads.data.0.parou.tem_registro', true));
    }

    public function test_lead_que_parou_na_entrega_aparece_na_tela_de_leads(): void
    {
        $lead = Lead::create([
            'site' => 'kazakora', 'visitor_id' => 'visitante-leads', 'nome' => 'Joao Lead', 'cpf' => self::CPF, 'cep' => '03187040',
            'ultimo_campo' => 'cep', 'itens' => [], 'status' => Lead::STATUS_ABERTO,
        ]);
        $this->datas($lead, '2026-11-23 11:00', '2026-11-23 11:03');
        $this->evento('visitante-leads', '2026-11-23 11:00:00', 'abriu');
        $this->evento('visitante-leads', '2026-11-23 11:01:00', 'preencheu', 'cpf');
        $this->evento('visitante-leads', '2026-11-23 11:02:00', 'preencheu', 'cep');
        $this->evento('visitante-leads', '2026-11-23 11:02:30', 'foco', 'nome');
        $this->evento('visitante-leads', '2026-11-23 11:03:00', 'saiu', null, 180, ['rolagem' => 40]);

        $admin = $this->admin();
        $resposta = $this->actingAs($admin)->getJson("/admin/leads/{$lead->id}/fluxo")
            ->assertOk()
            ->assertJsonPath('parou.etapa', 'entrega')
            ->assertJsonPath('parou.texto', 'Parou na entrega (CEP preenchido)');
        $this->assertSame(['abriu', 'preencheu', 'preencheu', 'foco', 'saiu'], array_column($resposta->json('passos'), 'evento'));
        $this->assertStringNotContainsString(self::CPF, $resposta->getContent());
        $this->assertStringNotContainsString('03187040', $resposta->getContent());

        $this->actingAs($admin)->get('/admin/leads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Leads/Index', false)
                ->where('leads.data.0.parou.texto', 'Parou na entrega (CEP preenchido)'));
    }

    public function test_lead_sem_visitante_fica_sem_registro_de_passos(): void
    {
        $lead = Lead::create([
            'site' => 'kazakora', 'nome' => 'Sem Visitante', 'ultimo_campo' => 'cpf', 'itens' => [], 'status' => Lead::STATUS_ABERTO,
        ]);

        $this->actingAs($this->admin())->getJson("/admin/leads/{$lead->id}/fluxo")
            ->assertOk()
            ->assertJsonPath('tem_registro', false)
            ->assertJsonPath('sem_visitante', true)
            ->assertJsonPath('passos', [])
            ->assertJsonPath('parou.etapa', 'identificacao')
            ->assertJsonPath('parou.texto', 'Parou na identificação (último campo: CPF) · sem registro de passos');
    }

    public function test_fluxo_e_so_para_o_admin(): void
    {
        $lead = $this->carrinhoQueParouNoPagamento();

        $visitante = $this->getJson("/admin/carrinhos-abandonados/{$lead->id}/fluxo");
        $this->assertContains($visitante->status(), [302, 401, 403]);
        $this->assertStringNotContainsString('passos', $visitante->getContent());

        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $doCliente = $this->actingAs($cliente)->getJson("/admin/carrinhos-abandonados/{$lead->id}/fluxo");
        $this->assertContains($doCliente->status(), [302, 401, 403]);
        $this->assertStringNotContainsString('passos', $doCliente->getContent());
    }

    public function test_cliques_no_cupom_aparecem_no_fluxo_sem_o_codigo(): void
    {
        $lead = Lead::create([
            'site' => 'kazakora', 'visitor_id' => 'visitante-cupom', 'nome' => 'Ana Cupom', 'ultimo_campo' => 'nome',
            'itens' => [], 'status' => Lead::STATUS_ABERTO,
        ]);
        $this->datas($lead, '2026-11-23 11:00', '2026-11-23 11:05');
        $v = 'visitante-cupom';
        $this->evento($v, '2026-11-23 11:00:00', 'abriu');
        $this->evento($v, '2026-11-23 11:00:20', 'foco', 'nome');
        $this->evento($v, '2026-11-23 11:01:00', 'foco', 'cupom');
        $this->evento($v, '2026-11-23 11:01:10', 'cupom_clicou', 'cupom');
        $this->evento($v, '2026-11-23 11:01:11', 'cupom_erro', 'cupom');
        $this->evento($v, '2026-11-23 11:01:30', 'cupom_clicou', 'cupom');
        $this->evento($v, '2026-11-23 11:01:31', 'cupom_aplicado', 'cupom');

        $resposta = $this->actingAs($this->admin())->getJson("/admin/leads/{$lead->id}/fluxo")->assertOk();

        $this->assertSame(
            ['abriu', 'foco', 'foco', 'cupom_clicou', 'cupom_erro', 'cupom_clicou', 'cupom_aplicado'],
            array_column($resposta->json('passos'), 'evento'),
        );
        $resposta
            ->assertJsonPath('passos.3.titulo', 'Clicou em Aplicar cupom')
            ->assertJsonPath('passos.3.campo', 'cupom')
            ->assertJsonPath('passos.4.titulo', 'Cupom deu erro')
            ->assertJsonPath('passos.6.titulo', 'Cupom aplicado')
            // O cupom não muda onde a pessoa parou.
            ->assertJsonPath('parou.etapa', 'identificacao');
        $this->assertStringNotContainsString('BLACKFRIDAY', $resposta->getContent());
    }

    public function test_alerta_do_whatsapp_aparece_no_fluxo(): void
    {
        $lead = Lead::create([
            'site' => 'kazakora', 'visitor_id' => 'visitante-zap', 'nome' => 'Bia Zap', 'ultimo_campo' => 'cep',
            'itens' => [], 'status' => Lead::STATUS_ABERTO,
        ]);
        $this->datas($lead, '2026-11-23 11:00', '2026-11-23 11:05');
        $v = 'visitante-zap';
        $this->evento($v, '2026-11-23 11:00:00', 'abriu');
        $this->evento($v, '2026-11-23 11:01:00', 'preencheu', 'cep');
        $this->evento($v, '2026-11-23 11:01:05', 'whatsapp_alerta_ddd55', 'celular');
        $this->evento($v, '2026-11-23 11:01:30', 'whatsapp_corrigiu', 'celular');
        $this->evento($v, '2026-11-23 11:02:00', 'whatsapp_alerta_ddd55', 'celular');
        $this->evento($v, '2026-11-23 11:02:10', 'whatsapp_confirmou', 'celular');

        $this->actingAs($this->admin())->getJson("/admin/leads/{$lead->id}/fluxo")
            ->assertOk()
            ->assertJsonPath('passos.2.titulo', 'Viu o alerta "Confira seu WhatsApp" (DDD 55 com CEP de outra região)')
            ->assertJsonPath('passos.3.titulo', 'Corrigiu o número do WhatsApp')
            ->assertJsonPath('passos.5.titulo', 'Confirmou que o WhatsApp está correto')
            ->assertJsonPath('passos.5.campo', 'celular')
            ->assertJsonPath('parou.etapa', 'entrega');
    }
}
