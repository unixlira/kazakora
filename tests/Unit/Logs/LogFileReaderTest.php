<?php

namespace Tests\Unit\Logs;

use App\Services\Logs\LogFileReader;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A parte delicada do leitor é a varredura reversa em blocos de 256 KB:
 * uma entrada pode cair em cima da fronteira de dois blocos e o stack trace
 * de várias linhas precisa continuar colado na entrada que o gerou. Por isso
 * um dos testes escreve um arquivo com mais de 256 KB de propósito.
 */
class LogFileReaderTest extends TestCase
{
    private string $base;

    private LogFileReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/kazakora-logs-'.uniqid();
        mkdir($this->base.'/logs', 0777, true);
        $this->app->useStoragePath($this->base);

        $this->reader = new LogFileReader();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base.'/logs/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->base.'/logs');
        @rmdir($this->base);

        parent::tearDown();
    }

    private function write(string $name, string $content): void
    {
        file_put_contents($this->base.'/logs/'.$name, $content);
    }

    public function test_lista_do_mais_novo_para_o_mais_velho_e_mantem_o_stack_trace_junto(): void
    {
        $this->write('laravel-2026-09-13.log', <<<'LOG'
        [2026-09-13 08:00:00] production.INFO: primeiro evento
        [2026-09-13 09:00:00] production.ERROR: explodiu {"pedido":2028}
        [stacktrace]
        #0 /var/www/app/Servico.php(10): faz()
        #1 {main}
        [2026-09-13 10:00:00] production.WARNING: quase explodiu

        LOG);

        $resultado = $this->reader->search([]);
        $entradas = $resultado['entries'];

        $this->assertCount(3, $entradas);
        $this->assertSame('quase explodiu', $entradas[0]['mensagem']);
        $this->assertSame('warning', $entradas[0]['nivel']);
        $this->assertSame('error', $entradas[1]['nivel']);
        $this->assertStringContainsString('explodiu', $entradas[1]['mensagem']);
        $this->assertStringContainsString('#0 /var/www/app/Servico.php', $entradas[1]['detalhe']);
        $this->assertSame('primeiro evento', $entradas[2]['mensagem']);
        $this->assertSame('13/09/2026 08:00:00', $entradas[2]['dataHora']);
    }

    public function test_filtra_por_nivel_texto_e_arquivo(): void
    {
        $this->write('laravel-2026-09-13.log', <<<'LOG'
        [2026-09-13 08:00:00] production.INFO: importou pedido 2028
        [2026-09-13 09:00:00] production.ERROR: falhou a nota do pedido 2028
        [2026-09-13 10:00:00] production.WARNING: estoque baixo no pedido 3000

        LOG);
        $this->write('shopee-2026-09-13.log', <<<'LOG'
        [2026-09-13 11:00:00] production.ERROR: shopee recusou o pedido 2028

        LOG);

        $erros = $this->reader->search(['niveis' => ['error']])['entries'];
        $this->assertCount(2, $erros);
        $this->assertSame('shopee-2026-09-13.log', $erros[0]['arquivo']);

        $busca = $this->reader->search(['q' => 'pedido 2028'])['entries'];
        $this->assertCount(3, $busca);

        $porArquivo = $this->reader->search(['arquivo' => 'shopee-2026-09-13.log'])['entries'];
        $this->assertCount(1, $porArquivo);
        $this->assertSame('shopee', $porArquivo[0]['canal']);

        $combinado = $this->reader->search(['niveis' => ['error'], 'q' => 'nota'])['entries'];
        $this->assertCount(1, $combinado);
        $this->assertStringContainsString('falhou a nota', $combinado[0]['mensagem']);
    }

    public function test_filtra_por_janela_de_data_e_hora(): void
    {
        $this->write('laravel-2026-09-13.log', <<<'LOG'
        [2026-09-13 07:59:59] production.INFO: antes da janela
        [2026-09-13 08:00:00] production.INFO: comeco da janela
        [2026-09-13 12:00:00] production.INFO: fim da janela
        [2026-09-13 12:00:01] production.INFO: depois da janela

        LOG);

        $entradas = $this->reader->search([
            'de' => Carbon::parse('2026-09-13 08:00:00'),
            'ate' => Carbon::parse('2026-09-13 12:00:00'),
        ])['entries'];

        $this->assertCount(2, $entradas);
        $this->assertSame('fim da janela', $entradas[0]['mensagem']);
        $this->assertSame('comeco da janela', $entradas[1]['mensagem']);
    }

    public function test_arquivo_com_dia_no_nome_fora_da_janela_nao_e_lido(): void
    {
        $this->write('laravel-2026-09-01.log', "[2026-09-01 08:00:00] production.ERROR: velho demais\n");
        $this->write('laravel-2026-09-13.log', "[2026-09-13 08:00:00] production.ERROR: dentro da janela\n");

        $resultado = $this->reader->search(['de' => Carbon::parse('2026-09-13 00:00:00')]);

        $this->assertSame(1, $resultado['filesScanned']);
        $this->assertCount(1, $resultado['entries']);
    }

    public function test_le_arquivo_maior_que_um_bloco_sem_perder_nem_partir_entradas(): void
    {
        $total = 3000;
        $conteudo = '';
        for ($i = 1; $i <= $total; $i++) {
            $minuto = str_pad((string) ($i % 60), 2, '0', STR_PAD_LEFT);
            $hora = str_pad((string) (1 + intdiv($i, 60)), 2, '0', STR_PAD_LEFT);
            $conteudo .= sprintf(
                "[2026-09-13 %s:%s:00] production.INFO: entrada-%04d %s\n",
                $hora,
                $minuto,
                $i,
                str_repeat('x', 120)
            );
        }

        // > 256 KB garante que a leitura reversa passe por vários blocos.
        $this->write('laravel-2026-09-13.log', $conteudo);
        $this->assertGreaterThan(262144, strlen($conteudo));

        $coletadas = [];
        for ($pagina = 1; $pagina <= 20; $pagina++) {
            $entradas = $this->reader->search(['pagina' => $pagina, 'porPagina' => 200])['entries'];
            if ($entradas === []) {
                break;
            }
            foreach ($entradas as $entrada) {
                $coletadas[] = $entrada;
            }
        }

        $this->assertCount($total, $coletadas);

        // Ordem decrescente e nenhuma entrada partida ao meio na fronteira
        // dos blocos: toda mensagem tem que começar no seu próprio marcador.
        foreach ($coletadas as $indice => $entrada) {
            $esperado = sprintf('entrada-%04d', $total - $indice);
            $this->assertStringStartsWith($esperado, $entrada['mensagem']);
            $this->assertSame('info', $entrada['nivel']);
        }
    }

    public function test_arquivo_fora_do_formato_monolog_vira_uma_entrada_por_linha(): void
    {
        $this->write('queue-cron.log', <<<'LOG'
          2026-09-14 09:44:37 App\Jobs\ProcessBlingOrderWebhook .......... RUNNING
          2026-09-14 09:44:47 App\Jobs\ProcessBlingOrderWebhook .......... 9s DONE
          2026-09-14 09:45:01 App\Jobs\ProcessBlingOrderWebhook .......... FAIL

        LOG);

        $entradas = $this->reader->search([])['entries'];

        $this->assertCount(3, $entradas);
        $this->assertStringContainsString('FAIL', $entradas[0]['mensagem']);
        $this->assertSame('error', $entradas[0]['nivel'], 'linha de FAIL no cron deve aparecer como erro');
        $this->assertSame('14/09/2026 09:44:47', $entradas[1]['dataHora']);
        $this->assertSame('queue-cron', $entradas[1]['canal']);
    }

    public function test_nome_de_arquivo_inexistente_nao_traz_nada(): void
    {
        $this->write('laravel-2026-09-13.log', "[2026-09-13 08:00:00] production.ERROR: unico\n");

        $resultado = $this->reader->search(['arquivo' => '../../.env']);

        $this->assertSame(0, $resultado['filesScanned']);
        $this->assertSame([], $resultado['entries']);
    }
}
