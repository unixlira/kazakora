<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Models\PdfEscolhido;
use App\Modules\Admin\Models\PdfOferta;
use App\Modules\Admin\Models\PdfOfertaAnuncio;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Botão "Clonar" do radar de PDF (Lira, 2026-11-18). Tira uma foto da oferta
 * (dados, textos dos anúncios e mídias copiadas para pdf-escolhidos/<id>/,
 * porque o importador apaga anúncio que sumiu) e já monta o plano de ação e
 * o prompt que gera o entregável. Clonar de novo devolve o mesmo clone.
 *
 * Plano e prompt saem de modelo fixo com os dados da oferta, sem chamar IA:
 * o clique é instantâneo e não falha por cota. O prompt é para colar no
 * Claude/Gemini e escrever o PDF nosso.
 */
class PdfClonagemService
{
    private const PASTA = 'pdf-escolhidos';

    private const IDIOMAS = ['es' => 'espanhol', 'en' => 'inglês', 'pt' => 'português', 'fr' => 'francês', 'de' => 'alemão', 'it' => 'italiano', 'ro' => 'romeno'];

    public function clonar(PdfOferta $oferta, ?User $usuario = null): PdfEscolhido
    {
        $existente = PdfEscolhido::query()->where('pdf_oferta_id', $oferta->id)->first();
        if ($existente) {
            return $existente;
        }

        $oferta->loadMissing(['anuncios' => fn ($q) => $q->orderByDesc('variacoes')->orderBy('iniciado_em')]);

        try {
            $escolhido = PdfEscolhido::create([
                'pdf_oferta_id' => $oferta->id,
                'user_id' => $usuario?->id,
                'nome' => $oferta->nome,
                'nicho' => $oferta->nicho,
                'idioma' => $oferta->idioma,
                'preco' => $oferta->preco,
                'moeda' => $oferta->moeda,
                'dados' => $this->dados($oferta),
                'plano_acao' => $this->planoDeAcao($oferta),
                'prompt_entregavel' => $this->promptEntregavel($oferta),
            ]);
        } catch (QueryException $e) {
            // Dois cliques ao mesmo tempo: o índice único segura, vale o primeiro.
            $primeiro = PdfEscolhido::query()->where('pdf_oferta_id', $oferta->id)->first();
            if ($primeiro) {
                return $primeiro;
            }
            throw $e;
        }

        $pasta = self::PASTA.'/'.$escolhido->id;
        $escolhido->update([
            'miniatura' => $this->copiar($oferta->miniatura, $pasta),
            'anuncios' => $oferta->anuncios->map(fn (PdfOfertaAnuncio $a): array => [
                'adId' => $a->ad_archive_id,
                'link' => $a->linkBiblioteca(),
                'titulo' => $a->titulo,
                'texto' => $a->texto,
                'cta' => $a->cta,
                'urlDestino' => $a->url_destino,
                'iniciadoEm' => $a->iniciado_em?->toDateString(),
                'variacoes' => $a->variacoes,
                'plataformas' => $a->plataformas ?? [],
                'midias' => collect($a->midias ?? [])->map(fn (array $m): array => [
                    'tipo' => $m['tipo'] ?? 'imagem',
                    'caminho' => $this->copiar($m['caminho'] ?? null, $pasta),
                    'poster' => $this->copiar($m['poster'] ?? null, $pasta),
                ])->filter(fn (array $m) => $m['caminho'])->values()->all(),
            ])->values()->all(),
        ]);

        return $escolhido;
    }

    /** Remove o clone e as mídias copiadas; a oferta volta a poder ser clonada. */
    public function remover(PdfEscolhido $escolhido): void
    {
        Storage::disk('public')->deleteDirectory(self::PASTA.'/'.$escolhido->id);
        $escolhido->delete();
    }

    private function dados(PdfOferta $o): array
    {
        return [
            'posicao' => $o->posicao,
            'anunciante' => $o->anunciante,
            'paginaId' => $o->pagina_id,
            'urlDestino' => $o->url_destino,
            'dominio' => $o->dominio,
            'plataforma' => $o->plataforma,
            'paises' => $o->paises ?? [],
            'anunciosAtivos' => $o->anuncios_ativos,
            'variacoes' => $o->variacoes,
            'diasRodando' => $o->dias_rodando,
            'inicioMaisAntigo' => $o->inicio_mais_antigo?->toDateString(),
            'pontuacao' => (float) $o->pontuacao,
            'observacao' => $o->observacao,
            'coletadoEm' => $o->coletado_em?->toDateTimeString(),
        ];
    }

