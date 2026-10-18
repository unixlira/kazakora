<?php

use App\Http\Controllers\Api\AmazonController;
use App\Http\Controllers\Api\AmazonReportsController;
use App\Http\Controllers\Api\BlingController;
use App\Http\Controllers\Api\BeautyKoraCatalogoController;
use App\Http\Controllers\Api\BeautyKoraCheckoutController;
use App\Http\Controllers\Api\BeautyKoraContaController;
use App\Http\Controllers\Api\BeautyKoraPerfilController;
use App\Http\Controllers\Api\BeautyKoraProdutoController;
use App\Http\Controllers\Api\FerraKoraBancadaController;
use App\Http\Controllers\Api\FerraKoraCatalogoController;
use App\Http\Controllers\Api\FerraKoraCheckoutController;
use App\Http\Controllers\Api\FerraKoraContaController;
use App\Http\Controllers\Api\FerraKoraPerfilController;
use App\Http\Middleware\AuthenticateBeautyKoraCliente;
use App\Modules\App\Http\Controllers\CheckoutAppController;
use App\Modules\App\Http\Controllers\ContaAppController;
use App\Modules\App\Http\Controllers\EnderecoAppController;
use App\Modules\App\Http\Controllers\FavoritoAppController;
use App\Modules\App\Http\Controllers\LojaController as AppLojaController;
use App\Modules\App\Http\Controllers\PedidoAppController;
use App\Modules\App\Http\Middleware\AuthenticateAppCliente;
use App\Modules\App\Http\Middleware\PrepararLojaDoApp;
use App\Http\Middleware\AuthenticateFerraKoraCliente;
use App\Http\Controllers\Api\BlingWebhookController;
use App\Http\Controllers\Api\DashboardAgentController;
use App\Http\Controllers\Api\MelhorEnvioController;
use App\Http\Controllers\Api\MercadoLivreController;
use App\Http\Controllers\Api\MercadoPagoWebhookController;
use App\Http\Controllers\Api\OutroSiteVisitaController;
use App\Http\Controllers\Api\KoraFlexController;
use App\Http\Controllers\Api\PrintAgentController;
use App\Http\Controllers\Api\ShopeeController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\ColetaFinanceiraController;
use App\Http\Controllers\Api\TikTokReportsController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use App\Modules\Analytics\Models\SiteVisit;
use Illuminate\Support\Facades\Route;

// Chamado pelos servidores do Stripe, não por um navegador — sem sessão/CSRF
// (ver bootstrap/app.php), a assinatura é verificada dentro do controller.
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])->name('api.stripe.webhook');

// Idem, pro Mercado Pago — assinatura HMAC verificada dentro do controller.
Route::post('/mercadopago/webhook', [MercadoPagoWebhookController::class, 'handle'])->name('api.mercadopago.webhook');

// O painel do Mercado Pago testa se a URL de callback/webhook existe com um
// GET simples antes de liberar a configuração — mesmo caso já resolvido pra
// Shopee logo abaixo (webhook.verify). Sem isso, o teste deles dá erro mesmo
// com a integração certa do nosso lado (a rota POST acima sozinha devolvia
// 405 pro teste GET deles).
Route::match(['get', 'head'], '/mercadopago/callback', fn () => response()->json(['status' => 'ok']))->name('api.mercadopago.callback');
Route::match(['get', 'head'], '/mercadopago/webhook', fn () => response()->json(['status' => 'ok']))->name('api.mercadopago.webhook.verify');


Route::prefix('whatsapp')->name('api.whatsapp.')->group(function () {
    // Webhook oficial da Meta/WhatsApp. GET valida hub.challenge; POST recebe mensagens/status.
    Route::match(['get', 'head'], '/webhook', [WhatsAppWebhookController::class, 'verify'])->name('webhook.verify');
    Route::post('/webhook', [WhatsAppWebhookController::class, 'handle'])->name('webhook');
});

