<?php

namespace App\Services\NFe;

use NFePHP\Common\Certificate;
use NFePHP\NFe\Tools;

/**
 * Etapas 2 e 3 do plano: assinatura + comunicação com o webservice da SEFAZ.
 * Toda a mecânica de assinatura (C14N + SHA-1/SHA-256 conforme NT vigente) e
 * SOAP fica dentro do NFePHP\NFe\Tools — não reimplementamos nada disso na
 * mão, só orquestramos. IMPORTANTE: nada aqui foi testado de verdade contra
 * a SEFAZ, porque não existe certificado A1 configurado neste projeto — ver
 * NFeCertificateService::isConfigured(). O código está pronto pra funcionar
 * assim que o certificado existir.
 */
class NFeWebserviceService
{
    public function __construct(private readonly NFeToolsFactory $toolsFactory)
    {
    }

    public function sign(string $xml, Certificate $certificate): string
    {
        return $this->toolsFactory->make($certificate)->signNFe($xml);
    }

    /**
     * Envia o lote (1 nota por lote, síncrono) e já retorna o protocolo de
     * autorização/rejeição — sefazEnviaLote com $indSinc=1 espera a resposta
     * na mesma chamada em vez de precisar consultar o recibo depois.
     */
    public function enviarESincronizar(string $xmlAssinado, int $idLote, Certificate $certificate): string
    {
        $tools = $this->toolsFactory->make($certificate);

        return $tools->sefazEnviaLote([$xmlAssinado], (string) $idLote, 1);
    }

    public function consultarRecibo(string $recibo, Certificate $certificate): string
    {
        return $this->toolsFactory->make($certificate)->sefazConsultaRecibo($recibo);
    }

    public function consultarStatusServico(Certificate $certificate): string
    {
        return $this->toolsFactory->make($certificate)->sefazStatus();
    }

    /**
     * Consulta direta por chave — usado pra reconstruir o nfeProc (NFe +
     * protocolo) de uma nota já autorizada quando não guardamos a resposta
     * original da SEFAZ (achado real 2026-08-07, corrigindo pra sempre
     * guardar o nfeProc daqui pra frente em signAndSend()).
     */
    public function consultarChave(string $chave, Certificate $certificate): string
    {
        return $this->toolsFactory->make($certificate)->sefazConsultaChave($chave);
    }

    /**
     * Etapa 5: evento de cancelamento (RecepcaoEvento4). sped-nfe expõe um
     * wrapper dedicado (sefazCancela) em cima do sefazEvento genérico.
     */
    public function cancelar(string $chave, string $justificativa, string $protocoloAutorizacao, Certificate $certificate): string
    {
        return $this->toolsFactory->make($certificate)->sefazCancela($chave, $justificativa, $protocoloAutorizacao);
    }
}
