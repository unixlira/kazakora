<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Services\Correios\CorreiosPrePostagemService;
use RuntimeException;

/**
 * Cancela uma pré-postagem nos Correios e marca aqui como cancelada — pelo
 * botão do menu Correios ou por `php artisan correios:cancelar`. Cancelada,
 * ela deixa de ser a etiqueta do pedido (CorreiosAutoShipping::geradaPara)
 * e de contar como frete na margem de contribuição (ContributionMargin).
 */
class CorreiosCancelamento
{
    public function __construct(private readonly CorreiosPrePostagemService $correios) {}

    public function cancelar(CorreiosPrePostagem $prePostagem): void
    {
        if ($prePostagem->status !== CorreiosPrePostagem::STATUS_GERADA || ! $prePostagem->correios_id) {
            throw new RuntimeException('Só dá pra cancelar pré-postagem gerada nos Correios (esta está "'.$prePostagem->status.'").');
        }

        $this->correios->cancel($prePostagem->correios_id);

        $prePostagem->update([
            'status' => CorreiosPrePostagem::STATUS_CANCELADA,
            'error_message' => 'Cancelada nos Correios em '.now()->timezone('America/Sao_Paulo')->format('d/m/Y H:i').'.',
        ]);
    }
}
