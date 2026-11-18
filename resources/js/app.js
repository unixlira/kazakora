import '../css/app.css';
import { instalarJornada } from '@/Shared/jornadaCarrinho.mjs';
import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { rastrearPagina } from '@/Shared/pixelMeta';
import { liberarImagensDepoisDaPrimeiraPintura } from '@/Shared/imagensDepoisDoTopo';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

const appName = import.meta.env.VITE_APP_NAME || 'KazaKora';

createInertiaApp({
    // SEO (tarefa 4037, 10/11): o nome da página vem antes da marca, como o Google prefere.
    // Título que já cita a loja ("KazaKora — eletrônicos...") fica como está.
    title: (title) => (!title ? appName : title.includes(appName) ? title : `${title} | ${appName}`),
    resolve: (name) =>
        resolvePageComponent(
            `./Modules/${name}.vue`,
            import.meta.glob('./Modules/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
        liberarImagensDepoisDaPrimeiraPintura();
        instalarJornada(router, props.initialPage);

        // Pixel da Meta (Shared/pixelMeta.js): desde 2026-11-18 só roda com o
        // "Aceito" do pop-up de cookies (ou aceite antigo / Finalizar pedido).
        // `navigate` cobre a primeira página e cada troca de página; o "Aceito"
        // chega por recarga parcial de `cookies` e dispara a página atual na hora.
        let pixelOk = false;
        router.on('navigate', (e) => {
            pixelOk = !!e.detail.page.props.cookies?.aceito;
            rastrearPagina(e.detail.page);
        });
        router.on('success', (e) => {
            const aceito = !!e.detail.page.props.cookies?.aceito;
            if (aceito && !pixelOk) rastrearPagina(e.detail.page, { imediato: true });
            pixelOk = aceito;
        });

        // Tela de abertura (resources/views/app.blade.php): some quando a loja montou
        // e o CSS da loja (carregado sem travar a página) já chegou.
        const abertura = document.getElementById('tela-abertura');
        if (abertura) {
            const cssPendente = [...document.querySelectorAll('link[data-adiado]')]
                .filter((link) => !link.sheet)
                .map((link) => new Promise((pronto) => {
                    link.addEventListener('load', pronto, { once: true });
                    link.addEventListener('error', pronto, { once: true });
                }));
            Promise.all(cssPendente).then(() => {
                requestAnimationFrame(() => abertura.classList.add('saindo'));
                setTimeout(() => abertura.remove(), 600);
            });
        }
    },
    // Barra no topo nas trocas de página (a mesma de sempre); aparece aos
    // 150 ms em vez de 250 pro cliente ver logo que está carregando.
    progress: {
        color: '#4B5563',
        delay: 150,
    },
});
