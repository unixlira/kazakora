<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Jobs\GerarRoteiroCriativos;
use App\Modules\Admin\Models\KzCriativo;
use App\Modules\Admin\Models\KzOportunidade;
use App\Modules\Admin\Models\KzOportunidadeAnuncio;
use App\Modules\WhatsApp\Models\GeminiUsageLog;
use App\Modules\WhatsApp\Services\GeminiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fábrica de criativos de Oportunidades › KazaKora (Lira, 2026-11-18).
 *
 * Clonar um anúncio = 1 criativo NOSSO com a mesma estrutura (gancho, ritmo,
 * ângulo, formato) do anúncio de referência, com roteiro, fala e imagens
 * nossos. Criar do zero = N criativos com conceitos diferentes entre si, a
 * partir de tudo o que a oportunidade mostra.
 *
 * Cada criativo ganha código (KZ-AAMMDD-0001) e pasta no servidor
 * (storage/app/private/criativos-kazakora/<código>/) com briefing.json,
 * roteiro.md e, no clone, a mídia de referência. O roteiro sai do Gemini em
 * fila (GerarRoteiroCriativos); a montagem do vídeo é a etapa seguinte.
 *
 * Regras do Lira que vão no prompt: produto físico = filmagem real do
 * produto (fornecedor/fabricante do mesmo modelo) e foto real, nada de
 * pessoa ou mão gerada por IA; sem preço no criativo (frete grátis e % de
 * cupom podem); método de criativos do Botrel (gancho em 3 s, take a cada
 * 2-3 s, 80% produto na tela, oferta depois do desejo, CTA dito e escrito).
 */
class FabricaCriativosKazakora
{
    public const PASTA = 'criativos-kazakora';

    public const MAXIMO_POR_LOTE = 10;

    /** @return Collection<int, KzCriativo> */
    public function pedir(KzOportunidade $oportunidade, ?KzOportunidadeAnuncio $referencia, int $quantidade, ?User $usuario = null): Collection
    {
        $quantidade = $referencia ? 1 : max(1, min(self::MAXIMO_POR_LOTE, $quantidade));
        $lote = (string) Str::uuid();

        $criativos = collect(range(1, $quantidade))->map(function (int $n) use ($oportunidade, $referencia, $quantidade, $lote, $usuario) {
            $criativo = KzCriativo::create([
                'kz_oportunidade_id' => $oportunidade->id,
                'kz_oportunidade_anuncio_id' => $referencia?->id,
                'referencia_ad_id' => $referencia?->ad_archive_id,
                'origem' => $referencia ? 'clone' : 'zero',
                'lote' => $lote,
                'numero' => $n,
                'total_lote' => $quantidade,
                'status' => 'roteirizando',
                'user_id' => $usuario?->id,
            ]);
            $codigo = 'KZ-'.now()->format('ymd').'-'.str_pad((string) $criativo->id, 4, '0', STR_PAD_LEFT);
            $criativo->update(['codigo' => $codigo, 'pasta' => self::PASTA.'/'.$codigo]);

            return $criativo;
        });

        if ($referencia) {
            $this->copiarReferencia($referencia, $criativos->first());
        }

        GerarRoteiroCriativos::dispatch($lote);

        return $criativos;
    }

