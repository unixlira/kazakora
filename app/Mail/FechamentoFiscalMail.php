<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Fechamento fiscal do mês (todo dia 1º): resumo no corpo, CSV de todas as
 * notas e o ZIP com os XMLs anexados, pronto pra encaminhar ao contador.
 */
class FechamentoFiscalMail extends Mailable
{
    use Queueable, SerializesModels;

    /** Acima disso o ZIP não vai anexado (limite do SMTP), só o link. */
    public const LIMITE_ANEXO_BYTES = 15 * 1024 * 1024;

    public function __construct(
        public readonly array $relatorio,
        public readonly string $csv,
        public readonly ?string $zipPath,
    ) {}

    public function build(): self
    {
        $mail = $this
            ->subject("Fechamento fiscal {$this->relatorio['mes_extenso']} — {$this->relatorio['totais']['autorizadas']} notas, {$this->relatorio['totais']['canceladas']} canceladas")
            ->view('emails.fechamento-fiscal', [
                'r' => $this->relatorio,
                'zipAnexado' => $this->zipAnexado(),
                'link' => route('admin.notas-fiscais.fechamento', ['mes' => $this->relatorio['mes']]),
            ])
            ->attachData($this->csv, "notas-{$this->relatorio['mes']}.csv", ['mime' => 'text/csv']);

        if ($this->zipAnexado()) {
            $mail->attach(Storage::disk('local')->path($this->zipPath), ['as' => basename($this->zipPath), 'mime' => 'application/zip']);
        }

        return $mail;
    }

    private function zipAnexado(): bool
    {
        return $this->zipPath
            && Storage::disk('local')->exists($this->zipPath)
            && Storage::disk('local')->size($this->zipPath) <= self::LIMITE_ANEXO_BYTES;
    }
}
