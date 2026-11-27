<?php

namespace Tests\Feature\Materiais;

use App\Models\User;
use App\Modules\Analytics\Support\GeoDoVisitante;
use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Materiais\Mail\MaterialPorEmail;
use App\Modules\Materiais\Models\LeadMaterial;
use App\Modules\Materiais\Support\DddPorLocal;
use App\Modules\Materiais\Support\EmailDeLead;
use App\Modules\Materiais\Support\TelefoneBrasil;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Guia de Viagem grátis da FerraKora (26/11/2026): cadastro com validação de
 * e-mail e celular (+55, DDD, 9 dígitos), lead gravado com origem e UTMs e
 * download do PDF por link assinado.
 */
class LeadMaterialTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/ferrakora/materiais/guia-viagem/lead';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ferrakora.origens' => 'ferrakora.com,ferrakora.devlira.com.br', 'materiais.conferir_dns' => false]);
        EmailDeLead::$resolver = null;
        Storage::fake('local');
        Storage::disk('local')->put('livros-digitais/guia.pdf', '%PDF-1.4 guia');
        LivroDigital::query()->create(['titulo' => 'Guia de Viagem de Bolso: Brasil pela estrada', 'arquivo' => 'livros-digitais/guia.pdf', 'nome_arquivo' => 'guia.pdf', 'ativo' => true, 'todos_produtos' => false]);
        Mail::fake();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        EmailDeLead::$resolver = null;
        parent::tearDown();
    }

    /**
     * Zera o limite antes de enviar: a rota aceita 5 cadastros por minuto
     * (throttle:5,1), e este teste manda vários seguidos só para conferir a
     * validação. Desde 27/11/2026 cada rota tem o próprio contador
     * (LimitePorRota) e o limite passou a valer de verdade.
     */
    private function enviarSemLimite(array $dados = [])
    {
        \Illuminate\Support\Facades\Cache::flush();

        return $this->enviar($dados);
    }

    private function enviar(array $dados = [], string $origem = 'https://ferrakora.com')
    {
        return $this->withHeader('Origin', $origem)->postJson(self::URL, array_merge([
            'nome' => 'José Teste',
            'email' => 'joserobertolira@gmail.com',
            'telefone' => '(11) 99876-5432',
            'aceite' => true,
            'origem' => 'Instagram',
            'utm_source' => 'instagram',
            'utm_medium' => 'organico',
            'utm_campaign' => 'guia-viagem',
        ], $dados));
    }

    private function comLugar(array $lugar): void
    {
        $this->mock(GeoDoVisitante::class, fn ($m) => $m->shouldReceive('localizar')->andReturn($lugar));
    }

    public function test_cadastro_grava_o_lead_e_devolve_o_link_que_baixa_o_pdf(): void
    {
        $resposta = $this->enviar()->assertOk()->assertJsonPath('ok', true);

        $lead = LeadMaterial::sole();
        $this->assertSame('guia-viagem', $lead->material);
        $this->assertSame('ferrakora', $lead->site);
        $this->assertSame('5511998765432', $lead->telefone);
        $this->assertSame('11', $lead->ddd);
        $this->assertSame('instagram', $lead->origem);
        $this->assertSame('guia-viagem', $lead->utm_campaign);
        $this->assertTrue($lead->aceita_contato);
        $this->assertSame(config('materiais.consentimento_versao'), $lead->consentimento_versao);
        Mail::assertQueued(MaterialPorEmail::class, fn ($m) => $m->hasTo('joserobertolira@gmail.com'));

        $link = $resposta->json('download');
        $this->get($link)->assertOk()->assertHeader('content-disposition');
        $this->assertSame(1, $lead->fresh()->downloads);

        // Sem assinatura não baixa.
        $this->get("/api/ferrakora/materiais/guia-viagem/pdf/{$lead->id}")->assertForbidden();
    }

    public function test_55_digitado_na_frente_sai_e_o_mesmo_email_atualiza(): void
    {
        $this->enviar(['telefone' => '+55 11 99876-5432'])->assertOk();
        $this->enviar(['telefone' => '5521987654321', 'origem' => 'story', 'utm_campaign' => null])->assertOk();

        $lead = LeadMaterial::sole();
        $this->assertSame('5521987654321', $lead->telefone);
        $this->assertSame(2, $lead->cadastros);
        // Origem fica a do primeiro cadastro.
        $this->assertSame('instagram', $lead->origem);
        Mail::assertQueued(MaterialPorEmail::class, 1);
    }

    public function test_dados_errados_voltam_422_com_o_campo(): void
    {
        $this->enviarSemLimite(['email' => 'fulano@gmial.com'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->enviarSemLimite(['email' => 'fulano@mailinator.com'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->enviarSemLimite(['email' => 'fulano.com'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->enviarSemLimite(['telefone' => '(20) 99876-5432'])->assertStatus(422)->assertJsonValidationErrors('telefone');
        $this->enviarSemLimite(['telefone' => '(11) 3876-5432'])->assertStatus(422)->assertJsonValidationErrors('telefone');
        $this->enviarSemLimite(['telefone' => '(11) 99999-9999'])->assertStatus(422)->assertJsonValidationErrors('telefone');
        $this->enviarSemLimite(['telefone' => '9987'])->assertStatus(422)->assertJsonValidationErrors('telefone');
        $this->enviarSemLimite(['nome' => ''])->assertStatus(422)->assertJsonValidationErrors('nome');
        $this->enviarSemLimite(['aceite' => false])->assertStatus(422)->assertJsonValidationErrors('aceite');
        $this->assertSame(0, LeadMaterial::query()->count());
    }

    public function test_dominio_que_nao_existe_e_barrado(): void
    {
        config(['materiais.conferir_dns' => true]);
        EmailDeLead::$resolver = fn (string $d) => $d === 'gmail.com';

        $this->enviar(['email' => 'fulano@dominioquenaoexiste123.com.br'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->enviar()->assertOk();
    }

    public function test_ddd_55_fora_do_rs_pede_confirmacao(): void
    {
        $this->comLugar(['fonte' => 'cloudflare', 'pais' => 'BR', 'uf' => 'SP', 'cidade' => 'Campinas', 'latitude' => -22.9, 'longitude' => -47.06]);

        $this->enviar(['telefone' => '(55) 99876-5432'])->assertStatus(422)->assertJsonValidationErrors(['telefone', 'confirmou_ddd55']);
        $this->enviar(['telefone' => '(55) 99876-5432', 'confirmou_ddd55' => true])->assertOk();

        $lead = LeadMaterial::sole();
        $this->assertSame('SP', $lead->uf_ip);
        $this->assertSame('19', $lead->ddd_sugerido);
    }

    public function test_local_sugere_o_ddd_pelo_ip(): void
    {
        $this->comLugar(['fonte' => 'dbip', 'pais' => 'BR', 'uf' => 'RJ', 'cidade' => 'Petrópolis', 'latitude' => -22.5, 'longitude' => -43.18]);

        $this->getJson('/api/ferrakora/materiais/local')->assertOk()->assertJson(['uf' => 'RJ', 'ddd' => '24']);
    }

    public function test_honeypot_origem_de_fora_e_pdf_ausente(): void
    {
        $this->enviar(['website' => 'http://spam'])->assertOk()->assertJsonPath('download', null);
        $this->enviar([], 'https://outro.invalid')->assertForbidden();
        $this->assertSame(0, LeadMaterial::query()->count());

        LivroDigital::query()->delete();
        $this->enviar()->assertStatus(503);
    }

    public function test_sair_desliga_o_contato(): void
    {
        $this->enviar()->assertOk();
        $lead = LeadMaterial::sole();

        $this->get(\Illuminate\Support\Facades\URL::signedRoute('api.ferrakora.materiais.sair', ['lead' => $lead->id]))->assertOk();
        $this->assertFalse($lead->fresh()->aceita_contato);
        $this->assertNotNull($lead->fresh()->descadastrado_em);
    }

    public function test_regras_do_celular_e_do_ddd(): void
    {
        $this->assertSame('11998765432', TelefoneBrasil::nacional('+55 (11) 99876-5432'));
        $this->assertSame('55998765432', TelefoneBrasil::nacional('(55) 99876-5432'));
        $this->assertSame('11998765432', TelefoneBrasil::nacional('011 99876-5432'));
        $this->assertNull(TelefoneBrasil::erro('61998765432'));
        $this->assertNotNull(TelefoneBrasil::erro('23998765432'));
        $this->assertCount(67, TelefoneBrasil::ddds());

        $this->assertSame('11', DddPorLocal::sugerir('SP', -23.6, -46.7));
        $this->assertSame('16', DddPorLocal::sugerir('SP', -21.2, -47.8));
        $this->assertSame('82', DddPorLocal::sugerir('AL', null, null));
        $this->assertSame('68', DddPorLocal::sugerir('ac', null, null));
        $this->assertNull(DddPorLocal::sugerir('SP', null, null));
        $this->assertNull(DddPorLocal::sugerir(null, -23.6, -46.7));
    }

    public function test_admin_ve_os_leads(): void
    {
        $this->enviar()->assertOk();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/admin/leads-materiais')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/LeadsMateriais/Index', false)->where('total', 1)->where('leads.data.0.origem', 'instagram'));
        $this->actingAs($admin)->get('/admin/leads-materiais/exportar')->assertOk();
    }
}
