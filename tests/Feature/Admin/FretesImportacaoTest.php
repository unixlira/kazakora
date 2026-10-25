<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\FreteRegiao;
use App\Modules\Checkout\Support\RegrasDeFrete;
use App\Support\SiteAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/**
 * Regras de frete por planilha (pedido 2026-10-25): a planilha com
 * Afonso Cláudio e os distritos dele (Serra Pelada, Pontões) vira regras de
 * frete de uma vez, com prévia antes de gravar.
 */
class FretesImportacaoTest extends TestCase
{
    use RefreshDatabase;

    private const AFONSO_CLAUDIO = 3200102;

    private const VITORIA = 3205309;

    private const MAUA = 3529401;

    private const VIACEP = [
        '29600000' => ['bairro' => '', 'localidade' => 'Afonso Cláudio', 'uf' => 'ES', 'ibge' => '3200102'],
        '29603000' => ['bairro' => 'Serra Pelada', 'localidade' => 'Afonso Cláudio', 'uf' => 'ES', 'ibge' => '3200102'],
        '29604000' => ['bairro' => 'Pontões', 'localidade' => 'Afonso Cláudio', 'uf' => 'ES', 'ibge' => '3200102'],
        '29010000' => ['bairro' => 'Centro', 'localidade' => 'Vitória', 'uf' => 'ES', 'ibge' => '3205309'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $agora = now();
        DB::table('ibge_estados')->insert([
            ['id' => 32, 'sigla' => 'ES', 'nome' => 'Espírito Santo', 'created_at' => $agora, 'updated_at' => $agora],
            ['id' => 35, 'sigla' => 'SP', 'nome' => 'São Paulo', 'created_at' => $agora, 'updated_at' => $agora],
        ]);
        DB::table('ibge_municipios')->insert([
            ['id' => self::AFONSO_CLAUDIO, 'nome' => 'Afonso Cláudio', 'nome_normalizado' => 'afonso claudio', 'estado_id' => 32, 'uf' => 'ES', 'created_at' => $agora, 'updated_at' => $agora],
            ['id' => self::VITORIA, 'nome' => 'Vitória', 'nome_normalizado' => 'vitoria', 'estado_id' => 32, 'uf' => 'ES', 'created_at' => $agora, 'updated_at' => $agora],
            ['id' => self::MAUA, 'nome' => 'Mauá', 'nome_normalizado' => 'maua', 'estado_id' => 35, 'uf' => 'SP', 'created_at' => $agora, 'updated_at' => $agora],
        ]);

        Http::fake(function (Request $request) {
            if (preg_match('#viacep\.com\.br/ws/(\d{8})/json#', $request->url(), $m)) {
                return isset(self::VIACEP[$m[1]])
                    ? Http::response(['cep' => substr($m[1], 0, 5).'-'.substr($m[1], 5), 'logradouro' => '', ...self::VIACEP[$m[1]]])
                    : Http::response(['erro' => 'true']);
            }

            return Http::response([], 404);
        });
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function csv(array $linhas): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('fretes.csv', implode("\r\n", array_map(fn ($l) => implode(';', $l), $linhas)));
    }

    private const PLANILHA = [
        ['UF', 'Cidade', 'Distrito', 'CEP', 'Tipo', 'Valor', 'Prazo'],
        ['ES', 'Afonso Cláudio', '', '29600-000', 'fixo', '19,90', '6'],
        ['ES', 'Afonso Claudio', 'Serra Pelada', '29603-000', 'fixo', 'R$ 24,90', '8'],
        ['ES', 'Afonso Cláudio', 'Pontões', '29604000', 'acréscimo', '5', ''],
        ['SP', 'Mauá', '', '', 'grátis', '', ''],
    ];

    public function test_previa_mostra_o_que_vai_acontecer_sem_gravar(): void
    {
        $resposta = $this->actingAs($this->admin())
            ->post('/admin/logistica/fretes-por-estado/importar/previa', ['arquivo' => $this->csv(self::PLANILHA)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('erro', null)
            ->assertJsonPath('resumo', ['total' => 4, 'criar' => 4, 'atualizar' => 0, 'igual' => 0, 'erro' => 0]);

        $itens = $resposta->json('itens');
        $this->assertSame(['Afonso Cláudio', '', null, 'fixo', 19.9, 6], [$itens[0]['cidade'], $itens[0]['distrito'], $itens[0]['cep_inicio'], $itens[0]['tipo_preco'], $itens[0]['valor'], $itens[0]['prazo_dias']]);
        $this->assertSame(['Serra Pelada', '29603000', '29603000', 24.9, 8], [$itens[1]['distrito'], $itens[1]['cep_inicio'], $itens[1]['cep_fim'], $itens[1]['valor'], $itens[1]['prazo_dias']]);
        $this->assertEquals([FreteRegiao::ACRESCIMO, 5, null], [$itens[2]['tipo_preco'], $itens[2]['valor'], $itens[2]['prazo_dias']]);
        $this->assertEquals([self::MAUA, FreteRegiao::FIXO, 0], [$itens[3]['municipio_id'], $itens[3]['tipo_preco'], $itens[3]['valor']]);
        $this->assertSame(0, FreteRegiao::query()->count());
    }

    public function test_grava_cidade_e_distritos_e_a_loja_ja_usa(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/logistica/fretes-por-estado/importar', ['arquivo' => $this->csv(self::PLANILHA)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('gravadas', 4);

        $this->assertSame(4, FreteRegiao::query()->where('site', SiteAtual::KAZAKORA)->count());
        $this->assertDatabaseHas('frete_regiao_locais', ['municipio_id' => self::AFONSO_CLAUDIO, 'distrito' => 'Serra Pelada', 'cep_inicio' => '29603000', 'cep_fim' => '29603000']);
        $this->assertDatabaseHas('frete_regiao_locais', ['municipio_id' => self::AFONSO_CLAUDIO, 'distrito' => null, 'cep_inicio' => null]);

        $regras = app(RegrasDeFrete::class);
        $this->assertSame('Serra Pelada (Afonso Cláudio - ES)', $regras->paraDestino(SiteAtual::KAZAKORA, '29603000', 'ES')[0]['nome']);
        $this->assertSame('Afonso Cláudio - ES', $regras->paraDestino(SiteAtual::KAZAKORA, '29600000', 'ES')[0]['nome']);
        $this->assertSame([], $regras->paraDestino(SiteAtual::KAZAKORA, '29010000', 'ES'));
    }

    public function test_segunda_planilha_atualiza_e_nao_duplica(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/logistica/fretes-por-estado/importar', ['arquivo' => $this->csv(self::PLANILHA)], ['Accept' => 'application/json'])->assertOk();

        $nova = self::PLANILHA;
        $nova[2][5] = '29,90';
        $this->actingAs($admin)
            ->post('/admin/logistica/fretes-por-estado/importar', ['arquivo' => $this->csv($nova)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('resumo.atualizar', 1)
            ->assertJsonPath('resumo.igual', 3)
            ->assertJsonPath('itens.1.antes.valor', 24.9)
            ->assertJsonPath('gravadas', 1);

        $this->assertSame(4, FreteRegiao::query()->count());
        $this->assertSame(29.9, FreteRegiao::query()->where('nome', 'Serra Pelada (Afonso Cláudio - ES)')->value('valor') + 0.0);
    }

    public function test_linhas_com_erro_ficam_de_fora(): void
    {
        $planilha = [
            ['uf', 'cidade', 'distrito', 'cep', 'valor'],
            ['ES', 'Afonso Cláudio', 'Serra Pelada', '29010-000', '10'],   // CEP de Vitória
            ['ES', 'Cidade Que Não Existe', '', '', '10'],
            ['ES', 'Vitória', '', '', 'dez reais'],
            ['ES', 'Vitória', '', '', '12'],
            ['ES', 'vitoria', '', '', '13'],                                // repetida
            ['', '', 'Pontões', '29604-000', '9'],                           // cidade pelo CEP
        ];

        $resposta = $this->actingAs($this->admin())
            ->post('/admin/logistica/fretes-por-estado/importar', ['arquivo' => $this->csv($planilha)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('gravadas', 2)
            ->assertJsonPath('resumo.erro', 4);

        $erros = collect($resposta->json('itens'))->pluck('erros', 'linha');
        $this->assertStringContainsString('é de Vitória - ES', $erros[2][0]);
        $this->assertStringContainsString('não existe em ES', $erros[3][0]);
        $this->assertStringContainsString('não é número', $erros[4][0]);
        $this->assertStringContainsString('Repete a linha 5', $erros[6][0]);
        $this->assertSame(['Vitória - ES', 'Pontões (Afonso Cláudio - ES)'], FreteRegiao::query()->orderBy('id')->pluck('nome')->all());
    }

    public function test_le_xlsx_com_cep_numerico_e_faixa(): void
    {
        $caminho = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($caminho, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Fretes" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Município</t></si><si><t>Estado</t></si><si><t>Distrito</t></si><si><t>CEP inicial</t></si><si><t>CEP final</t></si><si><t>Frete</t></si><si><t>Afonso Cláudio</t></si><si><t>Espírito Santo</t></si><si><t>Serra Pelada</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c><c r="E1" t="s"><v>4</v></c><c r="F1" t="s"><v>5</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>6</v></c><c r="B2" t="s"><v>7</v></c><c r="C2" t="s"><v>8</v></c><c r="D2"><v>29603000</v></c><c r="E2"><v>29603999</v></c><c r="F2"><v>15.5</v></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $this->actingAs($this->admin())
            ->post('/admin/logistica/fretes-por-estado/importar/previa', ['arquivo' => new UploadedFile($caminho, 'fretes.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('resumo.criar', 1)
            ->assertJsonPath('itens.0.uf', 'ES')
            ->assertJsonPath('itens.0.distrito', 'Serra Pelada')
            ->assertJsonPath('itens.0.cep_inicio', '29603000')
            ->assertJsonPath('itens.0.cep_fim', '29603999')
            ->assertJsonPath('itens.0.valor', 15.5);
    }

    public function test_planilha_sem_coluna_de_valor_e_recusada(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/logistica/fretes-por-estado/importar/previa', ['arquivo' => $this->csv([['uf', 'cidade'], ['ES', 'Vitória']])], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('erro', 'Faltou a coluna "valor" (ou "tipo" com "gratis").');

        $this->actingAs($this->admin())
            ->post('/admin/logistica/fretes-por-estado/importar/previa', ['arquivo' => UploadedFile::fake()->createWithContent('fretes.pdf', 'x')], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_modelo_para_baixar(): void
    {
        $resposta = $this->actingAs($this->admin())->get('/admin/logistica/fretes-por-estado/importar/modelo')->assertOk();
        $this->assertStringContainsString('Serra Pelada', $resposta->getContent());
    }
}
