# Privacidade, cookies e sessão — loja KazaKora

Criado em 10/10/2026 a pedido do Lira. Vale **só para a loja (e-commerce
KazaKora)**; o painel admin (KoraSync/KoraFlex) não mostra o aviso nem registra
visita. Serve de apoio para qualquer recurso novo que colete dado de quem
visita a loja.

## Leis que seguimos

| Lei / norma | O que exige de nós |
|---|---|
| **LGPD** — Lei 13.709/2018 | Base legal para cada dado (art. 7º), finalidade clara, só o necessário (art. 6º), provar o consentimento (art. 8º, § 2º), segurança (art. 46), atender os direitos do titular (art. 18). |
| **Guia Orientativo de Cookies da ANPD** (out/2022) | Separar cookie necessário de não necessário; não necessário só com consentimento; aviso claro, com link para a política; guardar registro do consentimento. |
| **Marco Civil da Internet** — Lei 12.965/2014, art. 15 | Loja com fins econômicos é obrigada a guardar **IP + data/hora de acesso por 6 meses**, em sigilo; entrega só com ordem judicial (art. 10). |
| **CDC** — Lei 8.078/1990 | Informação clara ao consumidor; nada de anúncio enganoso. |

## Como está montado

### Aceite dos cookies (desde 26/10/2026: sem faixa, aceite nos Termos de Uso e no Finalizar pedido)
- **Pedido do Lira em 26/10/2026** ("não quero mais esse botão de cookies [...]
  vai tá dentro do termos de uso [...] assim que você clicar em comprar, você
  aceita"): a faixa (`AvisoCookies.vue`) saiu da loja e do checkout. Motivo: no
  celular ela cobria o COMPRAR AGORA na primeira tela, e 74% dos visitantes da
  Meta nunca aceitavam (leitura de ADS de 26/10).
- **Pixel da Meta do navegador carrega para todo visitante, sem esperar aceite**
  (`pixelMeta.js` só exige o ID; `app.js` chama `rastrearPagina` a cada
  navegação). A API de Conversões (servidor) continua só com aceite
  (`Cookies::aceitou`), que agora vem do Finalizar pedido (ou de OK antigo).
- Os **Termos de Uso** ganharam a seção "Cookies e dados de navegação": ao
  navegar no site, o visitante concorda com cookies e coleta de dados; o aceite
  é registrado no Finalizar pedido. Políticas de Privacidade e de Cookies dizem
  o mesmo. `VERSAO` não mudou (a frase do checkout é a mesma de 24/10).
- Risco conhecido, escolha do Lira: aceite implícito pela navegação e Pixel antes
  de qualquer clique contrariam o Guia de Cookies da ANPD (cookie de publicidade
  só com consentimento por ação clara). Não reverter sem pedido do Lira.

#### Histórico: 25/10/2026, faixa "ao navegar você concorda", aceite em 3 s
- **Pedido do Lira em 25/10/2026:** a faixa voltou (`AvisoCookies.vue`, na
  `AppLayout` e na `CheckoutLayout`) com "Ao continuar navegando no site, você
  concorda com o uso de cookies e dos seus dados de navegação, conforme a LGPD"
  e botão OK. O OK grava o aceite na hora; **sem clique, a faixa some sozinha em
  3 s e grava o aceite do mesmo jeito** (`POST /cookies/aceitar`). Versão
  `2026-10-25`; os OKs de 2026-10-18 e 2026-10-24 continuam valendo.
- Motivo: sem a faixa (24/10) o aceite só vinha no Finalizar pedido e o Pixel
  da Meta não via ninguém antes do checkout. Com o aceite em 3 s, Pixel e API
  de Conversões voltam a pegar quase toda visita, sem quebrar a regra "terceiro
  só depois do aceite" (`pixelMeta.js` continua exigindo `cookies.aceito`).
- Risco conhecido, escolha do Lira: aceite por "continuar navegando" é
  consentimento fraco para a ANPD (ela pede ação clara e opção de recusar).
  Não há botão de recusar.
- O aceite no Finalizar pedido (abaixo) continua valendo.

#### Histórico: 24/10/2026, só no checkout
- Pedido do Lira em 24/10/2026: saiu o aviso flutuante (`AvisoCookies.vue`,
  botão OK). O aceite agora é a frase abaixo do **Finalizar pedido**
  (`CheckoutV2.vue`; no checkout antigo, a caixinha do `Payment.vue`): "Ao
  clicar em Finalizar pedido, você declara que leu e aceita os termos de uso,
  a política de privacidade e o uso de cookies, conforme a LGPD".
- Ao finalizar (`CheckoutController`, antes de criar o pedido),
  `Cookies::registrarAceite()` grava a prova e cria os cookies, igual ao OK
  antigo. Beauty Kora e FerraKora: a API de pedido
  (`CheckoutOutroSiteController::pedido`) grava só a prova, com `origem` do site.
- **Efeito (24/10, corrigido em 25/10 pela faixa):** quem só navegava e não comprava ficava só com os cookies necessários.
  Pixel da Meta (PageView, ViewContent, AddToCart, no navegador e no servidor)
  só roda para quem já comprou ou deu OK no aviso antigo (versão 2026-10-18,
  que continua valendo: `Cookies::VERSOES_ANTERIORES`).
- Sem aceite a loja funciona normal com os cookies necessários e o de navegação
  (`kazakora_visitor`, legítimo interesse, desde 25/10/2026). **Nenhum cookie de
  publicidade é criado antes do aceite.**
- `POST /cookies/aceitar` (`CookiesController`) continua existindo para o OK vindo de outro lugar e:
  - grava a prova em `consentimentos_cookies` (visitante, usuário, versão do aviso, IP, navegador, data/hora);
  - cria `kazakora_cookies` = versão do aviso e `kazakora_visitor` (12 meses).
- Versão do aviso: `App\Support\Privacidade\Cookies::VERSAO`. **Mudou o que a
  loja coleta? Troque a versão** — o aviso volta para todo mundo e a política
  precisa ser atualizada junto.

### Cookies

| Cookie | Tipo | Para quê | Duração | Base legal |
|---|---|---|---|---|
| `kazakora-session` | Necessário | login, carrinho, checkout | `SESSION_LIFETIME` (120 min sem uso) | art. 7º V e IX |
| `XSRF-TOKEN` | Necessário | proteção contra CSRF | igual à sessão | art. 7º IX, art. 46 |
| `kazakora_cookies` | Necessário | lembra o OK | 12 meses | art. 8º § 2º |
| `kazakora_cep` | Necessário | CEP que o cliente digitou, para o preço já sair com o frete dele (frete embutido, 2026-10-12) | 12 meses | art. 7º V |
| `kazakora_cupom_1a` | Necessário | DESDE 2026-10-25 NÃO É MAIS GRAVADO (o pop-up aparece toda vez que o checkout abre sem cupom). Antes lembrava que o pop-up do cupom de 1ª compra do checkout já apareceu, para não mostrar de novo (2026-10-23). Gravado pelo navegador (JS), sem dado pessoal: só o valor `1` | 12 meses | art. 7º V |
| `kazakora_visitor` | Navegação | liga as páginas abertas pelo mesmo navegador (histórico de navegação e número de páginas no olho de Visitas ao site). Desde 25/10/2026 criado no **primeiro acesso** (`Cookies::garantirVisitante`, no `TrackSiteVisit`), antes do aceite; o mesmo código fica na sessão (`kazakora_visitante`) para quem recusa cookie | 12 meses | art. 7º IX (legítimo interesse) |
| `_fbp`, `_fbc` | Publicidade (Meta) | Pixel da Meta (pixel_kazakora, 2186107885537817): PageView, ViewContent, AddToCart, InitiateCheckout, Purchase. Desde 26/10/2026 carrega desde o primeiro acesso, sem esperar o OK (`resources/js/Shared/pixelMeta.js`); ID do admin (/admin/pixel-meta) ou `META_ADS_PIXEL_ID`. Também lidos pelo servidor para a API de Conversões (abaixo) | até 90 dias | art. 7º I (consentimento) |
| `_gcl_aw`, `_gcl_au` | Publicidade (Google) | tag do Google Ads (`GOOGLE_ADS_CONVERSION_ID`): conversão "Compra" com valor e número do pedido. Só depois do aceite registrado (`resources/js/Shared/tagGoogle.js`). `_gcl_aw` também criado pelo servidor no aceite, com o gclid guardado na sessão (desde 19/11/2026) | até 90 dias | art. 7º I (consentimento) |

Todos são do próprio site, criptografados pelo Laravel, `HttpOnly` e
`SameSite=Lax`. A sessão só trafega em https quando o `APP_URL` é https
(`config/session.php`). Login expira em 8 h (`ExpireStaleSession`).

### Tag do Google Ads (pedido 2026-11-19, PMax do travesseiro)
Conversão "Compra" do Google Ads no pedido pago do kazakora.com, com valor e
número do pedido. Código: `App\Support\GoogleAds` (servidor) e
`resources/js/Shared/tagGoogle.js` (front, chamado pelo `app.js` e pelo
`CheckoutV2.vue`). Com o pop-up de cookies (homolog, 18/11) a tag carrega já
no "Aceito" da primeira página.

- **Consentimento: o aceite registrado (`Cookies::aceitou`), o mesmo da API de
  Conversões.** Nesta versão (produção, sem pop-up) o aceite vem do Finalizar
  pedido ou de OK antigo: diferente do Pixel da Meta, o `gtag.js` NÃO carrega
  sem ele. Sem aceite: nada do Google carrega. A compra só sai
  do servidor para o front (`google_compra` no status do pagamento,
  `googleCompra` na confirmação) se `Cookies::aceitou` for verdadeiro naquela
  requisição, o pedido estiver pago e for da loja (`ConversoesMeta::pedidoDaLoja`).
- **Clique do anúncio (`gclid`):** guardado só na sessão do servidor
  (`TrackSiteVisit::SESSAO_GCLID`, chave `google_gclid`), igual ao fbclid. No
  aceite, `Cookies::criarGclAw` cria o `_gcl_aw` (`GCL.<hora>.<gclid>`, 90 dias,
  `.kazakora.com`, sem criptografia, `bootstrap/app.php`) para a tag ligar a
  compra ao clique mesmo quando o aceite só vem no Finalizar pedido. Nenhum
  cookie do Google existe antes do aceite. O log de visitas continua sem o
  valor do gclid antes do aceite.
- **Consent Mode:** depois do aceite, `ad_storage`, `ad_user_data` e
  `ad_personalization` liberados; `analytics_storage` negado (a loja não usa
  Google Analytics).
- **Uma vez por pedido:** `transaction_id` = número do pedido (o Google
  desduplica) e trava no `localStorage` (`kzg_<pedido>`).
- **Conversões otimizadas (Enhanced Conversions):** e-mail e celular
  (+55...) normalizados e com hash SHA-256, só com
  `GOOGLE_ADS_ENHANCED_CONVERSIONS=true`. Desligado por padrão: ligar só
  depois de aceitar os "termos de dados do cliente" na conta do Google Ads.
  Mesmo dado que a API de Conversões da Meta já manda com o mesmo aceite; as
  políticas já citam.
- **.env:** `GOOGLE_ADS_CONVERSION_ID` (AW-...), `GOOGLE_ADS_PURCHASE_LABEL`
  (rótulo da ação "Compra"), `GOOGLE_ADS_ENHANCED_CONVERSIONS`. Sem ID ou sem
  rótulo válidos, a tag não carrega.
- `VERSAO` passou para **2026-11-19** (políticas e Termos citam o Google). Os aceites anteriores continuam valendo (`VERSOES_ANTERIORES`),
  como nas trocas de 02/11 e 18/11.
- Cookies do Google: `_gcl_aw` e `_gcl_au` (já estavam na lista
  `COOKIES_REGISTRAVEIS` do log de visitas).

### API de Conversões da Meta (pedido 2026-10-18)
O servidor manda à Meta os mesmos **AddToCart**, **InitiateCheckout** (desde
24/11/2026) e **Purchase** do Pixel, com o mesmo `event_id` (a Meta desduplica).
Código: `App\Services\Meta\ConversoesMeta`, jobs `EnviarEventoMetaJob`
(AddToCart e InitiateCheckout) e `EnviarPurchaseMetaJob` (pagamento aprovado).
InitiateCheckout: a tela do checkout entrega ao Pixel o `event_id`
(`metaInitiateCheckout`, guardado na sessão enquanto o carrinho for o mesmo) e o
servidor manda uma vez por carrinho, só com OK.

- **Consentimento:** é o mesmo OK do Pixel (`Cookies::aceitou`, versão atual).
  Sem OK, nada sai do servidor. AddToCart: OK na hora do clique. Purchase: OK
  no momento do pagamento (o checkout grava `orders.meta_rastreio`; sem OK fica
  nulo e o Purchase do servidor não sai). Escolha documentada: o aviso é "só
  OK", então o OK cobre Pixel e API de Conversões juntos (mesma finalidade:
  medir anúncios); a política foi atualizada e a `VERSAO` trocada para
  2026-10-18, para o aviso voltar a todos.
