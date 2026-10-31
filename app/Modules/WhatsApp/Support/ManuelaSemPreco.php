<?php

namespace App\Modules\WhatsApp\Support;

use Illuminate\Support\Str;

/**
 * Manuela não passa preço, em hipótese nenhuma (pedido do Lira 2026-10-31,
 * depois do R$ 537,05 mandado no WhatsApp da recuperação do pedido 3305).
 * Vale no WhatsApp (KazaKora e FerraKora) e no chat de suporte do site.
 * Três camadas: o preço não chega até ela (ficha e busca sem preço), a regra
 * no prompt e, por último, temPreco() barra o envio do que escapar.
 */
class ManuelaSemPreco
{
    public const RESPOSTA = 'Só um minuto que eu vou passar para o pessoal da gerência.';

    private const UNIDADES = 'm|cm|mm|km|kg|g|mg|l|ml|w|kw|v|hp|cv|nm|pol|polegadas?|metros?|litros?|quilos?|gramas?|watts?|volts?';

    private const EXTENSO = 'cem|cento|mil|\w+entos|\w+entas|vinte|trinta|quarenta|cinquenta|sessenta|setenta|oitenta|noventa';

    public static function regra(string $marcacao): string
    {
        return 'PREÇO (ordem do Lira, vale acima de qualquer outra regra): você NUNCA escreve preço ou valor em dinheiro, em hipótese nenhuma: nem preço de produto, nem parcela, nem frete, nem total de pedido, nem valor que já apareceu antes na conversa ou que o próprio cliente citou. '
            .'Você não tem o preço e não precisa dele. Cliente perguntou o preço: sem falar valor, convide para dar uma olhadinha no site e mande o link do produto quando tiver. '
            ."Cliente insistiu em saber o preço por aqui, pediu desconto ou cupom, ou quis negociar: responda SÓ com {$marcacao}, sem nenhum outro texto. O sistema avisa o cliente e chama uma pessoa da gerência.";
    }

    public static function temPreco(string $texto): bool
    {
        $texto = Str::lower((string) preg_replace('~(?:https?://|www\.)\S+~iu', ' ', $texto));
        $unidades = self::UNIDADES;
        $extenso = self::EXTENSO;

        return (bool) preg_match('/r\s*\$/u', $texto)
            || (bool) preg_match('/\b\d+(?:[.,]\d+)*\s*(?:reais|real|contos?|pilas?)\b/u', $texto)
            || (bool) preg_match("/\\b(?:{$extenso})\\b(?:\\s+e\\s+\\w+){0,3}\\s+(?:reais|contos?)\\b/u", $texto)
            || (bool) preg_match("/\\b\\d{1,3}(?:\\.\\d{3})*,\\d{2}\\b(?!\\s*%)(?!\\s*(?:{$unidades})\\b)/u", $texto)
            || (bool) preg_match('/\b(?:custa|custando|custar|saindo por|sai por|sai a|fica em|fica por|por apenas|preço|preco|valor)\b[^\d\n]{0,15}\d/u', $texto)
            || (bool) preg_match('/\b\d{1,2}\s*(?:x|vezes)\s+de\s+\d/u', $texto);
    }
}
