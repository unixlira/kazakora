<?php

namespace App\Modules\Cart\Support\Sequencia;

use App\Modules\Cart\Mail\CadernoDoCarrinho;
use App\Modules\Cart\Mail\SequenciaCarrinho;
use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Catalog\Models\Product;
use App\Modules\Newsletter\Models\NewsletterInscrito;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\WhatsAppCloudApiClient;
use App\Modules\WhatsApp\Services\WhatsAppModelos;
use App\Modules\WhatsApp\Support\WhatsAppNumeros;
use App\Modules\WhatsApp\Support\WhatsAppPhone;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use App\Support\SiteAtual;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Manda o disparo da vez de um carrinho da sequência e grava tudo no
 * histórico. Desde 26/11/2026 é UM canal por disparo (CalendarioSequencia::ETAPAS):
 * D+1, D+3 e D+7 pelo WhatsApp (template aprovado da WABA da loja; quem não
 * tem celular recebe por e-mail) e D+5 o e-mail do Caderno KazaKora com a
 * matéria do blog ligada ao produto. Quem saiu da lista do Caderno não recebe
 * e-mail nenhum (o D+5 é pulado). Nunca dois disparos de carrinho no mesmo
 * dia para a mesma pessoa, nem entre registros/lojas diferentes.
 */
class DisparadorSequencia
{
    /** Tentativas do mesmo disparo com o canal falhando antes de pular para o próximo. */
    public const TENTATIVAS = 3;

    /** Texto do último WhatsApp (D+7). Variável de template: sem quebra de linha. */
    public const TEXTO_ULTIMO = 'Esta é a nossa última mensagem sobre ele: o seu carrinho continua salvo e, se ficou alguma dúvida, é só responder aqui que a gente ajuda.';

