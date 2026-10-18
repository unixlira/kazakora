# Contrato da API do app KazaKora (`/api/app`)

Versão 1 (18/10/2026). Este arquivo é o combinado entre a API (KazaKora, `routes/api.php` prefixo `app`, código em `app/Modules/App`) e os apps nativos (`unixlira/kazakora-ios` em Swift, `unixlira/kazakora-android` em Kotlin). Quem mudar um campo muda aqui primeiro. Testes: `tests/Feature/App/AppApiTest.php`.

## Geral

- Base: `{API_URL}/api/app`. `API_URL` = `https://kazakora.devlira.com.br` (desenvolvimento e preview) ou `https://kazakora.com` (só no perfil `production` do EAS). Nunca kazakora.com.br.
- JSON em UTF-8, nomes de campo em português, `snake_case`.
- **Dinheiro sempre em centavos (inteiro)**, como no checkout dos outros sites (`CheckoutOutroSite`). Ex.: R$ 49,90 = `4990`.
- Datas em ISO 8601 com fuso de São Paulo (`2026-10-18T02:30:00-03:00`).
- Autenticação: `Authorization: Bearer <token>` (Sanctum, tabela `users` da KazaKora). Rotas marcadas 🔒 exigem token; sem token ou token inválido → `401 {"message": "..."}`.
- Cabeçalhos que o app sempre manda: `Accept: application/json`, `X-App-Versao: 1.0.0`, `X-App-Plataforma: ios|android|web`.
- Erros:
  - `422 {"message": "...", "errors": {"campo": ["mensagem"]}}` validação (mensagens prontas para mostrar).
  - `409 {"message": "...", "estoque": true}` estoque mudou; `409 {"message": "...", "resumo": {...}}` preço/total mudou (o app mostra o novo resumo e pede para confirmar).
  - `429` limite de tentativas; `503` pagamento indisponível.
- Variação é identificada por `variante` = `"kzv-<id do produto/variação>"` (mesmo formato do checkout dos outros sites). Produto sem variação tem uma única variante.

## Objetos

### ProdutoCard
```json
{
  "id": 123,
  "slug": "cozinha-infantil-pia",
  "nome": "Cozinha Infantil com Pia",
  "imagem": "https://.../foto.webp",
  "preco": 12990,
  "preco_de": 15990,
  "preco_pix": 12340,
  "desconto_percentual": 18,
  "relampago": { "termina_em": "2026-10-18T23:59:59-03:00" },
  "disponivel": true,
  "link": "https://kazakora.com/produto/cozinha-infantil-pia"
}
```
`preco_de`, `relampago` podem ser `null`. `preco` é o mesmo que o site mostra (mesma regra de preço, Relâmpago e menor preço da variação).

### Produto (detalhe)
ProdutoCard + 
```json
{
  "imagens": ["https://.../1.webp", "https://.../2.webp"],
  "descricao_html": "<p>...</p>",
  "departamento": { "id": 4, "nome": "Brinquedos", "slug": "brinquedos" },
  "opcoes": [ { "nome": "Cor", "valores": ["Azul", "Rosa"] } ],
  "variantes": [
    {
      "variante": "kzv-124",
      "nome": "Azul",
      "atributos": { "Cor": "Azul" },
      "preco": 12990,
      "preco_de": 15990,
      "preco_pix": 12340,
      "estoque": 7,
      "imagem": "https://.../azul.webp"
    }
  ],
  "avaliacoes": {
    "media": 4.8,
    "total": 37,
    "itens": [{ "id": 1, "nome": "Maria", "nota": 5, "comentario": "...", "resposta_loja": null, "imagens": ["https://..."], "criado_em": "..." }]
  }
}
```
`avaliacoes.itens` traz até 20 (mesma ordem da página do produto: mais estrelas, com foto antes, mais recentes); `media` e `total` contam todas, variações incluídas.

### Endereco
```json
{ "id": 9, "apelido": "Casa", "destinatario": "Maria Silva", "telefone": "11999999999", "cep": "01310100", "rua": "Av. Paulista", "numero": "1000", "complemento": "ap 12", "bairro": "Bela Vista", "cidade": "São Paulo", "uf": "SP", "principal": true }
```

