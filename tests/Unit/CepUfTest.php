<?php

namespace Tests\Unit;

use App\Modules\Checkout\Support\CepUf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CepUfTest extends TestCase
{
    public static function ceps(): array
    {
        return [
            ['01310-100', 'SP'], ['20040020', 'RJ'], ['60060-000', 'CE'], ['70040-010', 'DF'],
            ['73700000', 'GO'], ['69900-000', 'AC'], ['123', null],
        ];
    }

    #[DataProvider('ceps')]
    public function test_descobre_o_estado_pelo_cep(string $cep, ?string $uf): void
    {
        $this->assertSame($uf, CepUf::porCep($cep));
    }
}
