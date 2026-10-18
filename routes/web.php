<?php

use App\Modules\Admin\Http\Controllers\AdsRechargeController;
use App\Modules\Admin\Http\Controllers\ApiPartnerController;
use App\Modules\Admin\Http\Controllers\AuditLogController;
use App\Modules\Admin\Http\Controllers\BannerController;
use App\Modules\Admin\Http\Controllers\CouponController;
use App\Modules\Admin\Http\Controllers\CashFlowController;
use App\Modules\Admin\Http\Controllers\CategoryController;
use App\Modules\Admin\Http\Controllers\CompanyController;
use App\Modules\Admin\Http\Controllers\CompetitorAnalysisController;
use App\Modules\Admin\Http\Controllers\CorreiosController;
use App\Modules\Admin\Http\Controllers\CostCenterController;
use App\Modules\Admin\Http\Controllers\DashboardController;
use App\Modules\Admin\Http\Controllers\DiagnosticoAcaoController;
use App\Modules\Admin\Http\Controllers\ConversionPageController;
use App\Modules\Admin\Http\Controllers\DigitalMarketingController;
use App\Modules\Admin\Http\Controllers\PdfOpportunityController;
use App\Modules\Admin\Http\Controllers\FinancialDashboardController;
use App\Http\Controllers\Api\DashboardAgentController;
use App\Modules\Admin\Http\Controllers\IntegrationController;
use App\Modules\Admin\Http\Controllers\KoraSyncController;
use App\Modules\Admin\Http\Controllers\EnviosFlexController;
use App\Modules\Admin\Http\Controllers\DevolucoesController;
use App\Modules\Admin\Http\Controllers\MercadoLivreClaimsController;
use App\Modules\Admin\Http\Controllers\MercadoLivreFlexController;
use App\Modules\Admin\Http\Controllers\MercadoLivreListingsController;
use App\Modules\Admin\Http\Controllers\MercadoLivreSalesController;
use App\Modules\Admin\Http\Controllers\MercadoLivreShippingController;
use App\Modules\Admin\Http\Controllers\FiscalClosingController;
use App\Modules\Admin\Http\Controllers\InvoiceController;
use App\Modules\Admin\Http\Controllers\InvoiceManualController;
use App\Modules\Admin\Http\Controllers\InvoiceReturnController;
use App\Modules\Admin\Http\Controllers\KpiController;
use App\Modules\Admin\Http\Controllers\MarketplaceAdPhotoController;
use App\Modules\Admin\Http\Controllers\ManualLabelController;
use App\Modules\Admin\Http\Controllers\CustomerController;
use App\Modules\Admin\Http\Controllers\ManualOrderController;
use App\Modules\Admin\Http\Controllers\MercadoLivreFullPrintController;
use App\Modules\Admin\Http\Controllers\OrderImportController;
use App\Modules\Admin\Http\Controllers\PaymentSettingsController;
use App\Modules\Admin\Http\Controllers\PixelMetaController;
use App\Modules\Admin\Http\Controllers\OrderController as AdminOrderController;
use App\Modules\Admin\Http\Controllers\PricingCalculatorController;
use App\Modules\Admin\Http\Controllers\PrintJobController;
use App\Modules\Admin\Http\Controllers\PrintTestController;
use App\Modules\Admin\Http\Controllers\WebhookLogController;
use App\Modules\Admin\Http\Controllers\WebhookTestController;
use App\Modules\Admin\Http\Controllers\WhatsAppCampaignController;
use App\Modules\Admin\Http\Controllers\WhatsAppInboxController;
use App\Modules\Admin\Http\Controllers\WhatsAppSettingsController;
use App\Modules\Admin\Http\Controllers\ProductController;
use App\Modules\Admin\Http\Controllers\ProductFiscalController;
use App\Modules\Admin\Http\Controllers\ProductImageController;
use App\Modules\Admin\Http\Controllers\ProductLogisticsController;
use App\Modules\Admin\Http\Controllers\ProductQuantityDiscountController;
use App\Modules\Admin\Http\Controllers\ProductVideoController;
use App\Modules\Admin\Http\Controllers\VideoDownloadController;
use App\Modules\Admin\Http\Controllers\PurchaseOrderController;
use App\Modules\Admin\Http\Controllers\RadarController;
use App\Modules\Admin\Http\Controllers\ReportController;
use App\Modules\Admin\Http\Controllers\ReviewController as AdminReviewController;
use App\Modules\Admin\Http\Controllers\PromotionalNotificationController;
use App\Modules\Admin\Http\Controllers\ServiceOrderController;
use App\Modules\Admin\Http\Controllers\ShippingMethodController;
use App\Modules\Admin\Http\Controllers\StockMovementController;
use App\Modules\Admin\Http\Controllers\SupplierController;
use App\Modules\Admin\Http\Controllers\SiteVisitLogController;
use App\Modules\Admin\Http\Controllers\FerraKoraProdutoController;
use App\Modules\Admin\Http\Controllers\OfertaRelampagoController;
use App\Modules\Admin\Http\Controllers\OutroSiteVisitLogController;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Admin\Http\Controllers\BeautyKoraPedidoController;
use App\Modules\Admin\Http\Controllers\VideosEmbalagemController;
use App\Modules\Admin\Http\Controllers\SystemLogController;
use App\Modules\Admin\Http\Controllers\UserPermissionController;
use App\Modules\Cart\Http\Controllers\CartController;
use App\Modules\Catalog\Http\Controllers\CatalogController;
use App\Modules\Catalog\Http\Controllers\FavoriteController;
use App\Modules\Catalog\Http\Controllers\ReviewController;
use App\Modules\Checkout\Http\Controllers\CheckoutController;
use App\Modules\Checkout\Http\Controllers\TrackingController;
use App\Modules\Contato\Http\Controllers\ContatoController;
use App\Modules\Marketplace\Http\Controllers\ProductChannelController;
use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Profile\Http\Controllers\ProfileAddressController;
use App\Modules\Profile\Http\Controllers\ProfileAvatarController;
use App\Modules\Profile\Http\Controllers\ProfileController;
use App\Modules\Profile\Http\Controllers\ProfilePasswordController;
use App\Modules\Profile\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->name('catalogo.inicio');
// Todas as ofertas no ar, com filtros (pedido 2026-10-15).
Route::get('/ofertas', [CatalogController::class, 'ofertas'])->name('catalogo.ofertas');
// Busca instantânea do topo: foto e nome enquanto digita (pedido 2026-10-15).
Route::get('/busca/sugestoes', [CatalogController::class, 'sugestoes'])->middleware('throttle:120,1')->name('catalogo.busca.sugestoes');
// Mapa do site para o Google Search Console (pedido 2026-10-12).
Route::get('/sitemap.xml', \App\Modules\Catalog\Http\Controllers\SitemapController::class)->name('sitemap');
Route::get('/produtos/{product:slug}', [CatalogController::class, 'show'])
    ->name('produtos.ver')
    // Link antigo da Nuvemshop (/produtos/<slug>-<id>/): 301 para o produto atual, se houver par.
    ->missing(function (\Illuminate\Http\Request $request) {
        $produto = \App\Modules\Catalog\Support\ProdutoDaLojaAntiga::encontrar((string) $request->route('product'));
        abort_unless($produto, 404);

        return redirect()->route('produtos.ver', $produto->slug, 301);
    });
Route::get('/produtos/{product:slug}/envio', [CatalogController::class, 'shipping'])->name('produtos.envio.detalhes');

// Páginas institucionais/legais — públicas de propósito, precisam ser
// rastreáveis sem login para aprovação no Google Merchant Center.
Route::inertia('/trocas-e-devolucoes', 'Legal/Trocas')->name('legal.trocas');
// Política de Entrega com o Full e as regiões atendidas (pedido 2026-10-10).
Route::get('/politica-de-entrega', fn () => \Inertia\Inertia::render('Legal/Entrega', [
    'full' => [
        'horario_corte' => str_replace(':00', 'h', (string) config('entrega_expressa.horario_corte', '13:00')),
        'horario_entrega' => (string) config('entrega_expressa.horario_entrega', '21h'),
        'faixas' => config('entrega_expressa.faixas', []),
    ],
]))->name('legal.entrega');
Route::inertia('/politica-de-privacidade', 'Legal/Privacidade')->name('legal.privacidade');
// Exclusão de dados (pedido 2026-10-10, URL exigida pela Meta para o WhatsApp).
Route::redirect('/exclusao-de-dados', '/politica-de-privacidade#exclusao-de-dados')->name('legal.exclusao');
// Cookies (pedido 2026-10-10, docs/privacidade-e-cookies.md).
Route::get('/politica-de-cookies', fn () => \Inertia\Inertia::render('Legal/Cookies', [
    'sessaoMinutos' => (int) config('session.lifetime'),
    'nomeSessao' => (string) config('session.cookie'),
    'versao' => \App\Support\Privacidade\Cookies::VERSAO,
]))->name('legal.cookies');
Route::post('/cookies/aceitar', [\App\Modules\Analytics\Http\Controllers\CookiesController::class, 'aceitar'])->middleware('throttle:20,1')->name('cookies.aceitar');
Route::inertia('/termos-de-uso', 'Legal/Termos')->name('legal.termos');

// URLs da loja antiga (Nuvemshop) que o Google ainda mostra nos resultados
// (pedido 2026-10-12: clicar em "kazakora.com › quem-somos" dava 404).
// 301 para a página atual equivalente, o Google troca o link sozinho.
Route::permanentRedirect('/quem-somos', '/');
Route::permanentRedirect('/produtos', '/?todos=1');
Route::permanentRedirect('/politica-de-frete', '/politica-de-entrega');
Route::permanentRedirect('/contato', '/fale-conosco');
// Mais URLs da Nuvemshop que o Search Console ainda lista como erro (2026-10-12).
// As com barra final já resolvem; só estas não tinham equivalente.
Route::permanentRedirect('/politica-de-devolucoes', '/trocas-e-devolucoes');
Route::permanentRedirect('/account/login', '/entrar');
Route::permanentRedirect('/account/register', '/cadastro');

