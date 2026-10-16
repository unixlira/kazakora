<?php

namespace App\Modules\Admin\Support;

use App\Models\Setting;
use App\Modules\Admin\Models\AlertaAdmin;
use Illuminate\Support\Str;

/**
 * Notificações do painel do admin para o Lira (pedido 2026-10-16): o que
 * precisa de ação dele aparece numa faixa vermelha no topo de todas as telas
 * do admin (AdminLayout.vue) e em Sistema › Alertas, até alguém marcar como
 * resolvido ou o próprio sistema ver que voltou a funcionar.
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

        return AlertaAdmin::query()->create([
            'tipo' => $tipo,
            'chave' => $chave,
            'titulo' => Str::limit($titulo, 200, ''),
            'mensagem' => $mensagem,
            'link' => $link,
            'primeira_em' => now(),
            'ultima_em' => now(),
        ]);
    }

    /** Fecha os alertas abertos do tipo + chave (o problema passou). */
    public function resolver(string $tipo, string $chave, string $por = 'sistema'): int
    {
        return AlertaAdmin::query()->abertos()->where('tipo', $tipo)->where('chave', $chave)
            ->update(['resolvido_em' => now(), 'resolvido_por' => $por, 'updated_at' => now()]);
    }

    /** @return list<array<string, mixed>> */
    public function abertosParaTela(int $limite = 5): array
    {
        return AlertaAdmin::query()->abertos()->latest('ultima_em')->limit($limite)->get()
            ->map(fn (AlertaAdmin $a) => $a->paraTela())->all();
    }
}
