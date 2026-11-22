<?php

namespace App\Modules\Cart\Support\Sequencia;

use App\Modules\Cart\Mail\SequenciaCarrinho;
use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Catalog\Models\Product;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\WhatsAppCloudApiClient;
use App\Modules\WhatsApp\Support\WhatsAppNumeros;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use App\Support\SiteAtual;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Manda o disparo da vez de um carrinho da sequência (pedido do Lira
 * 21/11/2026), por WhatsApp (template aprovado da WABA da loja) e por
 * e-mail, e grava tudo no histórico. Template vazio no .env = WhatsApp não
 * sai, só o e-mail, sem erro. FerraKora sai só pelo número da FerraKora.
 */
class DisparadorSequencia
{
    /** Tentativas do mesmo disparo com todos os canais falhando antes de pular para o próximo. */
    public const TENTATIVAS = 3;

    public function __construct(
        private readonly WhatsAppCloudApiClient $whatsapp,
        private readonly WhatsAppSettings $settings,
        private readonly LinkDoCarrinho $links,
        private readonly NoticiaDoProduto $noticias,
    ) {
    }

    /** Template da loja para o disparo $numero, ou null (WhatsApp desligado para ele). */
    public function templateWhatsApp(Registro $registro, int $numero): ?array
    {
        $loja = $registro->site === SiteAtual::FERRAKORA ? WhatsAppNumeros::FERRAKORA : WhatsAppNumeros::KAZAKORA;
        $chave = $numero === 1 ? 'carrinho_lembrete' : 'carrinho_novidade';
        $nome = WhatsAppNumeros::template($loja, $chave);

        // FerraKora: o número dela tem que estar configurado (normalizar() cai na KazaKora se não estiver).
        if (! filled($nome) || WhatsAppNumeros::normalizar($loja) !== $loja || ! $this->settings->isReadyToSend($loja)) {
            return null;
        }

        return ['loja' => $loja, 'nome' => $nome, 'idioma' => (string) WhatsAppNumeros::template($loja, $chave.'_idioma', 'pt_BR')];
    }

    /** Canais que sairiam agora: ['whatsapp', 'email']. */
    public function canais(Registro $registro, int $numero): array
    {
        return array_values(array_filter([
            $registro->telefone && $this->templateWhatsApp($registro, $numero) ? 'whatsapp' : null,
            $registro->email ? 'email' : null,
        ]));
    }

    /**
     * Prévia do disparo da vez, sem mandar nem gastar Gemini (--simular).
     *
     * @return array{numero: int, dia: int, canais: list<string>, produto: string, link: string}
     */
    public function previa(Registro $registro): array
    {
        $numero = $registro->disparos_enviados + 1;
        $produto = $this->produto($registro);
        $canais = $this->canais($registro, $numero);

        return [
            'numero' => $numero,
            'dia' => CalendarioSequencia::dia($numero),
            'canais' => $canais,
            'produto' => $this->nomeProduto($registro, $produto),
            'link' => $this->links->resolver($registro->site, $produto, $numero, $canais[0] ?? 'email')['url'],
        ];
    }

    /** @return string enviado|sem_canal|falhou */
    public function disparar(Registro $registro): string
    {
        $numero = $registro->disparos_enviados + 1;
        $canais = $this->canais($registro, $numero);

        if ($canais === []) {
            // Só celular e o template ainda não está no .env: espera, sem gastar a vez.
            $registro->forceFill(['proximo_disparo_em' => CalendarioSequencia::janela(now()->addDay())])->save();

            return 'sem_canal';
        }

        $produto = $this->produto($registro);
        $nomeProduto = $this->nomeProduto($registro, $produto);
        $noticia = $numero === 1 ? null : $this->noticias->escrever($produto, $numero);
        $imagem = $this->imagem($registro, $produto);
        $base = ['numero' => $numero, 'dia' => CalendarioSequencia::dia($numero), 'assunto' => $numero === 1 ? 'lembrete' : NoticiaDoProduto::assunto($numero)];
        if ($noticia) {
            $base += ['texto' => $noticia['texto'], 'fonte_texto' => $noticia['fonte']];
        }

        $ok = false;
        foreach ($canais as $canal) {
            $link = $this->links->resolver($registro->site, $produto, $numero, $canal);
            try {
                if ($canal === 'whatsapp') {
                    $this->mandarWhatsApp($registro, $numero, $nomeProduto, $noticia['texto'] ?? null, $imagem, $link);
                } else {
                    Mail::to($registro->email)->send(new SequenciaCarrinho($registro, $numero, $nomeProduto, $link['url'], $noticia['texto'] ?? null, $imagem));
                }
                $registro->anotar($base + ['canal' => $canal, 'status' => 'enviado', 'link' => $link['url'], 'link_tipo' => $link['tipo']]);
                $ok = true;
            } catch (Throwable $e) {
                Log::warning('carrinho_sequencia_falhou', ['registro' => $registro->id, 'canal' => $canal, 'erro' => mb_substr($e->getMessage(), 0, 300)]);
                $registro->anotar($base + ['canal' => $canal, 'status' => 'falhou', 'erro' => mb_substr($e->getMessage(), 0, 300), 'link' => $link['url']]);
            }
        }

        $falhasDoNumero = collect($registro->historico ?? [])->where('numero', $numero)->where('status', 'falhou')->count();
        if (! $ok && $falhasDoNumero < self::TENTATIVAS * count($canais)) {
            $registro->forceFill(['proximo_disparo_em' => CalendarioSequencia::janela(now()->addHour())])->save();

            return 'falhou';
        }

        $registro->forceFill(['disparos_enviados' => $numero, 'ultimo_disparo_em' => now()]);
        $registro->proximo_disparo_em = CalendarioSequencia::proximoEm($registro);
        $registro->save();

        if ($numero >= CalendarioSequencia::TOTAL) {
            $registro->parar(Registro::PAROU_FIM);
        }

        return $ok ? 'enviado' : 'falhou';
    }

