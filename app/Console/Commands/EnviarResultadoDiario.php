<?php

namespace App\Console\Commands;

use App\Modules\Financeiro\Mail\ResultadoDoDia;
use App\Modules\Financeiro\Support\ResultadoDiario;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * E-mail de resultado do dia às 23:50 (pedido 2026-10-10): lucro líquido do
 * dia, do mês e impostos do mês a pagar. Substitui o e-mail de margem que a
 * Naia mandava por fora (mesmo arquivo de controle, não repete o dia).
 */
class EnviarResultadoDiario extends Command
{
    protected $signature = 'loja:resultado-diario
        {--data= : Dia do relatório (AAAA-MM-DD); padrão hoje}
        {--para= : Outro destinatário (teste)}
        {--forcar : Manda mesmo se o dia já foi enviado}';

    protected $description = 'Envia o e-mail de resultado do dia (lucro líquido e impostos do mês)';

    private const CONTROLE = 'automation/kazakora_margin_daily_email_state.json';

    public function handle(ResultadoDiario $calculo): int
    {
        $data = $this->option('data') ? Carbon::parse($this->option('data')) : now();
        $chave = $data->toDateString();
        $teste = filled($this->option('para'));

        $controle = $this->controle();
        if (! $teste && ! $this->option('forcar') && isset($controle[$chave]['sent_at'])) {
            $this->info("Resultado de {$chave} já enviado.");

            return self::SUCCESS;
        }

        $resultado = $calculo->calcular($data);
        $para = $teste ? [$this->option('para')] : array_filter(array_map('trim', explode(',', (string) config('loja.email_relatorios'))));

        Mail::to($para)->send(new ResultadoDoDia($resultado));

        if (! $teste) {
            $controle[$chave] = ['sent_at' => now()->toIso8601String(), 'lucro' => $resultado['dia']['lucro']];
            Storage::disk('local')->put(self::CONTROLE, json_encode($controle, JSON_PRETTY_PRINT));
        }

        $this->info(sprintf('Enviado para %s: lucro do dia R$ %s, mês R$ %s, impostos R$ %s.',
            implode(', ', $para),
            number_format($resultado['dia']['lucro'], 2, ',', '.'),
            number_format($resultado['mes']['lucro'], 2, ',', '.'),
            number_format($resultado['mes']['impostos'], 2, ',', '.')));

        return self::SUCCESS;
    }

    private function controle(): array
    {
        $dados = Storage::disk('local')->exists(self::CONTROLE)
            ? json_decode((string) Storage::disk('local')->get(self::CONTROLE), true)
            : [];

        return is_array($dados) ? $dados : [];
    }
}
