<?php

namespace Tests\Unit\Catalog;

use App\Modules\Catalog\Models\Review;
use Tests\TestCase;

class NomeOutraLojaTest extends TestCase
{
    private function review(array $attrs): Review
    {
        $r = new Review;
        $r->forceFill($attrs);

        return $r;
    }

    public function test_generico_de_outra_loja_vira_nome_fixo(): void
    {
        $a = $this->review(['id' => 7, 'outra_loja' => 1, 'reviewer_name' => 'Comprador de outra loja']);
        $b = $this->review(['id' => 7, 'outra_loja' => 1, 'reviewer_name' => null]);

        $this->assertNotSame('Comprador de outra loja', $a->reviewer_display_name);
        $this->assertSame($a->reviewer_display_name, $b->reviewer_display_name);
    }

    public function test_nome_original_de_outra_loja_e_mantido(): void
    {
        $r = $this->review(['id' => 7, 'outra_loja' => 1, 'reviewer_name' => 'Maria J.']);

        $this->assertSame('Maria J.', $r->reviewer_display_name);
    }

    public function test_avaliacao_da_loja_nao_muda(): void
    {
        $r = $this->review(['id' => 7, 'outra_loja' => 0, 'reviewer_name' => 'Ana']);

        $this->assertSame('Ana', $r->reviewer_display_name);
    }
}