    /** Copia um arquivo do disco public para a pasta do clone; null se não existir. */
    private function copiar(?string $caminho, string $pasta): ?string
    {
        $disco = Storage::disk('public');
        if (! $caminho || ! $disco->exists($caminho)) {
            return null;
        }
        $destino = $pasta.'/'.basename($caminho);
        if (! $disco->exists($destino)) {
            $disco->copy($caminho, $destino);
        }

        return $destino;
    }

    private function precoOriginal(PdfOferta $o): ?string
    {
        return $o->preco === null ? null : trim(($o->moeda ?? '').' '.number_format((float) $o->preco, 2, ',', '.'));
    }

    /** @return list<array{titulo: string, itens: list<string>}> */
    private function planoDeAcao(PdfOferta $o): array
    {
        $preco = $this->precoOriginal($o);
        $idioma = self::IDIOMAS[$o->idioma] ?? ($o->idioma ?: 'idioma não identificado');
        $topo = $o->anuncios->take(3)->count();
        $paises = implode(', ', $o->paises ?? []) ?: 'não informado';

        return [
            [
                'titulo' => '1. Entender a oferta original',
                'itens' => array_values(array_filter([
                    $o->url_destino ? "Abrir a página de vendas ({$o->dominio}) e anotar: promessa principal, para quem é, o que vem no PDF, bônus, garantia e preço." : 'A oferta não tem página de destino salva: abrir os anúncios na Biblioteca e seguir o link de lá.',
                    "Ler os {$o->anuncios->count()} criativos salvos (em {$idioma}) e listar os ganchos que se repetem: o que se repete é o que está vendendo.",
                    "Sinal de que se paga: {$o->anuncios_ativos} anúncios ativos, {$o->variacoes} variações, rodando há {$o->dias_rodando} dias. Países: {$paises}.",
                    $preco ? "Preço lá fora: {$preco}. No Brasil, testar ticket de entrada (R\$ 19,90 a R\$ 37,00) com order bump." : 'Preço não apareceu na coleta: confirmar na página de vendas.',
                ])),
            ],
            [
                'titulo' => '2. Produzir o entregável',
                'itens' => [
                    'Copiar o prompt do entregável (abaixo) e rodar no Claude. Ele pede conteúdo original em português, com a mesma promessa, sem copiar o texto do concorrente.',
                    'Revisar o conteúdo: conferir números, receitas, exercícios e qualquer afirmação de saúde ou dinheiro (nada de promessa que não dá para cumprir).',
                    'Diagramar no Canva (A4, capa + sumário + capítulos), exportar em PDF e gerar o mockup 3D da capa para a página e os criativos.',
                    'Montar 1 ou 2 bônus curtos (checklist, planilha ou guia rápido) que o prompt também descreve.',
                ],
            ],
            [
                'titulo' => '3. Página de venda e checkout',
                'itens' => [
                    'Página no padrão aprovado: promessa, mockup e botão de compra na primeira tela do celular.',
                    'Prova (o que tem dentro, páginas de amostra), bônus, garantia de 7 dias e perguntas frequentes.',
                    'Checkout com Pix e cartão, entrega automática do PDF por e-mail logo após o pagamento.',
                    'Lira confere a página antes de qualquer anúncio subir.',
                ],
            ],
            [
                'titulo' => '4. Criativos',
                'itens' => [
                    "Modelar os {$topo} criativos com mais variações (estão no topo da lista de criativos): mesma estrutura de gancho, refeitos em português com o nosso mockup.",
                    'Fazer de 3 a 5 ganchos diferentes para o mesmo corpo de vídeo/imagem.',
                    'Criativo não mostra nem fala preço (frete grátis e % de cupom podem). Nada de usar o vídeo do concorrente.',
                    'Lira aprova página e criativos antes de criar o anúncio.',
                ],
            ],
            [
                'titulo' => '5. Validar',
                'itens' => [
                    'Campanha de teste com orçamento baixo por 3 a 5 dias, um conjunto por gancho.',
                    'Cortar o criativo que não vende até gastar 1 a 1,5x o preço do produto; manter e escalar o que vende.',
                    'Se a página tiver clique e não tiver venda, o problema é página/preço, não criativo.',
                ],
            ],
        ];
    }

