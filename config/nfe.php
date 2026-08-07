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
];
