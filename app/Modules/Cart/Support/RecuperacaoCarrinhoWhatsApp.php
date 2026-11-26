<?php

namespace App\Modules\Cart\Support;

use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Cart\Support\Sequencia\DisparadorSequencia;
use App\Modules\Cart\Support\Sequencia\ImportadorSequencia;
use App\Modules\Checkout\Models\LeadCheckout;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Botão "Enviar WhatsApp" e "Disparar para todos" em Admin > Carrinhos
 * abandonados (pedido do Lira 2026-11-23). Não manda nada por fora: antecipa
 * o 1º disparo da sequência automática (DisparadorSequencia): desde
 * 26/11/2026 é o WhatsApp de lembrete (sem celular, o e-mail), grava no
 * histórico do carrinho e na tela de Conversas e segue o calendário a partir
 * daí (D+3, D+5 Caderno, D+7). Assim o automático não repete a mensagem, e
 * quem já recebeu mensagem de carrinho hoje não recebe outra.
 */
class RecuperacaoCarrinhoWhatsApp
{
    public const OK = 'enviado';

    public function __construct(
        private readonly ImportadorSequencia $importador,
        private readonly DisparadorSequencia $disparador,
    ) {}

    /** Motivo para não enviar, ou null quando pode. */
    public function bloqueio(LeadCheckout $lead): ?string
    {
        return match (true) {
            $lead->status !== LeadCheckout::STATUS_ABERTO => 'O carrinho já virou pedido.',
            $lead->whatsapp_enviado_em !== null => 'A mensagem já foi enviada para este carrinho.',
            ! filled($lead->telefone) && ! filled($lead->email) => 'O carrinho não tem celular nem e-mail.',
            empty($lead->itens) => 'O carrinho não tem produto.',
            default => null,
        };
    }

    /** Envia e devolve self::OK ou o motivo de não ter enviado. */
    public function enviar(LeadCheckout $lead): string
    {
        if ($motivo = $this->bloqueio($lead)) {
            return $motivo;
        }

        try {
            $registro = $this->registro($lead);
            if (! $registro) {
                return 'O carrinho ainda não entrou na sequência (fica parado 60 minutos antes).';
            }
            if ($registro->parado) {
                return 'A sequência deste cliente está parada ('.$registro->motivo_parada.').';
            }
            if ($registro->disparos_enviados > 0) {
                $lead->forceFill(['whatsapp_enviado_em' => $registro->ultimo_disparo_em ?? now()])->save();

                return 'O cliente já recebeu a mensagem pela sequência automática.';
            }

            $resultado = $this->disparador->disparar($registro);
        } catch (Throwable $e) {
            Log::warning('Recuperação de carrinho pelo painel falhou', ['lead' => $lead->id, 'erro' => $e->getMessage()]);

            return 'Não foi possível enviar: '.mb_substr($e->getMessage(), 0, 160);
        }

        if ($resultado !== 'enviado') {
            return match ($resultado) {
                'sem_canal' => 'Sem canal para enviar (celular sem modelo de WhatsApp e sem e-mail).',
                'adiado' => 'O cliente já recebeu uma mensagem de carrinho hoje; a próxima sai amanhã, sozinha.',
                'pulado' => 'Sem contato para esta etapa; a sequência seguiu para a próxima.',
                default => 'O envio foi recusado. Veja o histórico em Sequência.',
            };
        }

        $lead->forceFill(['whatsapp_enviado_em' => now()])->save();

        return self::OK;
    }

    /** O carrinho deste lead na sequência (traz os novos antes de procurar). */
    private function registro(LeadCheckout $lead): ?Registro
    {
        $achar = fn () => Registro::query()->where('origem', Registro::ORIGEM_LEAD)->where('origem_id', $lead->id)->first()
            ?? $this->importador->daMesmaPessoa((string) $lead->site, $lead->email ? mb_strtolower($lead->email) : null, null, $lead->user_id);

        return $achar() ?? (($this->importador->importar() || true) ? $achar() : null);
    }
}
