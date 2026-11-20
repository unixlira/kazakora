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

### Aceite dos cookies (desde 18/11/2026: pop-up "Aceito" / "Somente o básico")
- **Pedido do Lira em 18/11/2026:** aviso de cookies em pop-up no MEIO da tela
  (`resources/js/Shared/Components/AvisoCookies.vue`, na `AppLayout`), com fundo
  escurecido e dois botões do mesmo tamanho. **Decisão do dono: os dois botões
  são honestos.** Coletar tudo mesmo com "Somente o básico" está fora de
  questão (enganoso; LGPD e CDC).
- **"Aceito"** → `POST /cookies/aceitar` → `Cookies::registrarAceite`: prova em
  `consentimentos_cookies` com `escolha = aceito`, cookie `kazakora_cookies` =
  `VERSAO`. Libera o Pixel da Meta do navegador (`pixelMeta.js` voltou a exigir
  `cookies.aceito`; o `app.js` dispara a página atual na hora do aceite), a API
  de Conversões (`Cookies::aceitou`), o `_fbc` do clique e os cookies de
  terceiros no log de visitas.
- **"Somente o básico"** → `POST /cookies/recusar` → `Cookies::registrarRecusa`:
  prova com `escolha = basico` (visitante, usuário, versão do aviso, IP,
  navegador, data/hora em `aceito_em`), cookie `kazakora_cookies` =
  `basico:<VERSAO>`. `aceitou()` fica falso: **Pixel e API de Conversões não
  rodam**, nenhum cookie de terceiro é criado nem gravado no log. Sobra o
  necessário e a visita anônima que a loja já registrava sem aceite (tabela
  "O que é registrado das visitas", coluna "Sem OK", inclusive o `fbclid`).
- O pop-up aparece uma vez: some quando `cookies.escolhido` (prop do Inertia,
  `Cookies::escolheu`) é verdadeiro. **Esc** não é aceite nem recusa: só
  esconde o pop-up até o fim da sessão do navegador (`sessionStorage`), sem
  gravar nada (Pixel continua desligado). Foco vai para o primeiro botão,
  Tab fica preso nos dois botões e no link, rolagem da página travada.
- Não aparece no admin (`cookies` é null lá), nem na `/politica-de-cookies`
  (a pessoa está lendo; a página tem o botão "Rever minha escolha de cookies",
  que reabre o pop-up para trocar a escolha). O checkout não tem pop-up.
- **Aceites antigos continuam valendo** (`VERSOES_ANTERIORES`, inclusive
  2026-11-02). `VERSAO` passou para **2026-11-18** porque a coleta mudou
  (o Pixel voltou a esperar o aceite e existe a recusa).
- O aceite no **Finalizar pedido** continua como estava (`CheckoutController`
  chama `registrarAceite`): quem escolheu "Somente o básico" e depois finaliza
  uma compra passa a ter aceite, porque a frase do botão diz isso.
- Termos de Uso, Política de Privacidade e Política de Cookies reescritas para
  dizer isso (saiu o "ao navegar você concorda").
- **FerraKora:** o mesmo pop-up no site dela (repo `ferrakora`). A escolha fica
  no `localStorage` de lá e a prova vem para cá por `POST /api/ferrakora/cookies`
  (`OutroSiteCookiesController`, `escolha=aceito|basico`, `visitante=<uuid>`
  opcional; só dos domínios em `services.ferrakora.origens`), gravada com
  `origem = ferrakora`. Pixel da FerraKora (e os `_fbp`/`_fbc` no aviso de
  visita) só depois do "Aceito".

#### Histórico: 26/10/2026, sem faixa, aceite nos Termos de Uso e no Finalizar pedido
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
- Risco conhecido na época, escolha do Lira: aceite implícito pela navegação e
  Pixel antes de qualquer clique contrariavam o Guia de Cookies da ANPD.
  Resolvido em 18/11/2026 pelo pop-up acima.

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
| `kazakora_cookies` | Necessário | lembra a escolha do pop-up: versão do aviso (aceito) ou `basico:<versão>` (somente o básico, desde 18/11/2026) | 12 meses | art. 8º § 2º |
| `kazakora_cep` | Necessário | CEP que o cliente digitou, para o preço já sair com o frete dele (frete embutido, 2026-10-12) | 12 meses | art. 7º V |
| `kazakora_cupom_1a` | Necessário | DESDE 2026-10-25 NÃO É MAIS GRAVADO (o pop-up aparece toda vez que o checkout abre sem cupom). Antes lembrava que o pop-up do cupom de 1ª compra do checkout já apareceu, para não mostrar de novo (2026-10-23). Gravado pelo navegador (JS), sem dado pessoal: só o valor `1` | 12 meses | art. 7º V |
| `kazakora_visitor` | Navegação | liga as páginas abertas pelo mesmo navegador (histórico de navegação e número de páginas no olho de Visitas ao site). Desde 25/10/2026 criado no **primeiro acesso** (`Cookies::garantirVisitante`, no `TrackSiteVisit`), antes do aceite; o mesmo código fica na sessão (`kazakora_visitante`) para quem recusa cookie | 12 meses | art. 7º IX (legítimo interesse) |
| `_fbp`, `_fbc` | Publicidade (Meta) | Pixel da Meta (pixel_kazakora, 2186107885537817): PageView, ViewContent, AddToCart, InitiateCheckout, Purchase. Desde 18/11/2026 só depois do "Aceito" (de 26/10 a 18/11 carregou sem aceite; `resources/js/Shared/pixelMeta.js`); ID do admin (/admin/pixel-meta) ou `META_ADS_PIXEL_ID`. Também lidos pelo servidor para a API de Conversões (abaixo) | até 90 dias | art. 7º I (consentimento) |
| `_gcl_aw`, `_gcl_au` | Publicidade (Google) | tag do Google Ads (`GOOGLE_ADS_CONVERSION_ID`): conversão "Compra" com valor e número do pedido. Só depois do aceite (`resources/js/Shared/tagGoogle.js`). `_gcl_aw` também criado pelo servidor no aceite, com o gclid guardado na sessão (desde 19/11/2026) | até 90 dias | art. 7º I (consentimento) |

