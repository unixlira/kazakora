<?php

return [
    // 1 = produção, 2 = homologação — sempre homologação até validar tudo.
    'ambiente' => env('NFE_AMBIENTE', 'homologacao'),

    // Certificado digital A1 (.pfx) — NÃO existe ainda neste projeto. Precisa
    // ser comprado numa Autoridade Certificadora credenciada ICP-Brasil,
    // vinculado ao CNPJ da empresa. Guardar fora do controle de versão
    // (storage/app/nfe/certificado.pfx, nunca em resources/ ou public/).
    'certificado_path' => env('NFE_CERTIFICADO_PATH', storage_path('app/nfe/certificado.pfx')),
    'certificado_senha' => env('NFE_CERTIFICADO_SENHA'),

    'serie' => (int) env('NFE_SERIE', 1),

    // Código Numérico da UF do emitente (SP = 35), usado na chave de acesso.
    'cuf' => (int) env('NFE_CUF', 35),
];
