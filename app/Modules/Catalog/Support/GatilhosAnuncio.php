<?php

namespace App\Modules\Catalog\Support;

/**
 * Regra de negócio dos textos de anúncio gerados pelo Gemini (pedido do
 * Lira, 2026-10-19): títulos e textos usam os gatilhos psicológicos de forma
 * combinada e todos levam emojis pertinentes. Vale para a página do produto
 * (AnuncioConteudoService) e para a descrição da Shopee (ShopeeDescricaoPadrao).
 *
 * Os gatilhos nunca viram publicidade enganosa: escassez, urgência, prova
 * social e autoridade só com o que o cadastro sustenta.
 */
class GatilhosAnuncio
{
    public const GATILHOS = ['escassez', 'vontade', 'necessidade', 'urgência', 'prova social', 'autoridade', 'curiosidade', 'reciprocidade'];

    public static function regras(): string
    {
        return <<<'TXT'
        Gatilhos psicológicos (obrigatório): use ativamente nos títulos e nos textos os gatilhos de escassez, vontade, necessidade, urgência, prova social, autoridade, curiosidade e reciprocidade, combinando 2 ou 3 por trecho de forma estratégica para converter:
          - Necessidade e vontade: comece pela dor do dia a dia e mostre o resultado que dá vontade de ter.
          - Curiosidade: títulos que abrem uma pergunta ou prometem revelar algo que o texto entrega.
          - Autoridade: material, especificação técnica e acabamento que o cadastro informa, ditos com segurança.
          - Prova social: o uso comum do produto e o que quem compra procura ("o jeito que muita gente escolheu para..."), sem inventar número de vendas, nota, avaliação ou depoimento.
          - Reciprocidade: entregue uma dica de uso ou cuidado que ajuda de verdade, antes de pedir a compra.
          - Urgência e escassez: na chamada final e nos fechos, convite para garantir agora ("garanta o seu", "aproveite enquanto tem estoque"), sem inventar prazo, quantidade, contador, desconto ou fim de oferta.
          Nunca use selo, certificação, garantia, brinde ou promessa que não esteja nas informações do produto.
        Emojis (obrigatório): todo título e todo texto gerado leva emoji pertinente ao produto e ao assunto do trecho (1 a 2 por título ou frase curta, até 3 num texto maior), no começo ou no fim, sem repetir o mesmo emoji em sequência e sem exagero que atrapalhe a leitura.
        TXT;
    }

    /**
     * Frases finais (pedido do Lira, 2026-10-20), no padrão que ele escreveu à
     * mão na Lanterna LED 589: a "chamada" que fecha a descrição, o bloco de
     * CTA e os cartões de benefícios. $tom e $tituloBeneficios vêm do
     * PerfilDoCliente (categoria do produto).
     */
    public static function fechamento(string $tom, string $tituloBeneficios): string
    {
        return <<<TXT
        Frases finais (obrigatório, é o que fecha a venda):
          - "chamada": a última frase antes do selo de compra. Uma frase só, de 50 a 90 caracteres, terminando em "!", sem emoji (exceção à regra dos emojis). Estrutura: verbo no imperativo que abre a cena (Ilumine, Organize, Corte, Transforme, Deixe) + o principal resultado + palavra de autoridade ou potência que o cadastro sustenta (profissional, de verdade, sem esforço) + identificação com quem compra ("que você precisa", "do seu dia a dia", "que a sua casa merece"). Modelo: "Ilumine qualquer situação com a potência profissional que você precisa!"
          - Último bloco (CTA): título curto que junta desejo e urgência ("Recarregável, sem gastar com pilhas"); texto que recapitula o que vem na caixa ou o uso principal e termina com convite direto ("Garanta a sua e tenha luz sempre à mão."), sem inventar prazo, quantidade ou oferta.
          - "beneficios": aparecem sob o título "{$tituloBeneficios}". Cada um com 2 a 3 palavras fortes (Alta intensidade, Modelo recarregável, Fácil de usar, Versátil) e uma explicação de até 60 caracteres que responda a esse título.
          - "comparativo": título "Por que ter este(a) <produto em 1 a 2 palavras>?" e intro "Veja a diferença para um(a) <produto> comum.".
          - Tom para o cliente deste produto: {$tom}.
        TXT;
    }
}
