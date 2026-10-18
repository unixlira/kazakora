import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { rastrearPagina } from '@/Shared/pixelMeta';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

const appName = import.meta.env.VITE_APP_NAME || 'KazaKora';

// Depois de um deploy, a aba aberta pede pedaços do Vite que não existem
// mais: recarrega uma vez para pegar a versão nova em vez de ficar quebrada.
window.addEventListener('vite:preloadError', (event) => {
    const ultima = Number(sessionStorage.getItem('kz-recarga-vite') || 0);
    if (Date.now() - ultima < 30000) return;
    sessionStorage.setItem('kz-recarga-vite', String(Date.now()));
    event.preventDefault();
    window.location.reload();
});

createInertiaApp({
    title: (title) => (title ? `${appName} - ${title}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./Modules/${name}.vue`,
            import.meta.glob('./Modules/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);

        // Pixel da Meta: só roda depois do OK de cookies (Shared/pixelMeta.js).
        // `navigate` cobre a primeira página e cada troca de página; o OK chega
        // por recarga parcial de `cookies` e dispara a página atual em seguida.
        let pixelOk = false;
        router.on('navigate', (e) => {
            pixelOk = !!e.detail.page.props.cookies?.aceito;
            rastrearPagina(e.detail.page);
        });
        router.on('success', (e) => {
            const aceito = !!e.detail.page.props.cookies?.aceito;
            if (aceito && !pixelOk) rastrearPagina(e.detail.page);
            pixelOk = aceito;
        });

        // Tela de abertura (resources/views/app.blade.php): some quando a loja montou.
        const abertura = document.getElementById('tela-abertura');
        if (abertura) {
            requestAnimationFrame(() => abertura.classList.add('saindo'));
            setTimeout(() => abertura.remove(), 600);
        }
    },
    // Barra no topo nas trocas de página (a mesma de sempre); aparece aos
    // 150 ms em vez de 250 pro cliente ver logo que está carregando.
    progress: {
        color: '#4B5563',
        delay: 150,
    },
});
