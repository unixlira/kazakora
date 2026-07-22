import { onMounted, ref, watch } from 'vue';

const STORAGE_KEY = 'kazakora_admin_theme';

const isDark = ref(false);

export function useDarkMode() {
    onMounted(() => {
        const stored = localStorage.getItem(STORAGE_KEY);
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

        isDark.value = stored ? stored === 'dark' : prefersDark;
        applyClass();
    });

    watch(isDark, () => {
        localStorage.setItem(STORAGE_KEY, isDark.value ? 'dark' : 'light');
        applyClass();
    });

    const applyClass = () => {
        document.documentElement.classList.toggle('dark', isDark.value);
    };

    const toggle = () => {
        isDark.value = !isDark.value;
    };

    return { isDark, toggle };
}
