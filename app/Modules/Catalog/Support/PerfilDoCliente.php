<?php

namespace App\Modules\Catalog\Support;

/**
 * Tipo de cliente pela categoria do produto (pedido do Lira, 2026-10-20):
 * define o título dos cartões de benefícios da página ("Por que você vai
 * amar" só em beleza; o Lira achou "amar" feminino demais para ferramenta)
 * e o tom das frases finais que o Gemini escreve.
 *
 * Categoria nova ou produto sem categoria cai no perfil neutro.
 */
class PerfilDoCliente
{
    private const TITULO_NEUTRO = 'Por que vale a pena';

    private const TOM_NEUTRO = 'praticidade, tempo ganho e o problema do dia a dia resolvido';

    /** slug da categoria => [título dos benefícios, tom das frases finais] */
    private const PERFIS = [
        'ferramentas-e-automotivo' => ['Por que você precisa', 'desempenho, potência, robustez e serviço feito sem dor de cabeça, com linguagem direta de quem trabalha com as mãos'],
        'jardim-e-area-externa' => ['Por que você precisa', 'serviço pesado resolvido rápido, resistência ao uso externo e quintal em ordem'],
        'gadgets' => ['Por que você precisa', 'tecnologia útil, praticidade e estar sempre preparado'],
        'beleza-e-bem-estar' => ['Por que você vai amar', 'cuidado, autoestima, conforto e o prazer de se cuidar'],
        'saude-e-fitness' => ['Por que vale a pena', 'resultado, disposição e constância no treino e na saúde'],
        'eletroportateis-e-casa' => ['Por que vale a pena', 'casa organizada, tempo ganho e conforto da família'],
        'pet' => ['Por que vale a pena', 'bem-estar e segurança do pet e a tranquilidade do tutor'],
        'brinquedos-e-games' => ['Por que vale a pena', 'diversão, momentos em família e criança entretida longe da tela'],
    ];

    public static function tituloBeneficios(?string $categoriaSlug): string
    {
        return self::PERFIS[$categoriaSlug][0] ?? self::TITULO_NEUTRO;
    }

    public static function tom(?string $categoriaSlug): string
    {
        return self::PERFIS[$categoriaSlug][1] ?? self::TOM_NEUTRO;
    }
}
