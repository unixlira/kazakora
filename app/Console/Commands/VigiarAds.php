<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Financeiro\Support\RadarDeAds;
use App\Notifications\AdsAcimaDoLimiteNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Sino do admin para o ADS acima de 6% da receita (2026-10-19), com a mesma
 * cadência do vigia do Homer: avisa quando o canal passa do limite, repete
 * no máximo a cada 24 h enquanto continuar acima e avisa quando volta. O
 * agendamento só roda das 8h às 22h (America/Sao_Paulo).
 */
class VigiarAds extends Command
{
    protected $signature = 'financeiro:vigiar-ads {--seco : Mostra o que avisaria, sem notificar nem gravar}';

    protected $description = 'Notifica no sino do admin quando o ADS de um canal passa de 6% da receita (mês ou 7 dias)';

    public const ESTADO = 'financeiro:vigiar-ads:abertos';

    private const REPETIR_HORAS = 24;

    public function handle(RadarDeAds $radar): int
    {
        $dados = $radar->calcular();
        $seco = (bool) $this->option('seco');
        /** @var array<string, string> $abertos canal => ISO do último aviso */
        $abertos = Cache::get(self::ESTADO, []);
        $limite = $this->pct($dados['limite']);

        $avisos = [];
        foreach ($dados['acima'] as $c) {
            $ultimo = $abertos[$c['canal']] ?? null;
            if ($ultimo !== null && Carbon::parse($ultimo)->gt(now()->subHours(self::REPETIR_HORAS))) {
                $this->line("{$c['nome']}: acima, já avisado em {$ultimo}");

                continue;
            }
            $avisos[] = new AdsAcimaDoLimiteNotification(
                "ADS {$c['nome']} ".($ultimo ? 'continua' : 'passou')." de {$limite} da receita",
                $this->detalhe($c),
                $c['link']['href'],
            );
            $abertos[$c['canal']] = now()->toIso8601String();
            $this->warn("{$c['nome']}: {$this->detalhe($c)}");
        }

        $acima = array_column($dados['acima'], 'canal');
        $nomes = array_column($dados['canais'], 'nome', 'canal');
        foreach (array_keys($abertos) as $canal) {
            // Meta sem resposta agora não conta como "voltou".
            if (in_array($canal, $acima, true) || ($canal === RadarDeAds::META && ! $dados['meta']['disponivel'])) {
                continue;
            }
            $nome = $nomes[$canal] ?? $canal;
            $avisos[] = new AdsAcimaDoLimiteNotification(
                "ADS {$nome} voltou para {$limite} da receita ou menos",
                'Mês e últimos 7 dias dentro do limite.',
                RadarDeAds::LINKS[$canal]['href'] ?? '/admin/publicidade',
                voltou: true,
            );
            unset($abertos[$canal]);
            $this->info("{$nome}: voltou abaixo do limite");
        }

        if ($seco) {
            $this->line(count($avisos).' aviso(s) seriam enviados.');

            return self::SUCCESS;
        }

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();
        foreach ($avisos as $aviso) {
            if ($admins->isNotEmpty()) {
                Notification::send($admins, $aviso);
            }
        }
        Cache::forever(self::ESTADO, $abertos);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $c */
    private function detalhe(array $c): string
    {
        $parte = function (array $p, string $rotulo) {
            if ($p['ads'] <= 0) {
                return "{$rotulo}: sem ADS";
            }
            if ($p['pct'] === null) {
                return "{$rotulo}: ADS {$this->moeda($p['ads'])} sem nenhuma venda";
            }

            return "{$rotulo}: {$this->pct($p['pct'])} (ADS {$this->moeda($p['ads'])} de {$this->moeda($p['receita'])})";
        };

        return $parte($c['mes'], 'No mês').'; '.$parte($c['semana'], 'últimos 7 dias').'.';
    }

    private function moeda(float $v): string
    {
        return 'R$ '.number_format($v, 2, ',', '.');
    }

    private function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',').'%';
    }
}
