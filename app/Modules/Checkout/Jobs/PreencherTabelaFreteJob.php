<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Admin\Support\AlertasAdmin;
use App\Modules\Checkout\Support\TabelaFreteLocalidades;
use App\Support\FreteProvedor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Preenche a tabela de frete por localidade em segundo plano (pedido do Lira
 * em 2026-10-25): primeiro as localidades, uma UF por vez; depois a cotação,
 * CEPS_POR_VEZ CEPs por vez. Cada pedaço cabe folgado no tempo do worker
 * (110 s no Horizon) e o job se agenda de novo até acabar.
 *
 * Uma cadeia só: iniciar() troca o código da cadeia e a anterior para no
 * próximo pedaço. Falha da API (token, Correios fora) espera 10 minutos e
 * tenta de novo, até 6 vezes seguidas. O andamento fica em status() para a
 * tela do admin.
 *
 * Toda falha também abre (ou soma) o alerta do painel (AlertasAdmin, tipo
 * frete_tabela: sino e Sistema › Alertas), inclusive o job morto pelo tempo
 * do worker (failed()). O alerta fecha sozinho quando a tabela termina.
 */
class PreencherTabelaFreteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CEPS_POR_VEZ = 40;

    /**
     * Melhor Envio (2026-11-03): 4 chamadas por CEP e limite de 250 por
     * minuto na API, então 10 CEPs por vez com 12 s entre os pedaços
     * (~200 chamadas por minuto).
     */
    public const CEPS_POR_VEZ_MELHOR_ENVIO = 10;

    public static function cepsPorVez(?string $provedor = null): int
    {
        return ($provedor ?? FreteProvedor::ativo()) === FreteProvedor::MELHOR_ENVIO ? self::CEPS_POR_VEZ_MELHOR_ENVIO : self::CEPS_POR_VEZ;
    }

    private const CADEIA = 'frete-tabela:cadeia';

    private const STATUS = 'frete-tabela:status';

    private const MAX_FALHAS = 6;

    private const LINK = '/admin/logistica/fretes-por-estado';

    public int $tries = 1;

    public int $timeout = 100;

    /**
     * @param  list<string>  $ufs  UFs que ainda faltam importar (vazio = só cotar)
     * @param  string|null  $provedor  null = o conectado em Logística; com
     *                                 provedor, cadeia e andamento próprios (o Melhor Envio preenche
     *                                 com os Correios conectados sem parar a cadeia deles, 2026-11-03)
     */
    public function __construct(
        public readonly string $cadeia,
        public readonly array $ufs = [],
        public readonly int $falhas = 0,
        public readonly ?string $provedor = null,
    ) {}

    /**
     * Começa uma cadeia nova (a que estiver rodando para).
     *
     * @param  list<string>|null  $ufs  null = todas; [] = não importa localidades, só cota
     */
    public static function iniciar(?array $ufs = null, ?string $provedor = null): string
    {
        $cadeia = (string) Str::uuid();
        $ufs = $ufs === null ? TabelaFreteLocalidades::UFS : array_values(array_map('strtoupper', $ufs));

        Cache::forever(self::chave(self::CADEIA, $provedor), $cadeia);
        self::anotar(['fase' => $ufs ? 'localidades' : 'cotacao', 'iniciado_em' => now()->toIso8601String(), 'uf' => null, 'erro' => null, 'cotados' => 0, 'localidades' => 0], true, $provedor);
        self::dispatch($cadeia, $ufs, 0, $provedor);

        return $cadeia;
    }

    /** @return array{fase: string, iniciado_em: ?string, atualizado_em: ?string, uf: ?string, erro: ?string, cotados: int, localidades: int} */
    public static function status(?string $provedor = null): array
    {
        return array_merge(
            ['fase' => 'parado', 'iniciado_em' => null, 'atualizado_em' => null, 'uf' => null, 'erro' => null, 'cotados' => 0, 'localidades' => 0],
            (array) Cache::get(self::chave(self::STATUS, $provedor), []),
        );
    }

    public function handle(TabelaFreteLocalidades $tabela): void
    {
        if (Cache::get(self::chave(self::CADEIA, $this->provedor)) !== $this->cadeia) {
            return;
        }

        try {
            if ($this->ufs !== []) {
                $uf = $this->ufs[0];
                self::anotar(['fase' => 'localidades', 'uf' => $uf], false, $this->provedor);
                $n = $tabela->importarUf($uf);
                self::anotar(['localidades' => self::status($this->provedor)['localidades'] + $n, 'erro' => null], false, $this->provedor);
                self::dispatch($this->cadeia, array_slice($this->ufs, 1), 0, $this->provedor);

                return;
            }

            $ceps = $tabela->cepsPendentes(self::cepsPorVez($this->provedor), $this->provedor);
            if ($ceps === []) {
                self::anotar(['fase' => 'parado', 'uf' => null, 'erro' => null, 'concluido_em' => now()->toIso8601String()], false, $this->provedor);
                rescue(fn () => app(AlertasAdmin::class)->resolver(AlertasAdmin::FRETE_TABELA, $this->origemDoAlerta()), report: false);

                return;
            }

            self::anotar(['fase' => 'cotacao', 'uf' => null], false, $this->provedor);
            $gravados = $tabela->cotar($ceps, $this->provedor);
            self::anotar(['cotados' => self::status($this->provedor)['cotados'] + $gravados, 'erro' => null], false, $this->provedor);
            $melhorEnvio = ($this->provedor ?? FreteProvedor::ativo()) === FreteProvedor::MELHOR_ENVIO;
            self::dispatch($this->cadeia, [], 0, $this->provedor)->delay(now()->addSeconds($melhorEnvio ? 12 : 2));
        } catch (Throwable $e) {
            Log::channel('correios')->warning('frete_tabela.job_falhou', ['message' => $e->getMessage(), 'falhas' => $this->falhas + 1, 'provedor' => $this->provedor]);

            if ($this->falhas + 1 >= self::MAX_FALHAS) {
                $erro = 'Parou depois de '.self::MAX_FALHAS.' falhas seguidas: '.Str::limit($e->getMessage(), 150);
                self::anotar(['fase' => 'parado', 'erro' => $erro], false, $this->provedor);
                self::alertar($erro.'. Os CEPs que faltam continuam com a cotação anterior; rode de novo em Logística › Frete por localidade.', $this->provedor);

                return;
            }

            $erro = 'Tentando de novo em 10 minutos: '.Str::limit($e->getMessage(), 150);
            self::anotar(['erro' => $erro], false, $this->provedor);
            self::alertar($erro.' (tentativa '.($this->falhas + 1).' de '.self::MAX_FALHAS.').', $this->provedor);
            self::dispatch($this->cadeia, $this->ufs, $this->falhas + 1, $this->provedor)->delay(now()->addMinutes(10));
        }
    }

    /** Job morto fora do handle() (tempo do worker estourado, worker derrubado): a cadeia para. */
    public function failed(?Throwable $e): void
    {
        if (Cache::get(self::chave(self::CADEIA, $this->provedor)) !== $this->cadeia) {
            return;
        }

        $erro = 'Parou: o job não terminou ('.Str::limit($e?->getMessage() ?? 'sem mensagem', 150).')';
        self::anotar(['fase' => 'parado', 'erro' => $erro], false, $this->provedor);
        self::alertar($erro.'. Rode de novo em Logística › Frete por localidade.', $this->provedor);
    }

    /** Abre ou soma o alerta do painel; falha ao alertar não derruba a cadeia. */
    public static function alertar(string $mensagem, ?string $provedor = null): void
    {
        Log::channel('correios')->error('frete_tabela.alerta', ['message' => $mensagem, 'provedor' => $provedor]);

        $nome = FreteProvedor::NOMES[$provedor ?? FreteProvedor::ativo()] ?? 'Correios';
        rescue(fn () => app(AlertasAdmin::class)->disparar(
            AlertasAdmin::FRETE_TABELA,
            $provedor ?? 'correios',
            "Falha na atualização da tabela de frete ({$nome})",
            $mensagem,
            self::LINK,
        ), report: false);
    }

    private function origemDoAlerta(): string
    {
        return $this->provedor ?? 'correios';
    }

    /** Chave de cache da cadeia/andamento: a do conectado não muda; provedor explícito ganha a dele. */
    private static function chave(string $base, ?string $provedor): string
    {
        return $provedor === null ? $base : "{$base}:{$provedor}";
    }

    private static function anotar(array $dados, bool $zerar = false, ?string $provedor = null): void
    {
        $chave = self::chave(self::STATUS, $provedor);
        $atual = $zerar ? [] : (array) Cache::get($chave, []);
        Cache::forever($chave, array_merge($atual, $dados, ['atualizado_em' => now()->toIso8601String()]));
    }
}
