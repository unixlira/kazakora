<?php

namespace App\Services\NFe;

use RuntimeException;

class NFeCertificateNotConfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Certificado digital A1 não configurado. É preciso comprar um certificado A1 '.
            'numa Autoridade Certificadora credenciada ICP-Brasil, vinculado ao CNPJ da empresa, '.
            'e configurar NFE_CERTIFICADO_PATH/NFE_CERTIFICADO_SENHA no .env.'
        );
    }
}