// Documentação da API pública de parceiros (ver routes/api_v1.php) — página
// estática (Blade puro, não Inertia/SPA), pública de propósito pra
// desenvolvedor de integrador externo conseguir consultar sem login. Nunca
// mostra um token real, só placeholder — o token de cada parceiro é gerado
// e entregue separadamente (ver Admin\ApiPartnerController::issueToken()).
Route::view('/api/documentacao', 'api.documentation')->name('api.documentacao');

// Minigame público, isolado da loja/checkout. Usa só créditos fictícios no navegador.
Route::view('/frute-jogo', 'frute-jogo')->name('frute-jogo');

// Favoritos sem login (pedido 2026-10-15): o visitante guarda no navegador
// (localStorage) e a página lista pelos ids que ele manda; ao entrar, o que
// estava no navegador se junta à conta por /favoritos/sincronizar.
Route::get('/favoritos', [FavoriteController::class, 'index'])
    ->name('favoritos.listar');

Route::post('/favoritos/sincronizar', [FavoriteController::class, 'sincronizar'])
    ->middleware(['auth', 'throttle:30,1'])
    ->name('favoritos.sincronizar');

Route::post('/favoritos/{product}', [FavoriteController::class, 'toggle'])
    ->middleware('auth')
    ->name('favoritos.alternar');

Route::post('/produtos/{product}/avaliacoes', [ReviewController::class, 'store'])
    ->middleware('auth')
    ->name('avaliacoes.armazenar');

// "Comprar" na página do produto: finalização só com aquele item (pedido 2026-10-11).
Route::post('/comprar-agora', [CartController::class, 'comprarAgora'])->name('comprar-agora');

Route::prefix('carrinho')->name('carrinho.')->group(function () {
    Route::get('/', [CartController::class, 'index'])->name('ver');
    // Links do e-mail de carrinho abandonado (pedido 2026-10-10), assinados.
    Route::get('/recuperar/{carrinho}', [CartController::class, 'recuperar'])->middleware('signed')->name('recuperar');
    Route::get('/lembretes/parar/{carrinho}', [CartController::class, 'pararLembretes'])->middleware('signed')->name('lembretes.parar');
    Route::post('/', [CartController::class, 'store'])->name('adicionar');
    Route::patch('/{product}', [CartController::class, 'update'])->name('atualizar');
    Route::delete('/{product}', [CartController::class, 'destroy'])->name('remover');
});

// Sair das promoções por e-mail (link assinado do rodapé do cupom).
Route::get('/promocoes/sair/{user}', function (\App\Models\User $user) {
    $user->forceFill(['recebe_promocoes' => false])->save();

    return redirect()->route('catalogo.inicio')->with('success', 'Pronto! Você não vai mais receber promoções por e-mail.');
})->middleware('signed')->name('promocoes.sair');

// Fale conosco > Mandar mensagem (pedido 2026-10-10): 5 envios a cada 10 min por IP.
Route::get('/fale-conosco', [ContatoController::class, 'index'])->name('contato');
Route::post('/fale-conosco', [ContatoController::class, 'store'])->middleware('throttle:5,10')->name('contato.enviar');

// Rastreio só com login (pedido 2026-10-17): o dono do pedido ou um admin.
// Visitante cai no login e volta para o mesmo link depois de entrar.
Route::middleware('auth')->group(function () {
    Route::get('/rastreio', [TrackingController::class, 'index'])->name('rastreio');
    Route::post('/rastreio', [TrackingController::class, 'search'])->middleware('throttle:20,1')->name('rastreio.buscar');
    Route::get('/rastreio/{ref}', [TrackingController::class, 'show'])->name('rastreio.ver');
});

Route::get('/frete/prazo', [CheckoutController::class, 'deliveryEstimate'])->middleware('throttle:30,1')->name('frete.prazo');
// Frete grátis com o frete embutido (2026-10-12): itens, subtotal e TURBO pelo CEP.
Route::get('/finalizacao/entrega-gratis', [CheckoutController::class, 'entregaGratis'])->middleware('throttle:30,1')->name('finalizacao.entrega-gratis');

Route::prefix('finalizacao')->name('finalizacao.')->group(function () {
    Route::get('/', [CheckoutController::class, 'delivery'])->name('entrega');
    Route::post('/entrega', [CheckoutController::class, 'storeDelivery'])->name('entrega.salvar');
    Route::post('/frete', [CheckoutController::class, 'quoteFreight'])->middleware('throttle:20,1')->name('frete.cotar');
    Route::get('/pagamento', [CheckoutController::class, 'payment'])->name('pagamento');
    Route::post('/pagamento/cupom', [CheckoutController::class, 'applyCoupon'])->name('pagamento.cupom');
    Route::post('/cupom', [CheckoutController::class, 'applyCouponJson'])->middleware('throttle:20,1')->name('cupom.aplicar');
    Route::delete('/cupom', [CheckoutController::class, 'removeCouponJson'])->name('cupom.remover');
    Route::post('/pagamento', [CheckoutController::class, 'storePayment'])->middleware('throttle:10,1')->name('pagamento.iniciar');
    Route::post('/{order}/pagamento/proxima-parte', [CheckoutController::class, 'storeSecondPayment'])->middleware('throttle:10,1')->name('pagamento.proxima-parte');
    Route::get('/{order}/status', [CheckoutController::class, 'status'])->name('status');
    Route::get('/{order}/confirmacao', [CheckoutController::class, 'confirmation'])->middleware('auth')->name('confirmacao');
});

Route::get('/pedidos', [CheckoutController::class, 'myOrders'])->middleware('auth')->name('pedidos.meus');
// Detalhe da compra e chat de suporte com a Manuela (pedido 2026-10-11).
Route::middleware('auth')->group(function () {
    Route::get('/pedidos/{order}', [CheckoutController::class, 'showOrder'])->whereNumber('order')->name('pedidos.ver');
    // Cliente cancela a própria compra antes de a etiqueta sair (2026-10-17).
    Route::post('/pedidos/{order}/cancelar', [CheckoutController::class, 'cancelOrder'])->whereNumber('order')->middleware('throttle:10,1')->name('pedidos.cancelar.cliente');
    Route::get('/pedidos/{order}/nota/{tipo}', [CheckoutController::class, 'notaDoCliente'])->whereNumber('order')->whereIn('tipo', ['pdf', 'xml'])->name('pedidos.nota.cliente');
    Route::get('/pedidos/{order}/suporte', [\App\Modules\Suporte\Http\Controllers\ChatSuporteController::class, 'mostrar'])->whereNumber('order')->name('pedidos.suporte');
    Route::post('/pedidos/{order}/suporte', [\App\Modules\Suporte\Http\Controllers\ChatSuporteController::class, 'enviar'])->whereNumber('order')->middleware('throttle:20,1')->name('pedidos.suporte.enviar');
});

// Área de conta do usuário autenticado — acessível tanto pela loja quanto
// pelo painel admin, sempre operando sobre o próprio usuário logado.
Route::middleware('auth')->group(function () {
    Route::get('/perfil', [ProfileController::class, 'edit'])->name('perfil.editar');
    Route::put('/perfil', [ProfileController::class, 'update'])->name('perfil.atualizar');
    // Mesma tela, mas abrindo o perfil de outro usuário — o controller exige
    // que quem está logado seja admin sempre que o alvo não for ele mesmo.
    Route::get('/perfil/usuario/{user}', [ProfileController::class, 'edit'])->name('perfil.editar-outro');
    Route::put('/perfil/usuario/{user}', [ProfileController::class, 'update'])->name('perfil.atualizar-outro');
    Route::put('/perfil/senha', [ProfilePasswordController::class, 'update'])->name('perfil.senha.atualizar');
    Route::post('/perfil/avatar', [ProfileAvatarController::class, 'store'])->name('perfil.avatar.adicionar');
    Route::delete('/perfil/avatar', [ProfileAvatarController::class, 'destroy'])->name('perfil.avatar.remover');
    Route::post('/perfil/enderecos', [ProfileAddressController::class, 'store'])->name('perfil.enderecos.adicionar');
    Route::put('/perfil/enderecos/{address}', [ProfileAddressController::class, 'update'])->whereNumber('address')->name('perfil.enderecos.atualizar');
    Route::delete('/perfil/enderecos/{address}', [ProfileAddressController::class, 'destroy'])->whereNumber('address')->name('perfil.enderecos.remover');

    Route::get('/configuracoes', [SettingsController::class, 'edit'])->name('configuracoes.editar');

    Route::get('/notificacoes', [NotificationController::class, 'index'])->name('notificacoes.listar');
    Route::get('/notificacoes/{notification}', [NotificationController::class, 'show'])->whereUuid('notification')->name('notificacoes.ver');
    Route::post('/notificacoes/{notification}/lida', [NotificationController::class, 'markRead'])->name('notificacoes.lida');
    Route::post('/notificacoes/ler-todas', [NotificationController::class, 'markAllRead'])->name('notificacoes.ler-todas');
    Route::delete('/notificacoes/{notification}', [NotificationController::class, 'destroy'])->name('notificacoes.excluir');
});

