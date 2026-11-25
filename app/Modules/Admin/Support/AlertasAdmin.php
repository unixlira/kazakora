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

    public const FRETE_TABELA = 'frete_tabela';

    public const NFE_CANCELAMENTO = 'nfe_cancelamento';

    public const MELHOR_ENVIO_CONEXAO = 'melhor_envio_conexao';

    /** Pedido do Lira em 15/11/2026 (#4198): etiqueta que ainda mostra o Melhor Envio não imprime. */
    public const ETIQUETA_MARCA = 'etiqueta_marca';

    /** Pedido do Lira em 12/11/2026: conciliação fiscal autônoma (fiscal:conciliar). */
    public const FISCAL_CONCILIACAO = 'fiscal_conciliacao';

    /** Pedido do Lira em 24/11/2026: livro de brinde (Promoção PDFs) sem o PDF no disco. */
    public const LIVROS_DIGITAIS = 'livros_digitais';

    /** Pedido do Lira em 24/11/2026 (#4474): pedido Amazon DBA que a API não deixou agendar. */
    public const AMAZON_DBA_AGENDAMENTO = 'amazon_dba_agendamento';

    /** Pedido do Lira em 25/11/2026: gestor de tráfego avisa CPA máximo batido e hora de subir 20%. */
    public const TRAFEGO_META = 'trafego_meta';

    /** @var array<string, array{nome: string, descricao: string}> */
    public const TIPOS = [
        self::COLETA_FINANCEIRA => [
            'nome' => 'Falha na coleta financeira (TikTok e Amazon)',
            'descricao' => 'A rotina da Cora Box que tira o financeiro do TikTok e da Amazon falhou, parou ou não está consolidando. Some sozinho quando o próximo relatório entra.',
        ],
        self::FRETE_TABELA => [
            'nome' => 'Falha na atualização da tabela de frete (Correios)',
            'descricao' => 'A atualização mensal do frete por localidade não conseguiu falar com os Correios (credencial, API fora do ar ou cotações recusadas). Some sozinho quando a tabela termina de atualizar.',
        ],
        self::NFE_CANCELAMENTO => [
            'nome' => 'NF-e de pedido cancelado que não foi cancelada',
            'descricao' => 'O canal cancelou o pedido e o cancelamento automático da nota na SEFAZ deu erro ou não foi possível (nota do Bling cuja autorização a SEFAZ não confirmou). Precisa cancelar dentro das 24h. Some sozinho quando a nota é cancelada pela rotina.',
        ],
        self::FISCAL_CONCILIACAO => [
            'nome' => 'Conciliação fiscal (notas e numeração)',
            'descricao' => 'Conferência das 07:30 e das 19:00 (fiscal:conciliar): venda sem nota, nota de pedido cancelado sem cancelamento ou devolução, devolução parada, número da série sem nota, nota parada que a SEFAZ não conhece e inutilização que a SEFAZ recusou. Cada alerta diz o que o sistema vai fazer ou já fez. Some sozinho quando o problema deixa de aparecer na conferência.',
        ],
        self::ETIQUETA_MARCA => [
            'nome' => 'Etiqueta com a marca do Melhor Envio',
            'descricao' => 'A conferência por OCR ainda achou "melhor envio" na etiqueta depois da troca pelo logo da loja, então ela não foi para a impressora. A etiqueta original fica no pedido para imprimir à mão. Some sozinho quando a etiqueta sai com o logo da loja.',
        ],
        self::LIVROS_DIGITAIS => [
            'nome' => 'Livro digital sem PDF',
            'descricao' => 'Um livro de brinde da Promoção PDFs está ativo (ou foi ganho num pedido) mas o PDF não está no servidor: o e-mail do pedido sai só com o link, que não baixa. Suba o PDF de novo em Entregáveis › Livros Digitais. Some sozinho quando o livros:conferir achar o arquivo.',
        ],
        self::AMAZON_DBA_AGENDAMENTO => [
            'nome' => 'Amazon DBA: agendar no Seller Central',
            'descricao' => 'A Amazon não deixou agendar o pedido do DBA pela API (nem listar horários de Ponto de Envio, nem agendar sem horário). Agende no Seller Central em Ponto de Envio, na data mais próxima, antes do prazo. O sistema confere a cada 30 min e some sozinho quando o pacote aparece agendado; a etiqueta é baixada sozinha.',
        ],
        self::TRAFEGO_META => [
            'nome' => 'Tráfego Meta: desligar ou escalar conjunto',
            'descricao' => 'O gestor de tráfego mede cada conjunto desde o início do ciclo: avisa quando um conjunto gastou o CPA máximo (0 a 0) sem vender (desligar), quando está fraco sem carrinho (baixar 20%) e quando está abaixo do CPA alvo por 2 dias fechados (subir 20%). Mesmo aviso vai por e-mail. Marque como resolvido depois de agir.',
        ],
        self::MELHOR_ENVIO_CONEXAO => [
            'nome' => 'Conexão do Melhor Envio caiu',
            'descricao' => 'A renovação diária do token da API do Melhor Envio falhou: sem ela não sai etiqueta nem cotação pelo Melhor Envio. Some sozinho quando a renovação volta a funcionar.',
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
