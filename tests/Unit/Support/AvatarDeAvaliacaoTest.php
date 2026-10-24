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

    public function test_avatar_fixo_por_avaliacao_e_sem_repetir_no_carrossel(): void
    {
        $this->assertSame(AvaliacoesDoCheckout::avatar(42, 'Ana'), AvaliacoesDoCheckout::avatar(42, 'Ana'));
        $this->assertStringStartsWith('m', AvaliacoesDoCheckout::avatar(42, 'Carlos'));

        $usados = [];
        $arquivos = [];
        foreach (range(1, AvaliacoesDoCheckout::AVATARES_FEMININOS) as $id) {
            $arquivos[] = AvaliacoesDoCheckout::avatar($id, 'Maria', $usados);
        }
        $this->assertCount(AvaliacoesDoCheckout::AVATARES_FEMININOS, array_unique($arquivos));
        foreach ($arquivos as $arquivo) {
            $this->assertFileExists(__DIR__.'/../../../public/images/avaliacoes/avatares/'.$arquivo);
        }
    }

    public function test_com_mais_homens_que_rostos_os_rostos_revezam(): void
    {
        $usados = [];
        $arquivos = [];
        foreach (range(1, 6) as $id) {
            $arquivos[] = AvaliacoesDoCheckout::avatar($id, 'Carlos', $usados);
        }

        $this->assertSame([2, 2, 2], array_values(array_count_values($arquivos)));
    }
}