    private function promptEntregavel(PdfOferta $o): string
    {
        $idioma = self::IDIOMAS[$o->idioma] ?? ($o->idioma ?: 'não identificado');
        $preco = $this->precoOriginal($o) ?? 'não visível';
        $paises = implode(', ', $o->paises ?? []) ?: 'não informado';
        $formato = $this->formato($o->nome.' '.$o->nicho);

        $referencias = $o->anuncios->take(5)->map(function (PdfOfertaAnuncio $a, int $i): string {
            $partes = array_filter([
                $a->titulo ? 'Título: '.Str::limit(trim($a->titulo), 200) : null,
                $a->texto ? 'Texto: '.Str::limit(preg_replace('/\s+/', ' ', trim($a->texto)), 600) : null,
            ]);

            return 'Anúncio '.($i + 1).($a->variacoes > 1 ? " ({$a->variacoes} variações)" : '').":\n".implode("\n", $partes);
        })->filter()->implode("\n\n");

        return <<<PROMPT
Você é um autor e designer instrucional especialista em infoprodutos em PDF que vendem no Brasil.

TAREFA
Escrever o conteúdo COMPLETO de um infoproduto em PDF, em português do Brasil, que entregue a mesma transformação de uma oferta que está vendendo muito lá fora. O conteúdo tem que ser 100% original: use a oferta só para entender a promessa, o público e o formato. Não traduza nem copie frases, títulos ou marcas do concorrente.

OFERTA DE REFERÊNCIA
- Nome: {$o->nome}
- Nicho: {$o->nicho}
- Idioma original: {$idioma}
- Países onde roda: {$paises}
- Preço lá fora: {$preco}
- Sinais de que vende: {$o->anuncios_ativos} anúncios ativos, {$o->variacoes} variações, {$o->dias_rodando} dias no ar
- Página de vendas: {$o->url_destino}

TEXTOS DOS ANÚNCIOS QUE MAIS RODAM (só para entender promessa e ângulo)
{$referencias}

PÚBLICO NO BRASIL
Descreva em 3 linhas quem compra isto aqui (idade, momento de vida, dor principal, o que já tentou) e escreva o PDF para essa pessoa, com linguagem simples, direta e brasileira.

FORMATO DO ENTREGÁVEL
{$formato}

ENTREGUE, NESTA ORDEM
1. Três opções de título + subtítulo (promessa clara e específica, sem exagero), indicando a recomendada.
2. Texto da capa e da contracapa.
3. Sumário.
4. Introdução curta (o problema, por que as tentativas comuns falham, o que a pessoa vai conseguir ao terminar).
5. O conteúdo completo de todos os capítulos/seções, pronto para diagramar, com exercícios, checklists, tabelas ou modelos práticos onde fizer sentido. Nada de "complete aqui depois".
6. Conclusão com próximo passo prático.
7. Dois bônus curtos (por exemplo: checklist de 1 página e guia rápido), também completos.
8. Sugestão de diagramação para o Canva: paleta de 3 cores, fontes, estilo das páginas e o que vai em cada página de amostra da página de vendas.

REGRAS
- Português do Brasil, frases curtas, tom de quem já fez e ensina.
- Nada de promessa de cura, renda garantida ou resultado em prazo garantido.
- Se o tema for saúde, alimentação ou exercício: recomendações gerais e seguras, com aviso para procurar um profissional.
- Formate em Markdown, com # para capítulos e ## para seções, para virar PDF depois.
PROMPT;
    }

    /** Formato do PDF conforme o tipo de oferta (planner, receitas, curso...). */
    private function formato(string $texto): string
    {
        $t = Str::lower(Str::ascii($texto));

        return match (true) {
            Str::contains($t, ['planner', 'agenda', 'planejador', 'planificador', 'journal', 'diario']) => 'Planner imprimível: 40 a 80 páginas de modelos prontos (capas de mês, semana, metas, hábitos, finanças, listas), com instrução de uso de cada página. Descreva cada modelo de página com os campos e a disposição exata.',
            Str::contains($t, ['receita', 'receta', 'recipe', 'cardapio', 'menu', 'dieta', 'diet', 'keto', 'fit', 'air fryer', 'airfryer', 'cozinha', 'cocina']) => 'Livro de receitas/cardápio: de 50 a 100 receitas organizadas por categoria, cada uma com ingredientes em medidas caseiras, modo de preparo numerado, tempo, rendimento e dica. Inclua lista de compras e um cardápio de 7 dias.',
            Str::contains($t, ['atividade', 'actividad', 'activity', 'kids', 'crianca', 'nino', 'colorir', 'colorear', 'alfabetiza', 'escola', 'professor', 'maestra']) => 'Material de atividades imprimíveis: de 30 a 60 atividades com objetivo, faixa de idade, material necessário e enunciado completo. Descreva a ilustração de cada página para o designer.',
            default => 'Ebook/guia prático de 30 a 60 páginas: de 6 a 10 capítulos, cada um com explicação, exemplo real, passo a passo e um exercício ou checklist no fim.',
        };
    }
}
