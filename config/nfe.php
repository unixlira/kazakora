<?php

return [
    // 1 = produção, 2 = homologação — sempre homologação até validar tudo.
    'ambiente' => env('NFE_AMBIENTE', 'homologacao'),

    // Certificado digital A1 (.pfx) é enviado pelo admin em /admin/empresa e
    // guardado no disco "local" (storage/app/private) via Company::certificate_path
    // — ver App\Services\NFe\NFeCertificateService. Não fica mais em .env.

    'serie' => (int) env('NFE_SERIE', 1),

    // Código Numérico da UF do emitente (SP = 35), usado na chave de acesso.
    'cuf' => (int) env('NFE_CUF', 35),
];