- **O que vai:** e-mail, telefone (com 55), primeiro e último nome, cidade, UF,
  CEP, país e id do cliente, todos com hash SHA-256 do texto normalizado; IP,
  navegador, `_fbp`, `_fbc` e a página, sem hash (a Meta exige assim).
  Pedido: valor, BRL, ids dos produtos e número do pedido.
- **Só kazakora.com:** pedido com `origin = loja` e `site_origem` nulo.
  Marketplace, FerraKora, Beauty Kora e app nativo (sem aviso de cookies) não mandam.
- **Uma vez por pedido:** `orders.meta_purchase_enviado_em` é a trava; event_id
  `purchase-{id do pedido}` (o Pixel do modal de pagamento aprovado usa o mesmo).
- **Guarda:** `orders.meta_rastreio` (IP, navegador, `_fbp`, `_fbc`, página) é
  apagado assim que o Purchase sai, ou aos 30 dias (`privacidade:limpar`).
- **Liga/desliga (.env):** `META_CAPI_ENABLED` (padrão desligado),
  `META_CAPI_ACCESS_TOKEN` (vazio = usa `META_ADS_ACCESS_TOKEN`),
  `META_CAPI_TEST_EVENT_CODE` (manda só para "Eventos de teste" do Gerenciador).
  Desligado ou sem token: não manda nada, só registra `meta_capi.pulado` no log.
