<?php

namespace Tests\Unit\Support;

use App\Modules\Checkout\Support\AvaliacoesDoCheckout;
use App\Support\NomeBrasileiro;
use PHPUnit\Framework\TestCase;

class AvatarDeAvaliacaoTest extends TestCase
{
    public function test_genero_pelo_primeiro_nome(): void
    {
        foreach (['Carla Souza', 'Patrícia Lima', 'Raquel', 'beatriz costa', 'Aline', 'Ísis Andrade'] as $nome) {
            $this->assertTrue(NomeBrasileiro::feminino($nome), $nome);
        }
        foreach (['José Silva', 'João', 'André Rocha', 'Cliente KazaKora', 'Igor'] as $nome) {
            $this->assertFalse(NomeBrasileiro::feminino($nome), $nome);
        }
    }

    public function test_todo_nome_feminino_do_sorteio_e_reconhecido(): void
    {
        foreach (array_slice(NomeBrasileiro::NOMES, 0, 40) as $nome) {
            $this->assertTrue(NomeBrasileiro::feminino($nome), $nome);
        }
        foreach (array_slice(NomeBrasileiro::NOMES, 40) as $nome) {
            $this->assertFalse(NomeBrasileiro::feminino($nome), $nome);
        }
    }

    public function test_iniciais_da_primeira_e_da_ultima_palavra(): void
    {
        $this->assertSame('CS', AvaliacoesDoCheckout::iniciais('Carla Souza'));
        $this->assertSame('CS', AvaliacoesDoCheckout::iniciais('Carla S.'));
        $this->assertSame('ÍA', AvaliacoesDoCheckout::iniciais('ísis de andrade'));
        $this->assertSame('A', AvaliacoesDoCheckout::iniciais('Ana'));
        $this->assertSame('?', AvaliacoesDoCheckout::iniciais(' '));
    }

    public function test_cor_fixa_por_nome_e_circulo_sem_rosto(): void
    {
        $this->assertSame(AvaliacoesDoCheckout::cor('Carla Souza'), AvaliacoesDoCheckout::cor('Carla Souza'));
        $this->assertContains(AvaliacoesDoCheckout::cor('Bruno Dias'), AvaliacoesDoCheckout::CORES);

        $avatar = AvaliacoesDoCheckout::avatarDeIniciais('CS', AvaliacoesDoCheckout::cor('Carla Souza'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $avatar);
        $svg = base64_decode(substr($avatar, strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('>CS</text>', $svg);
        $this->assertStringNotContainsString('avatares', $svg);
    }
}
