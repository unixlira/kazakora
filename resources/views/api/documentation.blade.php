<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>API Kazakora — Documentação</title>
<meta name="description" content="Guia de integração da API pública do Kazakora: autenticação, produtos, estoque, canais de marketplace, pedidos e nota fiscal.">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap">
<style>
  :root {
    --ink: #172A3F;
    --ink-soft: #51637A;
    --ink-faint: #7C8CA0;
    --ground: #EFF2F5;
    --surface: #FFFFFF;
    --surface-sunken: #E4E9EE;
    --border: #D6DEE5;
    --accent: #C85A15;
    --accent-bg: #F0721F;
    --accent-soft: #FCEADB;
    --get: #1D5F8A;
    --get-soft: #E1EEF5;
    --post: #C85A15;
    --post-soft: #FCEADB;
    --put: #93650A;
    --put-soft: #F6EBD2;
    --delete: #A03A2B;
    --delete-soft: #F6DEDA;
    --success: #276B49;
    --success-soft: #DCEEE3;
    --danger: #A03A2B;
    --danger-soft: #F6DEDA;
    --code-bg: #16232F;
    --code-ink: #DCE6EF;
    --code-key: #F0AD79;
    --code-str: #9FCB9A;
    --code-num: #E3C878;
    --code-punct: #7C93A8;
    --code-comment: #5C7185;
    --shadow: 0 1px 2px rgba(23,42,63,0.06), 0 8px 24px -12px rgba(23,42,63,0.18);
    --radius: 6px;
  }

  @media (prefers-color-scheme: dark) {
    :root {
      --ink: #E7EEF4;
      --ink-soft: #A9B9C8;
      --ink-faint: #7C8CA0;
      --ground: #0F1A24;
      --surface: #16232F;
      --surface-sunken: #1D2C3A;
      --border: #2B3D4C;
      --accent: #F0864B;
      --accent-bg: #F0721F;
      --accent-soft: rgba(240,114,31,0.16);
      --get: #6BB2DC;
      --get-soft: rgba(107,178,220,0.14);
      --post: #F0864B;
      --post-soft: rgba(240,114,31,0.16);
      --put: #DDB255;
      --put-soft: rgba(221,178,85,0.14);
      --delete: #E38779;
      --delete-soft: rgba(227,135,121,0.14);
      --success: #6FC79A;
      --success-soft: rgba(111,199,154,0.14);
      --danger: #E38779;
      --danger-soft: rgba(227,135,121,0.14);
      --code-bg: #0C161F;
      --code-ink: #DCE6EF;
      --shadow: 0 1px 2px rgba(0,0,0,0.3), 0 8px 28px -12px rgba(0,0,0,0.5);
    }
  }

  * { box-sizing: border-box; }
  body {
    margin: 0;
    background: var(--ground);
    color: var(--ink);
    font-family: "IBM Plex Sans", system-ui, sans-serif;
    font-size: 15.5px;
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
  }
  h1, h2, h3 { text-wrap: balance; color: var(--ink); }
  code, pre, .mono { font-family: "IBM Plex Mono", ui-monospace, "SF Mono", monospace; }

  a { color: var(--accent); }
  a:focus-visible, button:focus-visible, summary:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
  }

  .topbar {
    position: sticky;
    top: 0;
    z-index: 20;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.85rem 1.5rem;
    background: color-mix(in srgb, var(--surface) 92%, transparent);
    backdrop-filter: blur(8px);
    border-bottom: 1px solid var(--border);
  }
  .brand {
    display: flex;
    align-items: baseline;
    gap: 0.6rem;
    font-family: "Fraunces", Georgia, serif;
    font-weight: 700;
    font-size: 1.25rem;
    letter-spacing: -0.01em;
  }
  .brand .mark {
    display: inline-grid;
    place-items: center;
    width: 1.9rem;
    height: 1.9rem;
    border-radius: 5px;
    background: var(--accent-bg);
    color: #fff;
    font-family: "IBM Plex Mono", monospace;
    font-weight: 600;
    font-size: 0.9rem;
  }
  .brand .v {
    font-family: "IBM Plex Mono", monospace;
    font-size: 0.72rem;
    font-weight: 500;
    color: var(--ink-faint);
    letter-spacing: 0.02em;
  }
  .env-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.3rem 0.65rem;
    border-radius: 999px;
    background: var(--put-soft);
    color: var(--put);
    font-size: 0.76rem;
    font-weight: 600;
    letter-spacing: 0.02em;
  }
  .env-pill .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

  .layout {
    display: grid;
    grid-template-columns: 240px minmax(0, 1fr);
    gap: 0;
    max-width: 1180px;
    margin: 0 auto;
  }
  nav.toc {
    position: sticky;
    top: 58px;
    align-self: start;
    height: calc(100vh - 58px);
    overflow-y: auto;
    padding: 2rem 1.25rem 2rem 1.5rem;
  }
  nav.toc .toc-title {
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--ink-faint);
    margin: 0 0 0.6rem;
  }
  nav.toc ul { list-style: none; margin: 0 0 1.4rem; padding: 0; display: flex; flex-direction: column; gap: 0.15rem; }
  nav.toc a {
    display: block;
    padding: 0.32rem 0.6rem;
    border-radius: 5px;
    color: var(--ink-soft);
    text-decoration: none;
    font-size: 0.87rem;
  }
  nav.toc a:hover { background: var(--surface-sunken); color: var(--ink); }

  main { padding: 2.25rem 2rem 6rem; min-width: 0; }

  section { max-width: 720px; margin: 0 0 3.4rem; scroll-margin-top: 72px; }
  section > h2 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 0 0.35rem;
    padding-top: 1.6rem;
    border-top: 1px solid var(--border);
  }
  section:first-of-type > h2 { border-top: none; padding-top: 0; }
  .section-kicker {
    font-family: "IBM Plex Mono", monospace;
    font-size: 0.74rem;
    color: var(--ink-faint);
    letter-spacing: 0.03em;
    margin: 0 0 1.1rem;
  }
  h3 { font-size: 1.05rem; font-weight: 600; margin: 1.7rem 0 0.5rem; }
  p { margin: 0.6rem 0; color: var(--ink-soft); max-width: 65ch; }
  section > p:first-of-type { color: var(--ink); }
  strong { color: var(--ink); font-weight: 600; }

  .hero { max-width: 720px; margin: 0 0 3rem; }
  .hero h1 {
    font-family: "Fraunces", Georgia, serif;
    font-weight: 700;
    font-size: 2.5rem;
    line-height: 1.08;
    letter-spacing: -0.015em;
    margin: 0 0 0.8rem;
  }
  .hero .lede { font-size: 1.05rem; color: var(--ink-soft); max-width: 58ch; }
  .hero-meta { display: flex; flex-wrap: wrap; gap: 0.5rem 1.4rem; margin-top: 1.3rem; font-size: 0.84rem; }
  .hero-meta dt { color: var(--ink-faint); display: inline; font-weight: 500; }
  .hero-meta dd { display: inline; margin: 0 0 0 0.35rem; font-family: "IBM Plex Mono", monospace; }
  .hero-meta .item { display: flex; gap: 0.35rem; align-items: baseline; }

  .notice {
    display: flex;
    gap: 0.7rem;
    padding: 0.85rem 1rem;
    border-radius: var(--radius);
    background: var(--danger-soft);
    color: var(--ink);
    border: 1px solid color-mix(in srgb, var(--danger) 35%, transparent);
    font-size: 0.88rem;
    max-width: 720px;
    margin: 1.4rem 0;
  }
  .notice.warn { background: var(--put-soft); border-color: color-mix(in srgb, var(--put) 35%, transparent); }
  .notice.info { background: var(--get-soft); border-color: color-mix(in srgb, var(--get) 35%, transparent); }
  .notice .icon { flex: none; font-weight: 700; font-family: "IBM Plex Mono", monospace; }
  .notice p { margin: 0; color: var(--ink); }

  .endpoint-index { width: 100%; border-collapse: collapse; font-size: 0.86rem; max-width: 760px; }
  .endpoint-index th {
    text-align: left;
    font-size: 0.7rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--ink-faint);
    font-weight: 600;
    padding: 0 0.6rem 0.5rem 0;
    border-bottom: 1px solid var(--border);
  }
  .endpoint-index td { padding: 0.55rem 0.6rem 0.55rem 0; border-bottom: 1px solid var(--border); vertical-align: baseline; }
  .endpoint-index tr:last-child td { border-bottom: none; }
  .endpoint-index .path { font-family: "IBM Plex Mono", monospace; font-size: 0.85rem; }
  .endpoint-index .desc { color: var(--ink-soft); }

  .m {
    display: inline-block;
    font-family: "IBM Plex Mono", monospace;
    font-weight: 600;
    font-size: 0.72rem;
    letter-spacing: 0.02em;
    padding: 0.15rem 0.45rem;
    border-radius: 4px;
  }
  .m-get { background: var(--get-soft); color: var(--get); }
  .m-post { background: var(--post-soft); color: var(--post); }
  .m-put { background: var(--put-soft); color: var(--put); }
  .m-delete { background: var(--delete-soft); color: var(--delete); }

  .endpoint {
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
    box-shadow: var(--shadow);
    margin: 1.1rem 0 1.6rem;
    overflow: hidden;
  }
  .endpoint-head {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border);
    background: var(--surface-sunken);
    flex-wrap: wrap;
  }
  .endpoint-head .path { font-family: "IBM Plex Mono", monospace; font-size: 0.88rem; }
  .endpoint-head .ability-tag {
    margin-left: auto;
    font-family: "IBM Plex Mono", monospace;
    font-size: 0.72rem;
    color: var(--ink-faint);
    background: var(--ground);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 0.12rem 0.45rem;
  }
  .endpoint-body { padding: 0.9rem 1rem 1.1rem; }
  .endpoint-body p { margin: 0.35rem 0 0.7rem; }

  table.params { width: 100%; border-collapse: collapse; font-size: 0.84rem; margin: 0.5rem 0 0.9rem; }
  table.params th {
    text-align: left;
    font-size: 0.68rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--ink-faint);
    padding: 0 0.5rem 0.4rem 0;
    border-bottom: 1px solid var(--border);
  }
  table.params td { padding: 0.4rem 0.5rem 0.4rem 0; border-bottom: 1px solid var(--border); vertical-align: top; color: var(--ink-soft); }
  table.params tr:last-child td { border-bottom: none; }
  table.params code { font-size: 0.8rem; color: var(--ink); }
  table.params .req { color: var(--danger); font-size: 0.72rem; font-weight: 600; }
  table.params .opt { color: var(--ink-faint); font-size: 0.72rem; }

  pre.code {
    background: var(--code-bg);
    color: var(--code-ink);
    border-radius: 5px;
    padding: 0.9rem 1rem;
    overflow-x: auto;
    font-size: 0.82rem;
    line-height: 1.55;
    margin: 0.4rem 0;
  }
  pre.code .k { color: var(--code-key); }
  pre.code .s { color: var(--code-str); }
  pre.code .n { color: var(--code-num); }
  pre.code .p { color: var(--code-punct); }
  pre.code .c { color: var(--code-comment); font-style: italic; }

  .code-label {
    font-size: 0.68rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--ink-faint);
    font-weight: 600;
    margin: 0.9rem 0 0.3rem;
  }

  .status-chip {
    font-family: "IBM Plex Mono", monospace;
    font-size: 0.74rem;
    font-weight: 600;
    padding: 0.1rem 0.4rem;
    border-radius: 4px;
  }
  .s2 { background: var(--success-soft); color: var(--success); }
  .s4 { background: var(--danger-soft); color: var(--danger); }

  .ability-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    max-width: 640px;
    margin: 0.8rem 0 0.4rem;
  }
  .ability-chip {
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    padding: 0.3rem 0.75rem;
    font-family: "IBM Plex Mono", monospace;
    font-size: 0.8rem;
    color: var(--ink-soft);
  }

  footer {
    max-width: 720px;
    margin: 2rem 0 0;
    padding-top: 1.4rem;
    border-top: 1px solid var(--border);
    font-size: 0.85rem;
    color: var(--ink-faint);
  }

  @media (max-width: 880px) {
    .layout { grid-template-columns: 1fr; }
    nav.toc { position: static; height: auto; border-bottom: 1px solid var(--border); }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="brand">
    <span class="mark">K</span>
    API Kazakora
    <span class="v">v1</span>
  </div>
  <span class="env-pill"><span class="dot"></span> ambiente de homologação</span>
</div>

<div class="layout">
  <nav class="toc" aria-label="Sumário">
    <p class="toc-title">Começando</p>
    <ul>
      <li><a href="#visao-geral">Visão geral</a></li>
      <li><a href="#autenticacao">Autenticação</a></li>
      <li><a href="#convencoes">Convenções</a></li>
    </ul>
    <p class="toc-title">Recursos</p>
    <ul>
      <li><a href="#produtos">Produtos</a></li>
      <li><a href="#estoque">Estoque</a></li>
      <li><a href="#canais">Canais de marketplace</a></li>
      <li><a href="#pedidos">Pedidos</a></li>
      <li><a href="#nota-fiscal">Nota fiscal</a></li>
      <li><a href="#etiqueta">Etiqueta de envio</a></li>
    </ul>
    <p class="toc-title">Referência</p>
    <ul>
      <li><a href="#erros">Erros</a></li>
      <li><a href="#limites">Limites de uso</a></li>
    </ul>
  </nav>

  <main>
    <section class="hero" id="visao-geral">
      <h1>Integrando com a API Kazakora</h1>
      <p class="lede">
        API REST para parceiros externos cadastrarem produtos, ajustarem estoque,
        publicarem anúncios no Mercado Livre/Shopee e acompanharem pedidos e notas
        fiscais — sem acesso ao painel administrativo.
      </p>
      <dl class="hero-meta">
        <div class="item"><dt>Base URL</dt><dd>https://kazakora.devlira.com.br/api/v1</dd></div>
        <div class="item"><dt>Formato</dt><dd>JSON</dd></div>
        <div class="item"><dt>Auth</dt><dd>Bearer token</dd></div>
      </dl>

      <div class="notice warn">
        <span class="icon">!</span>
        <p>
          Esta é a <strong>base de homologação</strong> — ambiente real de testes, não uma sandbox
          sintética. Produtos criados aqui ficam visíveis na loja de verdade
          (<code>kazakora.devlira.com.br</code>), e publicar num canal de marketplace envia o
          anúncio de fato para a conta real conectada no Mercado Livre/Shopee. Ainda não existe
          um endpoint de produção separado — trate escrita nesta API com o mesmo cuidado que
          teria em produção.
        </p>
      </div>
    </section>

    <section id="autenticacao">
      <h2>Autenticação</h2>
      <p class="section-kicker">Bearer token · Laravel Sanctum</p>
      <p>
        Toda chamada autenticada leva o token no cabeçalho <code>Authorization</code>. Não existe
        usuário/senha — o token que você recebeu do time Kazakora já carrega, de forma imutável,
        a lista de permissões (<em>abilities</em>) concedidas à sua integração no momento em que
        foi gerado.
      </p>

      <p class="code-label">Requisição</p>
      <pre class="code"><span class="p">curl</span> https://kazakora.devlira.com.br/api/v1/me \
  -H <span class="s">"Authorization: Bearer SEU_TOKEN_AQUI"</span> \
  -H <span class="s">"Accept: application/json"</span></pre>

      <p class="code-label">Resposta — <span class="status-chip s2">200</span></p>
      <pre class="code">{
  <span class="k">"id"</span><span class="p">:</span> <span class="n">2</span><span class="p">,</span>
  <span class="k">"name"</span><span class="p">:</span> <span class="s">"Nome do seu parceiro"</span><span class="p">,</span>
  <span class="k">"abilities"</span><span class="p">:</span> [<span class="s">"cadastros.view"</span><span class="p">,</span> <span class="s">"cadastros.create"</span><span class="p">,</span> <span class="s">"..."</span>]<span class="p">,</span>
  <span class="k">"rate_limit_per_minute"</span><span class="p">:</span> <span class="n">60</span>
}</pre>
      <p>
        Use <code>GET /me</code> pra confirmar, a qualquer momento, que o token ainda é válido e
        exatamente quais permissões ele carrega — sem precisar de tentativa e erro contra um
        recurso de verdade. Um token inválido, expirado ou revogado responde
        <span class="status-chip s4">401</span>; um token válido chamando algo fora das suas
        abilities responde <span class="status-chip s4">403</span>. Precisa de mais acesso? Peça
        pro time Kazakora gerar um token novo com as abilities adicionais — trocar as permissões
        de um parceiro não afeta tokens já emitidos.
      </p>

      <p class="code-label">Abilities existentes</p>
      <div class="ability-list">
        <span class="ability-chip">cadastros.view</span>
        <span class="ability-chip">cadastros.create</span>
        <span class="ability-chip">cadastros.edit</span>
        <span class="ability-chip">cadastros.delete</span>
        <span class="ability-chip">estoque.adjust</span>
        <span class="ability-chip">pedidos.view</span>
        <span class="ability-chip">pedidos.edit</span>
      </div>
    </section>

    <section id="convencoes">
      <h2>Convenções</h2>
      <h3>Paginação</h3>
      <p>
        Endpoints de listagem aceitam <code>per_page</code> (padrão 25, máximo 100) e devolvem o
        formato padrão do Laravel: <code>data</code> com os itens da página e <code>links</code>/<code>meta</code>
        com a navegação.
      </p>
      <h3>Datas e valores</h3>
      <p>
        Datas em ISO 8601 (<code>2026-08-22T14:30:00-03:00</code>). Valores monetários em reais,
        como número decimal (<code>129.9</code>), nunca centavos nem string formatada.
      </p>
      <h3>Campos opcionais ausentes</h3>
      <p>
        Um campo não enviado no JSON é tratado como "não informado" — para atualizar um produto
        sem mudar o preço, por exemplo, basta omitir <code>price</code> do corpo (não mande
        <code>null</code>, que é tratado como "limpar o campo").
      </p>
    </section>

    <section id="produtos">
      <h2>Produtos</h2>
      <p class="section-kicker">cadastros.view · cadastros.create · cadastros.edit · cadastros.delete</p>
      <table class="endpoint-index">
        <thead><tr><th>Método</th><th>Rota</th><th>Descrição</th></tr></thead>
        <tbody>
          <tr><td><span class="m m-get">GET</span></td><td class="path">/produtos</td><td class="desc">Lista produtos (busca, categoria, ativo)</td></tr>
          <tr><td><span class="m m-get">GET</span></td><td class="path">/produtos/{id}</td><td class="desc">Detalhe de um produto</td></tr>
          <tr><td><span class="m m-post">POST</span></td><td class="path">/produtos</td><td class="desc">Cria um produto (SKU gerado automaticamente)</td></tr>
          <tr><td><span class="m m-put">PUT</span></td><td class="path">/produtos/{id}</td><td class="desc">Atualiza um produto</td></tr>
          <tr><td><span class="m m-delete">DELETE</span></td><td class="path">/produtos/{id}</td><td class="desc">Remove um produto</td></tr>
        </tbody>
      </table>

      <div class="endpoint">
        <div class="endpoint-head">
          <span class="m m-post">POST</span>
          <span class="path">/produtos</span>
          <span class="ability-tag">cadastros.create</span>
        </div>
        <div class="endpoint-body">
          <p>Cria um produto. O <code>sku</code> é gerado pelo sistema a partir de categoria/nome/marca — não envie um.</p>
          <table class="params">
            <thead><tr><th>Campo</th><th></th><th>Tipo</th><th>Notas</th></tr></thead>
            <tbody>
              <tr><td><code>name</code></td><td class="req">obrigatório</td><td>string</td><td>até 255 caracteres</td></tr>
              <tr><td><code>price</code></td><td class="req">obrigatório</td><td>decimal</td><td>preço cheio, sem desconto</td></tr>
              <tr><td><code>category_id</code></td><td class="opt">opcional</td><td>int</td><td>id de uma categoria existente</td></tr>
              <tr><td><code>description</code>, <code>brand</code>, <code>model</code>, <code>color</code>, <code>variation</code></td><td class="opt">opcional</td><td>string</td><td>—</td></tr>
              <tr><td><code>stock</code></td><td class="opt">opcional</td><td>int</td><td>padrão 0 — ajustes depois só via <a href="#estoque">/estoque/ajustar</a></td></tr>
              <tr><td><code>cost_price</code></td><td class="opt">opcional</td><td>decimal</td><td>custo interno</td></tr>
              <tr><td><code>discount_percentage</code> / <code>discount_amount</code></td><td class="opt">opcional</td><td>decimal</td><td>mutuamente exclusivos</td></tr>
              <tr><td><code>is_active</code>, <code>is_featured</code>, <code>is_new_release</code></td><td class="opt">opcional</td><td>bool</td><td>padrão <code>true</code>/<code>false</code>/<code>false</code></td></tr>
            </tbody>
          </table>

          <p class="code-label">Requisição</p>
          <pre class="code"><span class="p">curl -X POST</span> https://kazakora.devlira.com.br/api/v1/produtos \
  -H <span class="s">"Authorization: Bearer SEU_TOKEN_AQUI"</span> \
  -H <span class="s">"Content-Type: application/json"</span> \
  -d <span class="s">'{
    "name": "Lixeira Inox com Pedal 12L",
    "category_id": 4,
    "price": 89.90,
    "stock": 40
  }'</span></pre>

          <p class="code-label">Resposta — <span class="status-chip s2">201</span></p>
          <pre class="code">{
  <span class="k">"data"</span><span class="p">:</span> {
    <span class="k">"id"</span><span class="p">:</span> <span class="n">341</span><span class="p">,</span>
    <span class="k">"sku"</span><span class="p">:</span> <span class="s">"LIX-INX-0001"</span><span class="p">,</span>
    <span class="k">"name"</span><span class="p">:</span> <span class="s">"Lixeira Inox com Pedal 12L"</span><span class="p">,</span>
    <span class="k">"slug"</span><span class="p">:</span> <span class="s">"lixeira-inox-com-pedal-12l"</span><span class="p">,</span>
    <span class="k">"price"</span><span class="p">:</span> <span class="n">89.9</span><span class="p">,</span>
    <span class="k">"final_price"</span><span class="p">:</span> <span class="n">89.9</span><span class="p">,</span>
    <span class="k">"stock"</span><span class="p">:</span> <span class="n">40</span><span class="p">,</span>
    <span class="k">"is_active"</span><span class="p">:</span> <span class="k">true</span><span class="p">,</span>
    <span class="k">"images"</span><span class="p">:</span> []<span class="p">,</span>
    <span class="c">// ... demais campos, ver GET /produtos/{id}</span>
  }
}</pre>
        </div>
      </div>
    </section>

    <section id="estoque">
      <h2>Estoque</h2>
      <p class="section-kicker">estoque.adjust</p>
      <div class="endpoint">
        <div class="endpoint-head">
          <span class="m m-post">POST</span>
          <span class="path">/produtos/{id}/estoque/ajustar</span>
          <span class="ability-tag">estoque.adjust</span>
        </div>
        <div class="endpoint-body">
          <p>
            Ajuste <strong>relativo</strong>, nunca um valor absoluto — evita que dois sistemas
            escrevendo estoque ao mesmo tempo se sobrescrevam. Use um número negativo para dar baixa.
          </p>
          <table class="params">
            <thead><tr><th>Campo</th><th></th><th>Tipo</th><th>Notas</th></tr></thead>
            <tbody>
              <tr><td><code>adjustment</code></td><td class="req">obrigatório</td><td>int</td><td>diferente de zero; negativo dá baixa</td></tr>
              <tr><td><code>reason</code></td><td class="opt">opcional</td><td>string</td><td>fica registrado no histórico de movimentação</td></tr>
            </tbody>
          </table>
          <pre class="code"><span class="p">curl -X POST</span> .../produtos/341/estoque/ajustar \
  -H <span class="s">"Authorization: Bearer SEU_TOKEN_AQUI"</span> \
  -d <span class="s">'{"adjustment": -3, "reason": "venda"}'</span></pre>
        </div>
      </div>
    </section>

    <section id="canais">
      <h2>Canais de marketplace</h2>
      <p class="section-kicker">cadastros.edit (canal usa a mesma permissão do produto)</p>
      <p>
        Publica, sincroniza ou encerra o anúncio de um produto no Mercado Livre ou na Shopee.
        Roda exatamente a mesma lógica do painel administrativo — mesma validação, mesma
        descoberta automática de categoria do Mercado Livre.
      </p>

      <div class="notice">
        <span class="icon">!</span>
        <p>
          <code>PUT .../canais/{channel}</code> com <code>is_enabled: true</code> publica de verdade
          na conta real conectada (não existe conta de teste separada hoje). Não chame em produto
          nenhum sem intenção real de anunciá-lo.
        </p>
      </div>

      <table class="endpoint-index">
        <thead><tr><th>Método</th><th>Rota</th><th>Descrição</th></tr></thead>
        <tbody>
          <tr><td><span class="m m-get">GET</span></td><td class="path">/produtos/{id}/canais</td><td class="desc">Status do produto em cada canal</td></tr>
          <tr><td><span class="m m-put">PUT</span></td><td class="path">/produtos/{id}/canais/{channel}</td><td class="desc">Habilita e publica (ou desabilita)</td></tr>
          <tr><td><span class="m m-post">POST</span></td><td class="path">/produtos/{id}/canais/{channel}/sincronizar</td><td class="desc">Reenvia preço/estoque atuais</td></tr>
          <tr><td><span class="m m-delete">DELETE</span></td><td class="path">/produtos/{id}/canais/{channel}</td><td class="desc">Encerra o anúncio na plataforma</td></tr>
        </tbody>
      </table>
      <p><code>{channel}</code> é <code>mercado_livre</code> ou <code>shopee</code>.</p>

      <div class="endpoint">
        <div class="endpoint-head">
          <span class="m m-put">PUT</span>
          <span class="path">/produtos/{id}/canais/{channel}</span>
          <span class="ability-tag">cadastros.edit</span>
        </div>
        <div class="endpoint-body">
          <table class="params">
            <thead><tr><th>Campo</th><th></th><th>Tipo</th><th>Notas</th></tr></thead>
            <tbody>
              <tr><td><code>is_enabled</code></td><td class="req">obrigatório</td><td>bool</td><td><code>true</code> dispara a publicação</td></tr>
              <tr><td><code>attributes</code></td><td class="opt">opcional</td><td>objeto</td><td>ex.: <code>category_id</code> do Mercado Livre — se omitido, o sistema tenta descobrir sozinho pelo nome do produto</td></tr>
            </tbody>
          </table>

          <pre class="code"><span class="p">curl -X PUT</span> .../produtos/341/canais/mercado_livre \
  -H <span class="s">"Authorization: Bearer SEU_TOKEN_AQUI"</span> \
  -d <span class="s">'{"is_enabled": true}'</span></pre>

          <p class="code-label">Resposta — <span class="status-chip s2">200</span></p>
          <pre class="code">{
  <span class="k">"channel"</span><span class="p">:</span> <span class="s">"mercado_livre"</span><span class="p">,</span>
  <span class="k">"is_enabled"</span><span class="p">:</span> <span class="k">true</span><span class="p">,</span>
  <span class="k">"status"</span><span class="p">:</span> <span class="s">"pending"</span><span class="p">,</span>
  <span class="k">"external_id"</span><span class="p">:</span> <span class="k">null</span><span class="p">,</span>
  <span class="k">"last_error"</span><span class="p">:</span> <span class="k">null</span>
}</pre>
          <p>
            <code>status</code> começa em <code>pending</code> e vira <code>published</code> (com
            <code>external_id</code> preenchido) ou <code>error</code> (com <code>last_error</code>
            explicando o motivo) assim que o marketplace responder — consulte de novo via
            <code>GET /produtos/{id}/canais</code> alguns segundos depois.
          </p>
          <p>
            Se o Mercado Livre não conseguir identificar a categoria automaticamente, a resposta
            vem <span class="status-chip s4">422</span> pedindo pra informar
            <code>attributes.category_id</code> manualmente e tentar de novo.
          </p>
        </div>
      </div>
    </section>

    <section id="pedidos">
      <h2>Pedidos</h2>
      <p class="section-kicker">pedidos.view · pedidos.edit</p>
      <table class="endpoint-index">
        <thead><tr><th>Método</th><th>Rota</th><th>Descrição</th></tr></thead>
        <tbody>
          <tr><td><span class="m m-get">GET</span></td><td class="path">/pedidos</td><td class="desc">Lista pedidos (status, origem, id externo)</td></tr>
          <tr><td><span class="m m-get">GET</span></td><td class="path">/pedidos/{id}</td><td class="desc">Detalhe (itens, nota, envio)</td></tr>
          <tr><td><span class="m m-put">PUT</span></td><td class="path">/pedidos/{id}/status</td><td class="desc">Atualiza status</td></tr>
        </tbody>
      </table>
      <p>
        <code>status</code> aceita apenas <code>paid</code>, <code>shipped</code> ou
        <code>completed</code> — cancelamento (com estorno e reversão de estoque) continua
        sendo feito só pelo painel administrativo, de propósito, por envolver dinheiro real.
      </p>
    </section>

    <section id="nota-fiscal">
      <h2>Nota fiscal</h2>
      <p class="section-kicker">pedidos.view · pedidos.edit</p>
      <table class="endpoint-index">
        <thead><tr><th>Método</th><th>Rota</th><th>Descrição</th></tr></thead>
        <tbody>
          <tr><td><span class="m m-get">GET</span></td><td class="path">/pedidos/{id}/nota</td><td class="desc">Status da NF-e do pedido</td></tr>
          <tr><td><span class="m m-post">POST</span></td><td class="path">/pedidos/{id}/nota</td><td class="desc">Agenda emissão (assíncrona)</td></tr>
          <tr><td><span class="m m-get">GET</span></td><td class="path">/pedidos/{id}/nota/danfe/url</td><td class="desc">Link assinado (30 min) pro PDF do DANFE</td></tr>
        </tbody>
      </table>
      <p>
        <code>POST</code> responde <span class="status-chip s2">202</span> imediatamente — emissão
        roda em fila (comunicação com a SEFAZ pode demorar). Consulte <code>GET .../nota</code>
        depois pra ver se autorizou.
      </p>
    </section>

    <section id="etiqueta">
      <h2>Etiqueta de envio</h2>
      <p class="section-kicker">pedidos.view</p>
      <p>
        O link da etiqueta (<code>shipment.label_url</code>, dentro do detalhe do pedido) é uma
        URL assinada temporária, válida por 30 minutos — não exige o token da API pra baixar,
        de propósito: pode ser repassado direto pra transportadora ou sistema de impressão sem
        entregar a credencial completa.
      </p>
    </section>

    <section id="erros">
      <h2>Erros</h2>
      <p>Formato padrão em toda resposta de erro:</p>
      <pre class="code">{
  <span class="k">"message"</span><span class="p">:</span> <span class="s">"The given data was invalid."</span><span class="p">,</span>
  <span class="k">"errors"</span><span class="p">:</span> {
    <span class="k">"price"</span><span class="p">:</span> [<span class="s">"O campo price é obrigatório."</span>]
  }
}</pre>
      <table class="endpoint-index">
        <thead><tr><th>Status</th><th>Significado</th></tr></thead>
        <tbody>
          <tr><td><span class="status-chip s4">401</span></td><td class="desc">Token ausente, inválido ou revogado</td></tr>
          <tr><td><span class="status-chip s4">403</span></td><td class="desc">Token válido, mas sem a ability exigida por essa rota</td></tr>
          <tr><td><span class="status-chip s4">404</span></td><td class="desc">Recurso não encontrado</td></tr>
          <tr><td><span class="status-chip s4">422</span></td><td class="desc">Validação falhou, ou operação não permitida no estado atual (ex.: emitir nota de pedido não pago)</td></tr>
          <tr><td><span class="status-chip s4">429</span></td><td class="desc">Limite de requisições excedido</td></tr>
        </tbody>
      </table>
    </section>

    <section id="limites">
      <h2>Limites de uso</h2>
      <p>
        O limite padrão é 60 requisições por minuto por parceiro (o time Kazakora pode ajustar
        caso sua integração precise de mais). Toda chamada — sucesso ou erro — fica registrada
        numa trilha de auditoria interna, então picos de erro (ex.: <span class="status-chip s4">422</span>
        em massa) são visíveis do nosso lado sem precisar reportar manualmente.
      </p>
    </section>

    <footer>
      Dúvidas ou necessidade de mais permissões: fale com o time Kazakora.
    </footer>
  </main>
</div>
</body>
</html>