- **Log próprio:** `storage/logs/meta-capi-AAAA-MM-DD.log` (30 dias, sempre em
  info): `meta_capi.enviado` com `recebidos` (events_received) e `fbtrace_id`,
  `meta_capi.erro` e `meta_capi.desistiu` (3 tentativas esgotadas).

### O que é registrado das visitas (`site_visits`, middleware `TrackSiteVisit`)

| Campo | Sem OK | Com OK |
|---|---|---|
| página, data/hora | sim | sim |
| preço que a página de produto mostrou ao abrir e o frete dentro dele (`preco_visto`, `frete_visto`, desde 12/11/2026; dado do produto, não do visitante) | sim | sim |
| IP | sim (Marco Civil) | sim |
| visitante | `kazakora_visitor` (desde 25/10/2026; antes, código tirado da sessão `s-...`) | `kazakora_visitor` |
| navegador completo | **não** | sim |
| aparelho (celular/tablet/computador) | sim | sim |
| origem (site, sem `?...`) e UTM | sim | sim |
| origem classificada (`origem`: instagram, google_ads, direto...) | sim | sim |
| parâmetro de clique (`click_id_param`: fbclid, gclid, ttclid...) | sim | sim |
| valor do clique (`click_id`) | só o `fbclid` (desde 02/11/2026, pedido do Lira); os outros **não** | sim |
| `consentiu` | false | true |
| cookies do visitante (`cookies`, JSON) | **não** | sim, só a lista permitida |

**Cookies no log de visitas (pedido 2026-10-12, olho de Visitas ao site):**
só com o OK, e só os da lista permitida `Cookies::COOKIES_REGISTRAVEIS`
(`kazakora_visitor`, `kazakora_cookies`, `_fbp`, `_fbc`, `_ga`, `_ga_*`, `_gid`,
`_gcl_au`, `_gcl_aw`, `_ttp`, `ttclid`), valor cortado em 200 caracteres.
Sessão (`kazakora-session`), `XSRF-TOKEN`, `remember_*` e qualquer cookie fora
da lista **nunca** são gravados. Cookie novo? Entrar na lista é decisão
consciente (e política atualizada). Desde 16/10/2026 a loja carrega o Pixel da Meta (só após o OK);
os outros de terceiros só aparecem se o navegador já os tiver. Apagados junto com o IP aos 6
meses. Visitas anteriores a 12/10/2026 não têm cookies.

Não registra: admin, robôs, arquivos, recarga parcial do Inertia, nada que não
seja GET 200.

