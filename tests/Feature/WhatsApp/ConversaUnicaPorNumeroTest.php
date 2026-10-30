<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Modules\Checkout\Jobs\SendOrderApprovedWhatsAppJob;
use App\Modules\Checkout\Jobs\SendOrderDeliveredWhatsAppJob;
use App\Modules\Checkout\Jobs\SendOrderShippedWhatsAppJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\WhatsApp\Jobs\FaturarPedidoSemResposta;
use App\Modules\WhatsApp\Jobs\ReplyWithManuela;
use App\Modules\WhatsApp\Jobs\ResponderNaoRecebi;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Support\JuntarConversasDuplicadas;
use App\Modules\WhatsApp\Support\WhatsAppPhone;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Uma conversa só por número em cada loja (2026-10-30, pedidos #3662 e #3274):
 * o cadastro tem o nono dígito, a Meta devolve a resposta sem ele, e os dois
 * caminhos têm de cair na mesma conversa em todo o fluxo do pedido.
 */
class ConversaUnicaPorNumeroTest extends TestCase
{
    use RefreshDatabase;

    private const COM_NONO = '5541988887717';

    private const SEM_NONO = '554188887717';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.app_secret' => null,
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.manuela_url' => null,
            'services.gemini.api_key' => null,
            'services.whatsapp.templates.pedido_aprovado' => 'pedido_aprovado',
            'services.whatsapp.templates.pedido_enviado' => 'pedido_enviado',
            'services.whatsapp.templates.pedido_entregue' => 'pedido_entregue',
        ]);

        app(WhatsAppSettings::class)->setMany(['auto_reply_enabled' => false]);
        Notification::fake();
        Bus::fake([GenerateInvoiceJob::class, FaturarPedidoSemResposta::class, ReplyWithManuela::class]);

        $n = 0;
        Http::fake(['graph.facebook.com/*' => function () use (&$n) {
            return Http::response(['messages' => [['id' => 'wamid.OUT'.(++$n)]]]);
        }]);
    }

    private function pedido(array $extra = []): Order
    {
        return Order::create(array_merge([
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Maria da Silva', 'shipping_phone' => '(41) 98888-7717', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ], $extra));
    }

    private function webhook(array $value): void
    {
        $this->postJson('/api/webhooks/whatsapp', ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => $value]]]]])->assertOk();
    }

    private function resposta(string $de, string $id, array $mensagem): void
    {
        $this->webhook([
            'contacts' => [['wa_id' => $de, 'profile' => ['name' => 'Maria']]],
            'messages' => [['from' => $de, 'id' => $id, 'timestamp' => (string) now()->timestamp, ...$mensagem]],
        ]);
    }

    private function botao(string $de, string $id, string $payload, string $texto): void
    {
        $this->resposta($de, $id, ['type' => 'button', 'button' => ['payload' => $payload, 'text' => $texto]]);
    }

    /** Destinatários do que saiu para a Meta. @return array<int, string> */
    private function enviadosPara(): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/messages'))
            ->map(fn ($par) => $par[0]['to'])->values()->all();
    }

    public function test_chave_e_a_mesma_com_e_sem_o_nono_digito(): void
    {
        $this->assertSame(self::SEM_NONO, WhatsAppPhone::chave(self::COM_NONO));
        $this->assertSame(self::SEM_NONO, WhatsAppPhone::chave(self::SEM_NONO));
        $this->assertSame(self::SEM_NONO, WhatsAppPhone::chave('+55 (41) 98888-7717'));
    }

    public function test_chave_nao_confunde_outros_numeros(): void
    {
        // Fixo não tem nono dígito; número de fora do Brasil fica como veio.
        $this->assertSame('551133334444', WhatsAppPhone::chave('551133334444'));
        $this->assertSame('1298888771700', WhatsAppPhone::chave('1298888771700'));
        $this->assertNotSame(WhatsAppPhone::chave('5541988887717'), WhatsAppPhone::chave('5541988887716'));
        $this->assertNotSame(WhatsAppPhone::chave('5541988887717'), WhatsAppPhone::chave('5542988887717'));
        $this->assertSame('unknown', WhatsAppPhone::chave('unknown'));
        $this->assertNull(WhatsAppPhone::chave(null));
    }

    /** O caminho inteiro do pedido do site: sair o pedido, postar e entregar. */
    public function test_fluxo_inteiro_do_pedido_fica_numa_conversa_so(): void
    {
        $order = $this->pedido();

        // Saiu o pedido: template para o número do cadastro, com o 9.
        app()->call([new SendOrderApprovedWhatsAppJob($order->id), 'handle']);
        $conversa = WhatsAppConversation::query()->sole();
        $this->assertSame(self::COM_NONO, $conversa->wa_id);
        $this->assertFalse($conversa->insideServiceWindow());

        // Cliente toca "Tudo Certo" e a Meta entrega sem o 9.
        $this->botao(self::SEM_NONO, 'wamid.R1', "confirmacao_pedido:ok:{$order->id}", 'Tudo Certo');
        $this->assertSame('ok', $order->fresh()->whatsapp_confirmacao);

        // Postagem nos Correios.
        app()->call([new SendOrderShippedWhatsAppJob($order->id), 'handle']);

        // Texto livre do cliente, de novo sem o 9.
        $this->resposta(self::SEM_NONO, 'wamid.R2', ['type' => 'text', 'text' => ['body' => 'Obrigada!']]);

        // Entrega.
        app()->call([new SendOrderDeliveredWhatsAppJob($order->id), 'handle']);

        $conversa = WhatsAppConversation::query()->sole();
        $this->assertSame(self::SEM_NONO, $conversa->wa_id, 'O número que a Meta manda é o usado para responder.');
        $this->assertTrue($conversa->insideServiceWindow());

        $mensagens = $conversa->messages()->orderBy('id')->get();
        $this->assertSame(
            ['template', 'button', 'text', 'template', 'text', 'template'],
            $mensagens->pluck('type')->all(),
        );
        $this->assertSame(
            ['outbound', 'inbound', 'outbound', 'outbound', 'inbound', 'outbound'],
            $mensagens->pluck('direction')->all(),
        );
        $this->assertSame('outbound', $conversa->last_message_direction);

        // Chegou ao cliente: 3 templates + a resposta do "Tudo Certo", no número certo.
        $this->assertSame(
            [self::COM_NONO, self::SEM_NONO, self::COM_NONO, self::COM_NONO],
            $this->enviadosPara(),
        );

        // A Manuela responde o texto livre na mesma conversa.
        Bus::assertDispatched(ReplyWithManuela::class, fn ($job) => $job->conversationId === $conversa->id);
    }

    public function test_conversa_que_nasceu_da_meta_recebe_o_disparo_do_pedido(): void
    {
        $this->resposta(self::SEM_NONO, 'wamid.R1', ['type' => 'text', 'text' => ['body' => 'Oi']]);
        $order = $this->pedido();

        app()->call([new SendOrderApprovedWhatsAppJob($order->id), 'handle']);
        app()->call([new SendOrderShippedWhatsAppJob($order->id), 'handle']);

        $conversa = WhatsAppConversation::query()->sole();
        $this->assertSame(self::SEM_NONO, $conversa->wa_id);
        $this->assertSame(3, $conversa->messages()->count());
    }

    public function test_status_da_meta_antes_do_registro_do_disparo_nao_abre_outra_conversa(): void
    {
        $this->webhook(['statuses' => [['id' => 'wamid.OUTRO', 'status' => 'sent', 'recipient_id' => self::SEM_NONO]]]);

        app()->call([new SendOrderApprovedWhatsAppJob($this->pedido()->id), 'handle']);

        $this->assertSame(1, WhatsAppConversation::query()->count());
    }

    public function test_botao_nao_recebi_vindo_sem_o_nono_digito_vale(): void
    {
        $order = $this->pedido(['status' => Order::STATUS_SHIPPED]);
        app()->call([new SendOrderDeliveredWhatsAppJob($order->id), 'handle']);

        $this->botao(self::SEM_NONO, 'wamid.R1', ResponderNaoRecebi::PAYLOAD.$order->id, 'Não Recebi');

        $this->assertNotNull($order->fresh()->whatsapp_nao_recebi_em);
        $this->assertSame(1, WhatsAppConversation::query()->count());
    }

    public function test_tipo_nao_suportado_sem_o_nono_digito_acha_a_conversa(): void
    {
        WhatsAppConversation::paraNumero(self::COM_NONO, 'kazakora', ['phone' => self::COM_NONO]);

        $this->resposta(self::SEM_NONO, 'wamid.R1', ['type' => 'unsupported']);

        $conversa = WhatsAppConversation::query()->sole();
        $this->assertSame(1, $conversa->messages()->where('direction', 'inbound')->count());
    }

    public function test_nova_conversa_na_tela_abre_a_que_ja_existe(): void
    {
        $existente = WhatsAppConversation::paraNumero(self::SEM_NONO, 'kazakora', ['phone' => self::SEM_NONO]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/admin/whatsapp/conversas/nova', ['telefone' => '(41) 98888-7717'])
            ->assertOk()
            ->assertJsonPath('conversation.id', $existente->id);

        $this->assertSame(1, WhatsAppConversation::query()->count());
    }

    public function test_mesmo_numero_em_lojas_diferentes_continua_em_conversas_separadas(): void
    {
        $kaza = WhatsAppConversation::paraNumero(self::COM_NONO, 'kazakora');
        $ferra = WhatsAppConversation::paraNumero(self::SEM_NONO, 'ferrakora');

        $this->assertNotSame($kaza->id, $ferra->id);
    }

    public function test_banco_recusa_a_duplicata_mesmo_por_fora_do_paranumero(): void
    {
        WhatsAppConversation::query()->create(['wa_id' => self::COM_NONO, 'loja' => 'kazakora']);

        $this->expectException(UniqueConstraintViolationException::class);
        WhatsAppConversation::query()->create(['wa_id' => self::SEM_NONO, 'loja' => 'kazakora']);
    }

    /** Dois processos juntos: quem perde a corrida usa a conversa do outro. */
    public function test_corrida_entre_disparo_e_webhook_termina_numa_conversa_so(): void
    {
        $outro = null;
        WhatsAppConversation::creating(function () use (&$outro) {
            if ($outro === null) {
                $outro = DB::table('whatsapp_conversations')->insertGetId([
                    'wa_id' => self::SEM_NONO, 'numero_chave' => self::SEM_NONO, 'loja' => 'kazakora',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $conversa = WhatsAppConversation::paraNumero(self::COM_NONO, 'kazakora');

        $this->assertSame($outro, $conversa->id);
        $this->assertSame(1, WhatsAppConversation::query()->count());
    }

    /** @return array{0: int, 1: int} [disparo, resposta] */
    private function parDuplicado(): array
    {
        // Como ficou em produção: sem chave, antes da migração.
        \Illuminate\Support\Facades\Schema::table('whatsapp_conversations', fn ($t) => $t->dropUnique('whatsapp_conversations_numero_chave_loja_unique'));

        $linha = fn (string $waId, array $extra) => DB::table('whatsapp_conversations')->insertGetId(array_merge([
            'wa_id' => $waId, 'phone' => $waId, 'loja' => 'kazakora', 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        $disparo = $linha(self::COM_NONO, ['profile_name' => 'Maria', 'unread_count' => 0, 'ai_enabled' => true, 'needs_human' => true,
            'last_message_at' => '2026-10-30 17:00:06', 'metadata' => json_encode(['so_no_disparo' => 1, 'comum' => 'disparo'])]);
        $resposta = $linha(self::SEM_NONO, ['profile_name' => 'Maria Cliente', 'unread_count' => 2, 'ai_enabled' => false, 'needs_human' => false,
            'last_message_at' => '2026-10-30 06:31:09', 'last_customer_message_at' => '2026-10-30 06:31:07',
            'metadata' => json_encode(['pedido_escolhido' => 3662, 'comum' => 'resposta'])]);

        $msg = fn (int $conversa, string $wamid, string $direcao, string $tipo, string $quando) => DB::table('whatsapp_messages')->insert([
            'conversation_id' => $conversa, 'wa_message_id' => $wamid, 'direction' => $direcao, 'type' => $tipo, 'body' => $wamid,
            'status' => 'delivered', 'sent_at' => $quando, 'created_at' => $quando, 'updated_at' => $quando,
        ]);
        $msg($disparo, 'wamid.1', 'outbound', 'template', '2026-10-30 06:29:58');
        $msg($resposta, 'wamid.2', 'inbound', 'button', '2026-10-30 06:31:07');
        $msg($resposta, 'wamid.3', 'outbound', 'text', '2026-10-30 06:31:09');
        $msg($disparo, 'wamid.4', 'outbound', 'template', '2026-10-30 17:00:06');

        return [$disparo, $resposta];
    }

    public function test_juntar_leva_tudo_para_a_conversa_em_que_o_cliente_respondeu(): void
    {
        [$disparo, $resposta] = $this->parDuplicado();
        $outro = DB::table('whatsapp_conversations')->insertGetId([
            'wa_id' => '5511977776666', 'phone' => '5511977776666', 'loja' => 'kazakora', 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resultado = app(JuntarConversasDuplicadas::class)->executar();

        $this->assertSame([['fica' => $resposta, 'sai' => [$disparo]]], $resultado);
        $this->assertNull(WhatsAppConversation::query()->find($disparo));
        $this->assertNotNull(WhatsAppConversation::query()->find($outro), 'Número diferente não é tocado.');

        $conversa = WhatsAppConversation::query()->findOrFail($resposta);
        $this->assertSame(self::SEM_NONO, $conversa->wa_id);
        $this->assertSame(['wamid.1', 'wamid.2', 'wamid.3', 'wamid.4'], $conversa->messages()->orderBy('wa_message_id')->pluck('wa_message_id')->all());
        $this->assertSame(4, WhatsAppMessage::query()->count());
        $this->assertSame('Maria Cliente', $conversa->profile_name);
        $this->assertSame(2, $conversa->unread_count);
        $this->assertFalse($conversa->ai_enabled, 'Mantém a chave da conversa que fica.');
        $this->assertTrue($conversa->needs_human);
        $this->assertSame('2026-10-30 17:00:06', $conversa->last_message_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-30 06:31:07', $conversa->last_customer_message_at->format('Y-m-d H:i:s'));
        $this->assertSame('outbound', $conversa->last_message_direction);
        $this->assertStringContainsString('wamid.4', $conversa->last_message_preview);
        $this->assertSame(3662, $conversa->metadata['pedido_escolhido']);
        $this->assertSame(1, $conversa->metadata['so_no_disparo']);
        $this->assertSame('resposta', $conversa->metadata['comum']);
    }

    public function test_migracao_junta_o_que_ja_existe_e_cria_a_trava(): void
    {
        [$disparo, $resposta] = $this->parDuplicado();
        \Illuminate\Support\Facades\Schema::table('whatsapp_conversations', fn ($t) => $t->dropColumn('numero_chave'));

        (require database_path('migrations/2026_10_30_180000_conversa_unica_por_numero_whatsapp.php'))->up();

        $this->assertSame([$resposta], WhatsAppConversation::query()->pluck('id')->all());
        $this->assertSame(self::SEM_NONO, WhatsAppConversation::query()->sole()->numero_chave);
        $this->assertSame(4, WhatsAppMessage::query()->where('conversation_id', $resposta)->count());

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('whatsapp_conversations')->insert(['wa_id' => self::COM_NONO, 'numero_chave' => self::SEM_NONO, 'loja' => 'kazakora']);
    }
}
