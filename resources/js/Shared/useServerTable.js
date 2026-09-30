import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

/**
 * Filtros de uma listagem paginada no servidor (ver DataTable, modo
 * servidor). Guarda o estado atual dos filtros e, a cada mudança, refaz a
 * visita Inertia com eles como query param — sem `page`, então qualquer
 * filtro/busca/ordenação nova volta pra primeira página (continuar na
 * página 7 de um resultado que agora só tem 2 mostraria uma tela vazia).
 */
export function useServerTable(url, initialFilters = {}) {
    const filters = reactive({ ...initialFilters });

    const visit = (changes = {}) => {
        Object.assign(filters, changes);

        const params = Object.fromEntries(
            Object.entries(filters).filter(([, value]) => value !== null && value !== undefined && String(value).trim() !== ''),
        );

        router.get(url, params, { preserveState: true, preserveScroll: true, replace: true });
    };

    // DataTable emite { id, desc } | null — vira sort/direction na URL.
    const sortParams = (sort, prefix = '') => ({
        [`${prefix}sort`]: sort?.id ?? null,
        [`${prefix}direction`]: sort ? (sort.desc ? 'desc' : 'asc') : null,
    });

    return { filters, visit, sortParams };
}

// Converte sort/direction devolvidos pelo controller no formato que o
// DataTable espera no prop `sort`.
export const toTableSort = (sort, direction) => (sort ? { id: sort, desc: direction === 'desc' } : null);