    /**
     * Gera UMA leva (até 3) de roteiros do lote e diz se ainda falta algum.
     * Roteiro completo é longo e a fila padrão corta tarefa com mais de 110 s,
     * então o job chama de novo até acabar. Cada leva recebe os conceitos já
     * criados para não repetir; nova tentativa só refaz o que está "roteirizando".
     */
    public function roteirizar(string $lote, GeminiClient $gemini): bool
    {
        $todos = KzCriativo::query()->where('lote', $lote)->orderBy('numero')->get();
        $pendentes = $todos->where('status', 'roteirizando')->values();
        if ($pendentes->isEmpty()) {
            return false;
        }
        if (! $gemini->isConfigured()) {
            throw new RuntimeException('GEMINI_API_KEY não configurada.');
        }
        $oportunidade = KzOportunidade::query()->with(['anuncios' => fn ($q) => $q->orderByDesc('variacoes')->orderBy('iniciado_em')])->findOrFail($todos->first()->kz_oportunidade_id);
        $referencia = $todos->first()->anuncio;
        config(['services.gemini.timeout' => max(80, (int) config('services.gemini.timeout'))]);

        $leva = $pendentes->take(3);
        $feitos = KzCriativo::query()->where('lote', $lote)->where('status', 'roteiro_pronto')->get()
            ->map(fn (KzCriativo $c) => ($c->briefing['titulo'] ?? '').': '.($c->briefing['conceito'] ?? '').' (gancho: '.($c->briefing['gancho']['fala'] ?? '').')')
            ->all();

        $resposta = $gemini->generate(
            (string) config('services.gemini.chat_model'),
            [['role' => 'user', 'parts' => [['text' => $this->prompt($oportunidade, $referencia, $leva->count(), $feitos)]]]],
            $this->sistema(),
            12000,
            GeminiUsageLog::PURPOSE_AD_CONTENT,
        );

        $roteiros = $this->lerJson($resposta);
        if (count($roteiros) < $leva->count()) {
            throw new RuntimeException('O Gemini devolveu '.count($roteiros).' roteiro(s) para '.$leva->count().' criativo(s).');
        }

        foreach ($leva->values() as $i => $criativo) {
            $this->salvar($criativo, $roteiros[$i], $oportunidade, $referencia);
        }

        return $pendentes->count() > $leva->count();
    }

