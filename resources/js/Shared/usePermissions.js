import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export function usePermissions() {
    const page = usePage();
    const list = computed(() => page.props.permissions ?? []);

    const can = (permission) => list.value.includes('*') || list.value.includes(permission);

    return { can, permissions: list };
}
