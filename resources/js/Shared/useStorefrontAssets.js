const ASSETS = [
    { tag: 'link', rel: 'preconnect', href: 'https://fonts.googleapis.com' },
    { tag: 'link', rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' },
    { tag: 'link', rel: 'stylesheet', href: 'https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&family=Roboto:wght@400;500;700&display=swap' },
    { tag: 'link', rel: 'stylesheet', href: 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css' },
    { tag: 'link', rel: 'stylesheet', href: '/vendor/electro/css/bootstrap.min.css' },
    { tag: 'link', rel: 'stylesheet', href: '/vendor/electro/css/style.css' },
    { tag: 'script', src: 'https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js' },
];

let refCount = 0;
const injected = [];

export function useStorefrontAssets() {
    if (refCount === 0) {
        ASSETS.forEach((asset) => {
            const el = document.createElement(asset.tag);
            el.dataset.storefrontAsset = 'true';

            if (asset.tag === 'link') {
                el.rel = asset.rel;
                el.href = asset.href;
                if (asset.crossorigin !== undefined) el.crossOrigin = asset.crossorigin;
            } else {
                el.src = asset.src;
                el.defer = true;
            }

            document.head.appendChild(el);
            injected.push(el);
        });
    }

    refCount += 1;

    return () => {
        refCount -= 1;
        if (refCount === 0) {
            injected.splice(0).forEach((el) => el.remove());
        }
    };
}
