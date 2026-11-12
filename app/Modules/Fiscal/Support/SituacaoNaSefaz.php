<?php

namespace App\Modules\Fiscal\Support;

use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeWebserviceService;
use Illuminate\Support\Carbon;
use SimpleXMLElement;
use Throwable;

/**
 * O que a SEFAZ diz de uma chave (consulta de situação, só leitura).
 * Pedido do Lira em 12/11/2026 ("se foi cancelado ou devolvido o sistema
 * tem que fazer automático, com alerta"): a conciliação fiscal precisa
 * saber, antes de agir, se a nota existe lá, se está autorizada e se já
 * tem evento de cancelamento.
 *
 * `existe` é null quando a resposta não permite concluir (erro, timeout,
 * cStat desconhecido): quem chama NUNCA age nesse caso, só alerta.
 */
class SituacaoNaSefaz
{
    /** cStat da consulta que provam que a nota existe na base da SEFAZ. */
    private const EXISTE = ['100', '101', '110', '150', '151', '155', '301', '302'];

    /** 217 = "NF-e não consta na base de dados da SEFAZ". */
    public const NAO_CONSTA = '217';

    public function __construct(
        private readonly NFeCertificateService $certificado,
        private readonly NFeWebserviceService $webservice,
    ) {}

    /**
     * @return array{cStat: string, xMotivo: string, existe: ?bool, autorizada: bool, cancelada: bool, protocolo: ?string, autorizada_em: ?Carbon, protocolo_cancelamento: ?string, cancelada_em: ?Carbon}
     */
    public function consultar(string $chave): array
    {
        return self::interpretar($this->webservice->consultarChave($chave, $this->certificado->load()));
    }

    /** @return array{cStat: string, xMotivo: string, existe: ?bool, autorizada: bool, cancelada: bool, protocolo: ?string, autorizada_em: ?Carbon, protocolo_cancelamento: ?string, cancelada_em: ?Carbon} */
    public static function interpretar(string $resposta): array
    {
        $xml = new SimpleXMLElement($resposta);
        $xml->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $primeiro = fn (string $comNs, string $semNs) => ($xml->xpath($comNs) ?: $xml->xpath($semNs) ?: [null])[0];

        $cStat = (string) ($primeiro('//n:retConsSitNFe/n:cStat', '//retConsSitNFe/cStat') ?? '');
        $xMotivo = (string) ($primeiro('//n:retConsSitNFe/n:xMotivo', '//retConsSitNFe/xMotivo') ?? '');
        $infProt = $primeiro('//n:protNFe/n:infProt', '//protNFe/infProt');

        $protocolo = null;
        $autorizadaEm = null;
        if ($infProt !== null && (string) $infProt->cStat === '100') {
            $protocolo = (string) $infProt->nProt ?: null;
            $autorizadaEm = self::data((string) $infProt->dhRecbto);
        }

        // Evento de cancelamento (110111) registrado: 135 no prazo, 155 fora.
        $protocoloCancelamento = null;
        $canceladaEm = null;
        foreach ($xml->xpath('//n:retEvento/n:infEvento') ?: $xml->xpath('//retEvento/infEvento') ?: [] as $evento) {
            if ((string) $evento->tpEvento === '110111' && in_array((string) $evento->cStat, ['135', '155'], true)) {
                $protocoloCancelamento = (string) $evento->nProt ?: null;
                $canceladaEm = self::data((string) $evento->dhRegEvento);
            }
        }

        $cancelada = $cStat === '101' || $cStat === '151' || $cStat === '155' || $protocoloCancelamento !== null;

        return [
            'cStat' => $cStat,
            'xMotivo' => $xMotivo,
            'existe' => in_array($cStat, self::EXISTE, true) || $infProt !== null ? true : ($cStat === self::NAO_CONSTA ? false : null),
            'autorizada' => $cStat === '100' && ! $cancelada,
            'cancelada' => $cancelada,
            'protocolo' => $protocolo,
            'autorizada_em' => $autorizadaEm,
            'protocolo_cancelamento' => $protocoloCancelamento,
            'cancelada_em' => $canceladaEm,
        ];
    }

    /** Protocolo de autorização gravado no XML da nota (nfeProc/protNFe), se houver. */
    public static function protocoloDoXml(string $xml): ?string
    {
        try {
            $doc = new SimpleXMLElement($xml);
        } catch (Throwable) {
            return null;
        }

        $doc->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $infProt = ($doc->xpath('//n:protNFe/n:infProt') ?: $doc->xpath('//protNFe/infProt') ?: [null])[0];

        if ($infProt === null || (string) $infProt->cStat !== '100') {
            return null;
        }

        return preg_match('/^\d{15}$/', (string) $infProt->nProt) ? (string) $infProt->nProt : null;
    }

    private static function data(string $valor): ?Carbon
    {
        return $valor !== '' ? rescue(fn () => Carbon::parse($valor)->setTimezone(config('app.timezone')), null, false) : null;
    }
}
