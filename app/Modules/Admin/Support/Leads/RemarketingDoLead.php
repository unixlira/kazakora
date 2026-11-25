<?php

namespace App\Modules\Admin\Support\Leads;

use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Cart\Support\Sequencia\CalendarioSequencia;
use App\Modules\Cart\Support\Sequencia\ImportadorSequencia;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Support\WhatsAppNumeros;
use App\Modules\WhatsApp\Support\WhatsAppPhone;
use App\Support\SiteAtual;
use Throwable;

/**
 * Janela "Remarketing" da tela única de Leads (plano do Lira 25/11/2026, §5):
 * a sequência de carrinho abandonado do lead, passo a passo (D+1 ... D+30),
 * com o estado de cada canal (WhatsApp e e-mail). Só leitura: quem manda é o
 * comando loja:carrinho-abandonado-sequencia.
 */
class RemarketingDoLead
{
    public const ENVIADO = 'enviado';

    public const AGENDADO = 'agendado';

    public const PULADO = 'pulado';

    public const FALHOU = 'falhou';

    public const CANAIS = ['whatsapp', 'email'];

    /** @return array<string, mixed> */
    public function montar(PessoaLead $linha): array
    {
        $registro = $linha->registro;

        return [
            'chave' => $linha->chave(),
            'nome' => $linha->nome(),
            'loja' => SiteAtual::SITES[$linha->site()] ?? $linha->site(),
            'itens' => $linha->itens(),
            'total' => $linha->total(),
            'abandonado_em' => ($registro?->abandonado_em ?? $linha->ultimaAtividade())?->toIso8601String(),
            'situacao' => $linha->situacao,
            'sequencia' => $this->estado($linha),
            'disparos' => (int) ($registro?->disparos_enviados ?? 0),
            'total_disparos' => CalendarioSequencia::TOTAL,
            'proximo' => $linha->proximoContato()?->toIso8601String(),
            'passos' => $this->passos($linha),
            'pode_antecipar' => $linha->podeAntecipar,
            'conversa_url' => $this->conversa($linha),
            // Para onde a sequência manda: o contato do registro (o da pessoa, se ainda não entrou).
            'contato' => [
                'whatsapp' => self::semPais($registro?->telefone ?? $linha->telefone()),
                'email' => $registro?->email ?? $linha->email(),
            ],
        ];
    }

    /** @return array{estado: string, texto: string} */
    private function estado(PessoaLead $linha): array
    {
        $registro = $linha->registro;
        if ($registro && ! $registro->parado) {
            return ['estado' => 'andamento', 'texto' => 'Em andamento'];
        }
        if ($registro && $registro->motivo_parada === Registro::PAROU_COMPROU) {
            return ['estado' => 'comprou', 'texto' => 'Comprou'];
        }
        if ($registro) {
            return ['estado' => 'parou', 'texto' => 'Parou — '.(LeadStatusResolver::MOTIVOS[$registro->motivo_parada] ?? $registro->motivo_parada)];
        }

        return ['estado' => 'nao_inscrito', 'texto' => 'Não inscrito — '.$this->porQueNaoInscrito($linha)];
    }

    private function porQueNaoInscrito(PessoaLead $linha): string
    {
        $checkout = $linha->checkout ?? collect($linha->sessoes)->map(fn (LinhaLead $s) => $s->checkout)->filter()->first();
        $ultima = $linha->ultimaAtividade();

        return match (true) {
            ! in_array($linha->site(), ImportadorSequencia::SITES, true) => (SiteAtual::SITES[$linha->site()] ?? $linha->site()).' fica fora da sequência',
            in_array($linha->situacao['codigo'] ?? null, [LeadStatusResolver::PAGO, LeadStatusResolver::AGUARDANDO], true) => 'virou pedido',
            $checkout === null => 'não chegou na Entrega (a sequência usa o contato do "Ir para Entrega")',
            ! filled($checkout->telefone) && ! filled($checkout->email) => 'sem celular nem e-mail',
            $ultima && $ultima->gt(now()->subMinutes(ImportadorSequencia::MINUTOS_ABANDONO)) => 'entra '.ImportadorSequencia::MINUTOS_ABANDONO.' minutos depois de parar (por volta de '
                .$ultima->copy()->addMinutes(ImportadorSequencia::MINUTOS_ABANDONO)->setTimezone(CalendarioSequencia::FUSO)->format('H:i').')',
            $ultima && $ultima->lt(now()->subDays(ImportadorSequencia::DIAS_MAXIMO)) => 'carrinho com mais de '.ImportadorSequencia::DIAS_MAXIMO.' dias',
            default => 'esperando a próxima rodada da sequência',
        };
    }

