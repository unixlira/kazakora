<?php

namespace App\Services\Kalodata;

use App\Models\KalodataChamada;
use App\Services\Kalodata\Exceptions\KalodataException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cliente da API aberta do Kalodata (dados do TikTok Shop).
 *
 * Autenticação: cabeçalho `secret-key` com a KALODATA_API_KEY. Toda resposta
 * vem no envelope {success, data, message, code}:
 *  - code 2000: timeout do lado deles, a única situação em que repetimos;
 *  - code 2016: limite do plano/quota ou parâmetro inválido;
 *  - code 501 ("The key is not allowed") ou HTTP 401/403: chave recusada.
 *
 * A conta é paga por crédito, então:
 *  - respostas de sucesso ficam no cache (padrão 24 h) com chave pelo
 *    endpoint + corpo, e a mesma consulta não gasta crédito de novo;
 *  - cada chamada REAL (sem cache) vira uma linha em `kalodata_chamadas`;
 *  - acima de KALODATA_TETO_DIARIO chamadas reais no dia, bloqueia e loga.
 *
 * Ver docs/kalodata-api.md.
 */
class KalodataService
{
    public const MODULOS = ['product', 'shop', 'creator', 'video', 'livestream', 'category'];

    public const REGIOES = ['US', 'GB', 'ID', 'TH', 'VN', 'PH', 'MY', 'SG', 'JP', 'MX', 'DE', 'IT', 'FR', 'ES', 'BR'];

    public const PERIODOS_FIXOS = ['lastDay', 'last7Day', 'last30Day', 'last90Day', 'last180Day', 'last365Day'];

    /** Campo do id no corpo do /detail de cada módulo. */
    private const CAMPO_ID = [
        'product' => 'product_id',
        'shop' => 'shop_id',
        'creator' => 'creator_id',
        'video' => 'video_id',
        'livestream' => 'livestream_id',
        'category' => 'category_id',
    ];

    /**
     * Saldo de créditos: lista de pacotes {type, remain, total, endTime(ms)}.
     * Nunca usa cache (é a conferência do saldo).
     *
     * @return array<int, array<string, mixed>>
     */
    public function creditos(): array
    {
        return self::lista($this->chamar('/credit/usage', [], 'GET', usarCache: false));
    }