Route::prefix('webhooks')->name('api.webhooks.')->group(function () {
    // Alias compatível com a URL operacional usada no Meta Manager.
    Route::match(['get', 'head'], '/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('whatsapp.verify');
    Route::post('/whatsapp', [WhatsAppWebhookController::class, 'handle'])->name('whatsapp');
});

Route::prefix('mercadolivre')->name('api.mercadolivre.')->group(function () {
    // OAuth is initiated/completed by a logged-in admin's browser, so it
    // rides the 'web' stack for session + auth, even though it lives under
    // /api to match the redirect URI already registered in the ML portal.
    Route::middleware(['web', 'auth', 'admin'])->group(function () {
        Route::get('/auth', [MercadoLivreController::class, 'redirectToAuth'])->name('auth');
        Route::get('/callback', [MercadoLivreController::class, 'callback'])->name('callback');
    });

    // Called by Mercado Livre's servers, not a browser — stays stateless
    // and CSRF-exempt (see bootstrap/app.php).
    Route::post('/webhook', [MercadoLivreController::class, 'webhook'])->name('webhook');
});

Route::prefix('bling')->name('api.bling.')->group(function () {
    // OAuth: navegador do admin logado, precisa de sessão/CSRF (ver
    // /api/mercadolivre acima).
    Route::middleware(['web', 'auth', 'admin'])->group(function () {
        Route::get('/auth', [BlingController::class, 'redirectToAuth'])->name('auth');
        Route::get('/callback', [BlingController::class, 'callback'])->name('callback');
    });

    // Chamado pelos servidores do Bling (pedido de venda do TikTok Shop em
    // tempo real), não por um navegador — sem sessão/CSRF, a assinatura
    // HMAC é verificada dentro do controller.
    Route::post('/webhook', [BlingWebhookController::class, 'webhook'])->name('webhook');

    // A tela de webhooks do Bling pode bater na URL antes de salvar a
    // configuração (a referência deles não documenta se valida) — um GET
    // que devolve 200 evita que um 405 pareça "URL inválida", mesmo padrão
    // já usado na Shopee/Mercado Pago.
    Route::match(['get', 'head'], '/webhook', fn () => response()->json(['status' => 'ok']))->name('webhook.verify');
});

Route::prefix('melhorenvio')->name('api.melhorenvio.')->middleware(['web', 'auth', 'admin'])->group(function () {
    Route::get('/auth', [MelhorEnvioController::class, 'redirectToAuth'])->name('auth');
    Route::get('/callback', [MelhorEnvioController::class, 'callback'])->name('callback');
});

Route::prefix('shopee')->name('api.shopee.')->group(function () {
    // OAuth: navegador do admin logado, precisa de sessão/CSRF (ver
    // /api/mercadolivre acima).
    Route::middleware(['web', 'auth', 'admin'])->group(function () {
        Route::get('/auth', [ShopeeController::class, 'redirectToAuth'])->name('auth');
        Route::get('/callback', [ShopeeController::class, 'callback'])->name('callback');
    });

    // Chamado pelos servidores da Shopee (Push Notification), não por um
    // navegador — sem sessão/CSRF, a assinatura é verificada dentro do
    // controller.
    Route::post('/webhook', [ShopeeController::class, 'webhook'])->name('webhook');

    // O painel da Shopee testa se a "Test Callback URL" existe com um GET
    // simples antes de liberar o teste de push de verdade — sem isso a
    // rota (só POST) devolvia 405 e a Shopee mostrava "callback_url is
    // invalid" mesmo com tudo certo do lado de cá.
    Route::match(['get', 'head'], '/webhook', fn () => response()->json(['status' => 'ok']))->name('webhook.verify');
});

Route::prefix('amazon')->name('api.amazon.')->middleware(['web', 'auth', 'admin'])->group(function () {
    // OAuth completo — só é o caminho real se o app SP-API vier a ser
    // publicado; enquanto for privado, a conexão de verdade acontece via
    // token colado manualmente (ver IntegrationController::connectAmazon()).
    Route::get('/auth', [AmazonController::class, 'redirectToAuth'])->name('auth');
    Route::get('/callback', [AmazonController::class, 'callback'])->name('callback');
});

// Chamado pelo agente local de impressão (fora deste servidor) — token fixo,
// não sessão. Ver AuthenticatePrintAgent.
/**
 * KoraFlex — app de celular (PWA no iPhone, APK no Android) que bipa o QR
 * da etiqueta do Flex e registra a caixa como pronta pra coleta. Token
 * próprio, ver AuthenticateKoraFlex.
 */
Route::prefix('koraflex')->name('api.koraflex.')->middleware('koraflex')->group(function () {
    Route::get('/dia', [KoraFlexController::class, 'dia'])->name('dia');
    Route::post('/bipar', [KoraFlexController::class, 'bipar'])->name('bipar');
    Route::post('/entregar', [KoraFlexController::class, 'entregar'])->name('entregar');
    Route::post('/coletar', [KoraFlexController::class, 'coletar'])->name('coletar');
    Route::get('/recibos/{recibo}/{tipo}', [KoraFlexController::class, 'imagemDoRecibo'])
        ->whereIn('tipo', ['assinatura', 'foto'])
        ->name('recibo.imagem');
    Route::post('/desfazer', [KoraFlexController::class, 'desfazer'])->name('desfazer');
});

Route::prefix('print-agent')->name('api.print-agent.')->middleware('print.agent')->group(function () {
    Route::get('/jobs', [PrintAgentController::class, 'index'])->name('jobs.index');
    Route::post('/jobs/{printJob}/claim', [PrintAgentController::class, 'claim'])->name('jobs.claim');
    Route::get('/jobs/{printJob}/label', [PrintAgentController::class, 'label'])->name('jobs.label');
    Route::get('/jobs/{printJob}/archive', [PrintAgentController::class, 'archive'])->name('jobs.archive');
    Route::post('/jobs/{printJob}/complete', [PrintAgentController::class, 'complete'])->name('jobs.complete');
    Route::get('/dashboard/channels', [DashboardAgentController::class, 'channels'])->name('dashboard.channels');
    Route::get('/dashboard/metrics', [DashboardAgentController::class, 'metrics'])->name('dashboard.metrics');
    // Batida curta pro KoraSync Web: payload minúsculo, consultado de 5 em
    // 5s pra tocar o som e recarregar quase junto com o webhook.
    Route::get('/dashboard/pulse', [DashboardAgentController::class, 'pulse'])->name('dashboard.pulse');
    Route::get('/dashboard/channels/{channel}/orders', [DashboardAgentController::class, 'channelOrders'])->name('dashboard.channel-orders');
    Route::get('/dashboard/labels', [DashboardAgentController::class, 'labels'])->name('dashboard.labels');
    Route::get('/dashboard/queue', [DashboardAgentController::class, 'queue'])->name('dashboard.queue');
    Route::get('/dashboard/queue/{order}/image', [DashboardAgentController::class, 'queueOrderImage'])->name('dashboard.queue.image');
    Route::get('/dashboard/queue/{order}/image/{product}', [DashboardAgentController::class, 'queueOrderProductImage'])->name('dashboard.queue.product-image');
    Route::post('/dashboard/queue/{order}/pack', [DashboardAgentController::class, 'packOrder'])->name('dashboard.queue.pack');
    // Fase 3 (2026-09-04): o clique de separar com conferência no canal
    // antes de liberar. /pack acima continua no ar de propósito — KoraSync
    // antigo ainda chama ele, e este deploy não pode quebrar quem não
    // atualizou o app ainda.
    Route::post('/dashboard/queue/{order}/separar', [DashboardAgentController::class, 'separateOrder'])->name('dashboard.queue.separate');
    // Vídeo da embalagem (2026-10-11): gravado no KoraSync até o "Separado".
    Route::post('/dashboard/queue/{order}/video', [DashboardAgentController::class, 'uploadPackingVideo'])->name('dashboard.queue.video.upload');
    Route::get('/dashboard/queue/{order}/video', [DashboardAgentController::class, 'packingVideo'])->name('dashboard.queue.video');
    Route::post('/dashboard/queue/{order}/desfazer-separacao', [DashboardAgentController::class, 'unseparateOrder'])->name('dashboard.queue.unseparate');
    Route::get('/dashboard/queue/{order}/etiqueta-status', [DashboardAgentController::class, 'labelStatus'])->name('dashboard.queue.label-status');
    Route::post('/dashboard/queue/{order}/reimprimir', [DashboardAgentController::class, 'reprintLabel'])->name('dashboard.queue.reprint');
    // "Gerar etiquetas em lote" (2026-09-07): imprime de uma vez toda
    // etiqueta já disponível de pedido que ainda falta separar. Virou o
    // começo do dia no galpão — a separação, desde este deploy, só dá
    // baixa. Ver DashboardAgentController::batchPrintLabels().
    Route::post('/dashboard/etiquetas/lote', [DashboardAgentController::class, 'batchPrintLabels'])->name('dashboard.labels.batch');
    // Vincular item sem produto (2026-09-06): a decisão que o driver se
    // recusa a tomar sozinho quando o canal não diz qual variação foi
    // vendida. Ver DashboardAgentController::linkOrderItem().
    Route::get('/dashboard/produtos/buscar', [DashboardAgentController::class, 'searchProducts'])->name('dashboard.products.search');
    Route::get('/dashboard/pedidos/buscar', [DashboardAgentController::class, 'searchOrders'])->name('dashboard.orders.search');
    Route::post('/dashboard/queue/{order}/itens/{item}/vincular', [DashboardAgentController::class, 'linkOrderItem'])->name('dashboard.queue.link-item');
    Route::get('/dashboard/daily-text', [DashboardAgentController::class, 'dailyText'])->name('dashboard.daily-text');
    Route::get('/dashboard/scheduled-shipments', [DashboardAgentController::class, 'scheduledShipments'])->name('dashboard.scheduled-shipments');
    Route::get('/dashboard/mercadolivre-summary', [DashboardAgentController::class, 'mercadoLivreSummary'])->name('dashboard.mercadolivre-summary');
});

// API pública de parceiros externos — pedido explícito 2026-08-21. Arquivo
// separado (routes/api_v1.php) só pra não misturar com os webhooks/OAuth
// de integração acima — versionado via prefixo /v1 desde já, pra uma v2
// futura (breaking change de contrato) não precisar reescrever nada disso.
Route::prefix('v1')->group(function () {
    require __DIR__.'/api_v1.php';
});

// Relatórios do TikTok para a auditoria financeira (app "KazaKora TikTok
// Reports", 2026-10-11). Autenticação por Bearer TIKTOK_REPORTS_TOKEN.
Route::post('/tiktok/reports/import', [TikTokReportsController::class, 'import'])
    ->middleware('throttle:60,1')
    ->name('api.tiktok.reports.import');
Route::post('/tiktok/reports/status', [TikTokReportsController::class, 'status'])
    ->middleware('throttle:30,1')
    ->name('api.tiktok.reports.status');

// Falha na coleta financeira (TikTok/Amazon): a Manuela avisa o Lira no
// WhatsApp na hora (2026-10-16). Bearer do agente do TikTok ou da Amazon.
Route::post('/financeiro/coletas/falha', [ColetaFinanceiraController::class, 'falha'])
    ->middleware('throttle:30,1')
    ->name('api.financeiro.coletas.falha');

// Visitas dos outros sites (FerraKora, Beauty Kora; 2026-10-12): o navegador
// do visitante avisa cada página aberta; só dos domínios em
// services.{site}.origens. Grava em site_visits com a coluna site.
Route::post('/{site}/visita', [OutroSiteVisitaController::class, 'registrar'])
    ->whereIn('site', array_keys(SiteVisit::OUTROS_SITES))
    ->middleware('throttle:120,1')
    ->name('api.outro-site.visita');

// Catálogo do site FerraKora vindo do KazaKora (2026-10-13), igual ao do
// Beauty Kora, com categoria e link do produto na loja. Fica depois da rota
// POST /{site}/visita, que não colide (caminho diferente).
Route::prefix('ferrakora')->name('api.ferrakora.')->group(function () {
    Route::get('produtos', [FerraKoraCatalogoController::class, 'index'])->middleware('throttle:120,1')->name('produtos');
    Route::get('produtos/{id}', [FerraKoraCatalogoController::class, 'show'])
        ->where('id', '[a-z0-9-]{1,200}')->middleware('throttle:120,1')->name('produto');
    Route::post('produtos', [FerraKoraCatalogoController::class, 'sincronizar'])->middleware('throttle:20,1')->name('produtos.sincronizar');

    // Checkout próprio do FerraKora (2026-10-14): pedido e Mercado Pago pelo KazaKora.
    // Limite com prefixo próprio: sem ele o contador é um só por IP para todas
    // as rotas com throttle e a vitrine + resumo esgotavam o do pedido (429).
    Route::prefix('checkout')->name('checkout.')->group(function () {
        Route::post('resumo', [FerraKoraCheckoutController::class, 'resumo'])->middleware('throttle:60,1,fk-checkout-resumo')->name('resumo');
        Route::post('pedido', [FerraKoraCheckoutController::class, 'pedido'])->middleware('throttle:10,1,fk-checkout-pedido')->name('pedido');
        Route::get('pedido/{order}', [FerraKoraCheckoutController::class, 'status'])->whereNumber('order')->middleware('throttle:60,1,fk-checkout-status')->name('status');
        Route::get('cep/{cep}', [FerraKoraCheckoutController::class, 'cep'])->where('cep', '[0-9-]{8,9}')->middleware('throttle:30,1,fk-checkout-cep')->name('cep');
    });

    // Conta do cliente (2026-10-14; separada da KazaKora desde 2026-10-15,
    // tabela ferrakora_clientes) e Meus pedidos só para quem entrou.
    Route::post('cadastro', [FerraKoraContaController::class, 'cadastro'])->middleware('throttle:10,1,fk-conta-cadastro')->name('cadastro');
    Route::post('login', [FerraKoraContaController::class, 'login'])->middleware('throttle:10,1,fk-conta-login')->name('login');
    Route::post('esqueci-senha', [FerraKoraContaController::class, 'esqueciSenha'])->middleware('throttle:5,1,fk-conta-senha')->name('esqueci-senha');
    Route::post('nova-senha', [FerraKoraContaController::class, 'novaSenha'])->middleware('throttle:10,1,fk-conta-nova-senha')->name('nova-senha');
    Route::middleware([AuthenticateFerraKoraCliente::class, 'throttle:60,1,fk-conta'])->group(function () {
        Route::get('eu', [FerraKoraContaController::class, 'eu'])->name('eu');
        Route::patch('eu', [FerraKoraContaController::class, 'atualizar'])->name('atualizar');
        Route::post('sair', [FerraKoraContaController::class, 'sair'])->name('sair');
        Route::get('pedidos', [FerraKoraCheckoutController::class, 'meusPedidos'])->name('pedidos');

        // Minha Bancada (favoritos, 2026-10-15): ids "kz-<id>" do catálogo.
        Route::prefix('bancada')->name('bancada.')->controller(FerraKoraBancadaController::class)->group(function () {
            Route::get('/', 'index')->name('ver');
            Route::post('/', 'guardar')->name('guardar');
            Route::delete('{produto}', 'tirar')->where('produto', 'kz-[0-9]{1,10}')->name('tirar');
        });

        // Meu Perfil igual ao da KazaKora (2026-10-14).
        Route::prefix('perfil')->name('perfil.')->controller(FerraKoraPerfilController::class)->group(function () {
            Route::get('/', 'show')->name('ver');
            Route::patch('/', 'update')->name('atualizar');
            Route::post('avatar', 'avatar')->name('avatar');
            Route::delete('avatar', 'removerAvatar')->name('avatar.remover');
            Route::put('senha', 'senha')->name('senha');
            Route::post('enderecos', 'criarEndereco')->name('enderecos.criar');
            Route::put('enderecos/{id}', 'atualizarEndereco')->whereNumber('id')->name('enderecos.atualizar');
            Route::delete('enderecos/{id}', 'apagarEndereco')->whereNumber('id')->name('enderecos.apagar');
        });
    });
});

// Conta e pedidos do site Beauty Kora (2026-10-12): substituem o PocketBase
// do Horizons. Token do Sanctum só de BeautyKoraCliente.
Route::prefix('beautykora')->name('api.beautykora.')->group(function () {
    // Cada rota com prefixo próprio no limite (2026-10-15): sem ele todas contavam por IP no
    // mesmo balde do catálogo (120/min), e abrir umas páginas já travava o login com 429.
    Route::post('cadastro', [BeautyKoraContaController::class, 'cadastro'])->middleware('throttle:10,1,bk-conta-cadastro')->name('cadastro');
    Route::post('login', [BeautyKoraContaController::class, 'login'])->middleware('throttle:10,1,bk-conta-login')->name('login');
    // Página de produto (2026-10-12): conteúdo gerado pelo Gemini, igual ao da KazaKora.
    Route::get('produtos/{id}/conteudo', [BeautyKoraProdutoController::class, 'conteudo'])
        ->where('id', '(prod_[A-Za-z0-9]{10,40}|kz-[0-9]{1,10})')->middleware('throttle:30,1,bk-produto-conteudo')->name('produto.conteudo');
    // Catálogo vindo do KazaKora (2026-10-12): o site lê daqui, quem cadastra envia o JSON.
    Route::get('produtos', [BeautyKoraCatalogoController::class, 'index'])->middleware('throttle:120,1,bk-produtos')->name('produtos');
    Route::get('produtos/{id}', [BeautyKoraCatalogoController::class, 'show'])
        ->where('id', '[a-z0-9-]{1,200}')->middleware('throttle:120,1,bk-produto')->name('produto');
    Route::post('produtos', [BeautyKoraCatalogoController::class, 'sincronizar'])->middleware('throttle:20,1,bk-produtos-sincronizar')->name('produtos.sincronizar');
    // Região do visitante (2026-10-15): o site pergunta ao abrir e mostra no card
    // o frete grátis e o raio da entrega FULL (Grande SP), pelo IP ou pelo CEP.
    Route::get('regiao', [BeautyKoraCatalogoController::class, 'regiao'])->middleware('throttle:120,1,bk-regiao')->name('regiao');

    // Checkout pelo Mercado Pago da KazaKora (2026-10-14, só homolog): sai a Hostinger.
    Route::prefix('checkout')->name('checkout.')->group(function () {
        Route::post('resumo', [BeautyKoraCheckoutController::class, 'resumo'])->middleware('throttle:60,1,bk-checkout-resumo')->name('resumo');
        Route::post('pedido', [BeautyKoraCheckoutController::class, 'pedido'])->middleware('throttle:10,1,bk-checkout-pedido')->name('pedido');
        Route::get('pedido/{order}', [BeautyKoraCheckoutController::class, 'status'])->whereNumber('order')->middleware('throttle:60,1,bk-checkout-status')->name('status');
        Route::get('cep/{cep}', [BeautyKoraCheckoutController::class, 'cep'])->where('cep', '[0-9-]{8,9}')->middleware('throttle:30,1,bk-checkout-cep')->name('cep');
    });

    Route::middleware([AuthenticateBeautyKoraCliente::class, 'throttle:60,1,bk-conta'])->group(function () {
        Route::get('eu', [BeautyKoraContaController::class, 'eu'])->name('eu');
        Route::patch('eu', [BeautyKoraContaController::class, 'atualizar'])->name('atualizar');
        Route::post('sair', [BeautyKoraContaController::class, 'sair'])->name('sair');
        Route::get('endereco', [BeautyKoraContaController::class, 'endereco'])->name('endereco');
        Route::put('endereco', [BeautyKoraContaController::class, 'salvarEndereco'])->name('endereco.salvar');
        Route::delete('endereco/{id}', [BeautyKoraContaController::class, 'apagarEndereco'])->whereNumber('id')->name('endereco.apagar');
        Route::get('pedidos', [BeautyKoraContaController::class, 'pedidos'])->name('pedidos');
        Route::post('pedidos', [BeautyKoraContaController::class, 'criarPedidos'])->name('pedidos.criar');
        Route::get('pedidos/{id}', [BeautyKoraContaController::class, 'pedido'])->whereNumber('id')->name('pedido');

        // Meu Perfil igual ao da KazaKora (2026-10-14).
        Route::prefix('perfil')->name('perfil.')->controller(BeautyKoraPerfilController::class)->group(function () {
            Route::get('/', 'show')->name('ver');
            Route::patch('/', 'update')->name('atualizar');
            Route::post('avatar', 'avatar')->name('avatar');
            Route::delete('avatar', 'removerAvatar')->name('avatar.remover');
            Route::put('senha', 'senha')->name('senha');
            Route::post('enderecos', 'criarEndereco')->name('enderecos.criar');
            Route::put('enderecos/{id}', 'atualizarEndereco')->whereNumber('id')->name('enderecos.atualizar');
            Route::delete('enderecos/{id}', 'apagarEndereco')->whereNumber('id')->name('enderecos.apagar');
        });
    });
});

// App nativo da KazaKora (iOS e Android, 2026-10-18): a mesma loja, conta e
// checkout do kazakora.com. Contrato em docs/app-api.md. Token do Sanctum só
// de User com ability "app" (AuthenticateAppCliente).
Route::prefix('app')->name('api.app.')->middleware(PrepararLojaDoApp::class)->group(function () {
    Route::get('config', [AppLojaController::class, 'config'])->middleware('throttle:60,1,app-config')->name('config');
    Route::get('inicio', [AppLojaController::class, 'inicio'])->middleware('throttle:120,1,app-inicio')->name('inicio');
    Route::get('departamentos', [AppLojaController::class, 'departamentos'])->middleware('throttle:120,1,app-departamentos')->name('departamentos');
    Route::get('produtos', [AppLojaController::class, 'produtos'])->middleware('throttle:120,1,app-produtos')->name('produtos');
    Route::get('produtos/{slug}', [AppLojaController::class, 'produto'])->where('slug', '[A-Za-z0-9-]{1,200}')->middleware('throttle:120,1,app-produto')->name('produto');
    Route::get('cep/{cep}', [AppLojaController::class, 'cep'])->where('cep', '[0-9-]{8,9}')->middleware('throttle:30,1,app-cep')->name('cep');
    Route::post('frete', [AppLojaController::class, 'frete'])->middleware('throttle:60,1,app-frete')->name('frete');
    Route::post('checkout/resumo', [CheckoutAppController::class, 'resumo'])->middleware('throttle:60,1,app-resumo')->name('checkout.resumo');

    Route::post('conta/cadastro', [ContaAppController::class, 'cadastro'])->middleware('throttle:10,1,app-cadastro')->name('conta.cadastro');
    Route::post('conta/login', [ContaAppController::class, 'login'])->middleware('throttle:10,1,app-login')->name('conta.login');
    Route::post('conta/esqueci-senha', [ContaAppController::class, 'esqueciSenha'])->middleware('throttle:5,1,app-esqueci')->name('conta.esqueci');

    Route::middleware([AuthenticateAppCliente::class, 'throttle:120,1,app-conta'])->group(function () {
        Route::get('conta/eu', [ContaAppController::class, 'eu'])->name('conta.eu');
        Route::patch('conta/eu', [ContaAppController::class, 'atualizar'])->name('conta.atualizar');
        Route::put('conta/senha', [ContaAppController::class, 'senha'])->middleware('throttle:10,1,app-senha')->name('conta.senha');
        Route::post('conta/sair', [ContaAppController::class, 'sair'])->name('conta.sair');
        Route::delete('conta', [ContaAppController::class, 'excluir'])->middleware('throttle:5,1,app-excluir')->name('conta.excluir');
        Route::post('conta/push-token', [ContaAppController::class, 'pushToken'])->name('conta.push');

        Route::get('conta/enderecos', [EnderecoAppController::class, 'index'])->name('enderecos');
        Route::post('conta/enderecos', [EnderecoAppController::class, 'store'])->name('enderecos.criar');
        Route::put('conta/enderecos/{endereco}', [EnderecoAppController::class, 'update'])->whereNumber('endereco')->name('enderecos.atualizar');
        Route::delete('conta/enderecos/{endereco}', [EnderecoAppController::class, 'destroy'])->whereNumber('endereco')->name('enderecos.apagar');

        Route::get('favoritos', [FavoritoAppController::class, 'index'])->name('favoritos');
        Route::post('favoritos/{produto}', [FavoritoAppController::class, 'store'])->whereNumber('produto')->name('favoritos.criar');
        Route::delete('favoritos/{produto}', [FavoritoAppController::class, 'destroy'])->whereNumber('produto')->name('favoritos.apagar');

        Route::post('checkout/pedido', [CheckoutAppController::class, 'pedido'])->middleware('throttle:10,1,app-pedido')->name('checkout.pedido');
        Route::get('pedidos', [PedidoAppController::class, 'index'])->name('pedidos');
        Route::get('pedidos/{pedido}', [PedidoAppController::class, 'show'])->whereNumber('pedido')->name('pedido');
        Route::get('pedidos/{pedido}/status', [PedidoAppController::class, 'status'])->whereNumber('pedido')->name('pedido.status');
        Route::post('pedidos/{pedido}/cancelar', [PedidoAppController::class, 'cancelar'])->whereNumber('pedido')->middleware('throttle:10,1,app-cancelar')->name('pedido.cancelar');
        Route::get('pedidos/{pedido}/nota', [PedidoAppController::class, 'nota'])->whereNumber('pedido')->name('pedido.nota');
    });
});

// Relatórios de pagamento da Amazon (agente no PC da operação, 2026-10-12).
// Autenticação por Bearer AMAZON_REPORTS_TOKEN (ou o do TikTok).
Route::post('/amazon/reports/import', [AmazonReportsController::class, 'import'])
    ->middleware('throttle:60,1')
    ->name('api.amazon.reports.import');
Route::post('/amazon/reports/status', [AmazonReportsController::class, 'status'])
    ->middleware('throttle:30,1')
    ->name('api.amazon.reports.status');
