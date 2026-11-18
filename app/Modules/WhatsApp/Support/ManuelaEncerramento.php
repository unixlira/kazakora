<?php

namespace App\Modules\WhatsApp\Support;

use Illuminate\Support\Str;

/**
 * Mensagem que encerra a conversa (pedido do Lira 2026-11-18): "obrigada",
 * "ok, valeu", "tá bom, aguardo", "👍"... A Manuela não responde e o
 * atendimento é encerrado. Achado real: a cliente reagiu 🙏 ao aviso "saiu
 * para entrega" e a Manuela mandou "Como posso te ajudar com o seu pedido?".
 *
 * Só encerra quando a mensagem é SÓ cortesia (agradecimento, despedida,
 * concordância, emoji). Qualquer palavra fora disso ("ok, mas e o frete")
 * ou pergunta ("tudo bem?") segue para a Manuela.
 *
 * Se a nossa última mensagem foi uma pergunta, "ok", "sim", "certo" e "👍"
 * são RESPOSTA, não despedida: só agradecimento/despedida explícitos
 * ("obrigada", "valeu", "tchau") encerram.
 */
class ManuelaEncerramento
{
    /** Agradecimento ou despedida: encerra mesmo depois de uma pergunta nossa. */
    private const FORTES = [
        'muitissimo obrigad[oa]s?', 'muito obrigad[oa]s?', 'obrigad[oa]s?', 'obrigadinh[oa]', 'brigad[oa]', 'obgd?[oa]?', 'obg',
        'gratidao', 'grat[oa]', 'valeu', 'vlw', 'tchau', 'tchauzinho', 'ate mais', 'ate logo', 'ate breve', 'abracos?', 'beijos?', 'bjs?',
        'amem', 'deus (te |lhe |vos )?abencoe', 'deus (te |lhe )?pague', 'fica com deus', 'fique com deus', 'bom descanso', 'boa semana', 'bom fim de semana',
    ];

    /** Concordância: encerra só se não deixamos pergunta no ar. */
    private const FRACAS = [
        'ta bom', 'ta bem', 'ta otimo', 'ta certo', 'ta joia', 'tudo certo', 'tudo bem', 'tudo ok', 'ok', 'okay', 'oks?', 'ok+', 'blz', 'beleza',
        'certo', 'certinho', 'combinado', 'perfeito', 'otimo', 'otima', 'show', 'show de bola', 'top', 'joia', 'massa', 'legal', 'maravilha', 'que bom',
        'entendi', 'entendido', 'aguardo', 'vou aguardar', 'fico no aguardo', 'aguardando', 'no aguardo', 'recebi', 'de nada', 'disponha', 'sim', 'ss',
    ];

    /** Palavras que podem vir junto sem mudar o sentido. */
    private const ENFEITES = [
        'bom dia', 'boa tarde', 'boa noite', 'tenha um otimo dia', 'tenha uma otima tarde', 'tenha uma otima noite', 'tenha um bom dia', 'tenha uma boa tarde', 'tenha uma boa noite',
        'otimo dia', 'otima tarde', 'otima noite', 'pra voce tambem', 'para voce tambem', 'voce tambem', 'vc tambem', 'vc tb', 'igualmente',
        'pela ajuda', 'pela atencao', 'pelo atendimento', 'pela informacao', 'pelo retorno', 'pela paciencia',
        'muito', 'mt', 'mto', 'mesmo', 'demais', 'viu', 'entao', 'ah', 'ai', 'a', 'e', 'nao', 'manu', 'manuela', 'moca', 'querida', 'amiga', 'flor', 'linda',
        'k+', 'rs+', '(ha)+h?', '(he)+h?', 'eba', 'oba',
    ];

    public static function encerra(string $mensagem, bool $deixamosPergunta = false): bool
    {
        $mensagem = trim($mensagem);
        if ($mensagem === '' || mb_strlen($mensagem) > 120 || str_contains($mensagem, '?')) {
            return false;
        }

        // Só emoji (👍 🙏 ❤️): concordância.
        if (! preg_match('/[\p{L}\p{N}]/u', $mensagem)) {
            return ! $deixamosPergunta && preg_match('/\p{So}|\p{Sk}|\x{2764}/u', $mensagem) === 1;
        }

        $texto = ' '.preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', Str::lower(Str::ascii($mensagem)))).' ';

        // Enfeites primeiro: "tenha um ótimo dia" sai inteiro antes do "ótimo" sozinho.
        self::tirar($texto, self::ENFEITES);
        $forte = self::tirar($texto, self::FORTES);
        $fraca = self::tirar($texto, self::FRACAS);

        if (trim($texto) !== '') {
            return false;
        }

        return $forte || ($fraca && ! $deixamosPergunta);
    }

    /** Tira as expressões da lista (palavra inteira) e diz se achou alguma. */
    private static function tirar(string &$texto, array $lista): bool
    {
        // Mais longas primeiro: "muito obrigada" antes de "obrigada".
        usort($lista, fn ($a, $b) => strlen($b) <=> strlen($a));
        $padrao = '/ (?:'.implode('|', $lista).')(?= )/';
        $achou = false;
        do {
            $texto = preg_replace($padrao, ' ', $texto, -1, $trocas);
            $achou = $achou || $trocas > 0;
        } while ($trocas > 0);

        return $achou;
    }
}