    /**
     * Categorias do TikTok Shop pelo nome: [{id, name}].
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscarCategorias(string $palavra, ?string $regiao = null): array
    {
        return self::lista($this->chamar('/tiktok/category/searchByKeyword', [
            'region' => $this->regiao($regiao),
            'keyword' => trim($palavra),
        ]));
    }

    /**
     * Ranking de um módulo (product, shop, creator, video, livestream,
     * category). $filtros vai direto para o corpo com os nomes da API, por
     * exemplo category_ids, shop_id, keyword, unit_price_range, is_affiliate.
     *
     * @param  array<string, mixed>  $filtros
     * @param  array{field: string, type?: string}|null  $ordem
     * @return array<int, array<string, mixed>>
     */
    public function rank(
        string $modulo,
        array $filtros = [],
        string $periodo = 'last30Day',
        int $pagina = 1,
        int $porPagina = 10,
        ?array $ordem = null,
        ?string $regiao = null,
    ): array {
        $this->validarModulo($modulo);
        $this->validarPeriodo($periodo);

        if ($pagina < 1 || $pagina > 5) {
            throw new KalodataException('Página tem que ser de 1 a 5.', 'parametro');
        }
        if ($porPagina < 5 || $porPagina > 100) {
            throw new KalodataException('Itens por página tem que ser de 5 a 100.', 'parametro');
        }

        $corpo = array_merge($filtros, [
            'region' => $this->regiao($regiao),
            'date_range' => $periodo,
            'page_number' => $pagina,
            'page_size' => $porPagina,
        ]);

        if ($ordem !== null) {
            $corpo['sort_field'] = [
                'field' => $ordem['field'],
                'type' => strtoupper($ordem['type'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC',
            ];
        }

        return self::lista($this->chamar("/tiktok/{$modulo}/rank", $corpo));
    }

    /**
     * Detalhe de um item do módulo (com tendência, need_extra=true).
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    public function detalhe(
        string $modulo,
        string|int $id,
        array $filtros = [],
        string $periodo = 'last30Day',
        ?string $regiao = null,
    ): array {
        $this->validarModulo($modulo);
        $this->validarPeriodo($periodo);

        $corpo = array_merge($filtros, [
            'region' => $this->regiao($regiao),
            'date_range' => $periodo,
            self::CAMPO_ID[$modulo] => (string) $id,
            'need_extra' => true,
        ]);

        $dados = $this->chamar("/tiktok/{$modulo}/detail", $corpo);

        return is_array($dados) ? $dados : [];
    }

    /**
     * Detalhe de criador pelo @ (sem precisar do creator_id).
     *
     * @return array<string, mixed>
     */
    public function criadorPorHandle(string $handle, string $periodo = 'last30Day', ?string $regiao = null): array
    {
        $this->validarPeriodo($periodo);

        $dados = $this->chamar('/tiktok/creator/detailByHandle', [
            'region' => $this->regiao($regiao),
            'date_range' => $periodo,
            'creator_handle' => ltrim(trim($handle), '@'),
            'need_extra' => true,
        ]);

        return is_array($dados) ? $dados : [];
    }

    /**
     * Fotos de perfil de vários criadores.
     *
     * @param  array<int, string|int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function imagensCriadores(array $ids, ?string $regiao = null): array
    {
        return self::lista($this->chamar('/tiktok/creator/images', [
            'region' => $this->regiao($regiao),
            'creator_ids' => array_values(array_map('strval', $ids)),
            'need_image' => 1,
        ]));
    }

    /**
     * Chamada genérica. Devolve o `data` do envelope. Usa o cache quando
     * $usarCache e grava cada chamada real em kalodata_chamadas.
     *
     * @param  array<string, mixed>  $corpo
     */
    public function chamar(string $endpoint, array $corpo = [], string $metodo = 'POST', bool $usarCache = true): mixed
    {
        $endpoint = '/'.ltrim($endpoint, '/');
        $metodo = strtoupper($metodo);
        $horas = (int) config('services.kalodata.cache_horas', 24);
        $usarCache = $usarCache && $horas > 0;
        $chave = self::chaveCache($endpoint, $corpo, $metodo);

        if ($usarCache) {
            $guardado = Cache::get($chave);
            if (is_array($guardado) && array_key_exists('data', $guardado)) {
                return $guardado['data'];
            }
        }

        $dados = $this->chamarDeVerdade($endpoint, $corpo, $metodo);

        if ($usarCache) {
            Cache::put($chave, ['data' => $dados], now()->addHours($horas));
        }

        return $dados;
    }

    public function chamadasHoje(): int
    {
        return KalodataChamada::deHoje();
    }

    public function tetoDiario(): int
    {
        return (int) config('services.kalodata.teto_diario', 20);
    }

    public function configurado(): bool
    {
        return filled(config('services.kalodata.api_key'));
    }

    /**
     * Respostas de ranking às vezes vêm como lista, às vezes como {data: [...]}.
     *
     * @return array<int, mixed>
     */
    public static function lista(mixed $dados): array
    {
        if (is_array($dados) && array_is_list($dados)) {
            return $dados;
        }
        if (is_array($dados) && isset($dados['data']) && is_array($dados['data'])) {
            return array_values($dados['data']);
        }

        return is_array($dados) && $dados !== [] ? [$dados] : [];
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    public static function chaveCache(string $endpoint, array $corpo, string $metodo = 'POST'): string
    {
        return 'kalodata:'.sha1(strtoupper($metodo).' '.'/'.ltrim($endpoint, '/').' '.json_encode(self::ordenar($corpo)));
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function chamarDeVerdade(string $endpoint, array $corpo, string $metodo): mixed
    {
        $chaveApi = config('services.kalodata.api_key');
        if (blank($chaveApi)) {
            throw new KalodataException('KALODATA_API_KEY não está no .env.', 'config');
        }

        $tentativas = max(1, (int) config('services.kalodata.tentativas', 3));
        $ultimaMensagem = 'sem resposta';

        for ($tentativa = 1; $tentativa <= $tentativas; $tentativa++) {
            $this->conferirTeto($endpoint);

            $inicio = microtime(true);
            $registro = [
                'endpoint' => Str::limit($endpoint, 120, ''),
                'corpo' => Str::limit((string) json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 500),
            ];

            try {
                $resposta = $this->requisicao($chaveApi, $endpoint, $corpo, $metodo);
            } catch (ConnectionException $e) {
                $this->registrar($registro, $inicio, false, null, null, 'sem conexão');
                throw new KalodataException('Kalodata não respondeu: '.Str::limit($e->getMessage(), 150), 'rede');
            }

            $envelope = $resposta->json();
            $codigo = is_array($envelope) && isset($envelope['code']) ? (string) $envelope['code'] : null;
            $mensagem = is_array($envelope) ? (string) ($envelope['message'] ?? '') : '';
            $sucesso = is_array($envelope) && ($envelope['success'] ?? false) === true;

            $this->registrar($registro, $inicio, $sucesso, $resposta->status(), $codigo, $mensagem);

            if ($sucesso) {
                return $envelope['data'] ?? null;
            }

            if ($codigo === '2000' || str_contains(strtolower($mensagem), 'acquire connection timeout')) {
                $ultimaMensagem = $mensagem ?: 'code 2000';
                if ($tentativa < $tentativas) {
                    $this->esperar($tentativa);
                }

                continue;
            }

            $this->falhar($resposta, $envelope, $codigo, $mensagem, $endpoint);
        }

        throw new KalodataException("Kalodata ocupado (code 2000) depois de {$tentativas} tentativa(s): {$ultimaMensagem}", 'rede', '2000');
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function requisicao(string $chaveApi, string $endpoint, array $corpo, string $metodo): Response
    {
        $http = Http::baseUrl(rtrim((string) config('services.kalodata.base_url'), '/'))
            ->withHeaders([
                'secret-key' => $chaveApi,
                'Content-Type' => 'application/json;charset=UTF-8',
                'Accept' => 'application/json',
            ])
            ->timeout(max(5, (int) config('services.kalodata.timeout', 30)));

        $caminho = ltrim($endpoint, '/');

        return $metodo === 'GET'
            ? $http->get($caminho, $corpo)
            : $http->withBody((string) json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json;charset=UTF-8')->post($caminho);
    }

    private function falhar(Response $resposta, mixed $envelope, ?string $codigo, string $mensagem, string $endpoint): never
    {
        $status = $resposta->status();
        $contexto = ['endpoint' => $endpoint, 'status' => $status, 'body' => is_array($envelope) ? $envelope : Str::limit($resposta->body(), 300)];

        if (in_array($status, [401, 403], true) || $codigo === '501' || str_contains(strtolower($mensagem), 'not allowed')) {
            throw new KalodataException('Chave do Kalodata recusada'.($mensagem ? ": {$mensagem}" : '.'), 'auth', $codigo, $status, $contexto);
        }

        if ($codigo === '2016') {
            $baixo = strtolower($mensagem);
            foreach (['must be', 'invalid', 'required', 'for input string', 'parameter'] as $pista) {
                if (str_contains($baixo, $pista)) {
                    throw new KalodataException("Parâmetro recusado pelo Kalodata: {$mensagem}", 'parametro', $codigo, $status, $contexto);
                }
            }

            throw new KalodataException("Limite do plano ou de créditos do Kalodata: {$mensagem}", 'limite', $codigo, $status, $contexto);
        }

        if (! is_array($envelope)) {
            throw new KalodataException("Resposta inesperada do Kalodata (HTTP {$status}).", 'api', $codigo, $status, $contexto);
        }

        throw new KalodataException('Erro do Kalodata: '.($mensagem ?: "code {$codigo}"), 'api', $codigo, $status, $contexto);
    }

    private function conferirTeto(string $endpoint): void
    {
        $teto = $this->tetoDiario();
        $hoje = KalodataChamada::deHoje();

        if ($hoje >= $teto) {
            Log::warning('Kalodata: teto diário de chamadas atingido, chamada bloqueada.', [
                'endpoint' => $endpoint,
                'chamadas_hoje' => $hoje,
                'teto' => $teto,
            ]);

            throw new KalodataException("Teto diário do Kalodata atingido ({$hoje}/{$teto} chamadas reais hoje). Aumente KALODATA_TETO_DIARIO ou espere amanhã.", 'teto');
        }
    }

    /**
     * @param  array{endpoint: string, corpo: string}  $registro
     */
    private function registrar(array $registro, float $inicio, bool $sucesso, ?int $status, ?string $codigo, ?string $mensagem): void
    {
        KalodataChamada::create($registro + [
            'sucesso' => $sucesso,
            'http_status' => $status,
            'codigo' => $codigo !== null ? Str::limit($codigo, 20, '') : null,
            'mensagem' => $mensagem ? Str::limit($mensagem, 250) : null,
            'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
        ]);
    }

    private function esperar(int $tentativa): void
    {
        $ms = (int) config('services.kalodata.espera_ms', 500) * $tentativa;
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    private function regiao(?string $regiao): string
    {
        $regiao = strtoupper(trim($regiao ?: (string) config('services.kalodata.regiao', 'BR')));

        if (! in_array($regiao, self::REGIOES, true)) {
            throw new KalodataException("Região '{$regiao}' não existe no Kalodata. Use: ".implode(', ', self::REGIOES).'.', 'parametro');
        }

        return $regiao;
    }

    private function validarModulo(string $modulo): void
    {
        if (! in_array($modulo, self::MODULOS, true)) {
            throw new KalodataException("Módulo '{$modulo}' não existe. Use: ".implode(', ', self::MODULOS).'.', 'parametro');
        }
    }

    private function validarPeriodo(string $periodo): void
    {
        if (in_array($periodo, self::PERIODOS_FIXOS, true)
            || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)
            || preg_match('/^\d{4}-\d{2}-\d{2}~\d{4}-\d{2}-\d{2}$/', $periodo)) {
            return;
        }

        throw new KalodataException("Período '{$periodo}' inválido. Use last7Day, last30Day, AAAA-MM ou AAAA-MM-DD~AAAA-MM-DD.", 'parametro');
    }

    private static function ordenar(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }
        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return array_map(fn ($v) => self::ordenar($v), $valor);
    }
}
