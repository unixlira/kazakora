<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Uma venda que o canal tem e o Kazakora NÃO conseguiu importar.
 *
 * INCIDENTE 2026-09-10: a venda 260910M2M4KAK5 da Shopee falhou na
 * importação (erro de código nosso), o webhook tentou 3 vezes, a
 * varredura horária tentou mais uma vez por hora o dia inteiro — e todas
 * as falhas foram só um `$this->warn()` num console que ninguém lê. A
 * venda ficou 24h sem nota e sem etiqueta e quem descobriu foi o usuário,
 * abrindo o painel da Shopee.
 *
 * Venda que não entra é o pior tipo de erro do sistema: não aparece em
 * lugar nenhum da tela, porque a tela mostra o que existe no banco. A
 * única defesa é gritar.
 */
class OrderImportFailedNotification extends Notification
{
    /**
     * @param  list<array{sn: string, message: string}>  $falhas
     */
    public function __construct(
        private readonly string $canal,
        private readonly array $falhas,
        private readonly string $janela,
    ) {
    }

    /**
     * E-MAIL, e não só notificação no banco: notificação de tela só é vista
     * por quem abre a tela, e o dono da loja descobriu esse incidente no
     * painel da Shopee. Alerta de venda perdida tem que ir atrás da pessoa.
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $quantidade = count($this->falhas);

        $mensagem = (new MailMessage)
            ->subject($quantidade === 1
                ? "⚠️ Venda NÃO importada ({$this->canal}): ".$this->falhas[0]['sn']
                : "⚠️ {$quantidade} vendas NÃO importadas ({$this->canal})")
            ->greeting('Venda no canal que não entrou no sistema')
            ->line("Canal: {$this->canal}")
            ->line("Janela conferida: {$this->janela}")
            ->line('Estas vendas existem no canal e NÃO estão no Kazakora — ou seja, estão sem nota fiscal e sem etiqueta:');

        foreach ($this->falhas as $falha) {
            $mensagem->line("• {$falha['sn']} — {$falha['message']}");
        }

        return $mensagem
            ->line('A varredura tenta de novo sozinha na próxima rodada. Se o motivo for erro de código, ela vai falhar de novo — e este e-mail volta.')
            ->salutation('KazaKora');
    }

    public function toArray(object $notifiable): array
    {
        $quantidade = count($this->falhas);
        $vendas = implode(', ', array_column($this->falhas, 'sn'));

        return [
            'canal' => $this->canal,
            'janela' => $this->janela,
            'falhas' => $this->falhas,
            'message' => $quantidade === 1
                ? "VENDA NÃO IMPORTADA ({$this->canal}): {$vendas} existe no canal e não entrou no sistema — sem nota e sem etiqueta. Motivo: {$this->falhas[0]['message']}"
                : "{$quantidade} VENDAS NÃO IMPORTADAS ({$this->canal}) na janela {$this->janela}: {$vendas} — existem no canal e não entraram no sistema, sem nota e sem etiqueta.",
        ];
    }
}