    public function __construct(
        private readonly WhatsAppCloudApiClient $whatsapp,
        private readonly WhatsAppSettings $settings,
        private readonly LinkDoCarrinho $links,
        private readonly NoticiaDoProduto $noticias,
        private readonly MateriaDoCarrinho $materias,
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

    /** A pessoa saiu da lista do Caderno (newsletter)? Então não recebe e-mail de carrinho. */
    public static function saiuDoCaderno(?string $email): bool
    {
        if (! filled($email)) {
            return false;
        }

        try {
            return NewsletterInscrito::query()
                ->where('email', NewsletterInscrito::normalizarEmail((string) $email))
                ->where('status', NewsletterInscrito::DESCADASTRADO)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** Canal que sairia agora para o disparo $numero: [] ou ['whatsapp'] ou ['email']. */
    public function canais(Registro $registro, int $numero): array
    {
        $email = $registro->email && ! self::saiuDoCaderno($registro->email);

        if (CalendarioSequencia::canal($numero) === 'email') {
            return $email ? ['email'] : [];
        }

        if ($registro->telefone && $this->templateWhatsApp($registro, $numero)) {
            return ['whatsapp'];
        }

        return $email ? ['email'] : [];
    }

    /**
     * Por que o disparo $numero não tem canal: 'pular' (o D+5 sem e-mail ou de
     * quem saiu do Caderno: vai para o próximo) ou 'esperar' (só celular e o
     * template ainda não está no .env).
     */
    private function semCanal(Registro $registro, int $numero): string
    {
        if (CalendarioSequencia::canal($numero) === 'email') {
            return 'pular';
        }

        return $registro->telefone && ! $this->templateWhatsApp($registro, $numero) ? 'esperar' : 'pular';
    }

    /**
     * Prévia do disparo da vez, sem mandar nem gastar Gemini (--simular).
     *
     * @return array{numero: int, dia: int, canais: list<string>, produto: string, link: string, etapa: string}
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
            'etapa' => CalendarioSequencia::ETAPAS[min($numero, CalendarioSequencia::TOTAL)]['rotulo'],
        ];
    }

    /**
     * Tudo o que falta para o carrinho, com data e canal, sem mandar nada.
     *
     * @return list<array{numero: int, dia: int, quando: \Illuminate\Support\Carbon, canal: ?string, etapa: string, detalhe: string}>
     */
    public function previaCompleta(Registro $registro): array
    {
        if ($registro->parado || ! $registro->inicio_sequencia_em) {
            return [];
        }

        $produto = $this->produto($registro);
        $passos = [];
        $ultimo = $registro->ultimo_disparo_em;
        for ($numero = $registro->disparos_enviados + 1; $numero <= CalendarioSequencia::TOTAL; $numero++) {
            $quando = $numero === $registro->disparos_enviados + 1 && $registro->proximo_disparo_em
                ? $registro->proximo_disparo_em->copy()
                : CalendarioSequencia::quando($registro->inicio_sequencia_em, $numero, $ultimo);
            $canal = $this->canais($registro, $numero)[0] ?? null;
            $detalhe = match (true) {
                $canal === null => $this->semCanal($registro, $numero) === 'pular'
                    ? (self::saiuDoCaderno($registro->email) ? 'pulado: saiu da lista do Caderno' : 'pulado: sem e-mail')
                    : 'espera o template do WhatsApp',
                CalendarioSequencia::tipo($numero) === 'caderno' => 'matéria: '.($this->materias->escolher($registro->site, $produto)['titulo'] ?? 'nenhuma no blog (vai só o produto)'),
                $canal === 'email' => 'por e-mail (sem celular)',
                default => '',
            };
            $passos[] = [
                'numero' => $numero,
                'dia' => CalendarioSequencia::dia($numero),
                'quando' => $quando,
                'canal' => $canal,
                'etapa' => CalendarioSequencia::ETAPAS[$numero]['rotulo'],
                'detalhe' => $detalhe,
            ];
            if ($canal !== null) {
                $ultimo = $quando;
            }
        }

        return $passos;
    }

    /**
     * Outro carrinho (de qualquer loja) da mesma pessoa já recebeu mensagem
     * hoje? Então este espera até amanhã (nunca 2 no mesmo dia).
     */
    public function pessoaJaRecebeuHoje(Registro $registro): bool
    {
        $hoje = now()->setTimezone(CalendarioSequencia::FUSO)->startOfDay()->setTimezone(config('app.timezone'));

        if ($registro->ultimo_disparo_em && CalendarioSequencia::mesmoDia($registro->ultimo_disparo_em, now())) {
            return true;
        }

        $final = substr((string) preg_replace('/\D/', '', (string) $registro->telefone), -8);

        return Registro::query()
            ->whereKeyNot($registro->id)
            ->where('ultimo_disparo_em', '>=', $hoje)
            ->where(fn ($q) => $q
                ->when($registro->email, fn ($q) => $q->orWhere('email', $registro->email))
                ->when(strlen($final) === 8, fn ($q) => $q->orWhere('telefone', 'like', '%'.$final))
                ->when($registro->user_id, fn ($q) => $q->orWhere('user_id', $registro->user_id)))
            ->get(['id', 'email', 'telefone', 'user_id'])
            ->contains(fn (Registro $outro) => ($registro->email && $outro->email === $registro->email)
                || ($registro->user_id && (int) $outro->user_id === (int) $registro->user_id)
                || ($registro->telefone && $outro->telefone && WhatsAppPhone::mesmoNumero($registro->telefone, [$outro->telefone])));
    }

    /** @return string enviado|sem_canal|falhou|adiado|pulado */
    public function disparar(Registro $registro): string
    {
        $numero = $registro->disparos_enviados + 1;
        if ($numero > CalendarioSequencia::TOTAL) {
            $registro->parar(Registro::PAROU_FIM);

            return 'pulado';
        }

        if ($this->pessoaJaRecebeuHoje($registro)) {
            $registro->forceFill(['proximo_disparo_em' => CalendarioSequencia::janela(CalendarioSequencia::amanha(now()))])->save();

            return 'adiado';
        }

        $canais = $this->canais($registro, $numero);

        if ($canais === []) {
            if ($this->semCanal($registro, $numero) === 'esperar') {
                // Só celular e o template ainda não está no .env: espera, sem gastar a vez.
                $registro->forceFill(['proximo_disparo_em' => CalendarioSequencia::janela(now()->addDay())])->save();

                return 'sem_canal';
            }

            // D+5 sem e-mail (ou de quem saiu do Caderno): passa para o próximo sem mandar nada.
            $registro->anotar(['numero' => $numero, 'dia' => CalendarioSequencia::dia($numero), 'canal' => CalendarioSequencia::canal($numero),
                'status' => 'pulado', 'motivo' => self::saiuDoCaderno($registro->email) ? 'saiu do Caderno' : 'sem contato para este canal']);
            $this->avancar($registro, $numero, false);

            return 'pulado';
        }

        $canal = $canais[0];
        $tipo = CalendarioSequencia::tipo($numero);
        $produto = $this->produto($registro);
        $nomeProduto = $this->nomeProduto($registro, $produto);
        $imagem = $this->imagem($registro, $produto);
        $link = $this->links->resolver($registro->site, $produto, $numero, $canal);
        $base = ['numero' => $numero, 'dia' => CalendarioSequencia::dia($numero), 'assunto' => $tipo, 'canal' => $canal];

        try {
            if ($tipo === 'caderno') {
                $materia = $this->materias->escolher($registro->site, $produto);
                Mail::to($registro->email)->send(new CadernoDoCarrinho(
                    $registro,
                    $materia ? Arr::except($materia, ['post', 'pontos']) : null,
                    $nomeProduto,
                    $link['url'],
                    $imagem,
                    $this->linkSair($registro),
                ));
                $base += $materia ? ['materia_id' => $materia['post']->id, 'materia' => $materia['titulo'], 'materia_pontos' => $materia['pontos']] : ['materia' => null];
            } else {
                $texto = $this->texto($numero, $produto);
                if ($texto) {
                    $base += ['texto' => $texto['texto'], 'fonte_texto' => $texto['fonte']];
                }
                if ($canal === 'whatsapp') {
                    $this->mandarWhatsApp($registro, $numero, $nomeProduto, $texto['texto'] ?? null, $imagem, $link);
                } else {
                    Mail::to($registro->email)->send(new SequenciaCarrinho($registro, $numero, $nomeProduto, $link['url'], $texto['texto'] ?? null, $imagem, ($texto['fonte'] ?? null) === 'promocao'));
                }
            }
            $registro->anotar($base + ['status' => 'enviado', 'link' => $link['url'], 'link_tipo' => $link['tipo']]);
        } catch (Throwable $e) {
            Log::warning('carrinho_sequencia_falhou', ['registro' => $registro->id, 'canal' => $canal, 'erro' => mb_substr($e->getMessage(), 0, 300)]);
            $registro->anotar($base + ['status' => 'falhou', 'erro' => mb_substr($e->getMessage(), 0, 300), 'link' => $link['url']]);

            $falhas = collect($registro->historico ?? [])->where('numero', $numero)->where('status', 'falhou')->count();
            if ($falhas < self::TENTATIVAS) {
                $registro->forceFill(['proximo_disparo_em' => CalendarioSequencia::janela(now()->addHour())])->save();

                return 'falhou';
            }
            // Desiste deste disparo e segue para o próximo, sem contar como mensagem enviada.
            $this->avancar($registro, $numero, false);

            return 'falhou';
        }

        $this->avancar($registro, $numero, true);

        return 'enviado';
    }

    /** Texto do WhatsApp/e-mail dos disparos 2 e 4 (promoção da vez, notícia do Gemini ou o último aviso). */
    private function texto(int $numero, ?Product $produto): ?array
    {
        if ($numero === 1) {
            return null;
        }
        if ($promocao = PromocaoDoCarrinho::texto($produto)) {
            return ['texto' => $promocao, 'fonte' => 'promocao'];
        }

        return CalendarioSequencia::tipo($numero) === 'ultimo_lembrete'
            ? ['texto' => self::TEXTO_ULTIMO, 'fonte' => 'ultimo']
            : $this->noticias->escrever($produto, $numero);
    }

    private function avancar(Registro $registro, int $numero, bool $enviou): void
    {
        $registro->forceFill(['disparos_enviados' => $numero] + ($enviou ? ['ultimo_disparo_em' => now()] : []));
        $registro->proximo_disparo_em = CalendarioSequencia::proximoEm($registro);
        $registro->save();

        if ($numero >= CalendarioSequencia::TOTAL) {
            $registro->parar(Registro::PAROU_FIM);
        }
    }

    /** Descadastro do e-mail do Caderno: o da newsletter, se a pessoa está nela; senão, o da sequência. */
    private function linkSair(Registro $registro): string
    {
        $inscrito = rescue(fn () => NewsletterInscrito::query()->where('email', NewsletterInscrito::normalizarEmail((string) $registro->email))->first(), null, false);

        return $inscrito?->token
            ? route('newsletter.sair', $inscrito->token)
            : URL::signedRoute('carrinho.sequencia.parar', ['registro' => $registro->id]);
    }

    /**
     * Modelo com a foto do produto para o D+1 (27/11/2026, Lira: "manda o
     * template bonito com imagem, tá chegando só texto"). O botão dele é fixo
     * em /produtos/{{1}}, então só vale quando o link do carrinho é a página
     * do produto; senão o D+1 segue no modelo de texto.
     *
     * @return array{loja: string, nome: string, idioma: string, sufixo: string}|null
     */
    public function lembreteComImagem(Registro $registro, array $link): ?array
    {
        $template = $this->templateWhatsApp($registro, 1);
        $nome = $template ? WhatsAppNumeros::template($template['loja'], 'carrinho_lembrete_imagem') : null;
        if (! $nome || ! str_starts_with((string) $link['sufixo'], 'produtos/')) {
            return null;
        }

        return [
            'loja' => $template['loja'],
            'nome' => $nome,
            'idioma' => (string) WhatsAppNumeros::template($template['loja'], 'carrinho_lembrete_imagem_idioma', 'pt_BR'),
            'sufixo' => substr((string) $link['sufixo'], strlen('produtos/')),
        ];
    }

    private function mandarWhatsApp(Registro $registro, int $numero, string $nomeProduto, ?string $texto, ?string $imagem, array $link): void
    {
        $template = $this->templateWhatsApp($registro, $numero);
        $cliente = $this->whatsapp->paraLoja($template['loja']);
        $nome = $registro->primeiroNome();
        // Variável de template da Meta não aceita quebra de linha nem tab.
        $produto = (string) preg_replace('/\s+/u', ' ', $nomeProduto);
        $sufixo = (string) $link['sufixo'];

        $comImagem = $numero === 1 ? $this->lembreteComImagem($registro, $link) : null;
        if ($comImagem) {
            $template = $comImagem;
            $sufixo = $comImagem['sufixo'];
        }

        $corpo = $numero === 1 ? [$nome, $produto] : [$nome, $produto, (string) $texto];
        $cabecalho = $numero === 1 && ! $comImagem ? null : $imagem;
        $resposta = $cabecalho === null
            ? $cliente->sendTemplate($registro->telefone, $template['nome'], $template['idioma'],
                bodyParams: $corpo,
                urlButtonParams: [0 => $sufixo])
            : $cliente->sendTemplate($registro->telefone, $template['nome'], $template['idioma'],
                headerMediaType: 'image',
                bodyParams: $corpo,
                urlButtonParams: [0 => $sufixo],
                headerMediaLink: $cabecalho);

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
                    ? "Carrinho abandonado (lembrete D+1): {$produto} {$link['url']}"
                    : 'Carrinho abandonado ('.($numero === CalendarioSequencia::TOTAL ? 'último lembrete' : 'segundo lembrete')." D+".CalendarioSequencia::dia($numero)."): {$produto} — {$texto} {$link['url']}",
                'status' => 'sent',
                'sent_by' => 'system',
                // Nome, idioma, variáveis, imagem e sufixo do botão: a tela de Conversas redesenha o balão do modelo.
                'payload' => WhatsAppModelos::payloadDoEnvio($template['nome'], $template['idioma'], $corpo, $cabecalho, [0 => $sufixo])
                    + ['carrinho_sequencia_id' => $registro->id, 'disparo' => $numero],
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
