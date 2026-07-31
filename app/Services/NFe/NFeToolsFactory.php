<?php

namespace App\Services\NFe;

use App\Modules\Fiscal\Models\Company;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Tools;

class NFeToolsFactory
{
    public function make(Certificate $certificate): Tools
    {
        $company = Company::query()->firstOrFail();

        $config = json_encode([
            'atualizacao' => now()->toDateTimeString(),
            'tpAmb' => config('nfe.ambiente') === 'producao' ? 1 : 2,
            'razaosocial' => $company->razao_social,
            'cnpj' => preg_replace('/\D/', '', $company->cnpj),
            'siglaUF' => $company->state,
            'schemes' => 'PL_009_V4',
            'versao' => '4.00',
        ]);

        $tools = new Tools($config, $certificate);
        $tools->model(55);

        return $tools;
    }
}
