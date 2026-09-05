<?php

use App\Modules\Admin\Http\Controllers\AdsRechargeController;
use App\Modules\Admin\Http\Controllers\ApiPartnerController;
use App\Modules\Admin\Http\Controllers\AuditLogController;
use App\Modules\Admin\Http\Controllers\BannerController;
use App\Modules\Admin\Http\Controllers\CashFlowController;
use App\Modules\Admin\Http\Controllers\CategoryController;
use App\Modules\Admin\Http\Controllers\CompanyController;
use App\Modules\Admin\Http\Controllers\CompetitorAnalysisController;
use App\Modules\Admin\Http\Controllers\CorreiosController;
use App\Modules\Admin\Http\Controllers\CostCenterController;
use App\Modules\Admin\Http\Controllers\DashboardController;
use App\Modules\Admin\Http\Controllers\FinancialDashboardController;
use App\Http\Controllers\Api\DashboardAgentController;
use App\Modules\Admin\Http\Controllers\IntegrationController;
use App\Modules\Admin\Http\Controllers\KoraSyncController;
use App\Modules\Admin\Http\Controllers\MercadoLivreClaimsController;
use App\Modules\Admin\Http\Controllers\MercadoLivreFlexController;
use App\Modules\Admin\Http\Controllers\MercadoLivreListingsController;
use App\Modules\Admin\Http\Controllers\MercadoLivreSalesController;
use App\Modules\Admin\Http\Controllers\MercadoLivreShippingController;
use App\Modules\Admin\Http\Controllers\InvoiceController;
use App\Modules\Admin\Http\Controllers\InvoiceManualController;
use App\Modules\Admin\Http\Controllers\KpiController;
use App\Modules\Admin\Http\Controllers\ManualLabelController;
use App\Modules\Admin\Http\Controllers\CustomerController;
use App\Modules\Admin\Http\Controllers\ManualOrderController;
use App\Modules\Admin\Http\Controllers\MercadoLivreFullPrintController;
use App\Modules\Admin\Http\Controllers\OrderImportController;
use App\Modules\Admin\Http\Controllers\PaymentSettingsController;
use App\Modules\Admin\Http\Controllers\OrderController as AdminOrderController;
use App\Modules\Admin\Http\Controllers\PricingCalculatorController;
use App\Modules\Admin\Http\Controllers\PrintJobController;
use App\Modules\Admin\Http\Controllers\PrintTestController;
use App\Modules\Admin\Http\Controllers\WebhookLogController;
use App\Modules\Admin\Http\Controllers\WebhookTestController;
use App\Modules\Admin\Http\Controllers\WhatsAppCampaignController;
use App\Modules\Admin\Http\Controllers\WhatsAppSettingsController;
use App\Modules\Admin\Http\Controllers\ProductController;
use App\Modules\Admin\Http\Controllers\ProductFiscalController;
use App\Modules\Admin\Http\Controllers\ProductImageController;
use App\Modules\Admin\Http\Controllers\ProductLogisticsController;
use App\Modules\Admin\Http\Controllers\ProductQuantityDiscountController;
use App\Modules\Admin\Http\Controllers\ProductVideoController;
use App\Modules\Admin\Http\Controllers\PurchaseOrderController;
use App\Modules\Admin\Http\Controllers\ReportController;
use App\Modules\Admin\Http\Controllers\ReviewController as AdminReviewController;
use App\Modules\Admin\Http\Controllers\PromotionalNotificationController;
use App\Modules\Admin\Http\Controllers\ServiceOrderController;
use App\Modules\Admin\Http\Controllers\ShippingMethodController;
use App\Modules\Admin\Http\Controllers\StockMovementController;
use App\Modules\Admin\Http\Controllers\SupplierController;
use App\Modules\Admin\Http\Controllers\UserPermissionController;
use App\Modules\Cart\Http\Controllers\CartController;
use App\Modules\Catalog\Http\Controllers\CatalogController;
use App\Modules\Catalog\Http\Controllers\FavoriteController;
use App\Modules\Catalog\Http\Controllers\ReviewController;
use App\Modules\Checkout\Http\Controllers\CheckoutController;
use App\Modules\Marketplace\Http\Controllers\ProductChannelController;
use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Profile\Http\Controllers\ProfileAvatarController;
use App\Modules\Profile\Http\Controllers\ProfileController;
use App\Modules\Profile\Http\Controllers\ProfilePasswordController;
use App\Modules\Profile\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->name('catalogo.inicio');
Route::get('/produtos/{product:slug}', [CatalogController::class, 'show'])->name('produtos.ver');
Route::get('/produtos/{product:slug}/envio', [CatalogController::class, 'shipping'])->name('produtos.envio.detalhes');

