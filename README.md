# Kazakora

E-commerce escalável construído com Laravel 13 + Vue 3 + Inertia.js.

## Stack

- **Backend**: PHP 8.3-FPM, Laravel 13
- **Frontend**: Vue 3 + Inertia.js + Vite, Tailwind CSS
- **Banco de dados**: MySQL 8.0
- **Cache / sessões / filas**: Redis 7
- **Servidor web**: Nginx
- **E-mail (dev)**: Mailhog

## Estrutura modular

O código de domínio fica em `app/Modules/`, um diretório por área de negócio:

- `Catalog` — produtos e categorias
- `Cart` — carrinho de compras (baseado em sessão/Redis)
- `Checkout` — endereço de entrega, pedidos e confirmação por e-mail
- `Admin` — dashboard, gestão de produtos/categorias e pedidos (protegido por role)
- `Auth` — login, cadastro e recuperação de senha

O espelho no frontend fica em `resources/js/Modules/`, seguindo a mesma divisão.

## Subindo o ambiente

```bash
docker compose up -d
docker compose exec app php artisan migrate --seed
```

- App: http://localhost:8080
- Mailhog (caixa de e-mail de teste): http://localhost:8025
- Vite (dev server): http://localhost:5173

O seeder cria um usuário admin (`admin@kazakora.com` / `admin123`) e popula o catálogo com produtos de exemplo.

## Serviços do docker-compose

| Serviço  | Descrição                          | Porta |
|----------|-------------------------------------|-------|
| `app`    | PHP-FPM 8.3                         | —     |
| `nginx`  | Servidor web                        | 8080  |
| `mysql`  | Banco de dados                      | 3306  |
| `redis`  | Cache, sessões e filas              | 6379  |
| `node`   | Vite dev server (HMR)               | 5173  |
| `queue`  | Worker de filas (`queue:work redis`) | —     |
| `mailhog`| Captura de e-mails em dev            | 8025  |
