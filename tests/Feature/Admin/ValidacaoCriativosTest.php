<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Models\CriativoValidacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Marketing › Validação de criativos (2026-10-21): vídeo sobe em partes,
 * fica no disco privado e só o administrador aprova, pede ajuste ou reprova.
 */
class ValidacaoCriativosTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/admin/validacao-criativos';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /** Começo de um MP4 de verdade (caixa ftyp) e o resto preenchido. */
    private function conteudoMp4(int $bytes): string
    {
        $cabecalho = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

        return $cabecalho.str_repeat("\x00", $bytes - strlen($cabecalho));
    }

    private function enviarEmPartes(User $usuario, string $conteudo, string $nome = 'arvore-natal.mp4', array $dados = []): \Illuminate\Testing\TestResponse
    {
        $envio = (string) Str::uuid();
        foreach (str_split($conteudo, 1000) as $i => $pedaco) {
            $this->actingAs($usuario)
                ->post(self::BASE.'/parte', [
                    'envio' => $envio,
                    'inicio' => $i * 1000,
                    'parte' => UploadedFile::fake()->createWithContent('parte.bin', $pedaco),
                ], ['Accept' => 'application/json'])
                ->assertOk()
                ->assertJson(['recebido' => $i * 1000 + strlen($pedaco)]);
        }

        return $this->actingAs($usuario)->postJson(self::BASE, $dados + [
            'envio' => $envio,
            'nome_original' => $nome,
            'tamanho' => strlen($conteudo),
            'titulo' => 'Árvore de Natal 9:16',
            'plataforma' => 'meta',
        ]);
    }

    public function test_video_sobe_em_partes_e_fica_aguardando(): void
    {
        $admin = $this->admin();
        $conteudo = $this->conteudoMp4(3500);

        $this->enviarEmPartes($admin, $conteudo)
            ->assertCreated()
            ->assertJsonPath('criativo.status', CriativoValidacao::AGUARDANDO)
            ->assertJsonPath('criativo.titulo', 'Árvore de Natal 9:16');

        $criativo = CriativoValidacao::sole();
        $this->assertStringStartsWith('criativos/'.now()->format('Y-m').'/', $criativo->path);
        $this->assertSame($conteudo, Storage::disk('local')->get($criativo->path));
        $this->assertSame(3500, $criativo->tamanho);
        $this->assertSame($admin->id, $criativo->enviado_por);
        $this->assertSame([], Storage::disk('local')->files('criativos/envios'));

        $this->actingAs($admin)->get(self::BASE)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('totais.aguardando', 1)
                ->where('podeAvaliar', true)
                ->has('cards', 1)
                ->where('cards.0.id', 'sem')
                ->where('cards.0.total', 1)
                ->has('criativos.data', 0));

        // Cards por produto (2026-11-20): o card abre os vídeos daquele produto.
        $this->actingAs($admin)->get(self::BASE.'?produto=sem')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('cards', null)
                ->where('produtoAtual.nome', 'Sem produto')
                ->has('criativos.data', 1));

        $this->actingAs($admin)->get("/admin/validacao-criativos/{$criativo->id}/arquivo")
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_parte_fora_de_ordem_diz_onde_parou(): void
    {
        $admin = $this->admin();
        $envio = (string) Str::uuid();

        $this->actingAs($admin)->post(self::BASE.'/parte', [
            'envio' => $envio, 'inicio' => 0, 'parte' => UploadedFile::fake()->createWithContent('p', str_repeat('a', 500)),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->actingAs($admin)->post(self::BASE.'/parte', [
            'envio' => $envio, 'inicio' => 0, 'parte' => UploadedFile::fake()->createWithContent('p', str_repeat('a', 500)),
        ], ['Accept' => 'application/json'])->assertStatus(409)->assertJson(['recebido' => 500]);
    }

    public function test_envio_incompleto_ou_que_nao_e_video_e_recusado(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(self::BASE, [
            'envio' => (string) Str::uuid(), 'nome_original' => 'a.mp4', 'tamanho' => 100, 'titulo' => 'X', 'plataforma' => 'meta',
        ])->assertStatus(422)->assertJson(['message' => 'O arquivo não chegou inteiro. Envie de novo.']);

        $this->enviarEmPartes($admin, '<html><script>alert(1)</script></html>', 'falso.mp4')
            ->assertStatus(422);

        $this->enviarEmPartes($admin, $this->conteudoMp4(200), 'planilha.pdf')
            ->assertStatus(422);

        $this->assertSame(0, CriativoValidacao::count());
    }

    public function test_so_administrador_avalia_e_ajuste_exige_comentario(): void
    {
        $criativo = $this->criativo();
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($gerente)->get(self::BASE)
            ->assertInertia(fn (AssertableInertia $p) => $p->where('podeAvaliar', false));
        $this->actingAs($gerente)->patch(self::BASE."/{$criativo->id}/avaliar", ['status' => 'aprovado'])->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)->patch(self::BASE."/{$criativo->id}/avaliar", ['status' => 'ajustar'])
            ->assertSessionHasErrors('comentario');

        $this->actingAs($admin)->patch(self::BASE."/{$criativo->id}/avaliar", ['status' => 'ajustar', 'comentario' => 'Legenda maior nos 3 primeiros segundos'])
            ->assertSessionHasNoErrors();
        $criativo->refresh();
        $this->assertSame(CriativoValidacao::AJUSTAR, $criativo->status);
        $this->assertSame($admin->id, $criativo->avaliado_por);
        $this->assertNotNull($criativo->avaliado_em);

        $this->actingAs($admin)->patch(self::BASE."/{$criativo->id}/avaliar", ['status' => 'aprovado'])->assertSessionHasNoErrors();
        $this->assertSame(CriativoValidacao::APROVADO, $criativo->fresh()->status);
    }

    public function test_lixeira_guarda_o_arquivo_e_restaura(): void
    {
        $admin = $this->admin();
        $criativo = $this->criativo();

        $this->actingAs($admin)->delete(self::BASE."/{$criativo->id}")->assertRedirect();
        $this->assertSoftDeleted($criativo);
        Storage::disk('local')->assertExists($criativo->path);

        $this->actingAs($admin)->get(self::BASE.'?lixeira=1')
            ->assertInertia(fn (AssertableInertia $p) => $p->has('criativos.data', 1)->where('criativos.data.0.na_lixeira', true));
        $this->actingAs($admin)->get("/admin/validacao-criativos/{$criativo->id}/arquivo")->assertOk();

        $this->actingAs($admin)->patch(self::BASE."/{$criativo->id}/restaurar")->assertRedirect();
        $this->assertNotSoftDeleted($criativo);
    }

    public function test_identificacao_embaixo_do_video_salva_e_entra_na_busca(): void
    {
        $admin = $this->admin();
        $this->enviarEmPartes($admin, $this->conteudoMp4(1500), dados: ['identificacao' => 'Árvore, campanha de Natal'])
            ->assertCreated()
            ->assertJsonPath('criativo.identificacao', 'Árvore, campanha de Natal');
        $criativo = CriativoValidacao::sole();

        $this->actingAs($admin)->patch(self::BASE."/{$criativo->id}/identificacao", ['identificacao' => '  Bike, Black Friday Meta  '])
            ->assertRedirect();
        $this->assertSame('Bike, Black Friday Meta', $criativo->fresh()->identificacao);

        $this->actingAs($admin)->get(self::BASE.'?busca=Black+Friday')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->has('criativos.data', 1)
                ->where('criativos.data.0.identificacao', 'Bike, Black Friday Meta'));

        $this->actingAs($admin)->patch(self::BASE."/{$criativo->id}/identificacao", ['identificacao' => ''])
            ->assertRedirect();
        $this->assertNull($criativo->fresh()->identificacao);

        $this->actingAs($admin)->patch(self::BASE."/{$criativo->id}/identificacao", ['identificacao' => str_repeat('a', 256)])
            ->assertSessionHasErrors('identificacao');
    }

    public function test_cliente_nao_entra(): void
    {
        $cliente = User::factory()->create();

        $this->actingAs($cliente)->get(self::BASE)->assertForbidden();
    }

    private function criativo(): CriativoValidacao
    {
        Storage::disk('local')->put('criativos/2026-10/teste.mp4', $this->conteudoMp4(100));

        return CriativoValidacao::create([
            'titulo' => 'Teste',
            'plataforma' => 'tiktok',
            'nome_original' => 'teste.mp4',
            'path' => 'criativos/2026-10/teste.mp4',
            'tamanho' => 100,
        ]);
    }

    public function test_vids_envia_pela_api_com_token_e_cai_no_card_do_produto(): void
    {
        $admin = $this->admin();
        $produto = \App\Modules\Catalog\Models\Product::factory()->create(['name' => 'Travesseiro Borboleta']);
        config(['services.vids.token' => 'chave-vids', 'services.vids.usuario_id' => $admin->id]);
        $conteudo = $this->conteudoMp4(2500);
        $envio = (string) Str::uuid();

        $this->post('/api/vids/parte', ['envio' => $envio, 'inicio' => 0, 'parte' => UploadedFile::fake()->createWithContent('parte.bin', $conteudo)], ['Accept' => 'application/json'])
            ->assertUnauthorized();

        $cabecalho = ['Authorization' => 'Bearer chave-vids', 'Accept' => 'application/json'];
        $this->post('/api/vids/parte', ['envio' => $envio, 'inicio' => 0, 'parte' => UploadedFile::fake()->createWithContent('parte.bin', $conteudo)], $cabecalho)
            ->assertOk()->assertJson(['recebido' => 2500]);
        $this->postJson('/api/vids/criativos', [
            'envio' => $envio, 'nome_original' => 'MC_travesseiro_C01_9x16.mp4', 'tamanho' => 2500,
            'titulo' => 'Travesseiro Borboleta · C01', 'plataforma' => 'meta', 'produto_id' => $produto->id,
        ], $cabecalho)->assertCreated();

        $criativo = CriativoValidacao::sole();
        $this->assertSame($produto->id, $criativo->produto_id);
        $this->assertSame($admin->id, $criativo->enviado_por);

        $this->actingAs($admin)->get(self::BASE)
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('cards.0.id', $produto->id)
                ->where('cards.0.nome', 'Travesseiro Borboleta')
                ->where('cards.0.porStatus.aguardando', 1));
    }

    public function test_vids_sem_configuracao_nao_aceita_nada(): void
    {
        config(['services.vids.token' => null, 'services.vids.usuario_id' => null]);

        $this->postJson('/api/vids/criativos', [], ['Authorization' => 'Bearer qualquer'])->assertStatus(503);
    }

    public function test_acesso_da_vids_libera_equipe_e_barra_quem_nao_entrou(): void
    {
        $this->get('/admin/vids/acesso')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))->get('/admin/vids/acesso')->assertUnauthorized();
        $this->actingAs($this->admin())->get('/admin/vids/acesso')->assertNoContent();
    }
}
