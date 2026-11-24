<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\RelatoriosTikTok;
use App\Modules\Operacional\Mail\ColetaParada;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Vigia de hora em hora das coletas da loja (24/11/2026). O demonstrativo do
 * TikTok parou em 21/11 e só se viu três dias depois: o aviso pelo WhatsApp
 * da Manuela estava desligado. Agora cada fonte manda um e-mail quando passa
 * do limite sem chegar nada e outro quando volta; enquanto continua parada,
 * não repete (o estado fica no cache).
 *
 * Fontes: demonstrativo do TikTok (agente da Cora Box), pedidos novos do
 * TikTok Shop, Shopee e Mercado Livre, e a coleta de taxas reais da Amazon
 * (amazon:taxas-reais, que registra a última execução).
 */
class VigiarColetasLoja extends Command
{
    protected $signature = 'loja:vigiar-coletas {--seco : Só mostra o que avisaria, sem mandar e-mail nem gravar estado}';

    protected $description = 'Manda e-mail quando uma coleta (demonstrativo TikTok, pedidos dos marketplaces, taxas Amazon) para de chegar e quando volta';

    public const CACHE_ESTADO = 'loja:vigiar-coletas:estado:';

    private const PEDIDOS = [
        Order::ORIGIN_TIKTOK_SHOP => 'TikTok Shop',
        Order::ORIGIN_SHOPEE => 'Shopee',
        Order::ORIGIN_MERCADO_LIVRE => 'Mercado Livre',
    ];