    private function mandarWhatsApp(Registro $registro, int $numero, string $nomeProduto, ?string $texto, ?string $imagem, array $link): void
    {
        $template = $this->templateWhatsApp($registro, $numero);
        $cliente = $this->whatsapp->paraLoja($template['loja']);
        $nome = $registro->primeiroNome();
        // Variável de template da Meta não aceita quebra de linha nem tab.
        $produto = (string) preg_replace('/\s+/u', ' ', $nomeProduto);

        $resposta = $numero === 1
            ? $cliente->sendTemplate($registro->telefone, $template['nome'], $template['idioma'],
                bodyParams: [$nome, $produto],
                urlButtonParams: [0 => $link['sufixo']])
            : $cliente->sendTemplate($registro->telefone, $template['nome'], $template['idioma'],
                headerMediaType: 'image',
                bodyParams: [$nome, $produto, (string) $texto],
                urlButtonParams: [0 => $link['sufixo']],
                headerMediaLink: $imagem);

        // Aparece no histórico da conversa no painel do WhatsApp (falha aqui não desfaz o envio).
        try {
            $conversa = WhatsAppConversation::paraNumero($registro->telefone, $template['loja'], [
                'phone' => $registro->telefone,
                'profile_name' => $registro->nome,
                'last_message_at' => now(),
                'unread_count' => 0,
            ]);
            $mensagem = WhatsAppMessage::query()->create([
                'conversation_id' => $conversa->id,
                'wa_message_id' => $resposta['messages'][0]['id'] ?? null,
                'direction' => 'outbound',
                'type' => 'template',
                'body' => $numero === 1
                    ? "Carrinho abandonado (lembrete): {$produto} {$link['url']}"
                    : "Carrinho abandonado (novidade {$numero}): {$produto} — {$texto} {$link['url']}",
                'status' => 'sent',
                'sent_by' => 'system',
                'payload' => ['template' => $template['nome'], 'carrinho_sequencia_id' => $registro->id, 'disparo' => $numero],
                'sent_at' => now(),
            ]);
            $conversa->registerMessage($mensagem);
        } catch (Throwable $e) {
            Log::info('carrinho_sequencia_conversa_nao_registrada', ['registro' => $registro->id, 'erro' => $e::class]);
        }
    }

    private function produto(Registro $registro): ?Product
    {
        return $registro->produto_id
            ? Product::withTrashed()->with(['category', 'images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position')])->find($registro->produto_id)
            : null;
    }

    private function nomeProduto(Registro $registro, ?Product $produto): string
    {
        $nome = $produto?->name ?: (collect($registro->itens ?? [])->firstWhere('product_id', $registro->produto_id)['nome'] ?? null);

        return NoticiaDoProduto::nomeCurto($nome ? new Product(['name' => $nome]) : null);
    }

    private function imagem(Registro $registro, ?Product $produto): string
    {
        $foto = $produto?->images->first()?->url;

        if (! $foto && $produto?->parent_product_id) {
            $foto = Product::withTrashed()->with(['images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position')])
                ->find($produto->parent_product_id)?->images->first()?->url;
        }

        return $foto ?: asset($registro->site === SiteAtual::FERRAKORA
            ? 'images/whatsapp/confirmacao-pedido-ferrakora-v3.jpg'
            : 'images/whatsapp/confirmacao-pedido.jpg');
    }
}