Route::prefix('admin')->name('admin.')->middleware(['auth', 'staff'])->group(function () {
    // Filas (Horizon) aberto dentro do admin (pedido 2026-10-11).
    Route::inertia('filas', 'Admin/Filas/Index')->middleware('admin')->name('filas');
    Route::get('/', [DashboardController::class, 'index'])->name('painel');
    // Botões de ação do diagnóstico do Cauê (pedido 2026-10-14): mexem na Shopee, só admin.
    Route::post('diagnostico/shopee/acao', [DiagnosticoAcaoController::class, 'shopee'])->middleware('admin')->name('diagnostico.shopee.acao');
    Route::get('diagnostico/shopee/{itemId}/modelos', [DiagnosticoAcaoController::class, 'modelos'])->middleware('admin')->name('diagnostico.shopee.modelos');

    Route::resource('produtos', ProductController::class)->except('show')
        ->parameters(['produtos' => 'product'])
        ->names([
            'index' => 'produtos.listar',
            'create' => 'produtos.criar',
            'store' => 'produtos.armazenar',
            'edit' => 'produtos.editar',
            'update' => 'produtos.atualizar',
            'destroy' => 'produtos.excluir',
        ])
        ->middlewareFor(['create', 'store'], 'permission:cadastros.create')
        ->middlewareFor(['edit', 'update'], 'permission:cadastros.edit')
        ->middlewareFor('destroy', 'permission:cadastros.delete');

    Route::post('produtos/sku-preview', [ProductController::class, 'previewSku'])
        ->name('produtos.sku-preview')
        ->middleware('permission:cadastros.create');

    Route::resource('categorias', CategoryController::class)->except('show')
        ->parameters(['categorias' => 'category'])
        ->names([
            'index' => 'categorias.listar',
            'create' => 'categorias.criar',
            'store' => 'categorias.armazenar',
            'edit' => 'categorias.editar',
            'update' => 'categorias.atualizar',
            'destroy' => 'categorias.excluir',
        ])
        ->middlewareFor(['create', 'store'], 'permission:cadastros.create')
        ->middlewareFor(['edit', 'update'], 'permission:cadastros.edit')
        ->middlewareFor('destroy', 'permission:cadastros.delete');

    Route::resource('banners', BannerController::class)->except(['show', 'create'])
        ->parameters(['banners' => 'banner'])
        ->names([
            'index' => 'banners.listar',
            'store' => 'banners.armazenar',
            'edit' => 'banners.editar',
            'update' => 'banners.atualizar',
            'destroy' => 'banners.excluir',
        ])
        ->middlewareFor('store', 'permission:cadastros.create')
        ->middlewareFor(['edit', 'update'], 'permission:cadastros.edit')
        ->middlewareFor('destroy', 'permission:cadastros.delete');

    Route::resource('avaliacoes', AdminReviewController::class)->only(['index', 'show', 'edit', 'update', 'destroy'])
        ->parameters(['avaliacoes' => 'review'])
        ->names([
            'index' => 'avaliacoes.listar',
            'show' => 'avaliacoes.exibir',
            'edit' => 'avaliacoes.editar',
            'update' => 'avaliacoes.atualizar',
            'destroy' => 'avaliacoes.excluir',
        ])
        ->middlewareFor(['index', 'show'], 'permission:cadastros.view')
        ->middlewareFor(['edit', 'update'], 'permission:cadastros.edit')
        ->middlewareFor('destroy', 'permission:cadastros.delete');

    // Cupons de desconto + disparo em lote (pedido 2026-10-10).
    Route::get('cupons', [CouponController::class, 'index'])->middleware('permission:cadastros.view')->name('cupons.listar');
    Route::post('cupons', [CouponController::class, 'store'])->middleware('permission:cadastros.create')->name('cupons.armazenar');
    Route::put('cupons/{coupon}', [CouponController::class, 'update'])->middleware('permission:cadastros.edit')->name('cupons.atualizar');
    Route::patch('cupons/{coupon}/ativo', [CouponController::class, 'toggle'])->middleware('permission:cadastros.edit')->name('cupons.alternar');
    Route::delete('cupons/{coupon}', [CouponController::class, 'destroy'])->middleware('permission:cadastros.delete')->name('cupons.excluir');
    Route::get('cupons/{coupon}/disparo', [CouponController::class, 'disparo'])->middleware('permission:cadastros.view')->name('cupons.disparo');
    Route::get('cupons/{coupon}/publico', [CouponController::class, 'contarPublico'])->middleware('permission:cadastros.view')->name('cupons.publico');
    Route::post('cupons/{coupon}/disparo', [CouponController::class, 'disparar'])->middleware('permission:cadastros.create')->name('cupons.disparar');

    // Ofertas Relâmpago por empresa (pedido 2026-10-14): mesmas permissões dos cupons.
    Route::get('ofertas-relampago', [OfertaRelampagoController::class, 'index'])->middleware('permission:cadastros.view')->name('ofertas-relampago.listar');
    Route::get('ofertas-relampago/produtos', [OfertaRelampagoController::class, 'produtos'])->middleware('permission:cadastros.view')->name('ofertas-relampago.produtos');
    Route::get('ofertas-relampago/previa', [OfertaRelampagoController::class, 'previa'])->middleware('permission:cadastros.view')->name('ofertas-relampago.previa');
    Route::post('ofertas-relampago', [OfertaRelampagoController::class, 'store'])->middleware('permission:cadastros.create')->name('ofertas-relampago.armazenar');
    Route::put('ofertas-relampago/{oferta}', [OfertaRelampagoController::class, 'update'])->whereNumber('oferta')->middleware('permission:cadastros.edit')->name('ofertas-relampago.atualizar');
    Route::patch('ofertas-relampago/{oferta}/ativo', [OfertaRelampagoController::class, 'alternar'])->whereNumber('oferta')->middleware('permission:cadastros.edit')->name('ofertas-relampago.alternar');
    Route::delete('ofertas-relampago/{oferta}', [OfertaRelampagoController::class, 'destroy'])->whereNumber('oferta')->middleware('permission:cadastros.delete')->name('ofertas-relampago.excluir');

    Route::get('notificacoes-promocionais', [PromotionalNotificationController::class, 'index'])
        ->middleware('permission:cadastros.view')
        ->name('notificacoes-promocionais.listar');
    Route::post('notificacoes-promocionais', [PromotionalNotificationController::class, 'store'])
        ->middleware('permission:cadastros.create')
        ->name('notificacoes-promocionais.armazenar');

    // Logos e favicon da loja (pedido 2026-10-10).
    Route::post('banners/marca', [BannerController::class, 'salvarMarca'])
        ->middleware('permission:cadastros.edit')
        ->name('banners.marca');
    Route::patch('banners/{banner}/subir', [BannerController::class, 'moveUp'])
        ->middleware('permission:cadastros.edit')
        ->name('banners.subir');
    Route::patch('banners/{banner}/descer', [BannerController::class, 'moveDown'])
        ->middleware('permission:cadastros.edit')
        ->name('banners.descer');

    Route::get('concorrencia', [CompetitorAnalysisController::class, 'index'])
        ->name('concorrencia.listar')
        ->middleware('permission:cadastros.view');

    Route::get('precificacao', [PricingCalculatorController::class, 'index'])
        ->name('precificacao.calculadora')
        ->middleware('permission:cadastros.view');

    // Calculadora de importação (2026-10-12): só calcula, não grava nada.
    Route::get('calculadora-importacao', [\App\Modules\Admin\Http\Controllers\CalculadoraImportacaoController::class, 'index'])
        ->name('calculadora-importacao')
        ->middleware('permission:cadastros.view');
    Route::get('calculadora-importacao/cotacao', [\App\Modules\Admin\Http\Controllers\CalculadoraImportacaoController::class, 'cotacao'])
        ->name('calculadora-importacao.cotacao')
        ->middleware('permission:cadastros.view');

    Route::resource('fornecedores', SupplierController::class)->except('show')
        ->parameters(['fornecedores' => 'supplier'])
        ->names([
            'index' => 'fornecedores.listar',
            'create' => 'fornecedores.criar',
            'store' => 'fornecedores.armazenar',
            'edit' => 'fornecedores.editar',
            'update' => 'fornecedores.atualizar',
            'destroy' => 'fornecedores.excluir',
        ])
        ->middlewareFor(['create', 'store'], 'permission:cadastros.create')
        ->middlewareFor(['edit', 'update'], 'permission:cadastros.edit')
        ->middlewareFor('destroy', 'permission:cadastros.delete');

    Route::resource('centros-de-custo', CostCenterController::class)->except('show')
        ->parameters(['centros-de-custo' => 'cost_center'])
        ->names([
            'index' => 'centros-de-custo.listar',
            'create' => 'centros-de-custo.criar',
            'store' => 'centros-de-custo.armazenar',
            'edit' => 'centros-de-custo.editar',
            'update' => 'centros-de-custo.atualizar',
            'destroy' => 'centros-de-custo.excluir',
        ])
        ->middlewareFor(['create', 'store'], 'permission:cadastros.create')
        ->middlewareFor(['edit', 'update'], 'permission:cadastros.edit')
        ->middlewareFor('destroy', 'permission:cadastros.delete');

    Route::put('produtos/{product}/fiscal', [ProductFiscalController::class, 'update'])->name('produtos.fiscal.atualizar');
    Route::post('produtos/{product}/conteudo-anuncio', [ProductController::class, 'regenerateAdContent'])->middleware('throttle:10,1')->name('produtos.conteudo-anuncio.gerar');
    Route::post('produtos/{product}/descricao-ia', [ProductController::class, 'descricaoPelasFotos'])->middleware('throttle:10,1')->name('produtos.descricao-ia');
    Route::put('produtos/{product}/logistica', [ProductLogisticsController::class, 'update'])->name('produtos.logistica.atualizar');
    Route::put('produtos/{product}/descontos-quantidade', [ProductQuantityDiscountController::class, 'update'])->name('produtos.descontos-quantidade.atualizar');
    Route::post('produtos/{product}/imagens', [ProductImageController::class, 'store'])->name('produtos.imagens.adicionar');
    Route::put('produtos/{product}/imagens/ordem', [ProductImageController::class, 'reorder'])->name('produtos.imagens.ordenar');
    Route::delete('produtos/{product}/imagens/{image}', [ProductImageController::class, 'destroy'])->name('produtos.imagens.remover');
    Route::post('produtos/{product}/video', [ProductVideoController::class, 'store'])->name('produtos.video.adicionar');
    Route::delete('produtos/{product}/video', [ProductVideoController::class, 'destroy'])->name('produtos.video.remover');
    Route::put('produtos/{product}/canais/{channel}', [ProductChannelController::class, 'update'])->name('produtos.canais.atualizar');
    Route::put('produtos/{product}/canais/{channel}/preco', [ProductChannelController::class, 'updatePrice'])->name('produtos.canais.preco');
    Route::post('produtos/{product}/canais/{channel}/sincronizar', [ProductChannelController::class, 'sync'])->name('produtos.canais.sincronizar');
    Route::delete('produtos/{product}/canais/{channel}', [ProductChannelController::class, 'destroy'])->name('produtos.canais.excluir');
    Route::post('produtos/{product}/variacoes/vincular', [ProductController::class, 'attachVariation'])->name('produtos.variacoes.vincular');
    Route::post('produtos/{product}/variacoes/desvincular', [ProductController::class, 'detachVariation'])->name('produtos.variacoes.desvincular');

    Route::get('pedidos', [AdminOrderController::class, 'index'])->name('pedidos.listar');
    // Pedidos do site Beauty Kora (2026-10-12): tabela própria, fora de orders.
    Route::get('pedidos-beautykora', [BeautyKoraPedidoController::class, 'index'])
        ->name('pedidos-beautykora.listar')
        ->middleware('permission:pedidos.view');
    Route::patch('pedidos-beautykora/{pedido}/status', [BeautyKoraPedidoController::class, 'status'])
        ->name('pedidos-beautykora.status')
        ->middleware('permission:pedidos.edit');
    Route::post('pedidos/corrigir-etiquetas-hoje', [AdminOrderController::class, 'fixTodaysLabels'])
        ->name('pedidos.corrigir-etiquetas-hoje')
        ->middleware('permission:pedidos.edit');
    Route::get('pedidos/criar', [ManualOrderController::class, 'create'])
        ->name('pedidos.criar')
        ->middleware('permission:pedidos.edit');
    Route::post('pedidos/criar', [ManualOrderController::class, 'store'])
        ->name('pedidos.criar.armazenar')
        ->middleware('permission:pedidos.edit');
    Route::get('pedidos/{order}', [AdminOrderController::class, 'show'])->name('pedidos.exibir');
    // Vídeo da embalagem gravado no KoraSync (2026-10-11): assistir/baixar.
    // Todos os vídeos da embalagem numa página só (2026-10-13).
    Route::get('videos-embalagem', [VideosEmbalagemController::class, 'index'])
        ->name('videos-embalagem.listar')
        ->middleware('permission:pedidos.view');
    // O que o KoraSync grava: todos, nenhum ou só produtos/categorias escolhidos (2026-10-13).
    Route::put('videos-embalagem/regra', [VideosEmbalagemController::class, 'salvarRegra'])
        ->name('videos-embalagem.regra')
        ->middleware('permission:pedidos.edit');
    Route::get('pedidos/{order}/videos/{packingVideo}', [AdminOrderController::class, 'packingVideo'])->name('pedidos.video');
    Route::patch('pedidos/{order}', [AdminOrderController::class, 'update'])
        ->name('pedidos.atualizar')
        ->middleware('permission:pedidos.edit');
    // Botão "Cancelar pedido" com motivo e prévia do que acontece (2026-10-17).
    Route::post('pedidos/{order}/cancelar', [AdminOrderController::class, 'cancel'])
        ->name('pedidos.cancelar')
        ->middleware('permission:pedidos.edit');
    Route::patch('pedidos/{order}/itens', [AdminOrderController::class, 'updateItems'])
        ->name('pedidos.itens.atualizar')
        ->middleware('permission:pedidos.edit');
    Route::post('pedidos/{order}/verificar-etiqueta', [AdminOrderController::class, 'checkLabel'])
        ->name('pedidos.verificar-etiqueta')
        ->middleware('permission:pedidos.edit');
    Route::get('pedidos/{order}/etiqueta/imprimir', [AdminOrderController::class, 'printLabel'])
        ->name('pedidos.etiqueta.imprimir')
        ->middleware('permission:pedidos.view');
    Route::post('pedidos/{order}/nota/emitir', [InvoiceController::class, 'issue'])
        ->name('pedidos.nota.emitir')
        ->middleware('permission:pedidos.edit');
    Route::post('pedidos/{order}/nota/cancelar', [InvoiceController::class, 'cancel'])
        ->name('pedidos.nota.cancelar')
        ->middleware('permission:pedidos.edit');
    Route::post('pedidos/{order}/nota/reenviar-canal', [InvoiceController::class, 'resubmitToChannel'])
        ->name('pedidos.nota.reenviar-canal')
        ->middleware('permission:pedidos.edit');
    Route::get('pedidos/{order}/nota/danfe', [InvoiceController::class, 'danfe'])
        ->name('pedidos.nota.danfe')
        ->middleware('permission:pedidos.view');
    Route::get('pedidos/{order}/nota/xml', [InvoiceController::class, 'xml'])
        ->name('pedidos.nota.xml')
        ->middleware('permission:pedidos.view');

    Route::get('notas-fiscais', [InvoiceController::class, 'index'])
        ->name('notas-fiscais.listar')
        ->middleware('permission:pedidos.view');
    Route::get('notas-fiscais/emitir', [InvoiceManualController::class, 'create'])
        ->name('notas-fiscais.emitir')
        ->middleware('permission:pedidos.edit');
    Route::post('notas-fiscais/emitir', [InvoiceManualController::class, 'store'])
        ->name('notas-fiscais.emitir.armazenar')
        ->middleware('permission:pedidos.edit');
    Route::post('notas-fiscais/sincronizar', [InvoiceController::class, 'syncSefaz'])
        ->name('notas-fiscais.sincronizar')
        ->middleware('permission:pedidos.edit');
    Route::get('notas-fiscais/fechamento', [FiscalClosingController::class, 'index'])
        ->name('notas-fiscais.fechamento')
        ->middleware('permission:pedidos.view');
    Route::get('notas-fiscais/fechamento/{mes}/zip', [FiscalClosingController::class, 'zip'])
        ->where('mes', '\d{4}-\d{2}')
        ->name('notas-fiscais.fechamento.zip')
        ->middleware('permission:pedidos.view');
    Route::get('notas-fiscais/fechamento/{mes}/csv', [FiscalClosingController::class, 'csv'])
        ->where('mes', '\d{4}-\d{2}')
        ->name('notas-fiscais.fechamento.csv')
        ->middleware('permission:pedidos.view');
    Route::post('notas-fiscais/fechamento/enviar', [FiscalClosingController::class, 'send'])
        ->name('notas-fiscais.fechamento.enviar')
        ->middleware('permission:pedidos.edit');
    Route::post('notas-fiscais/fechamento/inutilizar', [FiscalClosingController::class, 'inutilize'])
        ->name('notas-fiscais.fechamento.inutilizar')
        ->middleware('permission:pedidos.edit');
    Route::post('notas-fiscais/fechamento/duplicidades/{ocorrencia}/conferida', [FiscalClosingController::class, 'resolveDuplicate'])
        ->name('notas-fiscais.fechamento.duplicidade')
        ->middleware('permission:pedidos.edit');
    Route::post('notas-fiscais/fechamento/ufesp', [FiscalClosingController::class, 'updateUfesp'])
        ->name('notas-fiscais.fechamento.ufesp')
        ->middleware('permission:pedidos.edit');
    Route::get('notas-fiscais/{invoice}', [InvoiceController::class, 'show'])
        ->name('notas-fiscais.exibir')
        ->middleware('permission:pedidos.view');
    Route::get('notas-fiscais/{invoice}/danfe', [InvoiceController::class, 'danfeForInvoice'])
        ->name('notas-fiscais.danfe')
        ->middleware('permission:pedidos.view');
    Route::get('notas-fiscais/{invoice}/xml', [InvoiceController::class, 'xmlForInvoice'])
        ->name('notas-fiscais.xml')
        ->middleware('permission:pedidos.view');
    Route::post('notas-fiscais/{invoice}/cancelar', [InvoiceController::class, 'cancelInvoice'])
        ->name('notas-fiscais.cancelar')
        ->middleware('permission:pedidos.edit');
    Route::get('notas-fiscais/{invoice}/cancelamento-xml', [InvoiceReturnController::class, 'cancellationXml'])
        ->name('notas-fiscais.cancelamento-xml')
        ->middleware('permission:pedidos.view');
    Route::get('notas-fiscais/{invoice}/declaracao-devolucao', [InvoiceReturnController::class, 'declaration'])
        ->name('notas-fiscais.declaracao-devolucao')
        ->middleware('permission:pedidos.view');
    Route::post('notas-fiscais/{invoice}/devolucao', [InvoiceReturnController::class, 'store'])
        ->name('notas-fiscais.devolucao')
        ->middleware('permission:pedidos.edit');
    Route::post('notas-fiscais/{invoice}/declaracao-assinada', [InvoiceReturnController::class, 'attachDeclaration'])
        ->name('notas-fiscais.declaracao-assinada')
        ->middleware('permission:pedidos.edit');
    Route::get('notas-fiscais/{invoice}/declaracao-assinada', [InvoiceReturnController::class, 'downloadDeclaration'])
        ->name('notas-fiscais.declaracao-assinada.baixar')
        ->middleware('permission:pedidos.view');

    Route::get('clientes', [CustomerController::class, 'index'])->name('clientes.listar');
    Route::get('clientes/{document}', [CustomerController::class, 'show'])->name('clientes.exibir');

    Route::get('empresa', [CompanyController::class, 'edit'])->name('empresa.editar');
    Route::put('empresa', [CompanyController::class, 'update'])->name('empresa.atualizar');

    Route::get('pagamentos', [PaymentSettingsController::class, 'edit'])->name('pagamentos.editar');
    Route::put('pagamentos', [PaymentSettingsController::class, 'update'])->name('pagamentos.atualizar');

    // Gestão
    Route::middleware('permission:relatorios.view')->group(function () {
        Route::get('relatorios', [ReportController::class, 'index'])->name('relatorios.ver');
        Route::get('indicadores', [KpiController::class, 'index'])->name('indicadores.ver');
        // Radar (2026-10-12): uma tela só, no grupo Oportunidades do menu.
        // Submenu TikTok / Mercado Livre / Google (pedido do Lira 2026-10-12):
        // a mesma tela mostrando só o bloco daquela fonte.
        Route::get('radar', [RadarController::class, 'index'])->name('radar.index');
        // O Radar do ML fica só em Oportunidades › Mercado Livre (mais vendidos
        // em cima, tabela do Radar embaixo; Lira 2026-10-12). O endereço antigo
        // redireciona para lá. Vem antes de radar/{fonte}.
        Route::redirect('radar/mercado-livre', '/admin/oportunidades/mercado-livre', 301)
            ->name('radar.mercado-livre');
        Route::get('radar/{fonte}', [RadarController::class, 'index'])
            ->whereIn('fonte', array_keys(RadarController::FONTES))
            ->name('radar.fonte');
        // Oportunidades (correção do Lira 2026-10-12): mais vendidos e buscas
        // em alta de TERCEIROS no Mercado Livre e na Shopee (dado de mercado,
        // não as vendas da loja).
        // Inteligência (Lira 2026-10-13): JSON do modal do Mercado Livre.
        // Vem antes de oportunidades/{canal}.
        Route::get('oportunidades/mercado-livre/inteligencia', [\App\Modules\Admin\Http\Controllers\OportunidadesController::class, 'inteligencia'])
            ->middleware('throttle:30,1')
            ->name('oportunidades.inteligencia');
        Route::get('oportunidades/{canal}', [\App\Modules\Admin\Http\Controllers\OportunidadesController::class, 'show'])
            ->whereIn('canal', array_keys(\App\Modules\Admin\Http\Controllers\OportunidadesController::CANAIS))
            ->middleware('throttle:30,1')
            ->name('oportunidades.ver');
        // Gerador de fotos de anúncio (Naia, 28-30/08) — feito direto no
        // servidor, apagado por um deploy e restaurado em 2026-09-29.
        Route::get('marketplaces/fotos-anuncio', [MarketplaceAdPhotoController::class, 'index'])->name('marketplaces.fotos-anuncio');
        Route::get('marketplaces/fotos-anuncio/criar', [MarketplaceAdPhotoController::class, 'create'])->name('marketplaces.fotos-anuncio.criar');
        Route::post('marketplaces/fotos-anuncio', [MarketplaceAdPhotoController::class, 'generate'])
            ->middleware('throttle:10,1')
            ->name('marketplaces.fotos-anuncio.gerar');
        Route::get('marketplaces/fotos-anuncio/{brief}', [MarketplaceAdPhotoController::class, 'show'])->name('marketplaces.fotos-anuncio.exibir');
        Route::patch('marketplaces/fotos-anuncio/{brief}/aprovar', [MarketplaceAdPhotoController::class, 'approve'])->name('marketplaces.fotos-anuncio.aprovar');
        Route::patch('marketplaces/fotos-anuncio/{brief}/reprovar', [MarketplaceAdPhotoController::class, 'reject'])->name('marketplaces.fotos-anuncio.reprovar');
        Route::post('marketplaces/fotos-anuncio/{brief}/gerar-criativos', [MarketplaceAdPhotoController::class, 'startGeneration'])->name('marketplaces.fotos-anuncio.gerar-criativos');
        Route::get('mkt-digital', [DigitalMarketingController::class, 'index'])->name('mkt-digital');
        Route::post('mkt-digital/atualizar', [DigitalMarketingController::class, 'refresh'])
            ->middleware('throttle:4,1')
            ->name('mkt-digital.atualizar');
        Route::get('paginas-de-conversao', [ConversionPageController::class, 'index'])->name('paginas-de-conversao');
        Route::post('paginas-de-conversao/atualizar', [ConversionPageController::class, 'refresh'])
            ->middleware('throttle:4,1')
            ->name('paginas-de-conversao.atualizar');
        Route::get('pdf-oportunidades', [PdfOpportunityController::class, 'index'])->name('pdf-oportunidades');
        Route::post('pdf-oportunidades/atualizar', [PdfOpportunityController::class, 'refresh'])
            ->middleware('throttle:4,1')
            ->name('pdf-oportunidades.atualizar');
    });

    Route::middleware('permission:financeiro.view')->group(function () {
        Route::get('dashboard-financeiro', [FinancialDashboardController::class, 'index'])->name('dashboard-financeiro.ver');
        // Estatísticas (pedido do Lira 2026-10-12): curva ABC com lucro por
        // marketplace. Saiu do menu Oportunidades (só dado de mercado).
        Route::get('estatisticas/curva-abc/{canal}', [\App\Modules\Admin\Http\Controllers\EstatisticasController::class, 'curvaAbc'])
            ->whereIn('canal', array_keys(\App\Modules\Admin\Http\Controllers\EstatisticasController::CANAIS))
            ->name('estatisticas.curva-abc');
        // Arquivos financeiros do TikTok enviados à mão (2026-10-11).
        Route::get('arquivos-tiktok', [\App\Modules\Admin\Http\Controllers\ArquivosTikTokController::class, 'index'])->name('arquivos-tiktok.listar');
        Route::post('arquivos-tiktok', [\App\Modules\Admin\Http\Controllers\ArquivosTikTokController::class, 'store'])->name('arquivos-tiktok.enviar');
        Route::get('fluxo-de-caixa', [CashFlowController::class, 'index'])->name('fluxo-de-caixa.listar');
        Route::get('anuncios/recargas', [AdsRechargeController::class, 'index'])->name('anuncios.recargas.listar');
        // Menu Publicidade (2026-10-12): resumo de gasto de ADS por canal.
        Route::get('publicidade', [\App\Modules\Admin\Http\Controllers\PublicidadeController::class, 'index'])->name('publicidade.ver');
        // Menu Publicidade (2026-10-12): Meta ADS e Google ADS, só leitura.
        Route::get('publicidade/meta-ads', [\App\Modules\Admin\Http\Controllers\PublicidadePlataformaController::class, 'metaAds'])->name('publicidade.meta-ads');
        Route::get('publicidade/google-ads', [\App\Modules\Admin\Http\Controllers\PublicidadePlataformaController::class, 'googleAds'])->name('publicidade.google-ads');
        // Estratégia Shopee (2026-10-14): campanhas de ADS com prejuízo, só leitura.
        Route::get('publicidade/shopee', [\App\Modules\Admin\Http\Controllers\PublicidadeShopeeController::class, 'index'])->name('publicidade.shopee');
    });
    // Meta ADS, campanhas de vídeo (2026-10-13): Lira sobe os vídeos e a
    // campanha vai para a Meta com o orçamento dividido entre eles. Ver é
    // financeiro.view; criar, enviar, ativar e pausar (gastam dinheiro)
    // exigem financeiro.create.
    Route::prefix('publicidade/meta-ads/campanhas')->name('publicidade.meta-ads.campanhas.')->group(function () {
        Route::get('/', [\App\Modules\Admin\Http\Controllers\MetaAdsController::class, 'index'])->name('listar')->middleware('permission:financeiro.view');
        Route::get('nova', [\App\Modules\Admin\Http\Controllers\MetaAdsController::class, 'create'])->name('criar')->middleware('permission:financeiro.create');
        Route::post('/', [\App\Modules\Admin\Http\Controllers\MetaAdsController::class, 'store'])->name('armazenar')->middleware('permission:financeiro.create');
        Route::get('localizacoes', [\App\Modules\Admin\Http\Controllers\MetaAdsController::class, 'localizacoes'])->name('localizacoes')->middleware(['permission:financeiro.create', 'throttle:30,1']);
        Route::get('{campanha}', [\App\Modules\Admin\Http\Controllers\MetaAdsController::class, 'show'])->whereNumber('campanha')->name('ver')->middleware('permission:financeiro.view');
        Route::get('{campanha}/editar', [\App\Modules\Admin\Http\Controllers\MetaAdsController::class, 'edit'])->whereNumber('campanha')->name('editar')->middleware('permission:financeiro.create');
        Route::put('{campanha}', [\App\Modules\Admin\Http\Controllers\MetaAdsController::class, 'update'])->whereNumber('campanha')->name('atualizar')->middleware('permission:financeiro.create');
        Route::delete('{campanha}', [\App\Modules\Admin\Http\Controllers\MetaAdsController::class, 'destroy'])->whereNumber('campanha')->name('excluir')->middleware('permission:financeiro.create');
        Route::post('{campanha}/videos', [\App\Modules\Admin\Http\Controllers\MetaAdsController::class, 'videoStore'])->whereNumber('campanha')->name('videos.armazenar')->middleware('permission:financeiro.create');
        Route::delete('{campanha}/videos/{video}', [\App\Modules\Admin\Http\Controllers\MetaAdsController::class, 'videoDestroy'])->whereNumber(['campanha', 'video'])->name('videos.excluir')->middleware('permission:financeiro.create');
        Route::get('{campanha}/videos/{video}/arquivo', [\App\Modules\Admin\Http\Controllers\MetaAdsController::class, 'videoArquivo'])->whereNumber(['campanha', 'video'])->name('videos.arquivo')->middleware('permission:financeiro.view');
        Route::post('{campanha}/enviar', [\App\Modules\Admin\Http\Controllers\MetaAdsController::class, 'enviar'])->whereNumber('campanha')->name('enviar')->middleware(['permission:financeiro.create', 'throttle:10,1']);
        Route::post('{campanha}/ativar', [\App\Modules\Admin\Http\Controllers\MetaAdsController::class, 'ativar'])->whereNumber('campanha')->name('ativar')->middleware(['permission:financeiro.create', 'throttle:10,1']);
        Route::post('{campanha}/pausar', [\App\Modules\Admin\Http\Controllers\MetaAdsController::class, 'pausar'])->whereNumber('campanha')->name('pausar')->middleware(['permission:financeiro.create', 'throttle:10,1']);
    });
    // Estratégia Shopee, botão Ação (tarefa 569, 2026-10-14): o Cauê pausa,
    // retoma ou muda a meta de ROAS da campanha do produto, só pelo clique
    // do Lira. Escrita real só com SHOPEE_ADS_ESCRITA=true; sem ela, simula.
    Route::prefix('publicidade/shopee/acoes')->name('publicidade.shopee.acoes.')->group(function () {
        Route::get('item/{item}', [\App\Modules\Admin\Http\Controllers\ShopeeAdsAcaoController::class, 'preparar'])->where('item', '[0-9]+')->name('preparar')->middleware(['permission:financeiro.view', 'throttle:30,1']);
        Route::post('/', [\App\Modules\Admin\Http\Controllers\ShopeeAdsAcaoController::class, 'aplicar'])->name('aplicar')->middleware(['permission:financeiro.create', 'throttle:20,1']);
        Route::post('{acao}/conferir', [\App\Modules\Admin\Http\Controllers\ShopeeAdsAcaoController::class, 'conferir'])->whereNumber('acao')->name('conferir')->middleware(['permission:financeiro.create', 'throttle:20,1']);
        Route::post('{acao}/desfazer', [\App\Modules\Admin\Http\Controllers\ShopeeAdsAcaoController::class, 'desfazer'])->whereNumber('acao')->name('desfazer')->middleware(['permission:financeiro.create', 'throttle:10,1']);
    });
    Route::post('fluxo-de-caixa', [CashFlowController::class, 'store'])->name('fluxo-de-caixa.armazenar')->middleware('permission:financeiro.create');
    Route::put('fluxo-de-caixa/{cash_flow_entry}', [CashFlowController::class, 'update'])->name('fluxo-de-caixa.atualizar')->middleware('permission:financeiro.edit');
    Route::put('fluxo-de-caixa/vendas/{order}/comissao', [CashFlowController::class, 'updateSaleFee'])->name('fluxo-de-caixa.vendas.comissao')->middleware('permission:financeiro.edit');
    Route::put('fluxo-de-caixa/vendas/item/{order_item}/custo', [CashFlowController::class, 'updateItemCost'])->name('fluxo-de-caixa.vendas.custo')->middleware('permission:financeiro.edit');
    Route::delete('fluxo-de-caixa/{cash_flow_entry}', [CashFlowController::class, 'destroy'])->name('fluxo-de-caixa.excluir')->middleware('permission:financeiro.delete');
    Route::post('anuncios/recargas', [AdsRechargeController::class, 'store'])->name('anuncios.recargas.armazenar')->middleware('permission:financeiro.create');
    Route::delete('anuncios/recargas/{ads_recharge}', [AdsRechargeController::class, 'destroy'])->name('anuncios.recargas.excluir')->middleware('permission:financeiro.delete');

    // Operacional
    Route::get('estoque', [StockMovementController::class, 'index'])->name('estoque.listar')->middleware('permission:operacional.view');
    Route::get('download-videos', [VideoDownloadController::class, 'index'])
        ->name('download-videos.index')
        ->middleware('permission:operacional.create');
    Route::post('download-videos', [VideoDownloadController::class, 'store'])
        ->middleware(['permission:operacional.create', 'throttle:5,1'])
        ->name('download-videos.store');

    Route::resource('pedidos-de-compra', PurchaseOrderController::class)->except(['edit', 'update'])
        ->parameters(['pedidos-de-compra' => 'purchase_order'])
        ->names([
            'index' => 'pedidos-de-compra.listar',
            'create' => 'pedidos-de-compra.criar',
            'store' => 'pedidos-de-compra.armazenar',
            'show' => 'pedidos-de-compra.exibir',
            'destroy' => 'pedidos-de-compra.excluir',
        ])
        ->middlewareFor(['index', 'show'], 'permission:operacional.view')
        ->middlewareFor(['create', 'store'], 'permission:operacional.create')
        ->middlewareFor('destroy', 'permission:operacional.delete');
    Route::patch('pedidos-de-compra/{purchase_order}/status', [PurchaseOrderController::class, 'updateStatus'])
        ->name('pedidos-de-compra.status.atualizar')->middleware('permission:operacional.edit');
    Route::post('pedidos-de-compra/{purchase_order}/receber', [PurchaseOrderController::class, 'receive'])
        ->name('pedidos-de-compra.receber')->middleware('permission:operacional.edit');

    Route::resource('ordens-de-servico', ServiceOrderController::class)->except('show')
        ->parameters(['ordens-de-servico' => 'service_order'])
        ->names([
            'index' => 'ordens-de-servico.listar',
            'create' => 'ordens-de-servico.criar',
            'store' => 'ordens-de-servico.armazenar',
            'edit' => 'ordens-de-servico.editar',
            'update' => 'ordens-de-servico.atualizar',
            'destroy' => 'ordens-de-servico.excluir',
        ])
        ->middlewareFor('index', 'permission:operacional.view')
        ->middlewareFor(['create', 'store'], 'permission:operacional.create')
        ->middlewareFor(['edit', 'update'], 'permission:operacional.edit')
        ->middlewareFor('destroy', 'permission:operacional.delete');

    Route::middleware('permission:operacional.view')->group(function () {
        Route::get('logistica', [ShippingMethodController::class, 'index'])->name('logistica.listar');
        // Fretes dos Correios para as 27 capitais (2026-10-12): só consulta.
        Route::get('logistica/fretes-por-estado', [\App\Modules\Admin\Http\Controllers\FretesPorEstadoController::class, 'index'])->name('logistica.fretes-por-estado');
        Route::post('logistica/fretes-por-estado/cotar', [\App\Modules\Admin\Http\Controllers\FretesPorEstadoController::class, 'cotar'])->name('logistica.fretes-por-estado.cotar');
        Route::post('logistica/fretes-por-estado/cotar-melhor-envio', [\App\Modules\Admin\Http\Controllers\FretesPorEstadoController::class, 'cotarMelhorEnvio'])->name('logistica.fretes-por-estado.cotar-melhor-envio');
    });
    Route::post('logistica', [ShippingMethodController::class, 'store'])->name('logistica.armazenar')->middleware('permission:operacional.create');
    Route::put('logistica/{shipping_method}', [ShippingMethodController::class, 'update'])->name('logistica.atualizar')->middleware('permission:operacional.edit');
    Route::delete('logistica/{shipping_method}', [ShippingMethodController::class, 'destroy'])->name('logistica.excluir')->middleware('permission:operacional.delete');
    Route::delete('logistica/melhor-envio', [ShippingMethodController::class, 'disconnectMelhorEnvio'])->name('logistica.melhor-envio.desconectar')->middleware('permission:operacional.edit');

    // KoraSync (versão web, dentro do admin) — mesma tela do app desktop
    // (Fila normal/Sem estoque/Vendas futuras/Separados/Cancelados), pedido
    // explícito 2026-08-31. korasync.ver serve só a casca Inertia (qual aba
    // abre); os dados de verdade vêm dos MESMOS endpoints que o app desktop
    // já usa (DashboardAgentController, reaproveitado direto — ver
    // KoraSyncController). Autenticação aqui é a sessão normal do admin
    // (auth+staff+permission), diferente do token fixo que o app desktop
    // usa (middleware print.agent, ver prefix('print-agent') em api.php) —
    // por isso são rotas novas, não uma reexposição das de api.php.
    Route::middleware('permission:operacional.view')->group(function () {
        Route::get('korasync/{tab}', [KoraSyncController::class, 'index'])
            ->where('tab', 'fila|sem-estoque|vendas-futuras|separados|cancelados')
            ->name('korasync.ver');

        Route::get('korasync-api/queue', [DashboardAgentController::class, 'queue'])->name('korasync.api.fila');
        Route::get('korasync-api/scheduled-shipments', [DashboardAgentController::class, 'scheduledShipments'])->name('korasync.api.agendados');
        Route::get('korasync-api/mercadolivre-summary', [DashboardAgentController::class, 'mercadoLivreSummary'])->name('korasync.api.resumo-ml');
        Route::get('korasync-api/metrics', [DashboardAgentController::class, 'metrics'])->name('korasync.api.metricas');
        Route::get('korasync-api/queue/{order}/image', [DashboardAgentController::class, 'queueOrderImage'])->name('korasync.api.foto-pedido');
        Route::get('korasync-api/queue/{order}/image/{product}', [DashboardAgentController::class, 'queueOrderProductImage'])->name('korasync.api.foto-produto');
    });
    Route::post('korasync-api/queue/{order}/pack', [DashboardAgentController::class, 'packOrder'])
        ->name('korasync.api.embalar')->middleware('permission:operacional.edit');

    // Envios Flex — controle do que saiu com o entregador, comprovante de
    // retirada e alertas (pedido explícito 2026-09-11). Ver EnviosFlexController.
    Route::middleware('permission:operacional.view')->group(function () {
        Route::get('envios-flex', [EnviosFlexController::class, 'index'])->name('envios-flex.listar');
        Route::get('envios-flex/recibos/{recibo}', [EnviosFlexController::class, 'comprovante'])->name('envios-flex.comprovante');
        Route::get('envios-flex/recibos/{recibo}/{tipo}', [EnviosFlexController::class, 'imagem'])
            ->whereIn('tipo', ['assinatura', 'foto'])
            ->name('envios-flex.recibo.imagem');
    });
    Route::middleware('permission:operacional.edit')->group(function () {
        Route::post('envios-flex/{envio}/resolver', [EnviosFlexController::class, 'resolver'])->name('envios-flex.resolver');
        Route::delete('envios-flex/{envio}/resolver', [EnviosFlexController::class, 'desfazerResolucao'])->name('envios-flex.desfazer-resolucao');
        Route::post('envios-flex/{envio}/justificar', [EnviosFlexController::class, 'justificar'])->name('envios-flex.justificar');
        Route::delete('envios-flex/{envio}/justificar', [EnviosFlexController::class, 'desfazerJustificativa'])->name('envios-flex.desfazer-justificativa');
        Route::post('envios-flex/{envio}/sincronizar', [EnviosFlexController::class, 'sincronizar'])->name('envios-flex.sincronizar');
        Route::post('envios-flex/recibos/{recibo}/reter', [EnviosFlexController::class, 'reter'])->name('envios-flex.reter');
        Route::delete('envios-flex/recibos/{recibo}/reter', [EnviosFlexController::class, 'liberar'])->name('envios-flex.liberar');
    });

    // Devoluções — controle de devoluções e reclamações de todas as
    // plataformas (pedido do usuário 2026-10-06). Ver DevolucoesController.
    Route::get('devolucoes', [DevolucoesController::class, 'index'])->name('devolucoes.listar')->middleware('permission:operacional.view');
    Route::get('devolucoes/{devolucao}/evidencias/{evidencia}', [DevolucoesController::class, 'evidencia'])->whereNumber(['devolucao', 'evidencia'])->name('devolucoes.evidencias.ver')->middleware('permission:operacional.view');
    Route::middleware('permission:operacional.edit')->group(function () {
        Route::post('devolucoes', [DevolucoesController::class, 'store'])->name('devolucoes.registrar');
        Route::post('devolucoes/sincronizar', [DevolucoesController::class, 'sincronizar'])->name('devolucoes.sincronizar');
        Route::post('devolucoes/{devolucao}', [DevolucoesController::class, 'update'])->whereNumber('devolucao')->name('devolucoes.atualizar');
        Route::post('devolucoes/{devolucao}/evidencias', [DevolucoesController::class, 'anexarEvidencia'])->whereNumber('devolucao')->name('devolucoes.evidencias.anexar');
        Route::delete('devolucoes/{devolucao}/evidencias/{evidencia}', [DevolucoesController::class, 'removerEvidencia'])->whereNumber(['devolucao', 'evidencia'])->name('devolucoes.evidencias.remover');
    });

    // WhatsApp > Conversas: atendimento é de quem cuida de pedido, não só do
    // admin (as rotas de configuração do WhatsApp continuam admin-only abaixo).
    Route::middleware('permission:pedidos.view')->group(function () {
        // E-mails do site (Fale conosco da loja) — pedido 2026-10-10.
        Route::get('mensagens-site', [\App\Modules\Admin\Http\Controllers\MensagensSiteController::class, 'index'])->name('mensagens-site.listar');
        Route::get('mensagens-site/chegando', [\App\Modules\Admin\Http\Controllers\MensagensSiteController::class, 'chegando'])->name('mensagens-site.chegando');
        Route::post('mensagens-site/{mensagem}/lida', [\App\Modules\Admin\Http\Controllers\MensagensSiteController::class, 'marcarLida'])->whereNumber('mensagem')->name('mensagens-site.lida');
        Route::post('mensagens-site/{mensagem}/nao-lida', [\App\Modules\Admin\Http\Controllers\MensagensSiteController::class, 'marcarNaoLida'])->whereNumber('mensagem')->name('mensagens-site.nao-lida');
        // Excluir vai para a lixeira (soft delete), com restaurar — pedido 2026-10-12.
        Route::delete('mensagens-site/lidas', [\App\Modules\Admin\Http\Controllers\MensagensSiteController::class, 'excluirLidas'])->name('mensagens-site.excluir-lidas');
        Route::delete('mensagens-site', [\App\Modules\Admin\Http\Controllers\MensagensSiteController::class, 'excluirSelecionadas'])->name('mensagens-site.excluir-selecionadas');
        Route::delete('mensagens-site/{mensagem}', [\App\Modules\Admin\Http\Controllers\MensagensSiteController::class, 'excluir'])->whereNumber('mensagem')->name('mensagens-site.excluir');
        Route::post('mensagens-site/restaurar', [\App\Modules\Admin\Http\Controllers\MensagensSiteController::class, 'restaurar'])->name('mensagens-site.restaurar');
        // Suporte > Chamados (pedido 2026-10-11).
        Route::get('suporte', [\App\Modules\Suporte\Http\Controllers\AdminSuporteController::class, 'index'])->name('suporte.chamados');
        Route::post('suporte', [\App\Modules\Suporte\Http\Controllers\AdminSuporteController::class, 'criar'])->name('suporte.criar');
        Route::get('suporte/{chamado}/mensagens', [\App\Modules\Suporte\Http\Controllers\AdminSuporteController::class, 'mensagens'])->whereNumber('chamado')->name('suporte.mensagens');
        Route::post('suporte/{chamado}/mensagens', [\App\Modules\Suporte\Http\Controllers\AdminSuporteController::class, 'enviar'])->whereNumber('chamado')->name('suporte.enviar');
        Route::post('suporte/{chamado}/humano', [\App\Modules\Suporte\Http\Controllers\AdminSuporteController::class, 'humano'])->whereNumber('chamado')->name('suporte.humano');
        Route::patch('suporte/{chamado}', [\App\Modules\Suporte\Http\Controllers\AdminSuporteController::class, 'atualizar'])->whereNumber('chamado')->name('suporte.atualizar');
        Route::delete('suporte/{chamado}', [\App\Modules\Suporte\Http\Controllers\AdminSuporteController::class, 'excluir'])->whereNumber('chamado')->name('suporte.excluir');
        Route::get('whatsapp/conversas', [WhatsAppInboxController::class, 'index'])->name('whatsapp.conversas');
        Route::get('whatsapp/conversas/atualizacoes', [WhatsAppInboxController::class, 'updates'])->name('whatsapp.conversas.atualizacoes');
        Route::get('whatsapp/conversas/chegando', [WhatsAppInboxController::class, 'incoming'])->name('whatsapp.conversas.chegando');
        Route::get('whatsapp/conversas/{conversation}/mensagens', [WhatsAppInboxController::class, 'messages'])->whereNumber('conversation')->name('whatsapp.conversas.mensagens');
        Route::get('whatsapp/conversas/midia/{message}', [WhatsAppInboxController::class, 'media'])->whereNumber('message')->name('whatsapp.conversas.midia');
        Route::post('whatsapp/conversas/{conversation}/lida', [WhatsAppInboxController::class, 'markRead'])->whereNumber('conversation')->name('whatsapp.conversas.lida');
    });
    Route::middleware('permission:pedidos.edit')->group(function () {
        Route::post('whatsapp/conversas/{conversation}/mensagens', [WhatsAppInboxController::class, 'send'])->whereNumber('conversation')->name('whatsapp.conversas.enviar');
        Route::post('whatsapp/conversas/{conversation}/manuela', [WhatsAppInboxController::class, 'toggleManuela'])->whereNumber('conversation')->name('whatsapp.conversas.manuela');
        Route::post('whatsapp/conversas/{conversation}/status', [WhatsAppInboxController::class, 'updateStatus'])->whereNumber('conversation')->name('whatsapp.conversas.status');
        Route::delete('whatsapp/conversas/{conversation}', [WhatsAppInboxController::class, 'destroy'])->whereNumber('conversation')->name('whatsapp.conversas.apagar');
    });

    Route::middleware('admin')->group(function () {
        Route::get('usuarios-permissoes', [UserPermissionController::class, 'index'])->name('usuarios-permissoes.listar');
        Route::patch('usuarios-permissoes/usuarios/{user}', [UserPermissionController::class, 'updateRole'])->name('usuarios-permissoes.papel.atualizar');
        Route::delete('usuarios-permissoes/usuarios/{user}', [UserPermissionController::class, 'destroy'])->name('usuarios-permissoes.usuarios.excluir');
        Route::put('usuarios-permissoes/matriz', [UserPermissionController::class, 'updatePermissions'])->name('usuarios-permissoes.matriz.atualizar');

        Route::get('auditoria', [AuditLogController::class, 'index'])->name('auditoria.listar');

        // Menu "Log": os arquivos de storage/logs na tela, com filtro de
        // data/hora, nível e busca por texto. Admin-only igual à auditoria —
        // log de produção carrega dado de cliente e payload de marketplace.
        Route::get('logs', [SystemLogController::class, 'index'])->name('logs.listar');

        // Alertas do painel (2026-10-16): aviso no sino de notificações (2026-10-17), histórico aqui.
        Route::get('alertas', [\App\Modules\Admin\Http\Controllers\AlertasAdminController::class, 'index'])->name('alertas.listar');
        Route::post('alertas/{alerta}/resolver', [\App\Modules\Admin\Http\Controllers\AlertasAdminController::class, 'resolver'])->whereNumber('alerta')->name('alertas.resolver');
        Route::post('alertas/tipos/{tipo}', [\App\Modules\Admin\Http\Controllers\AlertasAdminController::class, 'tipo'])->name('alertas.tipo');

        // Visitas ao site (2026-10-12): páginas abertas na loja e o IP de
        // quem abriu, lidas de site_visits. Admin-only como Logs (IP é dado
        // pessoal).
        Route::get('visitas-site', [SiteVisitLogController::class, 'index'])->name('visitas-site.listar');
        // Olho (histórico do visitante no período) e lixeira (apaga as
        // visitas daquele IP no período), pedido 2026-10-12.
        Route::get('visitas-site/visitante', [SiteVisitLogController::class, 'visitante'])->name('visitas-site.visitante');
        Route::delete('visitas-site/visitante', [SiteVisitLogController::class, 'apagar'])->name('visitas-site.apagar');

        // Visitas FerraKora e Visitas Beauty Kora (2026-10-12): o mesmo log
        // para os outros sites, lido de site_visits pela coluna site
        // (gravado por /api/{site}/visita).
        foreach (array_keys(SiteVisit::OUTROS_SITES) as $outroSite) {
            Route::get("visitas-{$outroSite}", [OutroSiteVisitLogController::class, 'index'])->defaults('site', $outroSite)->name("visitas-{$outroSite}.listar");
            Route::get("visitas-{$outroSite}/visitante", [OutroSiteVisitLogController::class, 'visitante'])->defaults('site', $outroSite)->name("visitas-{$outroSite}.visitante");
            Route::delete("visitas-{$outroSite}/visitante", [OutroSiteVisitLogController::class, 'apagar'])->defaults('site', $outroSite)->name("visitas-{$outroSite}.apagar");
        }

        // Produtos FerraKora (2026-10-13): tabela paginada do catálogo do site,
        // com categoria e ativo editáveis na linha.
        Route::get('produtos-ferrakora', [FerraKoraProdutoController::class, 'index'])->name('produtos-ferrakora.listar');
        Route::patch('produtos-ferrakora/{registro}', [FerraKoraProdutoController::class, 'update'])->name('produtos-ferrakora.atualizar');

        Route::get('api-parceiros', [ApiPartnerController::class, 'index'])->name('api-parceiros.listar');
        Route::post('api-parceiros', [ApiPartnerController::class, 'store'])->name('api-parceiros.armazenar');
        Route::patch('api-parceiros/{api_partner}', [ApiPartnerController::class, 'update'])->name('api-parceiros.atualizar');
        Route::delete('api-parceiros/{api_partner}', [ApiPartnerController::class, 'destroy'])->name('api-parceiros.excluir');
        Route::post('api-parceiros/{api_partner}/tokens', [ApiPartnerController::class, 'issueToken'])->name('api-parceiros.tokens.emitir');
        Route::delete('api-parceiros/{api_partner}/tokens/{token}', [ApiPartnerController::class, 'revokeToken'])->name('api-parceiros.tokens.revogar');


        // WhatsApp/Manuela precisa ser publicado como um conjunto: rotas, controllers,
        // migrations e componentes Vue. Se só o build Vite/manifest for enviado, o menu
        // aponta para /admin/whatsapp, mas o Laravel ativo não encontra a página e a tela quebra.
        // Não remover estas rotas sem remover também o item do menu e os assets relacionados.
        // Pixel da Meta (2026-10-16): ID editável, sem mexer no .env.
        Route::get('pixel-meta', [PixelMetaController::class, 'edit'])->name('pixel-meta.editar');
        Route::put('pixel-meta', [PixelMetaController::class, 'update'])->name('pixel-meta.atualizar');

        Route::get('whatsapp', [WhatsAppSettingsController::class, 'edit'])->name('whatsapp.editar');
        Route::put('whatsapp', [WhatsAppSettingsController::class, 'update'])->name('whatsapp.atualizar');
        Route::post('whatsapp/testar-envio', [WhatsAppSettingsController::class, 'testSend'])->name('whatsapp.testar-envio');
        Route::get('whatsapp/disparos', [WhatsAppCampaignController::class, 'index'])->name('whatsapp.disparos');
        Route::post('whatsapp/disparos', [WhatsAppCampaignController::class, 'store'])->name('whatsapp.disparos.enviar');

        Route::get('integracoes', [IntegrationController::class, 'index'])->name('integracoes.listar');
        Route::delete('integracoes/{channel}', [IntegrationController::class, 'disconnect'])->name('integracoes.desconectar');
        Route::post('integracoes/amazon/conectar', [IntegrationController::class, 'connectAmazon'])->name('integracoes.amazon.conectar');
        Route::post('integracoes/shopee/importar-produtos', [IntegrationController::class, 'importShopeeProducts'])->name('integracoes.shopee.importar-produtos');
        Route::post('integracoes/bling/loja-tiktok', [IntegrationController::class, 'saveBlingTiktokLoja'])->name('integracoes.bling.loja-tiktok');

        Route::get('integracoes/webhooks', [WebhookLogController::class, 'index'])->name('integracoes.webhooks');
        Route::post('integracoes/webhooks/{channel_webhook_log}/reprocessar', [WebhookLogController::class, 'reprocess'])->name('integracoes.webhooks.reprocessar');

        Route::get('integracoes/mercado-livre/vendas', [MercadoLivreSalesController::class, 'index'])->name('integracoes.mercado-livre.vendas');
        Route::get('integracoes/mercado-livre/anuncios', [MercadoLivreListingsController::class, 'index'])->name('integracoes.mercado-livre.anuncios');
        Route::get('integracoes/mercado-livre/envios', [MercadoLivreShippingController::class, 'index'])->name('integracoes.mercado-livre.envios');
        Route::get('integracoes/mercado-livre/devolucoes', [MercadoLivreClaimsController::class, 'index'])->name('integracoes.mercado-livre.devolucoes');
        Route::post('integracoes/mercado-livre/devolucoes/{marketplace_claim}/reverter-estoque', [MercadoLivreClaimsController::class, 'revertStock'])->name('integracoes.mercado-livre.devolucoes.reverter-estoque');

        Route::get('integracoes/mercado-livre/impressao-full', [MercadoLivreFullPrintController::class, 'create'])->name('integracoes.mercado-livre.impressao-full');
        Route::post('integracoes/mercado-livre/impressao-full', [MercadoLivreFullPrintController::class, 'store'])->name('integracoes.mercado-livre.impressao-full.armazenar');

        Route::get('integracoes/mercado-livre/flex', [MercadoLivreFlexController::class, 'index'])->name('integracoes.mercado-livre.flex');
        Route::put('integracoes/mercado-livre/flex', [MercadoLivreFlexController::class, 'update'])->name('integracoes.mercado-livre.flex.atualizar');

        Route::get('integracoes/mercado-livre/cupom', [\App\Modules\Admin\Http\Controllers\MercadoLivreCupomController::class, 'index'])->name('integracoes.mercado-livre.cupom');
        Route::post('integracoes/mercado-livre/cupom', [\App\Modules\Admin\Http\Controllers\MercadoLivreCupomController::class, 'store'])->middleware('admin')->name('integracoes.mercado-livre.cupom.enviar');

        Route::get('integracoes/teste-impressao', [PrintTestController::class, 'index'])->name('integracoes.teste-impressao');
        Route::post('integracoes/teste-impressao', [PrintTestController::class, 'store'])->name('integracoes.teste-impressao.armazenar');

        Route::get('impressoes', [PrintJobController::class, 'index'])->name('impressoes.listar');
        Route::get('impressoes/lista', [PrintJobController::class, 'list'])->name('impressoes.lista');
        Route::get('impressoes/teste-webhook', [WebhookTestController::class, 'create'])->name('impressoes.teste-webhook');
        Route::post('impressoes/teste-webhook', [WebhookTestController::class, 'store'])->name('impressoes.teste-webhook.armazenar');
        Route::get('impressoes/{print_job}', [PrintJobController::class, 'show'])->name('impressoes.ver');
        Route::get('impressoes/{print_job}/pdf', [PrintJobController::class, 'pdf'])->name('impressoes.pdf');
        Route::delete('impressoes/{print_job}', [PrintJobController::class, 'destroy'])->name('impressoes.excluir');

        Route::get('importar-pedido', [OrderImportController::class, 'create'])->name('importar-pedido.nova');
        Route::post('importar-pedido', [OrderImportController::class, 'store'])->name('importar-pedido.armazenar');

        Route::middleware('permission:operacional.view')->group(function () {
            Route::get('correios', [CorreiosController::class, 'index'])->name('correios.listar');
            Route::get('correios/nova', [CorreiosController::class, 'create'])->name('correios.nova');
            Route::get('correios/buscar-pedido', [CorreiosController::class, 'buscarPedido'])->name('correios.buscar-pedido');
            Route::get('correios/{correio}', [CorreiosController::class, 'show'])->name('correios.ver');
            Route::get('correios/{correio}/etiqueta.pdf', [CorreiosController::class, 'etiqueta'])->name('correios.etiqueta');
            Route::get('correios/{correio}/editar', [CorreiosController::class, 'edit'])->name('correios.editar');
        });
        Route::post('correios', [CorreiosController::class, 'store'])->name('correios.armazenar')->middleware('permission:operacional.create');
        Route::put('correios/{correio}', [CorreiosController::class, 'update'])->name('correios.atualizar')->middleware('permission:operacional.create');
        Route::delete('correios/{correio}', [CorreiosController::class, 'destroy'])->name('correios.excluir')->middleware('permission:operacional.delete');
        Route::post('correios/{correio}/cancelar', [CorreiosController::class, 'cancel'])->name('correios.cancelar')->middleware('permission:operacional.delete');

        Route::get('etiquetas-manuais/nova', [ManualLabelController::class, 'create'])->name('etiquetas-manuais.nova');
        Route::post('etiquetas-manuais', [ManualLabelController::class, 'store'])->name('etiquetas-manuais.armazenar');
        Route::get('etiquetas-manuais', [ManualLabelController::class, 'list'])->name('etiquetas-manuais.listar');
        Route::get('etiquetas-manuais/{print_job}', [ManualLabelController::class, 'show'])->name('etiquetas-manuais.ver');
        Route::get('etiquetas-manuais/{print_job}/pdf', [ManualLabelController::class, 'pdf'])->name('etiquetas-manuais.pdf');
        Route::put('etiquetas-manuais/{print_job}', [ManualLabelController::class, 'update'])->name('etiquetas-manuais.atualizar');
        Route::delete('etiquetas-manuais/{print_job}', [ManualLabelController::class, 'destroy'])->name('etiquetas-manuais.excluir');
    });
});

require __DIR__.'/auth.php';
