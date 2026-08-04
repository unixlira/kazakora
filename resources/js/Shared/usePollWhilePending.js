import { onMounted, onUnmounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';

const PENDING_STATUSES = ['queued', 'claimed'];

/**
 * Recarrega a prop `jobs` (via Inertia partial reload) enquanto houver algum
 * job com status "queued"/"claimed" na página — o KoraSync atualiza o status
 * no banco assim que termina, mas nada avisava a aba já aberta do admin,
 * então a listagem ficava presa em "Na fila"/"Imprimindo" até um reload
 * manual.
 */
export function usePollWhilePending(jobsRef, { interval = 4000, only = ['jobs'] } = {}) {
    let timer = null;

    const hasPending = () => (jobsRef.value?.data ?? []).some((job) => PENDING_STATUSES.includes(job.status));

    const stop = () => {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    };

    const start = () => {
        if (timer || !hasPending()) return;

        timer = setInterval(() => {
            if (!hasPending()) {
                stop();
                return;
            }

            router.reload({ only, preserveScroll: true, preserveState: true });
        }, interval);
    };

    onMounted(start);
    onUnmounted(stop);
    watch(jobsRef, () => (hasPending() ? start() : stop()));

    return { stop };
}
