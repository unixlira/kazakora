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
- Feed: GET `/blog?categoria=slug&tipo=recipe|news|video&page=1`. Filtros vazios significam todos; combinação sem resultados permanece vazia. 16 posts por página, consulta limitada a 17 para `hasMore`, ordenação published_at/id; links anterior/próximo preservam somente filtros editoriais, sem UTM. Canonical permanece `/blog` sem query. Feed em formato de revista (2026-11-22): matéria de capa na 1ª página e cartões com resumo, data, tipo, editoria com nome legível (`category_name`), curtidas e comentários aprovados (`comments_count`) e compartilhar (WhatsApp, copiar link, Web Share quando o aparelho tem); o texto completo e o formulário de comentário ficam na página da matéria. Props extras só de leitura: `categories` no feed (editorias com matéria publicada) e `related` no detalhe (3 matérias mais recentes, sem corpo). O blog usa o mesmo rodapé da loja (`resources/js/Shared/Components/RodapeLoja.vue`). Visitantes não enviam mutações sem canInteract explícito.
- Busca CMS: GET `/admin/editorial/products?q=nome` admin-only, throttle 60/min, q até 150 caracteres; até 50 resultados elegíveis (naKazaKora/comEstoque/forCard), DTO público e hasMore para refinar busca. Edição fornece selectedProducts (id/nome) mesmo fora da busca ou indisponíveis; seleção não é substituída ao buscar/falhar. Vínculos até 30 IDs. Exibição pública contextual permanece limitada a 12 produtos.
- Publicação type=video sem video_url retorna 422; rascunho incompleto, receita e notícia sem vídeo são permitidos. Fontes/direitos continuam sob revisão humana, sem comprovação automática.
- Listas admin limitadas a 100 posts/comentários e leitura do detalhe a 100 comentários aprovados; sem paginação administrativa nesta etapa.
- Exclusão é definitiva e cascata interações; usar despublicar para preservar registro. Retenção LGPD/denúncias e histórico imutável de revisões não implementados.

## Laboratório

Composer local `composer install --no-scripts --prefer-dist --no-interaction`, sem `.env` de produção. SQLite :memory:, fakes HTTP/Mail/Notification/Queue/Storage; sem worker/scheduler ou rede externa.

```sh
APP_ENV=testing vendor/bin/phpunit tests/Feature/Editorial/EditorialTest.php
APP_NAME=KazaKora APP_ENV=testing APP_KEY=base64:eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHg= vendor/bin/phpunit tests/Feature/Catalog/MenorPrecoDaVariacaoTest.php tests/Feature/Catalog/EsgotadoForaDaLojaTest.php tests/Feature/Catalog/CustoForaDaPaginaTest.php tests/Feature/Catalog/SeoDoProdutoTest.php
```

Teste editorial desabilita Vite deliberadamente; regressões antigas precisam assets buildados ou hot marker local de laboratório. Não enviar hot marker para deploy. Sem alteração de variável de ambiente de servidor.

Rollback de código via revert do commit; não executar down em produção, pois remove conteúdo/interações. Sem autorização para push/PR/deploy neste handoff. Homolog permanece bloqueada pela divergência já documentada.