    /** @return list<array<string, mixed>> */
    private function passos(PessoaLead $linha): array
    {
        $registro = $linha->registro;
        $enviados = (int) ($registro?->disparos_enviados ?? 0);
        $ativo = $registro && ! $registro->parado;
        $historico = $this->historicoDoCiclo($registro);
        $ids = $this->idsDoWhatsApp($registro);
        $contato = ['whatsapp' => filled($registro?->telefone ?? $linha->telefone()), 'email' => filled($registro?->email ?? $linha->email())];
        $semContato = ['whatsapp' => 'sem celular', 'email' => 'sem e-mail'];

        $passos = [];
        foreach (CalendarioSequencia::DIAS as $i => $dia) {
            $numero = $i + 1;
            $atual = $ativo && $numero === $enviados + 1;
            $canais = [];
            foreach (self::CANAIS as $canal) {
                $linhas = collect($historico)->filter(fn ($h) => (int) ($h['numero'] ?? 0) === $numero && ($h['canal'] ?? null) === $canal);
                $enviado = $linhas->where('status', 'enviado')->last();
                $falha = $linhas->where('status', 'falhou')->last();

                $canais[$canal] = match (true) {
                    $enviado !== null => ['estado' => self::ENVIADO, 'quando' => $enviado['quando'] ?? null,
                        'provider_id' => $canal === 'whatsapp' ? ($ids[$numero]['id'] ?? null) : null,
                        // Para quem saiu: o número da conversa da mensagem; sem ela, o do registro (antes de 25/11 o
                        // painel mostrava "enviado" sem dizer o número, mesmo com o lead sem telefone).
                        'para' => $canal === 'whatsapp' ? (self::semPais($ids[$numero]['numero'] ?? null) ?? self::semPais($registro->telefone)) : $registro->email],
                    $falha !== null && ($numero <= $enviados || $atual) => ['estado' => self::FALHOU, 'quando' => $falha['quando'] ?? null,
                        'erro' => $falha['erro'] ?? null, 'nova_tentativa' => $atual ? $registro->proximo_disparo_em?->toIso8601String() : null],
                    $numero <= $enviados => ['estado' => self::PULADO, 'motivo' => $contato[$canal] ? 'não saiu por este canal (modelo do WhatsApp desligado?)' : $semContato[$canal]],
                    $registro === null => ['estado' => self::PULADO, 'motivo' => 'não inscrito na sequência'],
                    ! $ativo => ['estado' => self::PULADO, 'motivo' => 'sequência parada ('.(LeadStatusResolver::MOTIVOS[$registro->motivo_parada] ?? $registro->motivo_parada).')'],
                    ! $contato[$canal] => ['estado' => self::PULADO, 'motivo' => $semContato[$canal]],
                    default => ['estado' => self::AGENDADO, 'quando' => $this->previsto($registro, $numero)],
                };
            }

            $passos[] = ['numero' => $numero, 'dia' => $dia, 'rotulo' => 'D+'.$dia, 'atual' => $atual, 'canais' => $canais];
        }

        return $passos;
    }

    /** Data prevista do disparo $numero: o da vez é o proximo_disparo_em; os seguintes, pelo calendário. */
    private function previsto(Registro $registro, int $numero): ?string
    {
        if ($numero === (int) $registro->disparos_enviados + 1) {
            return $registro->proximo_disparo_em?->toIso8601String();
        }
        if (! $registro->inicio_sequencia_em) {
            return null;
        }

        return CalendarioSequencia::janela($registro->inicio_sequencia_em->copy()->addDays(CalendarioSequencia::dia($numero)))->toIso8601String();
    }

    /** Histórico só do ciclo atual: depois de "reaberto" (comprou e voltou) a contagem recomeça. */
    private function historicoDoCiclo(?Registro $registro): array
    {
        $historico = array_values($registro?->historico ?? []);
        $ultimoReaberto = null;
        foreach ($historico as $i => $h) {
            if (($h['evento'] ?? null) === 'reaberto') {
                $ultimoReaberto = $i;
            }
        }

        return $ultimoReaberto === null ? $historico : array_slice($historico, $ultimoReaberto + 1);
    }

    /** "5511999990000" vira "11999990000" (o painel mostra sem o país). */
    private static function semPais(?string $telefone): ?string
    {
        $digitos = (string) preg_replace('/\D/', '', (string) $telefone);
        if ($digitos === '') {
            return null;
        }

        return strlen($digitos) >= 12 && str_starts_with($digitos, '55') ? substr($digitos, 2) : $digitos;
    }

    /** @return array<int, array{id: ?string, numero: ?string}> número do disparo => wamid e número da conversa */
    private function idsDoWhatsApp(?Registro $registro): array
    {
        if (! $registro) {
            return [];
        }

        try {
            return WhatsAppMessage::query()
                ->with('conversation:id,wa_id,phone')
                ->where('payload->carrinho_sequencia_id', $registro->id)
                ->orderBy('id')
                ->get(['id', 'conversation_id', 'wa_message_id', 'payload'])
                ->mapWithKeys(fn (WhatsAppMessage $m) => [(int) ($m->payload['disparo'] ?? 0) => [
                    'id' => $m->wa_message_id ? (string) $m->wa_message_id : null,
                    'numero' => $m->conversation?->phone ?: $m->conversation?->wa_id,
                ]])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function conversa(PessoaLead $linha): ?string
    {
        $telefone = WhatsAppPhone::normalize($linha->registro?->telefone ?? $linha->telefone());
        if (! $telefone) {
            return null;
        }
        $loja = $linha->site() === SiteAtual::FERRAKORA ? WhatsAppNumeros::FERRAKORA : WhatsAppNumeros::KAZAKORA;
        $conversa = WhatsAppConversation::doNumero($telefone, $loja);

        return $conversa ? '/admin/whatsapp/conversas?c='.$conversa->id : null;
    }
}