Todos são do próprio site, criptografados pelo Laravel, `HttpOnly` e
`SameSite=Lax`. A sessão só trafega em https quando o `APP_URL` é https
(`config/session.php`). Login expira em 8 h (`ExpireStaleSession`).

### Tag do Google Ads (pedido 2026-11-19, PMax do travesseiro)
Conversão "Compra" do Google Ads no pedido pago do kazakora.com, com valor e
número do pedido. Código: `App\Support\GoogleAds` (servidor) e
`resources/js/Shared/tagGoogle.js` (front, chamado pelo `app.js` e pelo
`CheckoutV2.vue`).

- **Consentimento: o mesmo do Pixel da Meta.** O `gtag.js` só é baixado com
  `cookies.aceito` (pop-up "Aceito", aceite antigo ou o do Finalizar pedido).
  "Somente o básico" ou sem aceite: nada do Google carrega. A compra só sai
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
- `VERSAO` passou para **2026-11-19** (texto do pop-up e das políticas citam
  o Google). Os aceites anteriores continuam valendo (`VERSOES_ANTERIORES`),
  como nas trocas de 02/11 e 18/11.
- Cookies do Google: `_gcl_aw` e `_gcl_au` (já estavam na lista
  `COOKIES_REGISTRAVEIS` do log de visitas).

### API de Conversões da Meta (pedido 2026-10-18)
O servidor manda à Meta os mesmos **AddToCart** e **Purchase** do Pixel, com o
mesmo `event_id` (a Meta desduplica). Código: `App\Services\Meta\ConversoesMeta`,
jobs `EnviarEventoMetaJob` (AddToCart) e `EnviarPurchaseMetaJob` (pagamento aprovado).

- **Consentimento:** é o mesmo OK do Pixel (`Cookies::aceitou`, versão atual ou anterior).
  Quem escolheu "Somente o básico" não manda nada (desde 18/11/2026).
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
  roda o Pixel desde 28/10/2026; desde 18/11/2026 só depois do "Aceito" do
  pop-up de cookies dela (sem aceite, o aviso de visita vai sem `fbp`/`fbc`).
  A Política dela cita os dois cookies. Guardar o valor aqui é medir os
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
  somente consulta. Nenhuma mensagem de recuperação sai daqui: falar com o
  cliente pede OK do Lira.
- Base legal: procedimentos preliminares do contrato que a pessoa começou
  (art. 7º V). Mensagem de recuperação, quando o Lira decidir ligar, precisa
  ser avaliada à parte (legítimo interesse com opção de sair, art. 7º IX).
- Prazo de guarda (decisão do Lira, 2026-10-24): **3 meses** desde a última
  interação (`updated_at`). Depois disso o lead é apagado, aberto ou
  convertido, pelo `php artisan leads:limpar` (todo dia 03:52, horário de São
  Paulo; `--dry-run` só conta). Convertido também sai porque o que importa da
  venda já está no pedido. Prazo em `config/loja.php`
  (`LOJA_LEAD_CHECKOUT_DIAS_GUARDA`, padrão 90 dias). Falta citar na Política
  de Privacidade pública.

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

## Regras para quem for mexer (Claude Code e Naia)

1. **Pixel do Meta, Google Analytics, Google Ads, TikTok Pixel, Hotjar e afins
   são cookies de terceiros não necessários.** Só podem carregar **depois** do
   "Aceito" (`page.props.cookies.aceito === true`); quem escolheu "Somente o
   básico" nunca os recebe. Têm que entrar na tabela da `/politica-de-cookies`
   e a `VERSAO` tem que mudar. (De 26/10 a 18/11/2026 o Pixel da Meta carregava
   sem OK; acabou com o pop-up de 18/11/2026.)
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
(`/produtos/*`) e da FerraKora (`/produto/*`).

- **Script:** `/js/kzm.js` (o mesmo nos 4 sites, < 3 KB, defer, carregado na loja
  só depois que a página fica ociosa: `resources/js/Shared/medicao.js`). Envio em
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