**Histórico de navegação no olho (pedido 2026-10-25):** o olho de Visitas ao
site mostra, no período, a coluna Página de cada visita e o quadro "Rotas
visitadas": total de páginas vistas, rotas diferentes e quantas vezes cada uma
foi aberta (até 200 rotas). O mesmo `kazakora_visitor` liga visitas, lead do
carrinho abandonado, eventos do checkout e geolocalização (`Cookies::visitante`).
A contagem de visitantes do painel não mudou: sem aceite continua um por IP no dia.

Contagem de visitantes (painel e Indicadores, `SiteVisit::visitantesPorDia`,
pedido 2026-10-11), sem repetir a mesma pessoa/IP:
- Com o OK: visitante único pelo cookie `kazakora_visitor` (fator principal).
  Dois cookies no mesmo IP são duas pessoas.
- Sem o OK: o código da sessão muda a cada sessão, então conta um visitante
  por IP no dia.
- Visita sem OK de um IP que no mesmo dia deu OK entra no cookie dele (quem
  navegou antes de dar OK não conta duas vezes). A página do OK já conta.

#### Visitas da FerraKora e da Beauty Kora (`/api/{site}/visita`, 2026-11-11)
O site avisa cada página (`OutroSiteVisitaController`). Desde 11/11/2026 o
aviso da FerraKora leva também, para a tela Visitas FerraKora mostrar de onde
veio e se o pixel rodou (as visitas dos anúncios apareciam todas "Interno"):
- 1ª página da aba (`e=1`): site de onde veio (`ref`, sem o `?...`), `utm_*` e
  o parâmetro de clique (fbclid, gclid...). Valor do clique só do fbclid,
  mesma regra da loja. Páginas seguintes (`e=0`) são Interno.
- `_fbp` e `_fbc` do Pixel da Meta, em `site_visits.cookies`. A FerraKora
  não tem aviso de cookies e roda o Pixel desde 28/10/2026 (decisão do Lira);
  a Política dela cita os dois cookies. Guardar o valor aqui é medir os
  anúncios, a mesma finalidade. Prazo: o mesmo das visitas.

### Localização no pedido (pedido 2026-10-12)
O pedido do site grava `orders.visitante_latitude` e `visitante_longitude`,
aproximadas pelo IP, dos cabeçalhos `CF-IPLatitude`/`CF-IPLongitude` da
Cloudflare (regra "Add visitor location headers"). Não é o GPS do aparelho nem
pede permissão ao navegador. Base legal: execução do contrato e prevenção a
fraude (art. 7º V e IX). Sem Cloudflare fica nulo.

### Localização do visitante por sessão (`visitante_geolocalizacoes`, pedido 2026-10-24)
Latitude, longitude e CEP aproximados pelo IP, uma linha por sessão, para
depois mostrar o frete já com o lugar do cliente (por enquanto só grava, nada
na tela usa). Código: `GeoDoVisitante`, middleware `RegistrarGeoDoVisitante`.
- **De onde vem:** cabeçalhos da Cloudflare (`CF-IPLatitude`, `CF-IPLongitude`,
  `CF-Postal-Code`, `CF-IPCity`, `CF-Region-Code`, `CF-IPCountry`,
  `CF-Timezone`), aceitos só quando a requisição chega de um IP da Cloudflare.
  Sem eles, a base DB-IP do servidor (`LocalDoIp`: cidade, UF e centro da
  cidade, sem CEP). O IP não vai a nenhuma API de terceiro. Não é GPS e não
  pede permissão ao navegador.
- **O que grava:** site, código da sessão (o mesmo `s-...` de `site_visits`,
  nunca o id da sessão), visitante (`kazakora_visitor` com OK), `user_id` se
  logado, IP, fonte, país, UF, cidade, CEP (às vezes só os 5 primeiros
  dígitos), latitude, longitude e fuso. Também fica na própria sessão (chave
  `visitante_geo`). Grava de novo só se o IP ou o login mudarem.
- Loja: só o domínio da loja, GET, sem admin e sem robô. FerraKora e Beauty
  Kora: pela rota de visita (`/api/{site}/visita`); a "sessão" é o visitante
  tirado do IP.
- Sem cookie novo. Base legal: procedimentos preliminares do contrato
  (mostrar frete e prazo, art. 7º V) e legítimo interesse (art. 7º IX), dado
  aproximado e mínimo.
- Guarda: **6 meses** desde a última atualização, depois apagado
  (`privacidade:limpar`). Falta citar na Política de Privacidade pública.

### Medição do checkout (`checkout_eventos`, pedido 2026-10-23)
O que a pessoa fez na `/finalizacao`, para entender quem sai sem comprar:
abriu, viu o formulário, rolagem (25/50/75/100%), primeiro foco em cada
campo, campo preenchido, CEP respondido, forma de pagamento escolhida,
clique em Finalizar, erro mostrado (qual campo), saída com tempo ativo e
rolagem máxima, e o pedido criado (servidor).
- **Nunca** o que foi digitado: só o NOME do campo. A rota
  `POST /finalizacao/eventos` aceita uma lista fechada de eventos e campos e
  recusa o lote inteiro se vier qualquer chave a mais.
- Sem IP e sem navegador completo. Visitante igual ao `site_visits`
  (`kazakora_visitor` com OK, senão código da sessão), `user_id` se logado,
  aparelho e origem da visita.
- Sem cookie novo e sem terceiros (nada de Pixel aqui).
- Base legal: legítimo interesse (melhorar a tela de compra), dado mínimo e
  pseudônimo. Leitura só pelo comando `php artisan checkout:funil --dias=7`
  (equipe e robôs fora).
