<?php

namespace Tests\Feature\Newsletter;

use App\Models\User;
use App\Modules\Editorial\Models\EditorialPost;
use App\Modules\Newsletter\Jobs\EnviarLoteDoBlog;
use App\Modules\Newsletter\Mail\BlogPorEmail;
use App\Modules\Newsletter\Mail\ConfirmarInscricao;
use App\Modules\Newsletter\Models\NewsletterEnvio;
use App\Modules\Newsletter\Models\NewsletterInscrito;
use App\Modules\Newsletter\Support\ResumoDoBlog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Relógio ao meio-dia de São Paulo: entre 0h e 1h a matéria "de 1 hora atrás"
        // caía no dia anterior e o teste falhava (deploy de 28/11 00:07 barrado).
        $this->travelTo(\Carbon\CarbonImmutable::parse('today 12:00', config('app.timezone')));
        $this->withoutVite();
        Mail::fake();
    }

    private function inscrever(array $dados = [])
    {
        return $this->postJson('/newsletter', array_merge(['email' => 'Maria@Exemplo.com ', 'nome' => 'Maria Souza', 'consentimento' => true, 'origem' => '/produtos/x'], $dados));
    }

    private function materia(array $dados = []): EditorialPost
    {
        return EditorialPost::create(array_merge([
            'slug' => 'materia-'.uniqid(), 'title' => 'Como organizar a cozinha', 'excerpt' => 'Cinco ideias simples.', 'body' => 'Texto da matéria.',
            'type' => 'news', 'category' => 'cozinha', 'status' => 'published', 'published_at' => now()->subHour(),
            'poster_url' => '/storage/blog/capa.webp', 'rights_confirmed' => true, 'editorial_checked' => true,
        ], $dados));
    }

    public function test_inscricao_valida_fica_pendente_com_prova_do_consentimento_e_manda_confirmacao(): void
    {
        $this->inscrever()->assertOk()->assertJson(['mensagem' => \App\Modules\Newsletter\Http\Controllers\NewsletterController::SUCESSO]);

        $inscrito = NewsletterInscrito::sole();
        $this->assertSame('maria@exemplo.com', $inscrito->email);
        $this->assertSame('Maria Souza', $inscrito->nome);
        $this->assertSame(NewsletterInscrito::PENDENTE, $inscrito->status);
        $this->assertSame(config('newsletter.consentimento_versao'), $inscrito->consentimento_versao);
        $this->assertNotNull($inscrito->consentimento_em);
        $this->assertNotNull($inscrito->consentimento_ip);
        Mail::assertQueued(ConfirmarInscricao::class, fn ($m) => $m->hasTo('maria@exemplo.com'));
    }

    public function test_sem_consentimento_ou_email_invalido_volta_erro(): void
    {
        $this->inscrever(['consentimento' => false])->assertStatus(422)->assertJsonValidationErrors('consentimento');
        $this->inscrever(['email' => 'nao-e-email'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame(0, NewsletterInscrito::count());
    }

    public function test_inscricao_duplicada_nao_cria_outro_nem_reenvia_logo_em_seguida(): void
    {
        $this->inscrever()->assertOk();
        $this->inscrever(['email' => 'maria@exemplo.com'])->assertOk();

        $this->assertSame(1, NewsletterInscrito::count());
        Mail::assertQueued(ConfirmarInscricao::class, 1);

        // Já ativo: mesma resposta, nada muda e nenhum e-mail novo.
        NewsletterInscrito::sole()->confirmar();
        $this->travel(1)->hours();
        $this->inscrever()->assertOk();
        $this->assertSame(NewsletterInscrito::ATIVO, NewsletterInscrito::sole()->status);
        Mail::assertQueued(ConfirmarInscricao::class, 1);
    }

    public function test_honeypot_finge_sucesso_e_nao_grava(): void
    {
        $this->inscrever(['website' => 'http://spam.example'])->assertOk();

        $this->assertSame(0, NewsletterInscrito::count());
        Mail::assertNothingQueued();
    }

    public function test_limite_de_tentativas_por_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->inscrever(['email' => "pessoa{$i}@exemplo.com"])->assertOk();
        }

        $this->inscrever(['email' => 'pessoa9@exemplo.com'])->assertStatus(429);
    }

    public function test_confirmar_e_sair_com_um_clique(): void
    {
        $this->inscrever();
        $inscrito = NewsletterInscrito::sole();

        $this->get('/newsletter/confirmar/'.$inscrito->token)->assertOk()
            ->assertInertia(fn ($p) => $p->component('Newsletter/Resultado', false)->where('situacao', 'confirmado'));
        $this->assertTrue($inscrito->fresh()->ativo());

        $this->get('/newsletter/sair/'.$inscrito->token)->assertOk()
            ->assertInertia(fn ($p) => $p->component('Newsletter/Resultado', false)->where('situacao', 'descadastrado'));
        $this->assertSame(NewsletterInscrito::DESCADASTRADO, $inscrito->fresh()->status);
        $this->assertNotNull($inscrito->fresh()->descadastrado_em);

        $this->get('/newsletter/sair/tokenquenaoexiste12345678')->assertOk()
            ->assertInertia(fn ($p) => $p->where('situacao', 'invalido'));
    }

    public function test_sair_pelo_botao_do_gmail_sem_csrf(): void
    {
        $inscrito = NewsletterInscrito::create(['email' => 'a@exemplo.com', 'status' => NewsletterInscrito::ATIVO]);

        $this->post('/newsletter/sair/'.$inscrito->token, ['List-Unsubscribe' => 'One-Click'])->assertNoContent();
        $this->assertSame(NewsletterInscrito::DESCADASTRADO, $inscrito->fresh()->status);
    }

    public function test_quem_saiu_e_se_inscreve_de_novo_volta_a_pendente(): void
    {
        $inscrito = NewsletterInscrito::create(['email' => 'maria@exemplo.com', 'status' => NewsletterInscrito::DESCADASTRADO]);

        $this->inscrever()->assertOk();

        $this->assertSame(NewsletterInscrito::PENDENTE, $inscrito->fresh()->status);
        Mail::assertQueued(ConfirmarInscricao::class, 1);
    }

    public function test_email_do_blog_tem_materias_link_de_saida_e_cabecalho_um_clique(): void
    {
        $this->materia(['title' => 'Como organizar a cozinha']);
        $this->materia(['title' => 'Receita de pão de queijo', 'type' => 'recipe']);
        $this->materia(['title' => 'Matéria da FerraKora', 'site' => EditorialPost::FERRAKORA]);
        $this->materia(['title' => 'Rascunho', 'status' => 'draft']);
        $this->materia(['title' => 'Da semana passada', 'published_at' => now()->subDays(3)]);

        $resumo = ResumoDoBlog::do();
        $this->assertCount(2, $resumo->posts);
        $this->assertStringContainsString('e mais 1 novidade do blog', $resumo->assunto());
        $this->assertCount(3, ResumoDoBlog::do(null, 'semanal')->posts);

        $mail = new BlogPorEmail($resumo->assunto(), $resumo->itens(), 'https://kazakora.test/newsletter/sair/abc', 'Maria Souza');
        $mail->assertSeeInHtml('Receita de pão de queijo');
        $mail->assertSeeInHtml('Cinco ideias simples.');
        $mail->assertSeeInHtml('/storage/blog/capa.webp');
        $mail->assertSeeInHtml('utm_source=newsletter');
        $mail->assertSeeInHtml('https://kazakora.test/newsletter/sair/abc');
        $mail->assertSeeInHtml('Maria, saiu no blog');
        $mail->assertSeeInHtml('CNPJ');
        $mail->assertDontSeeInHtml('Matéria da FerraKora');
        $mail->assertDontSeeInHtml('Rascunho');

        $headers = $mail->headers()->text;
        $this->assertSame('<https://kazakora.test/newsletter/sair/abc>', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
    }

    public function test_dry_run_nao_envia_nem_registra(): void
    {
        Queue::fake();
        $this->materia();
        NewsletterInscrito::create(['email' => 'a@exemplo.com', 'status' => NewsletterInscrito::ATIVO]);

        $this->artisan('newsletter:blog --dry-run')
            ->expectsOutputToContain('Inscritos ativos: 1')
            ->expectsOutputToContain('Simulação: nenhum e-mail foi enviado.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(0, NewsletterEnvio::count());
    }

    public function test_envio_em_lotes_so_para_ativos_e_uma_vez_por_resumo(): void
    {
        Queue::fake();
        config(['newsletter.lote_tamanho' => 2]);
        $this->materia();
        foreach (range(1, 5) as $i) {
            NewsletterInscrito::create(['email' => "ativo{$i}@exemplo.com", 'status' => NewsletterInscrito::ATIVO]);
        }
        NewsletterInscrito::create(['email' => 'pendente@exemplo.com']);
        NewsletterInscrito::create(['email' => 'saiu@exemplo.com', 'status' => NewsletterInscrito::DESCADASTRADO]);

        $this->artisan('newsletter:blog')->expectsOutputToContain('3 lote(s) na fila para 5 inscrito(s).')->assertSuccessful();
        Queue::assertPushed(EnviarLoteDoBlog::class, 3);
        $this->assertSame(1, NewsletterEnvio::count());

        $this->artisan('newsletter:blog')->expectsOutputToContain('já foi enviado')->assertSuccessful();
        Queue::assertPushed(EnviarLoteDoBlog::class, 3);
    }

    public function test_lote_manda_um_email_por_ativo_e_pula_quem_saiu(): void
    {
        $ativo = NewsletterInscrito::create(['email' => 'ativo@exemplo.com', 'status' => NewsletterInscrito::ATIVO]);
        $saiu = NewsletterInscrito::create(['email' => 'saiu@exemplo.com', 'status' => NewsletterInscrito::DESCADASTRADO]);

        (new EnviarLoteDoBlog('blog:diario:2026-11-24', 'Assunto', [], [$ativo->id, $saiu->id]))->handle();

        Mail::assertSent(BlogPorEmail::class, 1);
        Mail::assertSent(BlogPorEmail::class, fn ($m) => $m->hasTo('ativo@exemplo.com') && str_contains($m->linkSair, $ativo->token));
        $this->assertNotNull($ativo->fresh()->ultimo_envio_em);
    }

    public function test_sem_materia_nao_envia(): void
    {
        Queue::fake();
        NewsletterInscrito::create(['email' => 'a@exemplo.com', 'status' => NewsletterInscrito::ATIVO]);

        $this->artisan('newsletter:blog')->expectsOutputToContain('Nada a enviar')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_admin_lista_exporta_e_envia_teste_so_para_si(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'lira@exemplo.com']);
        $this->materia();
        NewsletterInscrito::create(['email' => 'a@exemplo.com', 'status' => NewsletterInscrito::ATIVO]);
        NewsletterInscrito::create(['email' => '=cmd@exemplo.com']);

        $this->actingAs($admin)->get('/admin/newsletter')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Newsletter/Index', false)->where('contagem.ativos', 1)->where('contagem.pendentes', 1)->where('blog.agendado', false));

        $csv = $this->actingAs($admin)->get('/admin/newsletter/exportar')->assertOk()->streamedContent();
        $this->assertStringContainsString('a@exemplo.com;', $csv);
        $this->assertStringContainsString("'=cmd@exemplo.com", $csv);

        $this->actingAs($admin)->get('/admin/newsletter/previa')->assertOk()->assertSee('Como organizar a cozinha');

        $this->actingAs($admin)->post('/admin/newsletter/teste')->assertRedirect();
        Mail::assertSent(BlogPorEmail::class, 1);
        Mail::assertSent(BlogPorEmail::class, fn ($m) => $m->hasTo('lira@exemplo.com') && $m->teste);
    }

    public function test_teste_com_smtp_fora_do_ar_avisa_em_vez_de_dar_500(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'lira@exemplo.com']);
        $this->materia();
        Mail::shouldReceive('to')->andThrow(new \Symfony\Component\Mailer\Exception\TransportException('Connection to "ssl://smtp.hostinger.com:465" has been closed unexpectedly.'));

        $this->actingAs($admin)->from('/admin/newsletter')->post('/admin/newsletter/teste')
            ->assertRedirect('/admin/newsletter')
            ->assertSessionHas('error');
    }

    public function test_bloco_de_newsletter_aparece_no_rodape_so_na_home(): void
    {
        $rodape = file_get_contents(resource_path('js/Shared/Components/RodapeLoja.vue'));
        $bloco = file_get_contents(resource_path('js/Shared/Components/BlocoNewsletter.vue'));

        // Só na home (pedido do Lira 25/11/2026), antes do rodapé.
        $this->assertStringContainsString('<BlocoNewsletter v-if="naHome" />', $rodape);
        $this->assertLessThan(strpos($rodape, '<footer'), strpos($rodape, '<BlocoNewsletter v-if="naHome" />'));
        $this->assertStringContainsString("=== '/'", $rodape);
        $this->assertStringNotContainsString('fa-bolt', $bloco);
        $this->assertStringContainsString('name="website"', $bloco);
    }
}
