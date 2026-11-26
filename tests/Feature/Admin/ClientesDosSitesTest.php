<?php

namespace Tests\Feature\Admin;

use App\Models\RolePermission;
use App\Models\User;
use App\Modules\Checkout\Models\Address;
use App\Modules\Checkout\Models\Lead;
use App\Modules\Checkout\Models\Order;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use App\Modules\FerraKora\Models\FerraKoraEndereco;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Admin > Clientes dos sites (pedido do Lira 2026-11-16): contas criadas na
 * KazaKora e na FerraKora, só leitura.
 */
class ClientesDosSitesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Dono Admin', 'email' => 'dono@kazakora.test']);
    }

    private function pedido(array $dono, string $status = Order::STATUS_PAID, float $total = 100, ?string $quando = null): Order
    {
        $order = new Order;
        $order->forceFill([
            ...$dono,
            'status' => $status, 'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01001000',
            'shipping_street' => 'Rua', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => $total, 'total' => $total,
        ])->save();

        if ($quando) {
            $order->forceFill(['created_at' => $quando])->save();
        }

        return $order;
    }

    private function clienteKazaKora(array $dados = []): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER, ...$dados]);
    }

    private function clienteFerraKora(array $dados = []): FerraKoraCliente
    {
        return FerraKoraCliente::query()->create([
            'name' => 'Cliente Ferra',
            'email' => 'ferra@cliente.test',
            'cpf' => '52998224725',
            'phone' => '11988887777',
            'password' => 'segredo-123',
            ...$dados,
        ]);
    }

    public function test_aba_kazakora_lista_clientes_com_pedidos_e_sem_a_equipe(): void
    {
        $maria = $this->clienteKazaKora(['name' => 'Maria Souza', 'email' => 'maria@cliente.test', 'phone' => '(11) 98765-4321']);
        $maria->forceFill(['recebe_promocoes' => true])->save();
        Address::query()->create([
            'user_id' => $maria->id, 'recipient_name' => 'Maria', 'phone' => '11987654321', 'zip' => '13010000', 'street' => 'Rua A', 'number' => '10',
            'neighborhood' => 'Centro', 'city' => 'Campinas', 'state' => 'SP', 'is_default' => true,
        ]);
        $this->pedido(['user_id' => $maria->id], Order::STATUS_PAID, 150, '2026-11-01 10:00:00');
        $this->pedido(['user_id' => $maria->id], Order::STATUS_COMPLETED, 50, '2026-11-10 10:00:00');
        $this->pedido(['user_id' => $maria->id], Order::STATUS_CANCELLED, 999);
        $this->pedido(['user_id' => $maria->id], Order::STATUS_AWAITING_PAYMENT, 999);

        $this->clienteKazaKora(['name' => 'João Sem Pedido', 'email' => 'joao@cliente.test']);
        User::factory()->create(['role' => User::ROLE_MANAGER, 'email' => 'gerente@kazakora.test']);
        User::factory()->create(['role' => User::ROLE_SUBSCRIBER, 'email' => 'equipe@kazakora.test']);
        $this->clienteKazaKora(['email' => 'apagado@cliente.test'])->delete();

        $this->actingAs($this->admin)->get('/admin/clientes-dos-sites?site=kazakora')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ClientesDosSites/Index', false)
                ->where('site', 'kazakora')
                ->has('clientes.data', 2)
                ->where('clientes.data', function ($linhas) use ($maria) {
                    $emails = collect($linhas)->pluck('email')->all();
                    $this->assertEqualsCanonicalizing(['maria@cliente.test', 'joao@cliente.test'], $emails);

                    $linha = collect($linhas)->firstWhere('id', $maria->id);
                    $this->assertSame(2, $linha['pedidos_pagos']);
                    $this->assertEquals(200.0, $linha['total_gasto']);
                    $this->assertStringStartsWith('2026-11-10', $linha['ultimo_pedido_em']);
                    $this->assertSame('11987654321', $linha['telefone']);
                    $this->assertSame('https://wa.me/5511987654321', $linha['whatsapp']);
                    $this->assertSame('Campinas', $linha['cidade']);
                    $this->assertSame('SP', $linha['uf']);
                    $this->assertTrue($linha['recebe_promocoes']);
                    $this->assertArrayNotHasKey('password', $linha);

                    return true;
                }));
    }

    public function test_aba_ferrakora_lista_as_contas_da_ferrakora_e_nao_as_da_kazakora(): void
    {
        $ferra = $this->clienteFerraKora();
        FerraKoraEndereco::query()->create([
            'cliente_id' => $ferra->id, 'recipient_name' => 'Ferra', 'zip' => '80010000', 'street' => 'Rua B', 'number' => '5',
            'neighborhood' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR', 'is_default' => true,
        ]);
        $this->pedido(['ferrakora_cliente_id' => $ferra->id, 'site_origem' => Order::SITE_FERRAKORA], Order::STATUS_SHIPPED, 320);
        $this->clienteKazaKora(['email' => 'so-kazakora@cliente.test']);

        $this->actingAs($this->admin)->get('/admin/clientes-dos-sites?site=ferrakora')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ClientesDosSites/Index', false)
                ->where('site', 'ferrakora')
                ->has('clientes.data', 1)
                ->where('clientes.data.0.email', 'ferra@cliente.test')
                ->where('clientes.data.0.pedidos_pagos', 1)
                ->where('clientes.data.0.total_gasto', fn ($v) => (float) $v === 320.0)
                ->where('clientes.data.0.cidade', 'Curitiba')
                ->where('clientes.data.0.recebe_promocoes', null)
                ->missing('clientes.data.0.password'));
    }

    public function test_busca_por_nome_email_telefone_e_cpf(): void
    {
        $this->clienteKazaKora(['name' => 'Ana Lima', 'email' => 'ana@cliente.test', 'phone' => '(21) 99999-1111', 'cpf' => '111.444.777-35']);
        $this->clienteKazaKora(['name' => 'Bruno Reis', 'email' => 'bruno@cliente.test', 'phone' => '11922223333', 'cpf' => '52998224725']);

        $buscar = fn (string $termo) => $this->actingAs($this->admin)
            ->get('/admin/clientes-dos-sites?search='.urlencode($termo))
            ->assertOk()
            ->viewData('page')['props']['clientes']['data'];

        $this->assertSame(['ana@cliente.test'], array_column($buscar('Ana'), 'email'));
        $this->assertSame(['bruno@cliente.test'], array_column($buscar('BRUNO@cliente'), 'email'));
        $this->assertSame(['ana@cliente.test'], array_column($buscar('99999-1111'), 'email'));
        $this->assertSame(['ana@cliente.test'], array_column($buscar('11144477735'), 'email'), 'CPF gravado com máscara acha pelos dígitos');
        $this->assertSame(['bruno@cliente.test'], array_column($buscar('529.982.247-25'), 'email'));
        $this->assertSame([], $buscar('ninguem-assim'));
    }

    public function test_filtros_de_periodo_com_e_sem_pedido_e_ordem(): void
    {
        $antiga = $this->clienteKazaKora(['email' => 'antiga@cliente.test']);
        $antiga->forceFill(['created_at' => '2026-01-10 12:00:00'])->save();
        $nova = $this->clienteKazaKora(['email' => 'nova@cliente.test']);
        $nova->forceFill(['created_at' => '2026-11-05 12:00:00'])->save();
        $this->pedido(['user_id' => $antiga->id], Order::STATUS_PAID, 500);

        $emails = fn (string $query) => array_column($this->actingAs($this->admin)
            ->get('/admin/clientes-dos-sites?'.$query)->assertOk()
            ->viewData('page')['props']['clientes']['data'], 'email');

        $this->assertSame(['nova@cliente.test', 'antiga@cliente.test'], $emails(''), 'mais recentes primeiro');
        $this->assertSame(['antiga@cliente.test', 'nova@cliente.test'], $emails('ordem=antigos'));
        $this->assertSame(['nova@cliente.test'], $emails('de=2026-11-01&ate=2026-11-30'));
        $this->assertSame(['antiga@cliente.test'], $emails('ate=2026-01-10'));
        $this->assertSame(['antiga@cliente.test'], $emails('pedido=com'));
        $this->assertSame(['nova@cliente.test'], $emails('pedido=sem'));
        $this->assertSame(['antiga@cliente.test', 'nova@cliente.test'], $emails('ordem=maior_gasto'));
    }

    public function test_detalhe_da_conta_kazakora_com_enderecos_pedidos_acesso_e_leads(): void
    {
        $maria = $this->clienteKazaKora(['name' => 'Maria Souza', 'email' => 'maria@cliente.test', 'phone' => '11987654321', 'cpf' => '52998224725']);
        Address::query()->create([
            'user_id' => $maria->id, 'recipient_name' => 'Maria', 'phone' => '11987654321', 'zip' => '13010000', 'street' => 'Rua A', 'number' => '10',
            'neighborhood' => 'Centro', 'city' => 'Campinas', 'state' => 'SP', 'is_default' => true,
        ]);
        $pago = $this->pedido(['user_id' => $maria->id], Order::STATUS_PAID, 150);
        $cancelado = $this->pedido(['user_id' => $maria->id], Order::STATUS_CANCELLED, 80);
        DB::table('site_visits')->insert(['visitor_id' => 'v-1', 'user_id' => $maria->id, 'path' => '/', 'created_at' => '2026-11-15 09:30:00']);
        Lead::query()->create(['site' => 'kazakora', 'email' => 'maria@cliente.test', 'nome' => 'Maria', 'status' => Lead::STATUS_ABERTO, 'subtotal' => 99]);
        Lead::query()->create(['site' => 'kazakora', 'telefone' => '11987654321', 'status' => Lead::STATUS_ABERTO]);
        Lead::query()->create(['site' => 'kazakora', 'email' => 'outra@pessoa.test', 'status' => Lead::STATUS_ABERTO]);
        Lead::query()->create(['site' => 'ferrakora', 'email' => 'maria@cliente.test', 'status' => Lead::STATUS_ABERTO]);

        $this->actingAs($this->admin)->get("/admin/clientes-dos-sites/kazakora/{$maria->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ClientesDosSites/Show', false)
                ->where('cliente.email', 'maria@cliente.test')
                ->where('cliente.cpf', '52998224725')
                ->where('cliente.whatsapp', 'https://wa.me/5511987654321')
                ->missing('cliente.password')
                ->missing('cliente.remember_token')
                ->has('enderecos', 1)
                ->where('enderecos.0.cidade', 'Campinas')
                ->has('pedidos', 2)
                ->where('pedidos', fn ($pedidos) => collect($pedidos)->pluck('id')->sort()->values()->all() === collect([$pago->id, $cancelado->id])->sort()->values()->all())
                ->where('resumo.pedidos_pagos', 1)
                ->where('acesso.ultimo_acesso_em', fn ($v) => str_starts_with((string) $v, '2026-11-15T09:30'))
                ->has('leads', 2));
    }

    public function test_detalhe_da_conta_ferrakora_com_sessao_e_lead(): void
    {
        $ferra = $this->clienteFerraKora();
        DB::table('ferrakora_sessoes')->insert([
            'cliente_id' => $ferra->id, 'token_hash' => str_repeat('a', 64), 'expira_em' => now()->addDays(10),
            'ultimo_uso_em' => '2026-11-14 18:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->pedido(['ferrakora_cliente_id' => $ferra->id, 'site_origem' => Order::SITE_FERRAKORA], Order::STATUS_PAID, 200);
        Lead::query()->create(['site' => 'ferrakora', 'cpf' => '52998224725', 'status' => Lead::STATUS_ABERTO]);

        $this->actingAs($this->admin)->get("/admin/clientes-dos-sites/ferrakora/{$ferra->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ClientesDosSites/Show', false)
                ->where('cliente.nome', 'Cliente Ferra')
                ->missing('cliente.password')
                ->has('pedidos', 1)
                ->where('acesso.sessoes_abertas', 1)
                ->where('acesso.ultimo_acesso_em', fn ($v) => str_starts_with((string) $v, '2026-11-14T18:00'))
                ->has('leads', 1));

        $this->assertStringNotContainsString(str_repeat('a', 64), $this->actingAs($this->admin)->get("/admin/clientes-dos-sites/ferrakora/{$ferra->id}")->getContent());
    }

    public function test_admin_e_equipe_nao_abrem_como_cliente(): void
    {
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($this->admin)->get("/admin/clientes-dos-sites/kazakora/{$this->admin->id}")
            ->assertRedirect('/admin/clientes-dos-sites?site=kazakora');
        $this->actingAs($this->admin)->get("/admin/clientes-dos-sites/kazakora/{$gerente->id}")
            ->assertRedirect('/admin/clientes-dos-sites?site=kazakora');

        $emails = array_column($this->actingAs($this->admin)->get('/admin/clientes-dos-sites?search=kazakora.test')
            ->assertOk()->viewData('page')['props']['clientes']['data'], 'email');
        $this->assertSame([], $emails);
    }

    public function test_csv_traz_o_que_esta_filtrado(): void
    {
        $maria = $this->clienteKazaKora(['name' => 'Maria Souza', 'email' => 'maria@cliente.test', 'phone' => '11987654321', 'cpf' => '05298224725']);
        $this->pedido(['user_id' => $maria->id], Order::STATUS_PAID, 1234.5);
        $this->clienteKazaKora(['name' => '=HYPERLINK("x")', 'email' => 'formula@cliente.test']);
        $this->clienteKazaKora(['name' => 'João', 'email' => 'joao@cliente.test']);

        $resposta = $this->actingAs($this->admin)->get('/admin/clientes-dos-sites/exportar?site=kazakora&pedido=com');
        $resposta->assertOk();
        $this->assertStringContainsString('text/csv', $resposta->headers->get('Content-Type'));
        $csv = $resposta->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Nome;E-mail;Telefone;CPF', $csv);
        $this->assertStringContainsString('maria@cliente.test', $csv);
        $this->assertStringContainsString('052.982.247-25', $csv);
        $this->assertStringContainsString('(11) 98765-4321', $csv);
        $this->assertStringContainsString('1234,50', $csv);
        $this->assertStringNotContainsString('joao@cliente.test', $csv);
        $this->assertStringNotContainsString('dono@kazakora.test', $csv);

        $this->assertDatabaseHas('audit_logs', ['user_id' => $this->admin->id, 'action' => 'export', 'entity' => 'ClientesDosSites']);

        $todos = $this->actingAs($this->admin)->get('/admin/clientes-dos-sites/exportar')->streamedContent();
        $this->assertMatchesRegularExpression("/'=hyperlink/i", $todos, 'fórmula do Excel neutralizada');
        $this->assertStringNotContainsString('dono@kazakora.test', $todos);
    }

    public function test_sem_permissao_recebe_403(): void
    {
        RolePermission::query()->updateOrCreate(['role' => User::ROLE_SUBSCRIBER, 'permission' => 'pedidos.view'], ['granted' => false]);
        $semPermissao = User::factory()->create(['role' => User::ROLE_SUBSCRIBER]);
        $cliente = $this->clienteKazaKora();

        $this->actingAs($semPermissao)->get('/admin/clientes-dos-sites')->assertForbidden();
        $this->actingAs($semPermissao)->get('/admin/clientes-dos-sites/exportar')->assertForbidden();
        $this->actingAs($semPermissao)->get("/admin/clientes-dos-sites/kazakora/{$cliente->id}")->assertForbidden();

        $this->actingAs($cliente)->get('/admin/clientes-dos-sites')->assertForbidden();
    }
}
