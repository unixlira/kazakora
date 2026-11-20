# Editorial KazaKora — backend

## Rotas e autorização

`routes/editorial.php`, carregado pelo grupo web em `routes/web.php`. Leitura pública `/blog` e `/blog/{slug}` apenas para published com published_at <= agora. Admin em `/admin/editorial` exige `auth,admin`, não apenas staff. CRUD posts (inclui DELETE), publish/unpublish e moderação approve/reject; preview admin com header X-Robots-Tag e meta noindex. Edição de publicado volta a draft e invalida reviewer.

DTO e formulários concretos no handoff externo `backend-contract.json`. Componentes Inertia esperados: Blog/Index, Blog/Show, Admin/Editorial/Index, Admin/Editorial/Edit, Admin/Editorial/Moderation. Vue deve interpolar body/título/comentários, NUNCA v-html.

## Limites deliberados

- Sem geração IA, scraper, seed público ou upload. Nenhuma chamada Gemini executada.
- Admin informa mídia própria **previamente armazenada** sob `/storage/editorial/`, com caminho relativo ou HTTPS no host exato de `APP_URL`; MP4/WebM e JPG/JPEG/PNG/WebP. Sem query, fragment, userinfo, porta, traversal ou percent encoding residual. Não baixa URLs nem permite iframe. Esta entrega valida URL/extensão, NÃO inspeciona bytes/codec nem garante que o arquivo exista. Provisionamento seguro dos arquivos é etapa separada.
- Fonte somente URL HTTPS; nenhum fetch no servidor.
- Rights e editorial_checked são atestações humanas obrigatórias na publicação, não licenciamento nem fact-check automático.
- Comentários autenticados: normalização Unicode NFKC quando disponível, remoção de invisíveis, análise ASCII/leet/homoglifos e bloqueio heurístico HTML/links/ofensas comuns. Aceitos sempre pending; humano admin aprova/rejeita com moderator_id/moderated_at. Não é filtro infalível de abuso, falsidade ou links.
- Likes autenticados PUT/DELETE idempotentes, unicidade no banco. CSRF web preservado; comentários 5/min e likes 30/min por identidade autenticada.
- Produtos: vínculos curados ou fallback categoria por slug; naKazaKora/comEstoque/forCard e preço real preco_vitrine.final_price/final_price; DTO explícito sem custo. PDP não modificada.
- Listas limitadas (30 posts públicos, 100 posts/comentários admin, 100 comentários aprovados por post, 12 produtos); paginação e pesquisa administrativa ficam para evolução.
- Exclusão é definitiva e cascata interações; usar despublicar para preservar registro. Retenção LGPD/denúncias e histórico imutável de revisões não implementados.

## Laboratório

Composer local `composer install --no-scripts --prefer-dist --no-interaction`, sem `.env` de produção. SQLite :memory:, fakes HTTP/Mail/Notification/Queue/Storage; sem worker/scheduler ou rede externa.

```sh
APP_ENV=testing vendor/bin/phpunit tests/Feature/Editorial/EditorialTest.php
APP_NAME=KazaKora APP_ENV=testing APP_KEY=base64:eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHg= vendor/bin/phpunit tests/Feature/Catalog/MenorPrecoDaVariacaoTest.php tests/Feature/Catalog/EsgotadoForaDaLojaTest.php tests/Feature/Catalog/CustoForaDaPaginaTest.php tests/Feature/Catalog/SeoDoProdutoTest.php
```

Teste editorial desabilita Vite deliberadamente; regressões antigas precisam assets buildados ou hot marker local de laboratório. Não enviar hot marker para deploy. Sem alteração de variável de ambiente de servidor.

Rollback de código via revert do commit; não executar down em produção, pois remove conteúdo/interações. Sem autorização para push/PR/deploy neste handoff. Homolog permanece bloqueada pela divergência já documentada.