// Páginas institucionais/legais — públicas de propósito, precisam ser
// rastreáveis sem login para aprovação no Google Merchant Center.
Route::inertia('/trocas-e-devolucoes', 'Legal/Trocas')->name('legal.trocas');
Route::inertia('/politica-de-privacidade', 'Legal/Privacidade')->name('legal.privacidade');
Route::inertia('/termos-de-uso', 'Legal/Termos')->name('legal.termos');

// Documentação da API pública de parceiros (ver routes/api_v1.php) — página
// estática (Blade puro, não Inertia/SPA), pública de propósito pra
// desenvolvedor de integrador externo conseguir consultar sem login. Nunca
// mostra um token real, só placeholder — o token de cada parceiro é gerado
// e entregue separadamente (ver Admin\ApiPartnerController::issueToken()).
Route::view('/api/documentacao', 'api.documentation')->name('api.documentacao');

Route::get('/favoritos', [FavoriteController::class, 'index'])
    ->middleware('auth')
    ->name('favoritos.listar');

Route::post('/favoritos/{product}', [FavoriteController::class, 'toggle'])
    ->middleware('auth')
    ->name('favoritos.alternar');

Route::post('/produtos/{product}/avaliacoes', [ReviewController::class, 'store'])
    ->middleware('auth')
    ->name('avaliacoes.armazenar');

Route::prefix('carrinho')->name('carrinho.')->group(function () {
    Route::get('/', [CartController::class, 'index'])->name('ver');
    Route::post('/', [CartController::class, 'store'])->name('adicionar');
    Route::patch('/{product}', [CartController::class, 'update'])->name('atualizar');
    Route::delete('/{product}', [CartController::class, 'destroy'])->name('remover');
});

Route::prefix('finalizacao')->name('finalizacao.')->group(function () {
    Route::get('/', [CheckoutController::class, 'delivery'])->name('entrega');
    Route::post('/entrega', [CheckoutController::class, 'storeDelivery'])->name('entrega.salvar');
    Route::post('/frete', [CheckoutController::class, 'quoteFreight'])->middleware('throttle:20,1')->name('frete.cotar');
    Route::get('/pagamento', [CheckoutController::class, 'payment'])->name('pagamento');
    Route::post('/pagamento/cupom', [CheckoutController::class, 'applyCoupon'])->name('pagamento.cupom');
    Route::post('/pagamento', [CheckoutController::class, 'storePayment'])->middleware('throttle:10,1')->name('pagamento.iniciar');
    Route::post('/{order}/pagamento/proxima-parte', [CheckoutController::class, 'storeSecondPayment'])->middleware('throttle:10,1')->name('pagamento.proxima-parte');
    Route::get('/{order}/status', [CheckoutController::class, 'status'])->name('status');
    Route::get('/{order}/confirmacao', [CheckoutController::class, 'confirmation'])->middleware('auth')->name('confirmacao');
});

Route::get('/pedidos', [CheckoutController::class, 'myOrders'])->middleware('auth')->name('pedidos.meus');

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

    Route::get('/configuracoes', [SettingsController::class, 'edit'])->name('configuracoes.editar');

    Route::get('/notificacoes', [NotificationController::class, 'index'])->name('notificacoes.listar');
    Route::post('/notificacoes/{notification}/lida', [NotificationController::class, 'markRead'])->name('notificacoes.lida');
    Route::post('/notificacoes/ler-todas', [NotificationController::class, 'markAllRead'])->name('notificacoes.ler-todas');
    Route::delete('/notificacoes/{notification}', [NotificationController::class, 'destroy'])->name('notificacoes.excluir');
});