    public function handle(RelatoriosTikTok $relatorios): int
    {
        $cfg = config('services.alerta_coleta_email');
        $seco = (bool) $this->option('seco');

        if (! ($cfg['ativo'] ?? false) && ! $seco) {
            $this->info('Vigia de coletas desligado (ALERTA_COLETA_EMAIL_ATIVO).');

            return self::SUCCESS;
        }

        $para = (string) ($cfg['para'] ?? '');
        foreach ($this->fontes($relatorios, $cfg['horas'] ?? []) as $fonte) {
            $chave = self::CACHE_ESTADO.$fonte['chave'];
            $anterior = Cache::get($chave);
            $situacao = $fonte['parada'] ? 'parada' : 'ok';
            $this->line("{$fonte['nome']}: {$situacao} (última chegada: ".($fonte['ultima']?->format('d/m H:i') ?? 'nunca').')');

            // Primeira vez que vê a fonte funcionando: só grava, sem e-mail.
            $avisar = $situacao === 'parada' ? $anterior !== 'parada' : $anterior === 'parada';
            if ($seco || ! $avisar) {
                if (! $seco && $anterior !== $situacao) {
                    Cache::forever($chave, $situacao);
                }

                continue;
            }

            try {
                Mail::to($para)->send(new ColetaParada(
                    nome: $fonte['nome'],
                    voltou: $situacao === 'ok',
                    ultima: $fonte['ultima']?->format('d/m/Y H:i'),
                    limiteHoras: $fonte['limite'],
                    detalhe: $fonte['detalhe'],
                    dica: $fonte['dica'],
                ));
            } catch (Throwable $e) {
                // Sem gravar o estado: tenta de novo na próxima hora.
                report($e);
                $this->error("{$fonte['nome']}: e-mail falhou: {$e->getMessage()}");

                continue;
            }

            Cache::forever($chave, $situacao);
            $this->warn("{$fonte['nome']}: e-mail de ".($situacao === 'ok' ? 'volta' : 'parada')." para {$para}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $horas
     * @return list<array{chave: string, nome: string, parada: bool, ultima: ?Carbon, limite: int, detalhe: ?string, dica: string}>
     */
    private function fontes(RelatoriosTikTok $relatorios, array $horas): array
    {
        $fontes = [$this->demonstrativoTikTok($relatorios, (int) ($horas['tiktok_demonstrativo'] ?? 6))];

        foreach (self::PEDIDOS as $origem => $nome) {
            $ultima = Order::query()->where('origin', $origem)->max('created_at');
            if ($ultima === null) {
                continue; // canal sem nenhum pedido: nada para vigiar
            }
            $limite = (int) ($horas['pedidos'] ?? 24);
            $ultima = Carbon::parse($ultima);
            $fontes[] = [
                'chave' => "pedidos_{$origem}", 'nome' => "Pedidos do {$nome}", 'ultima' => $ultima, 'limite' => $limite,
                'parada' => $ultima->lt(now()->subHours($limite)), 'detalhe' => null,
                'dica' => "Confira a integração do {$nome} (Integrações no admin), o agendador e o storage/logs/laravel.log. Se o canal só está sem venda, ignore.",
            ];
        }

        if ($amazon = $this->taxasAmazon((int) ($horas['amazon_taxas'] ?? 4))) {
            $fontes[] = $amazon;
        }

        return $fontes;
    }

    /**
     * Última chegada do demonstrativo do mês corrente. Arquivo repetido conta
     * (ultimo_envio_em), mas só o do mês atual: o modo "completo" do agente
     * reenvia julho, agosto... e isso não prova que novembro está chegando.
     */
    private function demonstrativoTikTok(RelatoriosTikTok $relatorios, int $limite): array
    {
        $linhas = DB::table('tiktok_report_uploads')
            ->whereRaw('COALESCE(ultimo_envio_em, created_at) >= ?', [now()->subDays(45)])
            ->get(['nome_original', 'created_at', 'ultimo_envio_em']);

        $ultima = $linhas
            ->map(fn ($l) => ['mes' => $relatorios->mesDoPeriodo($l->nome_original), 'em' => Carbon::parse($l->ultimo_envio_em ?? $l->created_at)])
            ->filter(fn ($l) => $l['mes'] === null
                || $l['mes'] === $l['em']->format('Y-m')
                // virada do mês: o TikTok pode ainda não liberar o mês novo
                || ($l['em']->day <= 2 && $l['mes'] === $l['em']->copy()->subMonthNoOverflow()->format('Y-m')))
            ->max('em');

        return [
            'chave' => 'tiktok_demonstrativo', 'nome' => 'Demonstrativo do TikTok', 'ultima' => $ultima, 'limite' => $limite,
            'parada' => $ultima === null || $ultima->lt(now()->subHours($limite)), 'detalhe' => null,
            'dica' => 'O agente da Cora Box (C:\\tiktok-agente, tarefa de hora em hora) baixa o demonstrativo no Chrome do agente. '
                .'Veja o sino do admin e C:\\tiktok-agente\\logs\\agente.log: sessão do TikTok caída (entrar de novo no Chrome do agente), '
                .'Chrome travado (fechar e abrir o "Chrome do Agente TikTok") ou arquivo errado recusado pela API.',
        ];
    }

    private function taxasAmazon(int $limite): ?array
    {
        $ultima = Cache::get(TaxasReaisAmazon::CACHE_ULTIMA);
        if (! is_array($ultima)) {
            return null; // ainda não rodou desde o último cache limpo
        }

        $ok = isset($ultima['ultimo_ok']) ? Carbon::parse($ultima['ultimo_ok'])->setTimezone(config('app.timezone')) : null;
        $erro = ($ultima['ok'] ?? false) ? null : 'Última execução falhou: '.Str::limit((string) ($ultima['erro'] ?? 'sem detalhe'), 200);

        return [
            'chave' => 'amazon_taxas', 'nome' => 'Taxas reais da Amazon', 'ultima' => $ok, 'limite' => $limite,
            'parada' => $ok === null || $ok->lt(now()->subHours($limite)), 'detalhe' => $erro,
            'dica' => 'Coleta pela SP-API (amazon:taxas-reais, de hora em hora). Confira as credenciais da Amazon e o storage/logs/laravel.log.',
        ];
    }
}
