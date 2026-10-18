<?php

namespace Tests\Unit\Support;

use App\Models\User;
use App\Modules\BeautyKora\Models\BeautyKoraCliente;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use Tests\TestCase;

class NomeCapitalizadoTest extends TestCase
{
    public function test_capitaliza_cada_palavra_e_baixa_o_resto(): void
    {
        $this->assertSame('José Roberto Lira', User::capitalizarNome('JOSÉ ROBERTO LIRA'));
        $this->assertSame('José Roberto Lira', User::capitalizarNome('josé roberto lira'));
        $this->assertSame('José Roberto Lira', User::capitalizarNome('  jOSÉ   rOBERTO  lIRA '));
        $this->assertSame('Maria Da Silva', User::capitalizarNome('MARIA DA SILVA'));
        $this->assertSame('Ana-Maria Souza', User::capitalizarNome('ana-maria souza'));
        $this->assertNull(User::capitalizarNome(null));
    }

    public function test_models_de_cliente_salvam_nome_padronizado(): void
    {
        $this->assertSame('João Silva', (new User(['name' => 'JOÃO SILVA']))->name);
        $this->assertSame('João Silva', (new FerraKoraCliente(['name' => 'joão silva']))->name);
        $this->assertSame('João Silva', (new BeautyKoraCliente(['name' => 'jOãO sILVA']))->name);
    }
}
