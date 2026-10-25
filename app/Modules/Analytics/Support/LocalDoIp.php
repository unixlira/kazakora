<?php

namespace App\Modules\Analytics\Support;

use App\Models\IbgeMunicipio;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader;
use Throwable;

/**
 * Estado e cidade aproximados de um IP (pedido 2026-10-24, tela Visitas ao
 * site: o olho de cada visita abre as cidades do estado daquele IP).
 *
 * Lê a base gratuita DB-IP City Lite (CC BY 4.0, atribuição na tela), que
 * o geoip:atualizar baixa todo mês para CAMINHO. Nada sai do servidor: o
 * IP do visitante não vai para API de terceiro. Sem a base, ou IP de fora
 * do Brasil, devolve o que souber (uf null).
 *
 * É estimativa: IP de celular costuma cair na cidade da operadora.
 */
class LocalDoIp
{
    public const CAMINHO = 'app/geoip/dbip-city-lite.mmdb';

    /** Nome do estado na base (inglês ou português, sem acento) → UF. */
    private const UFS = [
        'acre' => 'AC', 'alagoas' => 'AL', 'amapa' => 'AP', 'amazonas' => 'AM',
        'bahia' => 'BA', 'ceara' => 'CE', 'distrito federal' => 'DF', 'federal district' => 'DF',
        'espirito santo' => 'ES', 'goias' => 'GO', 'maranhao' => 'MA', 'mato grosso' => 'MT',
        'mato grosso do sul' => 'MS', 'minas gerais' => 'MG', 'para' => 'PA', 'paraiba' => 'PB',
        'parana' => 'PR', 'pernambuco' => 'PE', 'piaui' => 'PI', 'rio de janeiro' => 'RJ',
        'rio grande do norte' => 'RN', 'rio grande do sul' => 'RS', 'rondonia' => 'RO', 'roraima' => 'RR',
        'santa catarina' => 'SC', 'sao paulo' => 'SP', 'sergipe' => 'SE', 'tocantins' => 'TO',
    ];

    private ?Reader $leitor = null;

    public function __construct(private readonly ?string $arquivo = null) {}

    public static function caminho(): string
    {
        return storage_path(self::CAMINHO);
    }

    public function disponivel(): bool
    {
        return is_file($this->arquivo ?? self::caminho());
    }

    /**
     * Null = sem a base ou IP sem registro.
     *
     * @return array{pais: ?string, uf: ?string, estado: ?string, cidade: ?string, latitude: ?float, longitude: ?float}|null
     */
    public function buscar(string $ip): ?array
    {
        if (! $this->disponivel() || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        try {
            $this->leitor ??= new Reader($this->arquivo ?? self::caminho());
            $registro = $this->leitor->get($ip);
        } catch (Throwable $e) {
            Log::warning('geoip.leitura_falhou', ['error' => $e->getMessage()]);

            return null;
        }

        if (! is_array($registro)) {
            return null;
        }

        $pais = $registro['country']['iso_code'] ?? null;
        $estado = $registro['subdivisions'][0]['names']['en'] ?? null;
        // "São Paulo (Vila Albertina)": o bairro entre parênteses sai.
        $cidade = isset($registro['city']['names']['en'])
            ? trim((string) preg_replace('/\s*\(.*\)\s*$/', '', $registro['city']['names']['en']))
            : null;

        return [
            'pais' => $pais,
            'uf' => $pais === 'BR' && $estado !== null ? (self::UFS[IbgeMunicipio::normalizar($estado)] ?? null) : null,
            'estado' => $estado,
            'cidade' => $cidade !== '' ? $cidade : null,
            // Centro da cidade na base (card de localização do olho, 2026-10-24).
            'latitude' => isset($registro['location']['latitude']) ? (float) $registro['location']['latitude'] : null,
            'longitude' => isset($registro['location']['longitude']) ? (float) $registro['location']['longitude'] : null,
        ];
    }
}
