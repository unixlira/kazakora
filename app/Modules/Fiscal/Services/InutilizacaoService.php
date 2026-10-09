<?php

namespace App\Modules\Fiscal\Services;

use App\Models\User;
use App\Modules\Fiscal\Models\NumeracaoOcorrencia;
use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeWebserviceService;
use Illuminate\Support\Facades\Storage;
use NFePHP\NFe\Complements;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Inutiliza na SEFAZ uma faixa de números da série do Kazakora que nunca
 * vai virar nota. NÃO TEM VOLTA: só roda pelo botão do Fechamento fiscal,
 * uma faixa por vez, com confirmação do admin, e só pra faixa que o
 * relatório apontou como buraco.
 */
class InutilizacaoService
{
    public function __construct(
        private readonly FechamentoFiscalService $fechamento,
        private readonly NFeCertificateService $certificado,
        private readonly NFeWebserviceService $webservice,
    ) {}

    public function inutilizar(int $inicio, int $fim, string $justificativa, ?User $usuario): NumeracaoOcorrencia
    {
        $serie = (int) config('nfe.serie');
        $ambiente = (string) config('nfe.ambiente');
        $justificativa = trim($justificativa);

        if (mb_strlen($justificativa) < 15) {
            throw new RuntimeException('A justificativa precisa ter pelo menos 15 caracteres (exigência da SEFAZ).');
        }

        if ($fim < $inicio) {
            throw new RuntimeException('Faixa inválida.');
        }

        // Só aceita faixa que está inteira dentro de um buraco apontado agora.
        $dentro = collect($this->fechamento->buracos($serie, $ambiente))
            ->contains(fn ($b) => $inicio >= $b['inicio'] && $fim <= $b['fim']);

        if (! $dentro) {
            throw new RuntimeException("Os números {$inicio} a {$fim} da série {$serie} não estão todos livres. Atualize a tela do fechamento.");
        }

        ['request' => $pedido, 'response' => $resposta] = $this->webservice->inutilizar($serie, $inicio, $fim, $justificativa, $this->certificado->load());

        $xml = new SimpleXMLElement($resposta);
        $xml->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $inf = ($xml->xpath('//n:infInut') ?: $xml->xpath('//infInut'))[0] ?? null;
        $cStat = $inf ? (string) $inf->cStat : '';

        // 102 = inutilização homologada.
        if ($cStat !== '102') {
            throw new RuntimeException('A SEFAZ não inutilizou: '.($inf ? "{$cStat} - {$inf->xMotivo}" : 'resposta inesperada'));
        }

        try {
            $proc = Complements::toAuthorize($pedido, $resposta);
        } catch (Throwable) {
            $proc = $resposta;
        }

        $caminho = sprintf('inutilizacoes/inutilizacao-serie%03d-%09d-%09d.xml', $serie, $inicio, $fim);
        Storage::disk('local')->put($caminho, $proc);

        return NumeracaoOcorrencia::query()->create([
            'tipo' => NumeracaoOcorrencia::TIPO_INUTILIZACAO,
            'ambiente' => $ambiente,
            'serie' => $serie,
            'numero_inicial' => $inicio,
            'numero_final' => $fim,
            'motivo' => $justificativa,
            'user_id' => $usuario?->id,
            'protocolo' => (string) $inf->nProt,
            'xml_path' => $caminho,
            'resolvido_em' => now(),
        ]);
    }
}
