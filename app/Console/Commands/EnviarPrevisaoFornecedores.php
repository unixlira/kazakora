<?php

namespace App\Console\Commands;

use App\Modules\Financeiro\Support\PrevisaoFornecedores;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class EnviarPrevisaoFornecedores extends Command
{
    protected $signature = 'financeiro:fornecedores-d10 {--data= : AAAA-MM-DD; datas diferentes de hoje só em dry-run} {--dry-run : Exibe texto e CSV sem enviar nem gravar estado}';

    protected $description = 'Previsão D+10 de aquisição a conferir com planilha, não repasse nem quitação';

    public function handle(PrevisaoFornecedores $s): int
    {
        $hoje = now('America/Sao_Paulo')->toDateString();
        $data = (string) ($this->option('data') ?: $hoje);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || ! checkdate((int) substr($data, 5, 2), (int) substr($data, 8, 2), (int) substr($data, 0, 4))) {
            $this->error('Data inválida.');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        if (! $dry && $data !== $hoje) {
            $this->error('Envio somente da data atual; use --dry-run para prévia.');

            return self::FAILURE;
        }
        if (! $s->elegivel($data)) {
            $this->info('Sem envio: antes do início ou dia não útil.');

            return self::SUCCESS;
        }
        if (! $dry && ! app()->environment('production')) {
            $this->error('Envio permitido somente em produção.');

            return self::FAILURE;
        }
        $lock = $dry ? null : Cache::lock('fornecedores-d10:'.$data, 1800);
        if ($lock && ! $lock->get()) {
            $this->error('Relatório em execução.');

            return self::FAILURE;
        }
        try {
            $path = 'automation/fornecedores-d10/'.$data.'.json';
            if (! $dry && Storage::disk('local')->exists($path)) {
                $state = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
                if (empty($state['sent_at'])) {
                    throw new RuntimeException('Estado inválido; conferir manualmente antes de reenviar.');
                }
                $this->info('E-mail desta data já enviado; não é registro de quitação.');

                return self::SUCCESS;
            }
            $r = $s->calcular($data);
            $body = $s->texto($r);
            $csv = $s->csv($r);
            if ($dry) {
                $this->line($body."\n\n--- CSV ---\n".$csv);

                return self::SUCCESS;
            }
            $mail = (new Mailable)->subject('KazaKora — previsão fornecedores D+10 — '.$data)
                ->html(nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')))
                ->attachData("\xEF\xBB\xBF".$csv, 'fornecedores-'.$data.'.csv', ['mime' => 'text/csv; charset=UTF-8']);
            Mail::to('joserobertolira@gmail.com')->send($mail);
            if (! Storage::disk('local')->put($path, json_encode(['sent_at' => now()->toIso8601String(), 'report_date' => $data, 'body_sha256' => hash('sha256', $body), 'supplier_payment_verified' => false], JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Mail aceito mas estado não persistiu: conferir caixa antes de tentar novamente.');
            }
            $this->info('E-mail aceito pelo transporte; fornecedor não marcado como pago.');

            return self::SUCCESS;
        } finally {
            $lock?->release();
        }
    }
}
