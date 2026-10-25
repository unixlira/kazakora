<?php

namespace App\Console\Commands;

use App\Modules\Analytics\Support\LocalDoIp;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;
use Throwable;

/**
 * Baixa a base DB-IP City Lite do mês (gratuita, CC BY 4.0) usada pela tela
 * Visitas ao site para saber o estado do IP (LocalDoIp). A base sai no
 * começo do mês; se a do mês ainda não saiu, tenta a do mês anterior. Só
 * troca o arquivo depois de abrir a base nova: se o download falhar, a
 * antiga continua.
 */
class AtualizarGeoIp extends Command
{
    protected $signature = 'geoip:atualizar';

    protected $description = 'Baixa a base DB-IP City Lite (IP → estado/cidade) da tela Visitas ao site.';

    private const URL = 'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz';

    public function handle(): int
    {
        $destino = LocalDoIp::caminho();
        @mkdir(dirname($destino), 0755, true);
        $gz = $destino.'.download.gz';
        $novo = $destino.'.novo';

        foreach ([Carbon::now(), Carbon::now()->subMonthNoOverflow()] as $mes) {
            $url = sprintf(self::URL, $mes->format('Y-m'));

            try {
                $resposta = Http::timeout(600)->sink($gz)->get($url);
            } catch (Throwable $e) {
                $this->warn("{$url}: {$e->getMessage()}");

                continue;
            }

            if (! $resposta->successful()) {
                $this->warn("{$url}: HTTP {$resposta->status()}");

                continue;
            }

            try {
                $this->descompactar($gz, $novo);
                (new Reader($novo))->close();
            } catch (Throwable $e) {
                $this->warn("{$url}: arquivo inválido ({$e->getMessage()})");

                continue;
            } finally {
                @unlink($gz);
            }

            rename($novo, $destino);
            $this->info("Base {$mes->format('Y-m')} salva em {$destino} (".number_format(filesize($destino) / 1048576, 0, ',', '.').' MB).');

            return self::SUCCESS;
        }

        @unlink($novo);
        $this->error('Não foi possível baixar a base; a anterior (se existir) continua.');

        return self::FAILURE;
    }

    private function descompactar(string $origem, string $destino): void
    {
        $entrada = gzopen($origem, 'rb');
        $saida = fopen($destino, 'wb');
        if ($entrada === false || $saida === false) {
            throw new \RuntimeException('não abriu o arquivo');
        }

        while (! gzeof($entrada)) {
            fwrite($saida, (string) gzread($entrada, 1048576));
        }

        gzclose($entrada);
        fclose($saida);
    }
}