- **FerraKora (2026-11-14):** a tela `/seguro-pagamento` de lá manda os
  mesmos eventos, com a mesma lista fechada, para
  `POST /api/ferrakora/checkout/eventos` (`OutroSiteCheckoutEventosController`),
  aceitos só dos domínios em `services.ferrakora.origens`. Ficam na mesma
  tabela com `site = ferrakora` (a loja fica com `site` nulo). O visitante é
  o código tirado do IP, igual ao das visitas da FerraKora; o IP em si não é
  gravado aqui. Sem cookie novo e sem terceiros. Leitura:
  `php artisan checkout:funil --site=ferrakora`. Mesmo prazo de 6 meses.
- **Botão do cupom (2026-11-23):** `cupom_clicou` (clicou em "Aplicar
  cupom" ou apertou Enter no campo), `cupom_aplicado` e `cupom_erro`, com
  `campo = cupom` e nada mais. O código do cupom digitado **não** é gravado
  (chave a mais recusa o lote). Aparecem na janela "Ver fluxo" do admin.
- **Confira seu WhatsApp (2026-11-23):** quando o celular tem DDD 55 e o
  CEP é de fora da área do DDD 55 (`AlertaWhatsappDdd55`), a tela pede para
  conferir o número e grava `whatsapp_alerta_ddd55`, depois
  `whatsapp_confirmou` ou `whatsapp_corrigiu`, com `campo = celular` e nada
  mais. O número **não** é gravado nesses eventos.
- **Motivo do erro no cartão (2026-11-24):** o evento `erro` com
  `campo = cartao` pode trazer em `valor` um código da lista fechada
  `CheckoutEvento::MOTIVOS_CARTAO` (numero, validade, cvv, nome, documento,
  bin, parcelas, nao_carregou, sem_token, outro), gravado na coluna
  `motivo`. Diz só QUAL parte do cartão deu erro; número, validade, CVV,
  nome e CPF digitados **não** são lidos nem gravados (texto fora da lista
  recusa o lote). Aparece na janela "Ver fluxo" do admin.
- **Adicionar ao carrinho (2026-11-20):** o servidor grava
  `produto_carrinho` com o id do produto quando alguém põe um item no
  carrinho da loja ou clica em COMPRAR AGORA (`CartController`). Mesmo dado
  mínimo (visitante, aparelho, id do produto), sem cookie novo. Serve ao
  funil da tela Comportamento.
- **Equipe fora da tela Comportamento (2026-11-20):** a coleta dos
  infoprodutos confere o IP de quem manda o evento contra a lista da equipe
  (`COMPORTAMENTO_IPS_EQUIPE` e os IPs em que a equipe navegou logada) e marca
  a visita como teste. O IP continua sem ser gravado.

### Carrinho abandonado (`lead_checkout`, pedido 2026-10-24)
Quem preenche a Identificação do checkout e clica em "Ir para Entrega" (e de
novo em "Ir para Pagamento") fica gravado com nome, e-mail, CPF, celular, os
itens do carrinho (produto, variação, quantidade, preço), loja (`site`), sessão,
IP, navegador e origem/UTM da visita. Vira "convertido" com o `order_id` quando
o pedido sai da mesma sessão, do mesmo e-mail ou do mesmo CPF.
- Rota `POST /finalizacao/lead`: com CSRF, 10 por minuto, responde sempre 204
  sem corpo (não revela se o e-mail/CPF existe). Itens e preços vêm do
  carrinho do servidor. Falha vira log `checkout.lead.falhou` só com a classe
  do erro, nunca com o dado da pessoa.
- Leitura só no admin: `/admin/carrinhos-abandonados` (permissão `pedidos.view`),
  somente consulta.
- Base legal: procedimentos preliminares do contrato que a pessoa começou
  (art. 7º V). Mensagem de recuperação: legítimo interesse com opção de sair
  (art. 7º IX).
- **Sequência de recuperação (decisão do Lira 21/11/2026,
  `docs/carrinho-abandonado.md`)**: o comando `loja:carrinho-abandonado-sequencia`
  copia nome, e-mail, celular e itens para `adm_abandoned_carts` e manda até 6
  mensagens (WhatsApp por template e e-mail) em 30 dias, sem desconto. Opção de
  sair em toda mensagem: responder "sair"/"parar" no WhatsApp ou o link "Não
  quero mais receber" do e-mail; quem desmarcou `recebe_promocoes` não recebe.
  Quem pediu para parar não volta para a sequência.
- Prazo de guarda (decisão do Lira, 2026-10-24): **3 meses** desde a última
  interação (`updated_at`). Depois disso o lead é apagado, aberto ou
  convertido, pelo `php artisan leads:limpar` (todo dia 03:52, horário de São
  Paulo; `--dry-run` só conta). Convertido também sai porque o que importa da
  venda já está no pedido. Prazo em `config/loja.php`
  (`LOJA_LEAD_CHECKOUT_DIAS_GUARDA`, padrão 90 dias). Falta citar na Política
  de Privacidade pública.

### Clientes dos sites (`/admin/clientes-dos-sites`, pedido 2026-11-16)
Tela do admin com as contas criadas na KazaKora (`users`, só `role =
customer`) e na FerraKora (`ferrakora_clientes`): cadastro, endereços,
pedidos, último acesso e os leads/carrinhos ligados pelo e-mail, CPF ou
telefone. **Não coleta nada novo**: só lê o que já existe.
- Desde 26/11/2026: filtro das duas lojas juntas e linha do tempo (só admin),
  ver "Histórico do cliente" acima.
- Permissão `pedidos.view` (a mesma de Clientes e Leads). Só leitura: nada é
  enviado, editado ou apagado. Hash de senha, `remember_token` e token de
  sessão da FerraKora nunca vão para a tela.
- Último acesso: KazaKora = última página vista logado (`site_visits`, some
  junto com o usuário aos 6 meses); FerraKora = último uso de uma sessão ainda
  guardada (`ferrakora_sessoes`).
- O WhatsApp é só um link `wa.me` que abre a conversa; quem escreve é a
  pessoa da equipe. Base legal do contato: execução do contrato (art. 7º V)
  para pedido; oferta só para quem aceita promoções (`recebe_promocoes`).
- **Exportar CSV** leva o que está filtrado (nome, e-mail, telefone, CPF,
  cidade, pedidos). Cada exportação fica na Auditoria (`audit_logs`, ação
  `export`, entidade `ClientesDosSites`: quem, quando, site, filtro e IP).
  O arquivo baixado é dado pessoal: não mandar para fora da equipe.

### Histórico do cliente (`cliente_eventos`, pedido 2026-11-26)
Pedido do Lira: "quero saber quem fez cadastro no KazaKora e FerraKora, entrou,
quais telas acessou, quais horários, tipo de pagamento, se tem imagem de perfil,
por onde veio, se refez senha; quero rastreio de histórico total dos clientes".
Tela: Admin > Clientes dos sites (filtro KazaKora e FerraKora / KazaKora /
FerraKora, colunas de acompanhamento) e a linha do tempo
`/admin/clientes-dos-sites/{site}/{id}/historico`.

- **O que passa a ser gravado (desde 26/11/2026, nada do passado é
  inventado)** em `cliente_eventos` (`App\Modules\Clientes\Support\HistoricoDoCliente`),
  só de contas de cliente (equipe e admin fora): cadastro (com a origem: a 1ª
  página de fora do site do mesmo `kazakora_visitor` nos últimos 30 dias; na
  FerraKora, utm_*, site de onde veio sem o `?...` e o NOME do parâmetro de
  clique que o site manda junto do cadastro), login (como: senha, código,
  lembrar-me, cadastro), saída, link de nova senha enviado, senha redefinida
  pelo link, senha trocada no perfil, foto trocada/removida, endereço
  criado/alterado/apagado (só cidade/UF), perfil alterado (só o NOME dos campos).
  Cada evento leva IP e tipo de aparelho. Nunca senha, token, valor do clique
  nem o que foi digitado.
- **Navegação ligada à conta:**
  - KazaKora: as visitas com `user_id` já existiam (desde 12/10/2026). Novo:
    no login, no cadastro e no aceite de cookies com a conta logada, as visitas
    anteriores do MESMO `kazakora_visitor` (UUID, nunca o código de sessão
    `s-...`), sem dono e com menos de 6 meses, ganham o `user_id`, **só se o
    aceite de cookies estiver registrado naquela hora** (`Cookies::aceitou`).
    Fica um evento `visitas_ligadas` com a quantidade. Risco aceito:
    computador compartilhado liga a navegação de quem usou antes no mesmo
    navegador.
  - FerraKora: o visitante de lá é o IP (pode ser de várias pessoas), então
    **nada do passado é ligado**. A partir de agora, o site manda o token da
    conta no corpo do aviso de visita (`t`, nunca na URL) só com a conta aberta
    E o "Aceitar todos" (`ac=1`); a visita ganha `site_visits.ferrakora_cliente_id`.
  - Na tela, a página só aparece se a visita tem aceite (`consentiu`, ou
    FerraKora com dono); sem aceite, só "Acessou o site logado" e o horário.
  - Tempo na página: diferença até a próxima página do mesmo navegador, se
    for de até 30 min.
- **Mapa de cliques (kzm.js, `infoproduto_eventos`) continua anônimo** e não
  entra no histórico. Medição do checkout (`checkout_eventos`) entra só do
  próprio usuário ou dos `kazakora_visitor` cujas visitas já estão ligadas à conta.
- **O resto da linha do tempo só lê o que já existia:** pedidos, pagamentos
  (forma e status), e-mails do pedido (`order_email_logs`), WhatsApp pelo
  telefone da conta (`whatsapp_messages`), carrinho do checkout
  (`lead_checkout`, `leads` da FerraKora), sequência do carrinho abandonado
  (`adm_abandoned_carts.historico`), cupons (`cupom_usos`, `orders.coupon_code`),
  endereços, aceites de cookies, senha temporária (notificação
  `SenhaTemporariaEnviada`), último link de nova senha em aberto e sessões da
  FerraKora ainda guardadas. A tela mostra "registrado desde" de cada tipo.
- **Acesso:** a linha do tempo e as colunas de acompanhamento (último acesso,
  dias com acesso, foto, origem, pagamento, senha) são **só do admin**
  (`middleware admin`); a lista básica continua com `pedidos.view`. Cada
  abertura da linha do tempo fica na Auditoria (`audit_logs`, ação `view`,
  entidade `HistoricoDoCliente`).
- **Base legal:** execução do contrato (conta, pedidos, suporte) e legítimo
  interesse (segurança da conta, prevenção a fraude, entender o uso), art. 7º V
  e IX; páginas ligadas à pessoa só com o consentimento de cookies (art. 7º I).
- **Guarda:** login, saída e `visitas_ligadas` apagados aos 6 meses; IP e
  aparelho dos outros eventos apagados aos 6 meses; o evento fica enquanto a
  conta existir (some junto com ela, `cascadeOnDelete`). `site_visits.ferrakora_cliente_id`
  é apagado junto com o `user_id` aos 6 meses.
- **Políticas:** seção "Histórico da sua conta" na Política de Privacidade da
  KazaKora (`/politica-de-privacidade#historico-da-conta`) e na da FerraKora
  (`/politica-de-privacidade#historico-da-conta`), atualizadas em 26/11/2026.
  `Cookies::VERSAO` não mudou: nenhum cookie novo e nenhum terceiro novo; o
  que mudou é ligar à conta a navegação que já era registrada, e isso depende
  do aceite que já existe.

### Prazos de guarda (`php artisan privacidade:limpar`, todo dia 03:50)
- Medição do checkout (`checkout_eventos`) com mais de 6 meses: apagada.
- Carrinho abandonado (`lead_checkout`) sem interação há mais de 3 meses:
  apagado (`leads:limpar`, comando próprio).
- Visitas com mais de 6 meses: IP, navegador, usuário, visitante e valor do
  clique (`click_id`) apagados (`visitor_id = anonimo`). A estatística
  (página, dia, origem) fica.
- Localização do visitante por sessão (`visitante_geolocalizacoes`) sem
  atualização há mais de 6 meses: apagada.
- Prova de OK com mais de 5 anos: apagada.
- Histórico do cliente (`cliente_eventos`): login, saída e ligação de visitas
  com mais de 6 meses apagados; IP e aparelho dos outros eventos apagados.
  Visitas: `ferrakora_cliente_id` sai junto com o `user_id`.

## Regras para quem for mexer (Claude Code e Naia)

1. **Pixel do Meta, Google Analytics, Google Ads, TikTok Pixel, Hotjar e afins
   são cookies de terceiros não necessários.** Exceção decidida pelo Lira em
   26/10/2026: o Pixel da Meta do navegador carrega sem OK (seção Aceite). Os
   demais só podem carregar **depois** do OK
   (`page.props.cookies.aceito === true`), têm que entrar na tabela da
   `/politica-de-cookies` e a `VERSAO` tem que mudar. Com publicidade de
   terceiros, a ANPD recomenda dar ao cliente a opção de **recusar** — avaliar
   com o Lira trocar o "só OK" por "OK / Só os necessários" nesse momento.
2. Dado novo de visitante = conferir base legal, prazo de guarda e atualizar
   este arquivo e a Política de Privacidade.
3. Nunca guardar dado de cartão; pagamento fica com o intermediador.
4. Pedido de titular (acesso, correção, exclusão): chega pelo Fale conosco
   (Admin > E-mails do site) ou por contato@kazakora.com; responder em até
   15 dias (art. 19). Registro de acesso do Marco Civil não pode ser apagado
   antes dos 6 meses, mesmo a pedido.
5. Encarregado (DPO): hoje o próprio responsável (José Roberto Lira), como
   permite a Resolução CD/ANPD nº 2/2022 para agente de pequeno porte.

## Infoprodutos e mapa de cliques (2026-11-19)

Pedido do Lira em 19/11/2026: funil dos infoprodutos (digitalmktseller.com e
elcodigodivinoamen.com) e mapa de cliques das páginas de produto da KazaKora
(`/produtos/*`) e da FerraKora (`/produto/*`). Desde 26/11/2026, TODA página das
duas lojas (início, departamentos, busca, carrinho, blog, institucionais), menos
o checkout, que tem medição própria; a chave da página é a mesma das visitas
(`MedicaoDaLoja`, `PaginasDoSite::chave`).

- **Script:** `/js/kzm.js` (o mesmo nos 4 sites, < 3 KB, defer). Na KazaKora vem
  no HTML (`app.blade.php`) e a troca de página do Inertia avisa o script
  (`resources/js/Shared/medicao.js`); na FerraKora vem no `index.html`. A
  abertura da página vai na hora (sem esperar o navegador ficar ocioso). Envio em
  lote por `sendBeacon` para `POST /api-infoprodutos/evento`
  (`ColetaController`), aceito só dos domínios de `config/infoprodutos.php`
  (`INFOPRODUTOS_ORIGENS`), com CORS próprio (fora de `/api/*`).
- **O que vai:** página vista (produto, idioma, host do referrer, utm_*, se havia
  `fbclid` — nunca o valor), rolagem 25/50/75/100 e rolagem máxima, play e % do
  vídeo, clique em prévia/FAQ/botões de compra (com a posição), cada clique com
  x em % da largura do documento, y em px do topo do documento (do ponteiro do
  mouse ou do toque), largura da janela, link/botão/clique morto, id da
  seção, texto curto do elemento (de campo de formulário só o nome da tag, nunca
  o valor) e o nome estável `data-kzm`, ações nomeadas (`window.kzm.acao`) e o
  tempo na página. Clique sem ponteiro (Enter no teclado = origem `t`;
  `el.click()` de programa ou robô = origem `p`) vai com o centro do elemento,
  ou sem posição (null) se o elemento não aparece; nunca 0,0. Clique de origem
  `p` fica fora do mapa e do Reproduzir (2026-11-20: o robô de revisão do Meta
  "clica" em todos os links de cada anúncio novo). Lista fechada: qualquer
  campo fora dela = 422.
- **Fora da conta (lojas, 26/11/2026):** o mesmo critério das visitas
  (`VisitaIgnorada::doIp`): rede de robô (Meta/Google) e IP de fora do Brasil
  marcam a sessão (`robo`), IP da equipe/casa do dono marca como teste. O IP só
  é conferido em memória, nunca gravado.
- **O que NÃO vai:** cookie (nenhum é lido ou criado), localStorage, fingerprint,
  IP (não há coluna de IP; o país sai do `CF-IPCountry`, só quando a chamada vem
  de IP da Cloudflare), nome, e-mail, telefone, texto digitado.
- **Sessão:** código aleatório no `sessionStorage` (some ao fechar a aba). Não
  liga visitas de dias diferentes nem a pessoa à compra; o link do checkout da
  Kiwify leva `src` = posição do botão e `sck` = esse código, para saber qual
  botão/anúncio vendeu.
- **Prazo:** brutos (`infoproduto_eventos`) apagados aos 90 dias por
  `infoprodutos:consolidar` (03:45); ficam os totais por dia
  (`infoproduto_diarios`).
- **Vendas da Kiwify** (`POST /api-infoprodutos/kiwify/webhook`, assinatura
  HMAC-SHA1 com `KIWIFY_WEBHOOK_TOKEN`): gravamos id da venda, produto, tipo
  (principal, order bump, upsell, downsell), valor, moeda, país, método e
  UTMs/src/sck. Nome, e-mail, telefone, documento e IP do comprador NÃO são
  gravados. Carrinho abandonado: só id, produto, país e data.
- **Base legal / aviso de cookies:** dado anônimo, sem cookie e sem
  identificação: não muda o pop-up da loja (`Cookies::VERSAO` não mudou). A
  Política de Privacidade da loja ganhou o parágrafo "Mapa de cliques das
  páginas de produto"; as políticas do digitalmktseller (es/pt/en) e do Código
  Divino descrevem a medição.
- **Testes de ponta a ponta:** visita com `utm_source=teste-medicao` fica marcada
  (`teste = 1`) e fora do painel; apagar com
  `DELETE FROM infoproduto_eventos WHERE teste = 1`.

## Newsletter (24/11/2026, pedido do Lira na aula de e-commerce)

- **Onde:** bloco antes do rodapé de todas as páginas da loja e do blog
  (`BlocoNewsletter.vue`, dentro do `RodapeLoja.vue`). Admin > Atendimento >
  Newsletter (`/admin/newsletter`): contagem, lista, CSV (registrado na
  Auditoria), prévia e "Enviar teste para mim".
- **Base legal:** consentimento (art. 7º I). Caixinha obrigatória e **duplo
  opt-in**: a pessoa só entra na lista (status `ativo`) depois de clicar no
  link do e-mail de confirmação. Prova guardada em `newsletter_inscritos`:
  versão e texto do aceite (`config/newsletter.php`), IP, navegador e
  data/hora. Mudou o texto do aceite? Troque `consentimento_versao`.
- **Só o necessário:** e-mail e nome opcional. Inscrição nunca confirmada é
  apagada em 30 dias (`model:prune` das 03:58).
- **Sair:** todo e-mail tem o link de 1 clique (`/newsletter/sair/{token}`) e
  os cabeçalhos `List-Unsubscribe` / `List-Unsubscribe-Post` (RFC 8058, botão
  "Cancelar inscrição" do Gmail). Sair não apaga o registro: fica como
  `descadastrado` para provar a revogação e não mandar mais nada.
- **Contra robô:** campo escondido (`website`), limite de 5 inscrições por IP a
  cada 10 min e reenvio da confirmação no máximo a cada 10 min. A resposta é a
  mesma para e-mail novo ou já inscrito (não revela quem está na lista).
- **Resumo do blog por e-mail:** `php artisan newsletter:blog [--dry-run]
  [--data=AAAA-MM-DD] [--frequencia=diario|semanal]`. Um e-mail por período
  com as matérias do blog KazaKora (nunca um por matéria), em lotes pela fila
  (`NEWSLETTER_LOTE_TAMANHO`, `NEWSLETTER_SEGUNDOS_ENTRE_LOTES`); o mesmo
  resumo nunca sai duas vezes (`newsletter_envios`).
- **Agendamento DESLIGADO.** Para ligar: `NEWSLETTER_BLOG_AGENDADO=true` no
  `.env` do servidor (opcional `NEWSLETTER_BLOG_FREQUENCIA=diario|semanal`,
  `NEWSLETTER_BLOG_HORA=10:00`; semanal sai na sexta), `php artisan
  config:cache` e conferir com `php artisan schedule:list | grep newsletter`.


## Leads de materiais gratuitos (26/11/2026, Guia de Viagem da FerraKora)

Pedido do Lira: o Guia de Viagem de Bolso sai de graça em
`ferrakora.com/guia-de-viagem?origem=<instagram|facebook|reels|story...>` para
quem deixa nome, e-mail e celular. Código: `app/Modules/Materiais`,
`config/materiais.php`; na FerraKora, `src/pages/GuiaViagemPage.jsx`.

- **O que grava** (`leads_materiais`): nome, e-mail, celular (55 + DDD +
  número), cidade/UF e DDD sugerido aproximados pelo IP (Cloudflare ou base
  DB-IP do servidor; sem GPS, sem pedir permissão), origem (`?origem=`),
  `utm_*`, referrer, IP, navegador, quantas vezes baixou.
- **Base legal:** consentimento (art. 7º I). Caixinha obrigatória com o texto
  de `materiais.consentimento_texto` (receber o guia + dicas e ofertas por
  e-mail e WhatsApp). Prova: versão, texto, IP, navegador e data/hora. Mudou o
  texto? Troque `consentimento_versao`. O formulário mostra o aviso e o link da
  Política de Privacidade.
- **Sair:** link "Não quero mais receber" no e-mail (`/api/ferrakora/materiais/sair/{id}`
  assinado) marca `aceita_contato = false` e `descadastrado_em`. Pedido pelo
  WhatsApp/e-mail: marcar à mão na mesma tabela.
- **Contra robô:** campo escondido (`website`), Origin só dos domínios da
  FerraKora, limite de 5 cadastros por minuto e 20 por hora por IP; e-mail com
  o link no máximo 1 a cada 10 min para o mesmo endereço.
- **Download:** link assinado (`materiais.dias_link`, 30 dias) que entrega o
  PDF do Livro Digital (Entregáveis) cujo título contém "Guia de Viagem de
  Bolso" (ou o id em `MATERIAL_GUIA_VIAGEM_LIVRO_ID`).
- **Pixel:** evento `Lead` da Meta só com "Aceito" no pop-up de cookies da
  FerraKora (`lib/pixelMeta.js` descarta sem aceite). Sem dado pessoal no evento.
- **Prazo:** 12 meses sem nova interação (`materiais.meses_guarda`), apagado
  pelo `model:prune` das 03:58.
- **Admin:** Atendimento > Leads de materiais (`/admin/leads-materiais`,
  permissão `pedidos.view`), CSV registrado na Auditoria.
