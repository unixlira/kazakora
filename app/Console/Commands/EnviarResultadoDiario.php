<?php

namespace App\Console\Commands;

use App\Modules\Financeiro\Mail\ResultadoDoDia;
use App\Modules\Financeiro\Support\ResultadoDiario;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * E-mail de resultado do dia (pedido 2026-10-10): lucro líquido do dia, do
 * mês e impostos do mês a pagar. Substitui o e-mail de margem que a Naia
 * mandava por fora (mesmo arquivo de controle, não repete o dia).
 *
 * Desde 11/11/2026 sai às 07:30 com o dia ANTERIOR fechado ("no site o lucro
 * está um valor, no e-mail outro"). Às 23:50 o gasto de anúncio do dia ainda
 * não tinha chegado (Shopee/ML Ads e Meta sincronizam às 06:00 do dia
 * seguinte) e as taxas reais, cancelamentos e devoluções vinham depois: o
 * e-mail ficava até R$ 470 longe da tela no mesmo dia. Mesmo assim um dia
 * fechado ainda muda (devolução, extrato), então o e-mail lista os dias do
 * mês que mudaram desde que foram enviados, para bater com a tela.
 */
class EnviarResultadoDiario extends Command
{
    protected $signature = 'loja:resultado-diario
        {--data= : Dia do relatório (AAAA-MM-DD); padrão ontem (dia fechado)}
        {--para= : Outro destinatário (teste)}
        {--forcar : Manda mesmo se o dia já foi enviado}';

    protected $description = 'Envia o e-mail de resultado do dia (lucro líquido e impostos do mês)';

    private const CONTROLE = 'automation/kazakora_margin_daily_email_state.json';

    public function handle(ResultadoDiario $calculo): int
    {
        $data = $this->option('data') ? Carbon::parse($this->option('data')) : now()->subDay();
        $chave = $data->toDateString();
        $teste = filled($this->option('para'));

        $controle = $this->controle();
        // Envio das 23:50 (antes de 11/11/2026) não tem "fechado": o dia sai de novo, fechado.
        if (! $teste && ! $this->option('forcar') && ! empty($controle[$chave]['fechado'])) {
            $this->info("Resultado de {$chave} já enviado.");

            return self::SUCCESS;
        }

        $resultado = $calculo->calcular($data);
        $resultado['revisoes'] = $this->revisoes($resultado['dias'], $controle, $chave);
        $para = $teste ? [$this->option('para')] : array_filter(array_map('trim', explode(',', (string) config('loja.email_relatorios'))));

        Mail::to($para)->send(new ResultadoDoDia($resultado));

        if (! $teste) {
            $controle[$chave] = ['sent_at' => now()->toIso8601String(), 'lucro' => $resultado['dia']['lucro'], 'fechado' => true];
            // O e-mail de hoje já mostrou o valor novo dos dias revistos: a base passa a ser ele.
            foreach ($resultado['revisoes'] as $revisao) {
                $controle[$revisao['data']->toDateString()]['lucro'] = $revisao['agora'];
            }
            Storage::disk('local')->put(self::CONTROLE, json_encode($controle, JSON_PRETTY_PRINT));
        }

        $this->info(sprintf('Enviado para %s: lucro do dia R$ %s, mês R$ %s, impostos R$ %s.',
            implode(', ', $para),
            number_format($resultado['dia']['lucro'], 2, ',', '.'),
            number_format($resultado['mes']['lucro'], 2, ',', '.'),
            number_format($resultado['mes']['impostos'], 2, ',', '.')));

        return self::SUCCESS;
    }

    /**
     * Dias do mês, antes do dia do relatório, cujo lucro mudou desde o último
     * e-mail (anúncio sincronizado depois, taxa real, cancelamento, devolução).
     *
     * @param  list<array>  $dias
     * @return list<array{data: Carbon, enviado: float, agora: float, diferenca: float}>
     */
    private function revisoes(array $dias, array $controle, string $chave): array
    {
        $revisoes = [];
        foreach ($dias as $dia) {
            $data = $dia['data']->toDateString();
            if ($data === $chave || ! isset($controle[$data]['lucro'])) {
                continue;
            }

            $diferenca = round((float) $dia['lucro'] - (float) $controle[$data]['lucro'], 2);
            if (abs($diferenca) >= 0.01) {
                $revisoes[] = ['data' => $dia['data'], 'enviado' => (float) $controle[$data]['lucro'], 'agora' => (float) $dia['lucro'], 'diferenca' => $diferenca];
            }
        }

        return $revisoes;
    }

    private function controle(): array
    {
        $dados = Storage::disk('local')->exists(self::CONTROLE)
            ? json_decode((string) Storage::disk('local')->get(self::CONTROLE), true)
            : [];

        return is_array($dados) ? $dados : [];
    }
}
