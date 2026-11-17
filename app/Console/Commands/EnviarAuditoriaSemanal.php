<?php

namespace App\Console\Commands;

use App\Modules\Financeiro\Mail\AuditoriaDaSemana;
use App\Modules\Financeiro\Support\AuditoriaSemanal;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Auditoria semanal do dinheiro que caiu (pedido do Lira em 12/11/2026): toda
 * segunda às 14h, um e-mail com o que TikTok Shop, Amazon, Mercado Livre e
 * Shopee depositaram na semana fechada (segunda a domingo) e quanto separar
 * para imposto, fornecedor e insumos, e quanto sobra de lucro. Sai à tarde
 * para a manhã de segunda ter tempo de importar o extrato do TikTok e
 * conciliar o Mercado Pago. Primeira semana: 09 a 15/11/2026, enviada em 16/11.
 *
 * Não repete a mesma semana (arquivo de controle); --forcar manda de novo.
 */
class EnviarAuditoriaSemanal extends Command
{
    protected $signature = 'financeiro:auditoria-semanal
        {--de= : Início (AAAA-MM-DD); padrão a segunda da semana passada}
        {--ate= : Fim, sem incluir (AAAA-MM-DD); padrão a segunda desta semana}
        {--para= : Outro destinatário (teste)}
        {--seco : Só mostra a conta no terminal, não manda e-mail}
        {--forcar : Manda mesmo se a semana já foi enviada}';

    protected $description = 'Envia a auditoria semanal: quanto caiu de cada canal e quanto separar para imposto, fornecedor, insumos e lucro';

    private const CONTROLE = 'automation/auditoria_semanal_state.json';

    public function handle(AuditoriaSemanal $auditoria): int
    {
        [$de, $ate] = AuditoriaSemanal::semanaFechada();
        if ($this->option('de')) {
            $de = Carbon::parse($this->option('de'))->startOfDay();
            $ate = $this->option('ate') ? Carbon::parse($this->option('ate'))->startOfDay() : $de->copy()->addWeek();
        }
        if ($ate->lte($de)) {
            $this->error('O fim tem que ser depois do início.');

            return self::FAILURE;
        }

        $chave = $de->toDateString().'_'.$ate->toDateString();
        $teste = filled($this->option('para'));
        $seco = (bool) $this->option('seco');
        $controle = $this->controle();

        if (! $seco && ! $teste && ! $this->option('forcar') && isset($controle[$chave])) {
            $this->info("Auditoria de {$chave} já enviada em {$controle[$chave]['sent_at']}.");

            return self::SUCCESS;
        }

        $resultado = $auditoria->calcular($de, $ate);
        $this->mostrar($resultado);

        if ($seco) {
            return self::SUCCESS;
        }

        $para = $teste ? [$this->option('para')] : array_filter(array_map('trim', explode(',', (string) config('loja.email_relatorios'))));
        Mail::to($para)->send(new AuditoriaDaSemana($resultado));

        if (! $teste) {
            $controle[$chave] = ['sent_at' => now()->toIso8601String(), 'total' => $resultado['total'], 'avisos' => count($resultado['avisos'])];
            Storage::disk('local')->put(self::CONTROLE, json_encode($controle, JSON_PRETTY_PRINT));
        }

        $this->info('Enviado para '.implode(', ', $para).'.');

        return self::SUCCESS;
    }

    private function mostrar(array $resultado): void
    {
        $brl = fn ($v) => number_format((float) $v, 2, ',', '.');
        $this->line('Semana de '.$resultado['de']->format('d/m/Y').' a '.$resultado['ate']->copy()->subDay()->format('d/m/Y'));
        $this->table(
            ['Canal', 'Pedidos', 'Caiu', 'Imposto', 'Fornecedor', 'Insumos', 'Frete próprio', 'Lucro'],
            [
                ...array_map(fn (array $c) => [$c['nome'], $c['pedidos'], $brl($c['depositado']), $brl($c['imposto']), $brl($c['fornecedor']), $brl($c['insumos']), $brl($c['frete_proprio']), $brl($c['sobra'])], array_values($resultado['canais'])),
                ['TOTAL', $resultado['total']['pedidos'], $brl($resultado['total']['depositado']), $brl($resultado['total']['imposto']), $brl($resultado['total']['fornecedor']), $brl($resultado['total']['insumos']), $brl($resultado['total']['frete_proprio']), $brl($resultado['total']['sobra'])],
            ],
        );
        foreach ($resultado['avisos'] as $aviso) {
            $this->warn('AVISO: '.$aviso);
        }
    }

    private function controle(): array
    {
        $dados = Storage::disk('local')->exists(self::CONTROLE)
            ? json_decode((string) Storage::disk('local')->get(self::CONTROLE), true)
            : [];

        return is_array($dados) ? $dados : [];
    }
}
