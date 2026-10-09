<?php

return [
    // 1 = produção, 2 = homologação — sempre homologação até validar tudo.
    'ambiente' => env('NFE_AMBIENTE', 'homologacao'),

    // Certificado digital A1 (.pfx) é enviado pelo admin em /admin/empresa e
    // guardado no disco "local" (storage/app/private) via Company::certificate_path
    // — ver App\Services\NFe\NFeCertificateService. Não fica mais em .env.

    'serie' => (int) env('NFE_SERIE', 1),

    // Piso do próximo número de NF-e — InvoiceService::createPendingInvoice()
    // usa max(local, este valor) + 1. Achado real 2026-08-06: mesmo numa
    // série nova (2), números baixos (1, 2) já existiam na SEFAZ de
    // produção com data de julho/2026 — bem provável de testes reais
    // deste próprio projeto em sessões anteriores que nunca deixaram
    // registro local (Invoice nunca chegou a ser criada, ou foi apagada).
    // Sem nenhum jeito confiável de descobrir o número exato sem consultar
    // a distribuição DFe da SEFAZ (não implementado), pulamos pra uma
    // faixa bem mais alta em vez de continuar testando 1 a 1 contra
    // produção. Ajustar aqui de novo se colidir outra vez.
    'numero_inicial' => (int) env('NFE_NUMERO_INICIAL', 0),

    // Código Numérico da UF do emitente (SP = 35), usado na chave de acesso.
    'cuf' => (int) env('NFE_CUF', 35),

    // Cancelamento em SP (orientação do contador, 2026-10-08): até 24h da
    // autorização é normal; depois disso a SEFAZ-SP ainda aceita até 480h,
    // desde que a mercadoria não tenha circulado, mas cabe multa (RICMS-SP
    // art. 527, IV, z1): 1% do valor da nota, mínimo de 6 UFESPs. Passou de
    // 480h, só com nota de devolução.
    'cancelamento_horas' => (int) env('NFE_CANCELAMENTO_HORAS', 24),
    'cancelamento_extemporaneo_horas' => (int) env('NFE_CANCELAMENTO_EXTEMPORANEO_HORAS', 480),
    'ufesp' => (float) env('NFE_UFESP', 37.02),
];