Route::prefix('admin')->name('admin.')->middleware(['auth', 'staff'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('painel');

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

    Route::get('notificacoes-promocionais', [PromotionalNotificationController::class, 'index'])
        ->middleware('permission:cadastros.view')
        ->name('notificacoes-promocionais.listar');
    Route::post('notificacoes-promocionais', [PromotionalNotificationController::class, 'store'])
        ->middleware('permission:cadastros.create')
        ->name('notificacoes-promocionais.armazenar');

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
    Route::put('produtos/{product}/logistica', [ProductLogisticsController::class, 'update'])->name('produtos.logistica.atualizar');
    Route::put('produtos/{product}/descontos-quantidade', [ProductQuantityDiscountController::class, 'update'])->name('produtos.descontos-quantidade.atualizar');
    Route::post('produtos/{product}/imagens', [ProductImageController::class, 'store'])->name('produtos.imagens.adicionar');
    Route::delete('produtos/{product}/imagens/{image}', [ProductImageController::class, 'destroy'])->name('produtos.imagens.remover');
    Route::post('produtos/{product}/video', [ProductVideoController::class, 'store'])->name('produtos.video.adicionar');
    Route::delete('produtos/{product}/video', [ProductVideoController::class, 'destroy'])->name('produtos.video.remover');
    Route::put('produtos/{product}/canais/{channel}', [ProductChannelController::class, 'update'])->name('produtos.canais.atualizar');
    Route::post('produtos/{product}/canais/{channel}/sincronizar', [ProductChannelController::class, 'sync'])->name('produtos.canais.sincronizar');
    Route::delete('produtos/{product}/canais/{channel}', [ProductChannelController::class, 'destroy'])->name('produtos.canais.excluir');
    Route::post('produtos/{product}/variacoes/vincular', [ProductController::class, 'attachVariation'])->name('produtos.variacoes.vincular');
    Route::post('produtos/{product}/variacoes/desvincular', [ProductController::class, 'detachVariation'])->name('produtos.variacoes.desvincular');

    Route::get('pedidos', [AdminOrderController::class, 'index'])->name('pedidos.listar');
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
    Route::patch('pedidos/{order}', [AdminOrderController::class, 'update'])
        ->name('pedidos.atualizar')
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
    });

    Route::middleware('permission:financeiro.view')->group(function () {
        Route::get('dashboard-financeiro', [FinancialDashboardController::class, 'index'])->name('dashboard-financeiro.ver');
        Route::get('fluxo-de-caixa', [CashFlowController::class, 'index'])->name('fluxo-de-caixa.listar');
        Route::get('anuncios/recargas', [AdsRechargeController::class, 'index'])->name('anuncios.recargas.listar');
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

    Route::middleware('admin')->group(function () {
        Route::get('usuarios-permissoes', [UserPermissionController::class, 'index'])->name('usuarios-permissoes.listar');
        Route::patch('usuarios-permissoes/usuarios/{user}', [UserPermissionController::class, 'updateRole'])->name('usuarios-permissoes.papel.atualizar');
        Route::delete('usuarios-permissoes/usuarios/{user}', [UserPermissionController::class, 'destroy'])->name('usuarios-permissoes.usuarios.excluir');
        Route::put('usuarios-permissoes/matriz', [UserPermissionController::class, 'updatePermissions'])->name('usuarios-permissoes.matriz.atualizar');

        Route::get('auditoria', [AuditLogController::class, 'index'])->name('auditoria.listar');

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
            Route::get('correios/{correio}/editar', [CorreiosController::class, 'edit'])->name('correios.editar');
        });
        Route::post('correios', [CorreiosController::class, 'store'])->name('correios.armazenar')->middleware('permission:operacional.create');
        Route::put('correios/{correio}', [CorreiosController::class, 'update'])->name('correios.atualizar')->middleware('permission:operacional.create');
        Route::delete('correios/{correio}', [CorreiosController::class, 'destroy'])->name('correios.excluir')->middleware('permission:operacional.delete');

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