### Resumo (checkout)
Igual ao `CheckoutOutroSite::resumo()`:
```json
{
  "itens": [{ "variante": "kzv-124", "nome": "...", "imagem": "...", "quantidade": 1, "estoque": 7, "preco_unitario": 12990, "subtotal": 12990 }],
  "subtotal": 12990,
  "desconto_plataforma": 0,
  "cupom": { "codigo": "BEMVINDO", "descricao": "10% de desconto", "desconto": 1299 },
  "cupom_erro": null,
  "desconto_cupom": 1299,
  "desconto_pix": 584,
  "desconto_pix_percentual": 5,
  "frete": 0,
  "prazo_dias": 6,
  "entrega": {
    "opcoes": [{ "id": "correios", "transportadora": "Correios", "servico": "PAC", "preco": 0, "prazo": 6 }],
    "escolhida": "correios"
  },
  "total": 11691,
  "total_pix": 11107,
  "mercadopago_public_key": "APP_USR-..."
}
```

### PedidoResumo / Pedido
```json
{
  "id": 3206,
  "numero": "KZ-000123",
  "criado_em": "2026-10-18T02:30:00-03:00",
  "status": "aguardando_pagamento",
  "status_texto": "Aguardando pagamento",
  "pago": false,
  "total": 11107,
  "metodo": "pix",
  "itens": [{ "nome": "...", "imagem": "...", "quantidade": 1, "preco_unitario": 12990 }],
  "rastreio": {
    "codigo": "AN123456789BR",
    "transportadora": "Correios",
    "link": "https://...",
    "eventos": [{ "data": "2026-10-19T10:00:00-03:00", "descricao": "Objeto postado", "local": "São Paulo/SP" }]
  },
  "pix": { "copia_e_cola": "000201...", "qr_code_base64": "iVBOR...", "expira_em": "..." }
}
```
`status` usa os valores do `Order` da KazaKora em minúsculas (ex.: `aguardando_pagamento`, `pago`, `em_separacao`, `enviado`, `entregue`, `cancelado`); `status_texto` é o rótulo que o site usa. `rastreio` e `pix` podem ser `null` (`pix` só enquanto aguarda pagamento no Pix). Detalhe inclui `endereco`, `frete`, `desconto`, `desconto_pix`, `subtotal`, `etapas` (as do rastreio do site: `[{titulo, feito, data?}]`), `pode_cancelar`, `cancelar_bloqueio` (texto quando não pode), `tem_nota` e `boleto` (ou null).

## Rotas públicas

| Método | Rota | Entrada | Saída |
|---|---|---|---|
| GET | `/config` | | `{loja:{nome, whatsapp_numero, whatsapp_link, politica_url, termos_url, site_url}, mercadopago_public_key, desconto_pix_percentual, versao_minima:{ios, android}}` |
| GET | `/inicio` | | `{banners:[{id, imagem, imagem_celular, titulo, link, produto_slug?, departamento_slug?}], vitrines:[{titulo, slug, produtos:[ProdutoCard]}]}` (mesmos banners e vitrines da home do site) |
| GET | `/departamentos` | | `{departamentos:[{id, nome, slug, imagem, filhos:[...]}]}` |
| GET | `/produtos` | `busca?`, `departamento?` (slug), `ordem?` = `relevancia|menor_preco|maior_preco|novidades|mais_vendidos`, `pagina?` (1), `por_pagina?` (20, máx. 48) | `{produtos:[ProdutoCard], pagina, por_pagina, total, tem_mais}` |
| GET | `/produtos/{slug}` | | `{produto: Produto}` ou 404 |
| GET | `/cep/{cep}` | | `{cep, rua, bairro, cidade, uf}` |
| POST | `/frete` | `{cep, itens:[{variante, quantidade}]}` | `{opcoes:[...], escolhida, prazo_dias}` (mesmo cálculo do checkout) |
| POST | `/checkout/resumo` | `{itens:[{variante, quantidade}], cep?, frete?, cupom?}` | Resumo (cupom inválido volta em `cupom_erro`, sem 422) |

## Conta

