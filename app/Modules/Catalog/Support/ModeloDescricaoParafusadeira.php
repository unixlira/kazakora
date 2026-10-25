<?php

namespace App\Modules\Catalog\Support;

/**
 * Modelo da descrição gerada por IA para a KazaKora (pedido do Lira,
 * 2026-10-25): a parafusadeira 18V, que partiu da 48V, é o padrão. O texto é
 * guiado pelas imagens e pela utilidade: cada vantagem é algo que aparece nas
 * fotos ou que resolve um serviço do dia a dia, nada de ficha vazia.
 *
 * O exemplo mostra só a ESTRUTURA e o tom. Os fatos (LED, grip, 2 baterias...)
 * são da parafusadeira e nunca passam para outro produto.
 */
class ModeloDescricaoParafusadeira
{
    public static function regras(): string
    {
        $exemplo = self::exemplo();

        return <<<TXT
        MODELO DA DESCRIÇÃO (obrigatório, vale para o campo "descricao"): siga a estrutura e o tom do exemplo da parafusadeira 18V abaixo, que é o padrão da loja. O foco é imagem e utilidade: cada vantagem precisa aparecer nas fotos ou resolver um serviço real do dia a dia de quem compra.
        Estrutura, nesta ordem, só com as seções que o produto sustenta:
          1) Gancho: uma frase de abertura com emoji, juntando as 2 ou 3 coisas que o comprador vê nas fotos e valem a compra.
          2) Título em MAIÚSCULAS com emoji e um parágrafo de cena: o problema do dia a dia e como o produto o resolve (o que a pessoa faz com ele, em passos simples).
          3) "🔥 PRINCIPAIS VANTAGENS": lista com "✅", cada item = recurso visível nas fotos + o que ele muda no uso.
          4) Um bloco curto com emoji para cada recurso forte (ex.: "💡 ...", "🤝 ...", "🔋 ..."), no formato dor → solução.
          5) "🏠 INDICAÇÕES DE USO": lista com "✔" de serviços reais.
          6) "📋 FICHA TÉCNICA": itens com "🔹" no formato "Rótulo: valor" (regras da ficha técnica acima).
          7) "📦 CONTEÚDO DA EMBALAGEM": itens com "📌", só o que as fotos e o cadastro mostram que vem na caixa.
          8) Fecho: uma linha de compra tranquila usando só as políticas reais da loja listadas abaixo, "🚚 ENVIO RÁPIDO!" e "🛒" com o convite para comprar na KazaKora.
        Regras do modelo:
          - Texto puro, sem markdown e sem HTML; títulos de seção em MAIÚSCULAS com emoji; linha em branco entre as seções.
          - Garantia só se o cadastro ou as fotos disserem; sem isso, não cite garantia.
          - Troque o assunto do exemplo pelo do produto: nunca copie recurso, número, marca, voltagem nem medida do exemplo.
          - Cada item do exemplo é um formato, não uma obrigação: se o produto não tem o recurso, a seção não existe.
        Exemplo (parafusadeira 18V), só para copiar a estrutura e o tom:
        ---
        {$exemplo}
        ---
        TXT;
    }

    public static function exemplo(): string
    {
        return <<<'TXT'
        ✨ Luz de LED para enxergar onde fura, cabo com grip para segurar firme e 2 baterias de lítio: a parafusadeira 18V leve para resolver os serviços da casa no seu tempo.

        🔧 PARAFUSADEIRA FURADEIRA DE IMPACTO 18V COM LUZ DE LED, CABO COM GRIP, 2 BATERIAS E MALETA COM KIT
        A prateleira esperando há semanas, o quadro encostado na parede, o móvel novo ainda na caixa. Com ela, isso vira uma manhã tranquila: você encaixa a ponteira, aperta o gatilho e pronto. A luz de LED ilumina o ponto do parafuso e o cabo com grip deixa a pegada firme do primeiro ao último furo.

        🔥 PRINCIPAIS VANTAGENS
        ✅ Luz de LED: ilumina o ponto de trabalho em cantos escuros, dentro de armários e atrás de móveis
        ✅ Cabo com grip: empunhadura fina e emborrachada, pegada firme e confortável, sem escorregar
        ✅ 2 baterias de lítio: uma carrega enquanto a outra trabalha
        ✅ Maleta com kit de chaves, brocas e ponteira, tudo organizado e fácil de guardar

        💡 VEJA O QUE VOCÊ ESTÁ FAZENDO
        Montar o guarda-roupa, instalar um suporte dentro do armário ou apertar um parafuso atrás do móvel costuma ser feito com pouca luz. A luz de LED acende o ponto do parafuso para você acertar o furo de primeira.

        🔋 O SERVIÇO NÃO PARA NO MEIO
        Acabou a carga no meio da montagem? Troca a bateria e segue, enquanto a outra carrega.

        🏠 INDICAÇÕES DE USO
        ✔ Montar o móvel que chegou na caixa
        ✔ Pendurar quadros, espelhos, prateleiras e varão de cortina
        ✔ Apertar e retirar parafusos

        📋 FICHA TÉCNICA
        🔹 Produto: Parafusadeira Furadeira de Impacto leve, sem fio
        🔹 Tensão: 18V
        🔹 Iluminação: luz de LED
        🔹 Quantidade de baterias: 2

        📦 CONTEÚDO DA EMBALAGEM
        📌 01 Parafusadeira 18V com luz de LED
        📌 02 Baterias de lítio
        📌 01 Maleta para transporte com kit de chaves, brocas e ponteira

        Compra com tranquilidade: 7 dias após o recebimento para desistir da compra, conforme o Código de Defesa do Consumidor.

        🚚 ENVIO RÁPIDO!

        🛒 Garanta a sua e resolva tudo no seu tempo. Compre com segurança na KazaKora!
        TXT;
    }
}
