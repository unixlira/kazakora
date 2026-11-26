<?php

namespace Tests\Feature\Console;

use App\Modules\Catalog\Models\Product;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\FerraKora\Services\CatalogoFerraKora;
use App\Modules\Marketplace\Jobs\PublishProductToChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** produtos:titulos-persuasivos (pedido do Lira 25/11/2026). */
class TitulosPersuasivosTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $conteudo): string
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'titulos').'.csv';
        file_put_contents($arquivo, $conteudo);

        return $arquivo;
    }

    public function test_sem_aplicar_so_mostra(): void
    {
        $produto = Product::factory()->create(['name' => 'Lixeira 12 L', 'is_active' => true]);

        $this->artisan('produtos:titulos-persuasivos', ['arquivo' => $this->csv("id;titulo_novo\n{$produto->id};Cozinha Sem Cheiro + Lixeira 12 L\n")])
            ->assertSuccessful();

        $this->assertSame('Lixeira 12 L', $produto->fresh()->name);
    }

    public function test_aplica_guarda_backup_termos_e_slug_e_desfaz(): void
    {
        Storage::fake('local');
        Queue::fake();

        $produto = Product::factory()->create([
            'name' => 'Lixeira Automática 12 L + PDF Grátis',
            'slug' => 'lixeira-automatica-12-l',
            'seo_title' => 'Lixeira Automática 12 L + PDF Grátis',
            'termos_busca' => 'cesto de lixo',
            'is_active' => true,
        ]);
        FerraKoraProduto::create(['product_id' => $produto->id, 'ordem' => 1, 'ativo' => true]);
        $slugFerraKora = app(CatalogoFerraKora::class)->slugPublicado(FerraKoraProduto::query()->first());

        $travesseiro = Product::factory()->create(['name' => 'Travesseiro Cervical', 'is_active' => true]);
        $soFerraKora = Product::factory()->create(['name' => 'Trena 5 m', 'is_active' => true, 'so_ferrakora' => true]);

        $novo = 'Cozinha Sem Cheiro no Ar: Lixeira Automática 12 L';
        $arquivo = $this->csv("\xEF\xBB\xBFid;nome_atual;titulo_novo\n{$produto->id};Lixeira Automática 12 L + PDF Grátis;{$novo}\n{$travesseiro->id};Travesseiro Cervical;Durma Bem\n{$soFerraKora->id};Trena 5 m;Meça Tudo\n");

        $this->artisan('produtos:titulos-persuasivos', ['arquivo' => $arquivo, '--aplicar' => true])->assertSuccessful();

        $produto->refresh();
        $this->assertSame($novo, $produto->name);
        $this->assertSame($novo, $produto->seo_title);
        $this->assertSame('lixeira-automatica-12-l', $produto->slug);
        $this->assertSame('cesto de lixo | Lixeira Automática 12 L + PDF Grátis', $produto->termos_busca);
        $this->assertSame('Lixeira Automática 12 L', $produto->nome_interno);
        $this->assertSame('Travesseiro Cervical', $travesseiro->fresh()->name);
        $this->assertSame('Trena 5 m', $soFerraKora->fresh()->name);
        Queue::assertNotPushed(PublishProductToChannel::class);

        // FerraKora continua com o mesmo título e endereço.
        $registro = FerraKoraProduto::query()->first();
        $this->assertSame('Lixeira Automática 12 L + PDF Grátis', $registro->titulo);
        $this->assertSame($slugFerraKora, app(CatalogoFerraKora::class)->slugPublicado($registro));

        // A busca do site acha pela palavra que saiu do título.
        $produto->forceFill(['stock' => 5])->saveQuietly();
        $ids = collect($this->get('/?search=pdf')->viewData('page')['props']['products']['data'])->pluck('id')->all();
        $this->assertSame([$produto->id], $ids);

        $backups = Storage::disk('local')->files('titulos-persuasivos');
        $this->assertCount(1, $backups);

        $this->artisan('produtos:titulos-persuasivos', ['--desfazer' => $backups[0]])->assertSuccessful();

        $produto->refresh();
        $this->assertSame('Lixeira Automática 12 L + PDF Grátis', $produto->name);
        $this->assertSame('Lixeira Automática 12 L + PDF Grátis', $produto->seo_title);
        $this->assertSame('cesto de lixo', $produto->termos_busca);
        $this->assertNull($produto->nome_interno);
        $this->assertSame('lixeira-automatica-12-l', $produto->slug);
        $this->assertNull(FerraKoraProduto::query()->first()->titulo);
    }

    public function test_tira_o_pdf_do_nome_da_nota(): void
    {
        $this->assertSame('Lixeira 12 L', \App\Console\Commands\TitulosPersuasivos::semPdf('Lixeira 12 L + PDF Grátis'));
        $this->assertSame('Kit Pintura', \App\Console\Commands\TitulosPersuasivos::semPdf('Kit Pintura + Guia PDF Arte'));
        $this->assertSame('Panela 5 L', \App\Console\Commands\TitulosPersuasivos::semPdf('Panela 5 L'));
    }
}
