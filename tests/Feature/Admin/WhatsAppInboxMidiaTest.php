<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\ConversorDeVoz;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Conversas do WhatsApp (pedido 2026-10-22): atendente grava nota de voz e
 * anexa documento pela tela. A cópia fica no servidor para a tela tocar/abrir.
 */
class WhatsAppInboxMidiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.app_secret' => null,
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
        ]);
        app(WhatsAppSettings::class)->setMany(['auto_reply_enabled' => false]);
        Storage::fake('local');
    }

    private function conversa(): WhatsAppConversation
    {
        return WhatsAppConversation::create([
            'wa_id' => '5511999990000', 'phone' => '5511999990000', 'ai_enabled' => true,
            'needs_human' => true, 'status' => 'needs_human', 'last_customer_message_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Lira']);
    }

    private function fakeMeta(): void
    {
        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'MEDIA1']),
            'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT1']]]),
        ]);
    }

    public function test_documento_anexado_vai_como_document_com_nome_e_legenda(): void
    {
        $this->fakeMeta();
        $conversa = $this->conversa();
        $pdf = UploadedFile::fake()->createWithContent('Nota fiscal 123.pdf', "%PDF-1.4\n%teste\n");

        $resposta = $this->actingAs($this->admin())
            ->post("/admin/whatsapp/conversas/{$conversa->id}/midia", ['arquivo' => $pdf, 'legenda' => 'Segue a nota'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message.type', 'document')
            ->assertJsonPath('message.status', 'sent')
            ->assertJsonPath('message.hasMedia', true)
            ->assertJsonPath('message.fileName', 'Nota fiscal 123.pdf');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages')
            && $r['type'] === 'document'
            && $r['document'] === ['id' => 'MEDIA1', 'caption' => 'Segue a nota', 'filename' => 'Nota fiscal 123.pdf']);

        $mensagem = WhatsAppMessage::findOrFail($resposta->json('message.id'));
        $this->assertSame('Lira', $mensagem->sent_by);
        Storage::disk('local')->assertExists($mensagem->localMediaPath());

        // Humano respondeu: Manuela sai e o alerta some, igual ao texto.
        $conversa->refresh();
        $this->assertFalse($conversa->ai_enabled);
        $this->assertFalse($conversa->needs_human);
        $this->assertSame('📄 Documento: Segue a nota', $conversa->last_message_preview);

        // A tela abre o arquivo pela cópia local, sem ir à Meta.
        Http::fake(fn () => throw new \RuntimeException('não devia chamar a Meta'));
        $download = $this->get("/admin/whatsapp/conversas/midia/{$mensagem->id}")->assertOk();
        $this->assertSame("%PDF-1.4\n%teste\n", $download->streamedContent());
        $this->assertStringContainsString('Nota fiscal 123.pdf', $download->headers->get('Content-Disposition'));
    }

    public function test_foto_anexada_vai_como_imagem(): void
    {
        $this->fakeMeta();
        $conversa = $this->conversa();

        $this->actingAs($this->admin())
            ->post("/admin/whatsapp/conversas/{$conversa->id}/midia", ['arquivo' => UploadedFile::fake()->image('foto.jpg')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message.type', 'image');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['type'] === 'image');
    }

    public function test_gravacao_vira_nota_de_voz_ogg(): void
    {
        $this->fakeMeta();
        $conversa = $this->conversa();

        $ogg = tempnam(sys_get_temp_dir(), 'teste-ogg-');
        file_put_contents($ogg, 'OggS-fake');
        $conversor = Mockery::mock(ConversorDeVoz::class);
        $conversor->shouldReceive('paraOgg')->once()->andReturn($ogg);
        $this->app->instance(ConversorDeVoz::class, $conversor);

        $gravacao = UploadedFile::fake()->createWithContent('gravacao.webm', 'webm-fake');

        $this->actingAs($this->admin())
            ->post("/admin/whatsapp/conversas/{$conversa->id}/midia", ['arquivo' => $gravacao, 'gravacao' => '1', 'legenda' => 'ignorada'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message.type', 'audio')
            ->assertJsonPath('message.mimeType', 'audio/ogg')
            ->assertJsonPath('message.status', 'sent');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && $r['type'] === 'audio' && $r['audio'] === ['id' => 'MEDIA1']);
        $this->assertFileDoesNotExist($ogg);
    }

    public function test_conversao_que_falha_avisa_e_nao_envia(): void
    {
        $this->fakeMeta();
        $conversa = $this->conversa();
        $conversor = Mockery::mock(ConversorDeVoz::class);
        $conversor->shouldReceive('paraOgg')->andThrow(new \RuntimeException('sem ffmpeg'));
        $this->app->instance(ConversorDeVoz::class, $conversor);

        $this->actingAs($this->admin())
            ->post("/admin/whatsapp/conversas/{$conversa->id}/midia", ['arquivo' => UploadedFile::fake()->createWithContent('g.webm', 'x'), 'gravacao' => '1'], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath("errors.arquivo.0", "Não deu para converter o áudio gravado. Tente de novo.");

        Http::assertSentCount(0);
        $this->assertSame(0, WhatsAppMessage::count());
    }

    public function test_arquivo_grande_demais_responde_json_com_o_motivo(): void
    {
        $conversa = $this->conversa();

        $this->actingAs($this->admin())
            ->post("/admin/whatsapp/conversas/{$conversa->id}/midia", ['arquivo' => UploadedFile::fake()->create('grande.zip', 61 * 1024)], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Arquivo grande demais: o limite é 60 MB.');
    }

    public function test_sem_token_fica_salvo_como_rascunho_e_a_meta_recusando_vira_falha(): void
    {
        $conversa = $this->conversa();
        config(['services.whatsapp.access_token' => null]);

        $this->actingAs($admin = $this->admin())
            ->post("/admin/whatsapp/conversas/{$conversa->id}/midia", ['arquivo' => UploadedFile::fake()->createWithContent('a.txt', 'oi')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message.status', 'draft_no_token')
            ->assertJsonPath('message.hasMedia', true);

        config(['services.whatsapp.access_token' => 'meta-token']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'bad']], 400)]);

        $this->actingAs($admin)
            ->post("/admin/whatsapp/conversas/{$conversa->id}/midia", ['arquivo' => UploadedFile::fake()->createWithContent('a.txt', 'oi')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message.status', 'failed');
    }

    public function test_apagar_conversa_apaga_os_arquivos_enviados(): void
    {
        $this->fakeMeta();
        $conversa = $this->conversa();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post("/admin/whatsapp/conversas/{$conversa->id}/midia", ['arquivo' => UploadedFile::fake()->createWithContent('a.txt', 'oi')], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertNotEmpty(Storage::disk('local')->allFiles("whatsapp/enviadas/{$conversa->id}"));

        $this->actingAs($admin)->deleteJson("/admin/whatsapp/conversas/{$conversa->id}")->assertOk();

        $this->assertEmpty(Storage::disk('local')->allFiles("whatsapp/enviadas/{$conversa->id}"));
    }
}
