<?php

namespace App\Modules\Admin\Support;

use App\Models\Setting;
use App\Models\User;
use App\Modules\Admin\Models\AlertaAdmin;
use App\Notifications\AlertaAdminNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Alertas do painel do admin para o Lira (pedido 2026-10-16): o que precisa
 * de ação dele vira notificação no sino do admin (AlertaAdminNotification,
 * uma vez quando abre) e fica em Sistema › Alertas até alguém marcar como
 * resolvido ou o próprio sistema ver que voltou a funcionar. Desde
 * 2026-10-17 não tem mais faixa no meio das telas: o Lira achou invasivo.
 *
 * Cada tipo pode ser ligado ou desligado na tela (Setting
 * "alertas_admin.<tipo>.ativo"). Para criar um tipo novo, acrescente em TIPOS
 * e chame disparar() de onde o problema é detectado.
 */
class AlertasAdmin
{
    public const COLETA_FINANCEIRA = 'coleta_financeira';

    /** @var array<string, array{nome: string, descricao: string}> */
    public const TIPOS = [
        self::COLETA_FINANCEIRA => [
            'nome' => 'Falha na coleta financeira (TikTok e Amazon)',
            'descricao' => 'A rotina da Cora Box que tira o financeiro do TikTok e da Amazon falhou, parou ou não está consolidando. Some sozinho quando o próximo relatório entra.',
        ],
    ];

    public function ativo(string $tipo): bool
    {
        return isset(self::TIPOS[$tipo]) && Setting::get("alertas_admin.{$tipo}.ativo", '1') === '1';
    }

    public function definirAtivo(string $tipo, bool $ativo): void
    {
        Setting::set("alertas_admin.{$tipo}.ativo", $ativo ? '1' : '0');
    }

    /**
     * Abre o alerta ou, se o mesmo (tipo + chave) já está aberto, atualiza o
     * texto e soma uma ocorrência.
     */
    public function disparar(string $tipo, string $chave, string $titulo, string $mensagem, ?string $link = null): ?AlertaAdmin
    {
        if (! $this->ativo($tipo)) {
            return null;
        }

        $mensagem = Str::limit(trim($mensagem), 1000);
        $aberto = AlertaAdmin::query()->abertos()->where('tipo', $tipo)->where('chave', $chave)->latest('id')->first();

        if ($aberto) {
            $aberto->forceFill(['titulo' => $titulo, 'mensagem' => $mensagem, 'link' => $link ?? $aberto->link, 'ultima_em' => now()])->save();
            $aberto->increment('ocorrencias');

            return $aberto;
        }

        $alerta = AlertaAdmin::query()->create([
            'tipo' => $tipo,
            'chave' => $chave,
            'titulo' => Str::limit($titulo, 200, ''),
            'mensagem' => $mensagem,
            'link' => $link,
            'primeira_em' => now(),
            'ultima_em' => now(),
        ]);

        rescue(fn () => Notification::send(
            User::query()->where('role', User::ROLE_ADMIN)->get(),
            new AlertaAdminNotification($alerta->id, $alerta->titulo, $mensagem, $link),
        ), report: false);

        return $alerta;
    }

    /** Fecha os alertas abertos do tipo + chave (o problema passou). */
    public function resolver(string $tipo, string $chave, string $por = 'sistema'): int
    {
        $abertos = AlertaAdmin::query()->abertos()->where('tipo', $tipo)->where('chave', $chave);
        $this->tirarDoSino($abertos->pluck('id')->all());

        return $abertos->update(['resolvido_em' => now(), 'resolvido_por' => $por, 'updated_at' => now()]);
    }

    /**
     * Alerta resolvido sai do sino (marca lida a notificação ainda não lida).
     *
     * @param  list<int>  $ids
     */
    public function tirarDoSino(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        rescue(fn () => DatabaseNotification::query()
            ->where('type', AlertaAdminNotification::class)
            ->whereNull('read_at')
            ->whereIn('data->alerta_id', $ids)
            ->update(['read_at' => now()]), report: false);
    }
}
