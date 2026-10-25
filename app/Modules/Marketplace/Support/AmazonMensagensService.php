<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Marketplace\Models\MarketplaceChatConversa;
use App\Modules\Marketplace\Models\MarketplaceChatMensagem;
use App\Services\Email\EmailLido;
use App\Services\Email\ImapCliente;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Mensagens de comprador da Amazon na aba "Amazon" de Perguntas MK Place
 * (pedido do Lira 2026-10-25). A SP-API não deixa ler mensagem de comprador
 * (a Messaging API só manda modelos prontos), então o caminho é o e-mail: a
 * Amazon encaminha cada mensagem do comprador, vinda de um endereço anônimo
 * @marketplace.amazon.com.br, para o e-mail cadastrado na Seller Central; a
 * resposta que sai de um e-mail AUTORIZADO lá ("Permissões de mensagens")
 * chega ao comprador e aparece em Mensagens comprador-vendedor.
 *
 * Leitura por IMAP (ImapCliente, sem extensão), envio pelo mailer
 * amazon_mensagens (SMTP do mesmo e-mail). Nunca responde sozinho.
 * Pergunta da página do produto (antes da compra) não passa por aqui.
 */
class AmazonMensagensService
{
    public const LIMITE_TEXTO = 4000;

    private const ESTADO = 'amazon-mensagens:imap';

    private const POR_PASSE = 100;

    /** @return list<string> o que falta no .env para ligar a aba */
    public static function faltando(): array
    {
        $c = config('services.amazon_mensagens');
        $falta = [];
        if (blank($c['email'] ?? null)) {
            $falta[] = 'AMAZON_MENSAGENS_EMAIL (e-mail autorizado na Seller Central)';
        }
        if (blank($c['imap_host'] ?? null) || blank($c['imap_senha'] ?? null)) {
            $falta[] = 'AMAZON_MENSAGENS_IMAP_HOST e AMAZON_MENSAGENS_SENHA (acesso à caixa)';
        }
        if (blank(config('mail.mailers.amazon_mensagens.host'))) {
            $falta[] = 'AMAZON_MENSAGENS_SMTP_HOST (envio da resposta)';
        }

        return $falta;
    }

    public static function configurado(): bool
    {
        return self::faltando() === [];
    }

    /** @return array{novas: int, falhas: int} */
    public function sincronizar(?int $dias = null): array
    {
        $r = ['novas' => 0, 'falhas' => 0];
        $c = config('services.amazon_mensagens');

        $imap = app(ImapCliente::class);
        $imap->conectar($c['imap_host'], (int) $c['imap_porta'], (bool) $c['imap_ssl']);
        try {
            $imap->login($c['imap_usuario'] ?: $c['email'], $c['imap_senha']);
            $validade = $imap->selecionar($c['imap_pasta'] ?: 'INBOX');

            $estado = Cache::get(self::ESTADO, []);
            $ultimo = ($estado['validade'] ?? null) === $validade ? (int) ($estado['uid'] ?? 0) : 0;
            $filtro = 'FROM "marketplace.amazon"';
            $criterio = $ultimo > 0 && $dias === null
                ? 'UID '.($ultimo + 1).':* '.$filtro
                : 'SINCE '.now()->subDays($dias ?? (int) $c['dias_inicio'])->format('d-M-Y').' '.$filtro;

            $uids = array_values(array_filter($imap->buscarUids($criterio), fn (int $u) => $u > $ultimo || $dias !== null));
            $maior = $ultimo;
            foreach (array_slice($uids, 0, self::POR_PASSE) as $uid) {
                try {
                    $cru = $imap->baixar($uid);
                    if ($cru !== null && $this->registrar(EmailLido::de($cru))) {
                        $r['novas']++;
                    }
                } catch (Throwable $e) {
                    $r['falhas']++;
                    Log::channel('amazon')->warning('amazon-mensagens: e-mail não lido', ['uid' => $uid, 'erro' => $e->getMessage()]);
                }
                $maior = max($maior, $uid);
            }

            Cache::forever(self::ESTADO, ['validade' => $validade, 'uid' => $maior]);
        } finally {
            $imap->sair();
        }

        return $r;
    }