    private function salvar(KzCriativo $criativo, array $roteiro, KzOportunidade $oportunidade, ?KzOportunidadeAnuncio $referencia): void
    {
        $briefing = $roteiro + [
            'codigo' => $criativo->codigo,
            'oportunidade' => ['id' => $oportunidade->id, 'nome' => $oportunidade->nome, 'fase' => $oportunidade->fase, 'pontuacao' => (float) $oportunidade->pontuacao],
            'referencia' => $referencia ? ['ad_id' => $referencia->ad_archive_id, 'link' => $referencia->linkBiblioteca()] : null,
            'gerado_em' => now()->toIso8601String(),
        ];
        $disco = Storage::disk('local');
        $disco->put($criativo->pasta.'/briefing.json', json_encode($briefing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $disco->put($criativo->pasta.'/roteiro.md', $this->markdown($briefing));
        $criativo->update(['briefing' => $briefing, 'status' => 'roteiro_pronto', 'erro' => null]);
    }

    public function falhou(string $lote, string $erro): void
    {
        KzCriativo::query()->where('lote', $lote)->where('status', 'roteirizando')->update(['status' => 'erro', 'erro' => Str::limit($erro, 900)]);
    }

    private function copiarReferencia(KzOportunidadeAnuncio $anuncio, KzCriativo $criativo): void
    {
        $publico = Storage::disk('public');
        $local = Storage::disk('local');
        foreach ($anuncio->midias ?? [] as $m) {
            foreach (array_filter([$m['caminho'] ?? null, $m['poster'] ?? null]) as $caminho) {
                if ($publico->exists($caminho)) {
                    $local->writeStream($criativo->pasta.'/referencia/'.basename($caminho), $publico->readStream($caminho));
                }
            }
        }
    }

    private function sistema(): string
    {
        return <<<'TXT'
Você é diretor de criativos de performance da KazaKora, loja brasileira de produtos físicos para casa (vende no site próprio, Mercado Livre e Shopee). Escreve criativos de vídeo para Meta Ads e TikTok em português do Brasil que VENDEM.

Método obrigatório (Rafa Botrel / EcomClub):
- Gancho nos primeiros 3 segundos (fala + texto na tela + visual que para o dedo).
- Troca de take a cada 2 a 3 segundos; 80% do tempo o PRODUTO na tela, funcionando.
- Ordem: gancho → problema/desejo → demonstração → prova → oferta (só depois do desejo) → CTA dito E escrito.
- Legenda em até 2 linhas, fora das zonas mortas (topo 15%, base 20%, laterais).
- Variar ganchos para testar.

Regras da loja (não negociáveis):
- Produto físico: a imagem é FILMAGEM REAL do produto (do fornecedor/fabricante do mesmo modelo ou nossa) e foto real. Nada de pessoa, mão ou produto gerado por IA.
- NUNCA mostrar nem falar preço. Pode: frete grátis, % de cupom, "parcelado".
- Nada de depoimento inventado, resultado falso ou "antes e depois" que o produto não entrega.
- Nunca reaproveitar vídeo, áudio, voz ou texto do anúncio de referência: só a ESTRUTURA (tipo de gancho, ritmo, ângulo, formato).
- Narração em português do Brasil, natural, de gente real; frases curtas.

Responda SOMENTE com JSON válido, sem texto antes ou depois.
TXT;
    }

    private function prompt(KzOportunidade $o, ?KzOportunidadeAnuncio $referencia, int $quantidade, array $feitos = []): string
    {
        $preco = $o->preco !== null ? trim(($o->moeda ?? '').' '.number_format((float) $o->preco, 2, ',', '.')) : 'não visível';
        $dados = implode("\n", array_filter([
            "Produto/oferta: {$o->nome}",
            $o->nicho ? "Nicho: {$o->nicho}" : null,
            $o->anunciante ? "Anunciante: {$o->anunciante}" : null,
            $o->url_destino ? "Página de destino: {$o->url_destino}" : null,
            "Preço lá (só para contexto, NÃO vai no criativo): {$preco}",
            "Fase: {$o->fase} · pontuação {$o->pontuacao}/100 · {$o->anuncios_ativos} anúncios ativos · {$o->variacoes} variações · {$o->dias_rodando} dias no ar · {$o->criativos_novos_7d} criativos novos em 7 dias · {$o->anunciantes_distintos} anunciante(s)",
            $o->observacao ? "Observação: {$o->observacao}" : null,
        ]));

        $anuncio = fn (KzOportunidadeAnuncio $a): string => trim(implode("\n", array_filter([
            '- Anúncio '.$a->ad_archive_id.' ('.$a->variacoes.' variações'.($a->iniciado_em ? ', no ar desde '.$a->iniciado_em->format('d/m/Y') : '').', mídia: '.(collect($a->midias ?? [])->pluck('tipo')->implode('+') ?: 'sem mídia').')',
            $a->titulo ? '  Título: '.Str::limit(trim($a->titulo), 200) : null,
            $a->texto ? '  Texto: '.Str::limit(preg_replace('/\s+/', ' ', trim($a->texto)), 700) : null,
            $a->cta ? '  Botão: '.$a->cta : null,
        ])));

        if ($referencia) {
            $tarefa = "TAREFA: criar 1 criativo nosso que CLONA A ESTRUTURA deste anúncio de referência (o que mais funciona dele: tipo de gancho, ângulo, ritmo, formato, ordem das etapas, duração), com roteiro, fala, texto e takes 100% nossos.\n\nANÚNCIO DE REFERÊNCIA\n".$anuncio($referencia)
                ."\n\nNo campo \"o_que_veio_da_referencia\", diga exatamente o que foi modelado e o que mudou.";
        } else {
            $tarefa = "TAREFA: criar {$quantidade} criativo(s) do zero, cada um com CONCEITO DIFERENTE (ângulo, tipo de gancho e formato diferentes entre si), usando o que os anúncios que mais rodam revelam sobre o que vende.\n\nANÚNCIOS QUE MAIS RODAM\n"
                .$o->anuncios->take(6)->map($anuncio)->implode("\n");
        }

        $esquema = <<<'JSON'
[
  {
    "titulo": "nome curto do criativo",
    "conceito": "a ideia em 1 frase",
    "angulo": "desejo ou medo explorado",
    "publico": "quem é e em que momento está",
    "formato": "demonstração | problema-solução | UGC com filmagem real | comparação | unboxing | ...",
    "duracao_s": 25,
    "proporcao": "9:16",
    "gancho": {"fala": "", "texto_tela": "", "visual": ""},
    "ganchos_alternativos": [{"fala": "", "texto_tela": "", "visual": ""}, {"fala": "", "texto_tela": "", "visual": ""}],
    "roteiro": [{"de_s": 0, "ate_s": 3, "etapa": "gancho", "visual": "take exato", "fala": "", "texto_tela": ""}],
    "retencao": ["técnica usada e em que segundo"],
    "cta": {"fala": "", "texto_tela": ""},
    "legenda": {"estilo": "", "posicao": "", "palavras_por_tela": 3, "destaques": "palavras que ganham cor"},
    "narracao": {"voz": "feminina/masculina, idade", "tom": "", "velocidade": "", "texto": "narração completa, do primeiro ao último segundo"},
    "musica": {"estilo": "", "bpm": 110, "volume": "baixo sob a voz", "onde_buscar": "biblioteca com licença comercial"},
    "texto_anuncio": {"principal": "texto do post", "titulo": "", "descricao": ""},
    "takes_necessarios": ["lista dos takes reais do produto a filmar ou buscar no fornecedor"],
    "o_que_veio_da_referencia": "",
    "checklist": ["gancho em 3 s", "sem preço", "..."]
  }
]
JSON;

        if ($feitos) {
            $tarefa .= "\n\nJÁ CRIADOS NESTE LOTE (não repita conceito nem gancho):\n- ".implode("\n- ", $feitos);
        }

        return "DADOS DA OPORTUNIDADE (Biblioteca de Anúncios da Meta)\n{$dados}\n\n{$tarefa}\n\nDevolva um array JSON com exatamente {$quantidade} objeto(s) neste formato:\n{$esquema}";
    }

    /** @return list<array<string, mixed>> */
    private function lerJson(string $texto): array
    {
        $texto = trim($texto);
        $texto = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $texto);
        $inicio = strpos($texto, '[');
        $fim = strrpos($texto, ']');
        $dados = $inicio !== false && $fim !== false ? json_decode(substr($texto, $inicio, $fim - $inicio + 1), true) : null;
        if (! is_array($dados)) {
            $um = json_decode($texto, true);
            $dados = is_array($um) && isset($um['roteiro']) ? [$um] : null;
        }
        if (! is_array($dados)) {
            throw new RuntimeException('O Gemini não devolveu um JSON legível.');
        }

        return array_values(array_filter($dados, 'is_array'));
    }

    private function markdown(array $b): string
    {
        $linhas = ['# '.($b['codigo'] ?? '').' · '.($b['titulo'] ?? ''), '', ($b['conceito'] ?? ''), ''];
        $linhas[] = '**Ângulo:** '.($b['angulo'] ?? '').'  ';
        $linhas[] = '**Público:** '.($b['publico'] ?? '').'  ';
        $linhas[] = '**Formato:** '.($b['formato'] ?? '').' · '.($b['duracao_s'] ?? '?').' s · '.($b['proporcao'] ?? '9:16');
        $g = $b['gancho'] ?? [];
        $linhas = [...$linhas, '', '## Gancho (0-3 s)', '- Fala: '.($g['fala'] ?? ''), '- Tela: '.($g['texto_tela'] ?? ''), '- Visual: '.($g['visual'] ?? ''), '', '## Roteiro'];
        foreach ($b['roteiro'] ?? [] as $c) {
            $linhas[] = sprintf('- **%s-%ss · %s** — %s | Fala: "%s" | Tela: "%s"', $c['de_s'] ?? '', $c['ate_s'] ?? '', $c['etapa'] ?? '', $c['visual'] ?? '', $c['fala'] ?? '', $c['texto_tela'] ?? '');
        }
        $linhas = [...$linhas, '', '## CTA', '- Fala: '.($b['cta']['fala'] ?? ''), '- Tela: '.($b['cta']['texto_tela'] ?? ''), '', '## Narração', ($b['narracao']['texto'] ?? ''), '', '## Música', json_encode($b['musica'] ?? [], JSON_UNESCAPED_UNICODE), '', '## Takes necessários'];
        foreach ($b['takes_necessarios'] ?? [] as $t) {
            $linhas[] = '- '.$t;
        }

        return implode("\n", $linhas)."\n";
    }
}