| Método | Rota | Entrada | Saída |
|---|---|---|---|
| POST | `/conta/cadastro` | `{nome, email, cpf, telefone, senha, aceite_termos: true, dispositivo}` | `201 {token, usuario}` |
| POST | `/conta/login` | `{email, senha, dispositivo}` | `{token, usuario}`; senha errada → 422 em `email` |
| POST | `/conta/esqueci-senha` | `{email}` | `{message}` sempre igual (não revela se o e-mail existe); usa o e-mail de redefinição do site |
| 🔒 GET | `/conta/eu` | | `{usuario:{id, nome, email, cpf_mascarado, telefone}}` |
| 🔒 PATCH | `/conta/eu` | `{nome?, telefone?}` | `{usuario}` |
| 🔒 PUT | `/conta/senha` | `{senha_atual, senha}` | `{message}`; os outros aparelhos saem (tokens apagados, menos o atual) |
| 🔒 POST | `/conta/sair` | | `204` (apaga o token atual) |
| 🔒 DELETE | `/conta` | `{senha, motivo?}` | `204`. Apaga a conta e todos os tokens; dados pessoais anonimizados; pedidos e notas ficam pela obrigação fiscal, sem ligação com a pessoa. Exigência da Apple. |
| 🔒 GET | `/conta/enderecos` | | `{enderecos:[Endereco]}` |
| 🔒 POST | `/conta/enderecos` | Endereco sem `id` | `201 {endereco}` |
| 🔒 PUT | `/conta/enderecos/{id}` | Endereco | `{endereco}` |
| 🔒 DELETE | `/conta/enderecos/{id}` | | `204` (mesma regra do site para endereço já usado em pedido) |
| 🔒 POST | `/conta/push-token` | `{token: "ExponentPushToken[...]", plataforma}` | `204` (só guarda; nenhum disparo nesta versão) |

| 🔒 GET | `/favoritos` | | `{ids:[int], produtos:[ProdutoCard]}` (mesma tabela do coração do site) |
| 🔒 POST | `/favoritos/{produto_id}` | | `{ids}` (variação vira o anúncio pai) |
| 🔒 DELETE | `/favoritos/{produto_id}` | | `{ids}` |

`usuario` = `{id, nome, email, cpf_mascarado, telefone}`. Conta criada no site sem CPF: `PATCH /conta/eu` aceita `cpf` uma vez (sem CPF válido o pedido volta 422 em `cpf`). `dispositivo` = nome curto do aparelho (vai no nome do token Sanctum).

## Pedido e pagamento (🔒)

| Método | Rota | Entrada | Saída |
|---|---|---|---|
| POST | `/checkout/pedido` | ver abaixo | `201 {pedido: Pedido, pix?}` |
| GET | `/pedidos` | `pagina?` | `{pedidos:[PedidoResumo], tem_mais}` (só do usuário) |
| GET | `/pedidos/{id}` | | `{pedido: Pedido}` com rastreio; 404 se não for do usuário |
| POST | `/pedidos/{id}/cancelar` | `{motivo}` | `{message, pedido}`; mesma regra do botão de Meus pedidos (só venda do site, antes de sair para entrega). Bloqueado → 422 com a mensagem. Estorno, NF-e e e-mail rodam na fila. |
| GET | `/pedidos/{id}/nota` | | PDF da DANFE (`application/pdf`) quando `tem_nota`; senão 404 |
| GET | `/pedidos/{id}/status` | | `{status, status_texto, pago}`; enquanto aguarda pagamento, consulta o Mercado Pago (o app chama a cada 5 s na tela do Pix) |

Entrada de `/checkout/pedido`:
```json
{
  "itens": [{ "variante": "kzv-124", "quantidade": 1 }],
  "endereco_id": 9,
  "frete": "correios",
  "cupom": "BEMVINDO",
  "total_esperado": 11107,
  "pagamento": {
    "metodo": "pix",
    "mp_card_token": null,
    "mp_card_installments": null,
    "mp_payment_method_id": null,
    "mp_issuer_id": null
  }
}
```
- Pedido nasce na KazaKora como venda do site (origin `loja`), ligado ao `user` do token, com a mesma regra de preço, frete, cupom, estoque, Relâmpago, desconto Pix e Mercado Pago do checkout do site. Nome, e-mail, CPF e telefone vêm da conta.
- Cartão: o app tokeniza o cartão no aparelho com a chave pública do Mercado Pago (`mercadopago_public_key`) e manda só `mp_card_token`. Número do cartão nunca chega ao servidor da KazaKora.
- `total_esperado` = `total_pix` se Pix, `total` se cartão (com parcelas sem juros como no site). Diferença → 409 com o resumo novo.
- Resposta de cartão aprovado: `pedido.pago = true`. Recusado: 422 com a mensagem do site.
- Pedido do app = venda do site: `origin = loja`, `site_origem` nulo, `orders.app_plataforma` = `ios`/`android` (só marcação; margem, painel, KoraSync e e-mail das 23h50 não mudam).

## Limites
Por IP: catálogo 120/min, CEP 30/min, frete e resumo 60/min, cadastro e login 10/min, esqueci a senha 5/min. Logado: 120/min; pedido 10/min; cancelar 10/min; excluir conta 5/min.