    /** Grava o e-mail se for mensagem de comprador ainda não vista. */
    public function registrar(EmailLido $email): bool
    {
        $de = $email->remetente('reply-to');
        if (! str_contains((string) $de['email'], '@marketplace.amazon.')) {
            $de = $email->remetente('from');
        }
        if (! str_contains((string) $de['email'], '@marketplace.amazon.')) {
            return false;
        }

        $assunto = $email->assunto();
        $externo = $email->messageId() ?? 'sem-id-'.sha1($de['email'].$assunto.$email->texto);
        if (MarketplaceChatMensagem::query()->where('canal', MarketplaceChatConversa::AMAZON)->where('externo_id', Str::limit($externo, 191, ''))->exists()) {
            return false;
        }

        $texto = self::mensagemDoCorpo($email->texto);
        $quando = $email->data() ?? now();
        $pedido = preg_match('/\b(\d{3}-\d{7}-\d{7})\b/', $assunto.' '.$email->texto, $m) ? $m[1] : null;
        $nome = $de['nome'] ? trim(preg_replace('/\s*-\s*Amazon.*$/i', '', $de['nome'])) : null;

        $conversa = MarketplaceChatConversa::query()->firstOrNew(['canal' => MarketplaceChatConversa::AMAZON, 'externo_id' => $de['email']]);
        $conversa->fill([
            'comprador_nome' => $nome ?: $conversa->comprador_nome,
            'pedido_numero' => $pedido ?? $conversa->pedido_numero,
            'assunto' => Str::limit($assunto, 490, ''),
            'sincronizada_em' => now(),
        ]);
        if (! $conversa->ultima_mensagem_em || $quando->gte($conversa->ultima_mensagem_em)) {
            $conversa->fill(['ultima_mensagem_texto' => Str::limit($texto, 300), 'ultima_do_cliente' => true, 'ultima_mensagem_em' => $quando]);
        }
        $conversa->nao_lidas = (int) $conversa->nao_lidas + 1;
        $conversa->save();

        $conversa->mensagens()->create([
            'canal' => MarketplaceChatConversa::AMAZON,
            'externo_id' => Str::limit($externo, 191, ''),
            'tipo' => 'text',
            'do_cliente' => true,
            'texto' => $texto,
            'conteudo' => ['assunto' => $assunto, 'pedido' => $pedido],
            'enviada_em' => $quando,
        ]);

        return true;
    }

    /** Resposta do atendente: sai por SMTP do e-mail autorizado para o endereço anônimo do comprador. */
    public function enviar(MarketplaceChatConversa $conversa, string $texto, int $userId): MarketplaceChatMensagem
    {
        if (! self::configurado()) {
            throw new RuntimeException('A caixa de e-mail da Amazon ainda não está configurada.');
        }

        $c = config('services.amazon_mensagens');
        $assunto = $conversa->assunto ?: ($conversa->pedido_numero ? 'Pedido '.$conversa->pedido_numero : 'Sua mensagem');
        $assunto = preg_match('/^\s*(re|res)\s*:/i', $assunto) ? $assunto : 'Re: '.$assunto;

        $enviada = Mail::mailer('amazon_mensagens')->raw($texto, function (Message $m) use ($c, $conversa, $assunto) {
            $m->from($c['email'], $c['nome'])->to($conversa->externo_id)->subject($assunto);
        });

        $id = $enviada?->getMessageId() ?: 'enviada-'.Str::uuid();
        $mensagem = $conversa->mensagens()->create([
            'canal' => MarketplaceChatConversa::AMAZON,
            'externo_id' => Str::limit(trim($id, '<>'), 191, ''),
            'tipo' => 'text',
            'do_cliente' => false,
            'texto' => $texto,
            'conteudo' => ['assunto' => $assunto],
            'enviada_em' => now(),
            'enviada_por_user_id' => $userId,
        ]);
        $conversa->update(['ultima_mensagem_texto' => Str::limit($texto, 300), 'ultima_do_cliente' => false, 'ultima_mensagem_em' => now(), 'nao_lidas' => 0]);

        return $mensagem;
    }

    /**
     * Só o que o comprador escreveu: a Amazon põe a mensagem entre
     * "------------- Mensagem: -------------" e "------------- Fim da
     * mensagem -------------" (ou Message / End message); sem as marcas,
     * corta na primeira citação de e-mail anterior.
     */
    public static function mensagemDoCorpo(string $corpo): string
    {
        $corpo = str_replace("\r\n", "\n", $corpo);
        if (preg_match('/-{5,}\s*(?:Mensagem|Message)\s*:?\s*-{5,}(.*?)-{5,}\s*(?:Fim da mensagem|End message)/is', $corpo, $m)) {
            return Str::limit(trim($m[1]), self::LIMITE_TEXTO * 2, '');
        }

        $linhas = [];
        foreach (explode("\n", $corpo) as $l) {
            if (preg_match('/^\s*(>|Em .+escreveu:|On .+wrote:|-{5,}\s*(Mensagem original|Original message))/i', $l)) {
                break;
            }
            $linhas[] = $l;
        }

        return Str::limit(trim(implode("\n", $linhas)), self::LIMITE_TEXTO * 2, '');
    }
}
