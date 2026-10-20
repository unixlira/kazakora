<?php

namespace Tests\Unit\Catalog;

use App\Console\Commands\ImportarAvaliacoesOutraLoja;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class AvaliacoesOutraLojaNomesTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private $resolverAnterior;

    private $appAnterior;

    private MySqlConnection $conexao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolverAnterior = Model::getConnectionResolver();
        $this->appAnterior = Facade::getFacadeApplication();
        // Sem resolver o "db" do app anterior: depois de um teste de Feature
        // o container já foi esvaziado e DB::getFacadeRoot() estoura.
        Facade::setFacadeApplication(null);
        Facade::clearResolvedInstance('db');
        // Só compila SQL; qualquer tentativa de abrir um banco falha.
        $this->conexao = Mockery::mock(MySqlConnection::class.'[select,insert,getLastInsertId]', [fn () => throw new RuntimeException('Banco proibido neste teste')]);
        $resolver = new ConnectionResolver(['teste' => $this->conexao]);
        $resolver->setDefaultConnection('teste');
        Model::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('db');
        Facade::setFacadeApplication($this->appAnterior);
        if ($this->resolverAnterior) {
            Model::setConnectionResolver($this->resolverAnterior);
        } else {
            Model::unsetConnectionResolver();
        }

        parent::tearDown();
    }

    public static function autores(): array
    {
        return [
            'apelido original fictício' => ['@nuvem_teste_azul', '@nuvem_teste_azul'],
            'máscara da origem intacta' => ['n*****l', 'n*****l'],
            'autor nulo' => [null, Review::NOME_OUTRA_LOJA],
            'autor vazio' => ['', Review::NOME_OUTRA_LOJA],
            'autor só com espaços' => ['   ', Review::NOME_OUTRA_LOJA],
        ];
    }

    #[DataProvider('autores')]
    public function test_exibicao_preserva_autor_da_origem_ou_usa_fallback(?string $autor, string $esperado): void
    {
        $review = new Review(['outra_loja' => true, 'reviewer_name' => $autor]);

        $this->assertSame($esperado, $review->reviewer_display_name);
        $this->assertSame($esperado, $review->toArray()['reviewer_display_name']);
    }

    #[DataProvider('autores')]
    public function test_importador_preserva_autor_da_origem_ou_usa_fallback(?string $autor, string $esperado): void
    {
        $gravados = [];
        // Dublês apenas na fronteira de I/O: executa handle, fill e save reais.
        $this->conexao->shouldReceive('select')->andReturnUsing(function (string $sql) {
            if (str_contains($sql, 'from `products`')) {
                return [(object) ['id' => 53]];
            }
            if (str_contains($sql, 'as `exists`')) {
                return [(object) ['exists' => false]];
            }

            return [];
        });
        $this->conexao->shouldReceive('insert')->once()->andReturnUsing(function (string $sql, array $bindings) use (&$gravados) {
            preg_match('/\((.*?)\) values/', $sql, $colunas);
            $gravados = array_combine(explode(', ', str_replace('`', '', $colunas[1])), $bindings);

            return true;
        });
        $this->conexao->shouldReceive('getLastInsertId')->andReturn(901);
        DB::swap($transacoes = Mockery::mock());
        $transacoes->shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());

        $arquivo = tempnam(sys_get_temp_dir(), 'review-nomes-');
        try {
            file_put_contents($arquivo, json_encode([
                'produto_kazakora' => 53,
                'avaliacoes' => [[
                    'origem' => 'outra_loja', 'canal' => 'shopee', 'id_avaliacao' => 'teste-ficticio',
                    'autor' => $autor, 'nota' => 1, 'texto' => 'Avaliação fictícia.',
                ]],
            ], JSON_THROW_ON_ERROR));
            $comando = new ImportarAvaliacoesOutraLoja;
            $app = Mockery::mock(Application::class)->makePartial();
            $app->shouldReceive('runningUnitTests')->andReturnTrue();
            $comando->setLaravel($app);
            $saida = new BufferedOutput;
            $this->assertSame(0, $comando->run(new ArrayInput(['arquivo' => $arquivo]), $saida));

            $this->assertSame($esperado, $gravados['reviewer_name']);
            $this->assertSame(1, $gravados['rating']);
            $this->assertTrue($gravados['outra_loja']);
            $this->assertSame(Review::CHANNEL_OUTRA_LOJA, $gravados['channel']);
            $this->assertStringContainsString(Review::SELO_OUTRA_LOJA, $saida->fetch());
        } finally {
            unlink($arquivo);
        }
    }

    public function test_selo_e_escopos_de_notas_continuam_separando_origem_externa(): void
    {
        $this->assertSame('Avaliação feita em outra loja', Review::SELO_OUTRA_LOJA);
        $this->assertSame([false], Review::query()->daLoja()->getBindings());
        $this->assertSame([true], Review::query()->deOutraLoja()->getBindings());
        $this->assertStringContainsString('`reviews`.`outra_loja` = ?', Review::query()->daLoja()->toSql());
        $this->assertStringContainsString('`reviews`.`outra_loja` = ?', Product::query()->forCard()->toSql());
        $this->assertContains(false, Product::query()->forCard()->getBindings());
    }

    public function test_nome_da_avaliacao_da_kazakora_nao_muda(): void
    {
        $review = new Review(['outra_loja' => false, 'reviewer_name' => 'Apelido fictício']);
        $review->setRelation('user', null);
        $this->assertSame('Apelido fictício', $review->reviewer_display_name);

        $review->reviewer_name = null;
        $this->assertSame('Cliente KazaKora', $review->reviewer_display_name);
    }
}
