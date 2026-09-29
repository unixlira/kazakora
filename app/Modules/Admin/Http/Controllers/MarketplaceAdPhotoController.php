<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\MarketplaceAdPhotoBrief;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MarketplaceAdPhotoController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:160'],
            'marketplace' => ['nullable', 'string', 'in:shopee,mercado_livre'],
            'status' => ['nullable', 'string', 'max:40'],
        ]);

        $briefs = MarketplaceAdPhotoBrief::query()
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('product_name', 'like', "%{$search}%")
                        ->orWhere('category_hint', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($filters['marketplace'] ?? null, fn ($query, string $marketplace) => $query->where('marketplace', $marketplace))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(12)
            ->withQueryString()
            ->through(fn (MarketplaceAdPhotoBrief $brief): array => $brief->toAdminSummary());

        return Inertia::render('Admin/Marketplaces/AdPhotos/Index', [
            'briefs' => $briefs,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'marketplace' => $filters['marketplace'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'marketplaces' => $this->marketplaces(),
            'statusOptions' => MarketplaceAdPhotoBrief::statusLabels(),
            'generationStatus' => $this->generationStatus(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Marketplaces/AdPhotos/Create', [
            'marketplaces' => $this->marketplaces(),
            'limits' => [
                'description' => 6000,
                'immutableNotes' => 2000,
                'referenceLinks' => 2000,
                'imageMaxMb' => 10,
            ],
            'generationStatus' => $this->generationStatus(),
        ]);
    }

    public function show(MarketplaceAdPhotoBrief $brief): Response
    {
        return Inertia::render('Admin/Marketplaces/AdPhotos/Show', [
            'brief' => $brief->toAdminDetail(),
            'marketplaces' => $this->marketplaces(),
            'generationStatus' => $this->generationStatus(),
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'marketplace' => ['required', 'string', 'in:shopee,mercado_livre'],
            'product_name' => ['nullable', 'string', 'max:160'],
            'category_hint' => ['nullable', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:6000'],
            'immutable_notes' => ['nullable', 'string', 'max:2000'],
            'reference_links' => ['nullable', 'string', 'max:2000'],
            'product_image' => ['nullable', 'image', 'max:10240'],
        ]);

        $image = null;

        if ($request->hasFile('product_image')) {
            $path = $request->file('product_image')->store('marketplace-ad-photos', 'public');
            $image = [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'originalName' => $request->file('product_image')->getClientOriginalName(),
            ];
        }

        $marketplace = $validated['marketplace'];
        $description = trim($validated['description']);
        $immutableNotes = trim($validated['immutable_notes'] ?? '');
        $referenceLinks = trim($validated['reference_links'] ?? '');
        $categoryHint = trim($validated['category_hint'] ?? '');
        $productName = trim($validated['product_name'] ?? '') ?: $this->guessProductName($description);
        $matrix = $this->withPrompts($this->matrixFor($marketplace), $productName, $description, $immutableNotes, $marketplace);
        $generatedProduct = $this->generatedProductPack($productName, $categoryHint, $description, $immutableNotes);

        $brief = MarketplaceAdPhotoBrief::create([
            'marketplace' => $marketplace,
            'product_name' => $productName,
            'category_hint' => $categoryHint !== '' ? $categoryHint : null,
            'description' => $description,
            'immutable_notes' => $immutableNotes,
            'reference_links' => $referenceLinks,
            'image_path' => $image['path'] ?? null,
            'image_url' => $image['url'] ?? null,
            'image_original_name' => $image['originalName'] ?? null,
            'competitive_research' => $this->competitiveResearchSkeleton($marketplace, $productName, $referenceLinks),
            'hero_decision' => $this->heroDecisionFor($marketplace),
            'matrix' => $matrix,
            'copy_pack' => $this->copyPack($marketplace, $productName, $description, $immutableNotes, $referenceLinks, $generatedProduct),
            'generated_product' => $generatedProduct,
            'warnings' => $this->warnings($immutableNotes, $referenceLinks, $generatedProduct),
            'status' => MarketplaceAdPhotoBrief::STATUS_READY,
            'approval_status' => MarketplaceAdPhotoBrief::APPROVAL_PENDING,
        ]);

        return redirect('/admin/marketplaces/fotos-anuncio/'.$brief->uuid)
            ->with('success', 'Matriz criada e produto automático rascunhado. Revise e aprove antes de gerar imagens ou publicar em marketplace.');
    }

    public function approve(MarketplaceAdPhotoBrief $brief): RedirectResponse
    {
        $brief->approve();

        return redirect('/admin/marketplaces/fotos-anuncio/'.$brief->uuid)
            ->with('success', 'Matriz aprovada. Agora o pacote pode entrar na fila de geração dos criativos.');
    }

    public function reject(Request $request, MarketplaceAdPhotoBrief $brief): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $brief->reject($validated['reason'] ?? null);

        return redirect('/admin/marketplaces/fotos-anuncio/'.$brief->uuid)
            ->with('success', 'Matriz marcada para ajuste.');
    }

    public function startGeneration(MarketplaceAdPhotoBrief $brief): RedirectResponse
    {
        $brief->startGeneration();

        return redirect('/admin/marketplaces/fotos-anuncio/'.$brief->uuid)
            ->with('success', 'Fila interna de criativos criada. A tela pode ser fechada; ao voltar, o status e o temporizador continuam no card.');
    }

    private function marketplaces(): array
    {
        return [
            'shopee' => [
                'label' => 'Shopee',
                'imageCount' => 9,
                'tone' => 'Vibrante, mobile-first, comercial e informativo, com texto overlay curto quando ajudar a conversão.',
            ],
            'mercado_livre' => [
                'label' => 'Mercado Livre',
                'imageCount' => 10,
                'tone' => 'Mais limpo, técnico e confiável, com foco em clareza, especificações, compatibilidade e compra segura.',
            ],
        ];
    }

    private function generationStatus(): array
    {
        return [
            'label' => 'Esteira KazaKora · Produto, matriz e criativos',
            'mode' => 'A tela agora guarda histórico, busca por produto/categoria, abre detalhe por card, aprova matriz e deixa geração em fila persistente.',
            'guardrail' => 'Publicação em Shopee/Mercado Livre continua bloqueada até aprovação explícita e driver oficial estar confirmado. A Shopee ainda precisa do driver de publicação real.',
        ];
    }

    private function matrixFor(string $marketplace): array
    {
        if ($marketplace === 'mercado_livre') {
            return [
                ['key' => 'A', 'title' => 'Capa validada pela categoria', 'function' => 'Ganhar clique com clareza e confiança.', 'direction' => 'Produto em destaque, geralmente fundo branco ou limpo, mas a capa final deve seguir o padrão vencedor da pesquisa competitiva.'],
                ['key' => 'B', 'title' => 'Produto em uso real', 'function' => 'Mostrar escala, aplicação e desejo.', 'direction' => 'Cena realista de uso, sem exagero e com produto protagonista.'],
                ['key' => 'C', 'title' => 'Ângulo alternativo', 'function' => 'Mostrar lateral, traseira, interior, montagem ou segunda forma de uso.', 'direction' => 'Enquadramento diferente da capa e do uso real.'],
                ['key' => 'D', 'title' => 'Benefícios principais', 'function' => 'Explicar rapidamente por que comprar.', 'direction' => 'Layout limpo com 3 a 5 benefícios reais, sem poluir.'],
                ['key' => 'E', 'title' => 'Detalhes e acabamento', 'function' => 'Aumentar percepção de qualidade.', 'direction' => 'Close realista de material, encaixe, textura, trava, costura ou superfície relevante.'],
                ['key' => 'F', 'title' => 'Medidas e proporção', 'function' => 'Reduzir devolução e dúvida física.', 'direction' => 'Dimensões reais, escala visual e referências de tamanho.'],
                ['key' => 'G', 'title' => 'Ficha técnica ou compatibilidade', 'function' => 'Responder dúvidas objetivas antes da compra.', 'direction' => 'Especificações confirmadas, compatibilidades, capacidade, componentes ou conteúdo do kit.'],
                ['key' => 'H', 'title' => 'Quebra de objeção principal', 'function' => 'Atacar a dúvida mais provável do comprador.', 'direction' => 'Material, resistência, montagem, limpeza, encaixe, segurança ou durabilidade, só com dados verdadeiros.'],
                ['key' => 'I', 'title' => 'Confiança e compra segura', 'function' => 'Reduzir medo de comprar.', 'direction' => 'Visual confiável, reforço de envio, garantia, suporte ou proteção apenas quando confirmado.'],
                ['key' => 'J', 'title' => 'Fechamento comercial', 'function' => 'Reforçar valor e decisão.', 'direction' => 'Imagem final forte, limpa e desejável, conectando benefício, uso e confiança.'],
            ];
        }

        return [
            ['key' => 'A', 'title' => 'Capa decidida por pesquisa', 'function' => 'Ganhar clique na grade mobile da Shopee.', 'direction' => 'Capa pode ser fundo branco, produto em uso, modelo usando, close hero ou fundo vibrante, conforme evidência da categoria.'],
            ['key' => 'B', 'title' => 'Produto em uso real', 'function' => 'Criar identificação e desejo.', 'direction' => 'Cena lifestyle/UGC realista, com produto protagonista.'],
            ['key' => 'C', 'title' => 'Segundo ângulo ou uso', 'function' => 'Mostrar o que a capa não mostrou.', 'direction' => 'Lateral, interior, aplicação, montagem, encaixe ou segunda forma de uso.'],
            ['key' => 'D', 'title' => 'Benefícios reais', 'function' => 'Acelerar entendimento e decisão.', 'direction' => 'Infográfico mobile-first com 3 a 5 benefícios confirmados.'],
            ['key' => 'E', 'title' => 'Material e acabamento', 'function' => 'Quebrar objeção de baixa qualidade.', 'direction' => 'Close nítido de textura, acabamento, encaixe, costura, trava ou superfície.'],
            ['key' => 'F', 'title' => 'Dimensão ou compatibilidade', 'function' => 'Evitar dúvida física e devolução.', 'direction' => 'Medidas, escala visual, capacidade, modelos compatíveis ou aplicação real.'],
            ['key' => 'G', 'title' => 'Prova social segura', 'function' => 'Construir confiança sem inventar depoimento.', 'direction' => 'Estrelas e comprador verificado como representação visual; sem nomes, números ou prints falsos.'],
            ['key' => 'H', 'title' => 'Oferta ou ação rápida', 'function' => 'Ativar compra imediata.', 'direction' => 'Cores quentes e gatilho verdadeiro: cupom, frete, promoção, envio ou economia somente se confirmado.'],
            ['key' => 'I', 'title' => 'Confiança final', 'function' => 'Eliminar último medo.', 'direction' => 'Produto bem apresentado, compra segura e reforço de confiança só com claims verdadeiros.'],
        ];
    }

    private function withPrompts(array $matrix, string $productName, string $description, string $immutableNotes, string $marketplace): array
    {
        $tone = $this->marketplaces()[$marketplace]['tone'];
        $dontChange = $immutableNotes !== '' ? $immutableNotes : 'Preservar cor, formato, proporção, materiais, medidas e aparência real conforme imagem/descrição enviada.';

        return collect($matrix)->map(function (array $item) use ($productName, $description, $dontChange, $tone): array {
            return [
                ...$item,
                'status' => 'pending',
                'image_url' => null,
                'generated_at' => null,
                'prompt' => implode("\n", [
                    'Crie uma imagem individual 1:1 para anúncio de marketplace.',
                    "Produto: {$productName}.",
                    "Função comercial: {$item['function']}",
                    "Direção visual: {$item['direction']}",
                    "Descrição real do produto: {$description}",
                    "Fidelidade obrigatória: {$dontChange}",
                    "Estilo: {$tone}",
                    'Qualidade: foto realista, nítida, iluminação profissional, produto protagonista, composição mobile-first.',
                    "Proibido: colagem, mosaico, grid, número de sequência, 'imagem 1', 'slide', 1/9, 1/10, watermark, texto inventado, marca falsa, selo falso, promessa não confirmada ou alteração do produto real.",
                ]),
            ];
        })->all();
    }

    private function competitiveResearchSkeleton(string $marketplace, string $productName, string $referenceLinks): array
    {
        return [
            'status' => 'Preparada para pesquisa pela Naia/Giovanna antes da geração final.',
            'query' => trim("{$productName} {$this->marketplaces()[$marketplace]['label']} fotos anúncio concorrentes"),
            'referenceLinks' => $referenceLinks,
            'evaluate' => [
                'tipo de capa dominante',
                'fundo branco versus uso real',
                'ângulos que mais aparecem',
                'benefícios destacados',
                'objeções respondidas',
                'densidade de texto',
                'oportunidade de diferenciação sem copiar',
            ],
        ];
    }

    private function heroDecisionFor(string $marketplace): array
    {
        return [
            'rule' => 'A primeira imagem será decidida pela pesquisa competitiva da categoria, não por regra fixa.',
            'options' => $marketplace === 'shopee'
                ? ['uso real/lifestyle', 'fundo branco', 'fundo vibrante', 'modelo usando', 'close premium', 'benefício curto']
                : ['fundo branco limpo', 'produto isolado premium', 'uso real técnico', 'close de acabamento', 'composição limpa com benefício curto'],
        ];
    }

    private function generatedProductPack(string $productName, string $categoryHint, string $description, string $immutableNotes): array
    {
        $baseTitle = trim($productName.' '.$categoryHint);
        $facts = collect(preg_split('/\r\n|\r|\n/', $description))
            ->map(fn (string $line): string => trim(strip_tags($line)))
            ->filter()
            ->take(10)
            ->values()
            ->all();

        return [
            'shopeeTitle' => Str::limit($baseTitle, 118, ''),
            'mercadoLivreTitle' => Str::limit($baseTitle, 58, ''),
            'shopeeDescription' => $this->shopeeDescription($productName, $description, $facts),
            'mercadoLivreDescription' => $this->mercadoLivreDescription($productName, $description, $facts),
            'technicalData' => $facts,
            'invoiceData' => [
                'ncm' => '[PREENCHER COM DADO FISCAL VERIFICADO]',
                'cfop' => '[DEFINIR CONFORME OPERAÇÃO]',
                'origem' => '[PREENCHER ORIGEM FISCAL VERIFICADA]',
                'unidade' => 'UN',
                'gtin' => '[USAR GTIN REAL OU MARCADOR SEM GTIN APROVADO]',
                'observacao' => 'A Naia não deve inventar NCM, GTIN, peso, dimensão fiscal ou classificação tributária.',
            ],
            'immutableNotes' => $immutableNotes,
            'publicationGate' => 'Publicar em marketplace só depois de aprovação final do Lira e validação dos dados fiscais/logísticos obrigatórios.',
        ];
    }

    private function shopeeDescription(string $productName, string $description, array $facts): string
    {
        $factsText = collect($facts)->map(fn (string $fact): string => '✅ '.$fact)->implode("\n");

        return trim("✨ {$productName}\n\nProduto selecionado pela KazaKora para quem busca praticidade, bom acabamento e compra simples.\n\n{$factsText}\n\n📦 O que você recebe:\n• 1 unidade do produto anunciado\n\n⚠️ Antes de comprar:\nConfira medidas, cor, compatibilidade e dados técnicos informados no anúncio.\n\nKazaKora · Achados úteis para casa e rotina.");
    }

    private function mercadoLivreDescription(string $productName, string $description, array $facts): string
    {
        $factsText = collect($facts)->map(fn (string $fact): string => '- '.$fact)->implode("\n");

        return trim("{$productName}\n\nProduto selecionado pela KazaKora para uma compra clara, prática e segura.\n\nCaracterísticas e especificações:\n{$factsText}\n\nConteúdo da embalagem:\n- 1 unidade do produto anunciado\n\nAtenção:\nConfira medidas, cor, compatibilidade e demais dados técnicos antes da compra.\n\nDescrição base recebida:\n{$description}");
    }

    private function copyPack(string $marketplace, string $productName, string $description, string $immutableNotes, string $referenceLinks, array $generatedProduct): string
    {
        $imageCount = $this->marketplaces()[$marketplace]['imageCount'];
        $label = $this->marketplaces()[$marketplace]['label'];
        $technicalData = collect($generatedProduct['technicalData'])->map(fn (string $fact): string => "- {$fact}")->implode("\n");

        return <<<TEXT
Marketplace principal: {$label}
Quantidade: {$imageCount} imagens independentes, sem numeração visual dentro das artes.
Produto: {$productName}
Descrição: {$description}
O que não pode mudar: {$immutableNotes}
Links/referências: {$referenceLinks}

Títulos automáticos:
Shopee: {$generatedProduct['shopeeTitle']}
Mercado Livre: {$generatedProduct['mercadoLivreTitle']}

Descrição Shopee, com emojis:
{$generatedProduct['shopeeDescription']}

Descrição Mercado Livre, sem emojis:
{$generatedProduct['mercadoLivreDescription']}

Dados técnicos extraídos:
{$technicalData}

Dados fiscais/NF:
NCM: {$generatedProduct['invoiceData']['ncm']}
CFOP: {$generatedProduct['invoiceData']['cfop']}
Origem: {$generatedProduct['invoiceData']['origem']}
GTIN: {$generatedProduct['invoiceData']['gtin']}

Antes de gerar imagens, faça pesquisa competitiva da categoria, decida a primeira imagem por evidência e monte as imagens com função comercial própria. Não usar colagem, mosaico, grid, marca d'água, número de sequência, selo falso, avaliação falsa ou claim não confirmado.
TEXT;
    }

    private function warnings(string $immutableNotes, string $referenceLinks, array $generatedProduct): array
    {
        $warnings = [];

        if ($immutableNotes === '') {
            $warnings[] = 'Campo “o que não pode mudar” não preenchido; revisar fidelidade antes de criar as imagens.';
        }

        if ($referenceLinks === '') {
            $warnings[] = 'Sem links de referência; a pesquisa competitiva deve usar busca web pela categoria/produto.';
        }

        if (str_contains($generatedProduct['invoiceData']['ncm'], 'PREENCHER')) {
            $warnings[] = 'Dados fiscais/NF ficam como pendência segura; NCM, origem, GTIN, peso e dimensões não serão inventados.';
        }

        return $warnings;
    }

    private function guessProductName(string $description): string
    {
        $firstLine = trim(Str::of($description)->explode("\n")->first() ?? 'Produto para anúncio');

        return Str::limit($firstLine !== '' ? $firstLine : 'Produto para anúncio', 90, '');
    }
}
