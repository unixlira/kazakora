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

### Aceite dos cookies (desde 24/10/2026: no checkout, sem aviso flutuante)
- Pedido do Lira em 24/10/2026: saiu o aviso flutuante (`AvisoCookies.vue`,
  botão OK). O aceite agora é a frase abaixo do **Finalizar pedido**
  (`CheckoutV2.vue`; no checkout antigo, a caixinha do `Payment.vue`): "Ao
  clicar em Finalizar pedido, você declara que leu e aceita os termos de uso,
  a política de privacidade e o uso de cookies, conforme a LGPD".
- Ao finalizar (`CheckoutController`, antes de criar o pedido),
  `Cookies::registrarAceite()` grava a prova e cria os cookies, igual ao OK
  antigo. Beauty Kora e FerraKora: a API de pedido
  (`CheckoutOutroSiteController::pedido`) grava só a prova, com `origem` do site.
- **Efeito:** quem só navega e não compra fica só com os cookies necessários.
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
| `kazakora_cupom_1a` | Necessário | lembra que o pop-up do cupom de 1ª compra do checkout já apareceu, para não mostrar de novo (2026-10-23). Gravado pelo navegador (JS), sem dado pessoal: só o valor `1` | 12 meses | art. 7º V |
| `kazakora_visitor` | Navegação | liga as páginas abertas pelo mesmo navegador (histórico de navegação e número de páginas no olho de Visitas ao site). Desde 25/10/2026 criado no **primeiro acesso** (`Cookies::garantirVisitante`, no `TrackSiteVisit`), antes do aceite; o mesmo código fica na sessão (`kazakora_visitante`) para quem recusa cookie | 12 meses | art. 7º IX (legítimo interesse) |
| `_fbp`, `_fbc` | Publicidade (Meta) | Pixel da Meta (pixel_kazakora, 2186107885537817): PageView, ViewContent, AddToCart, InitiateCheckout, Purchase. Só carrega depois do OK (`resources/js/Shared/pixelMeta.js`); ID do admin (/admin/pixel-meta) ou `META_ADS_PIXEL_ID`. Também lidos pelo servidor para a API de Conversões (abaixo) | até 90 dias | art. 7º I (consentimento) |

Todos são do próprio site, criptografados pelo Laravel, `HttpOnly` e
`SameSite=Lax`. A sessão só trafega em https quando o `APP_URL` é https
(`config/session.php`). Login expira em 8 h (`ExpireStaleSession`).

### API de Conversões da Meta (pedido 2026-10-18)
O servidor manda à Meta os mesmos **AddToCart** e **Purchase** do Pixel, com o
mesmo `event_id` (a Meta desduplica). Código: `App\Services\Meta\ConversoesMeta`,
jobs `EnviarEventoMetaJob` (AddToCart) e `EnviarPurchaseMetaJob` (pagamento aprovado).

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

### O que é registrado das visitas (`site_visits`, middleware `TrackSiteVisit`)

| Campo | Sem OK | Com OK |
|---|---|---|
| página, data/hora | sim | sim |
| IP | sim (Marco Civil) | sim |
| visitante | `kazakora_visitor` (desde 25/10/2026; antes, código tirado da sessão `s-...`) | `kazakora_visitor` |
| navegador completo | **não** | sim |
| aparelho (celular/tablet/computador) | sim | sim |
| origem (site, sem `?...`) e UTM | sim | sim |
| origem classificada (`origem`: instagram, google_ads, direto...) | sim | sim |
| parâmetro de clique (`click_id_param`: fbclid, gclid, ttclid...) | sim | sim |
| valor do clique (`click_id`) | **não** | sim |
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
   são cookies de terceiros não necessários.** Só podem carregar **depois** do OK
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
