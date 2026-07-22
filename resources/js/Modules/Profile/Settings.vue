<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    profileUser: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head title="Configurações" />

    <AppLayout>
        <div class="container-fluid py-5">
            <div class="container" style="max-width: 760px;">
                <h1 class="display-6 mb-2">Configurações</h1>
                <p class="text-muted mb-4">Preferências da conta de {{ profileUser.name }}. Em breve.</p>

                <div class="bg-white rounded shadow-sm p-4 mb-4">
                    <div class="d-flex align-items-center justify-content-between py-3 border-bottom">
                        <div>
                            <p class="mb-0 fw-semibold"><i class="fas fa-bell me-2 text-muted"></i>Notificações</p>
                            <p class="mb-0 text-muted small">Receber e-mails sobre pedidos e novidades</p>
                        </div>
                        <span class="badge bg-light text-muted">Em breve</span>
                    </div>

                    <div class="d-flex align-items-center justify-content-between py-3 border-bottom">
                        <div>
                            <p class="mb-0 fw-semibold"><i class="fas fa-language me-2 text-muted"></i>Idioma</p>
                            <p class="mb-0 text-muted small">Português (Brasil)</p>
                        </div>
                        <span class="badge bg-light text-muted">Em breve</span>
                    </div>

                    <div class="d-flex align-items-center justify-content-between py-3 border-bottom">
                        <div>
                            <p class="mb-0 fw-semibold"><i class="fas fa-sun me-2 text-muted"></i>Aparência</p>
                            <p class="mb-0 text-muted small">Tema claro</p>
                        </div>
                        <span class="badge bg-light text-muted">Em breve</span>
                    </div>

                    <div class="d-flex align-items-center justify-content-between py-3">
                        <div>
                            <p class="mb-0 fw-semibold"><i class="fas fa-shield-halved me-2 text-muted"></i>Privacidade</p>
                            <p class="mb-0 text-muted small">Preferências de dados e privacidade</p>
                        </div>
                        <span class="badge bg-light text-muted">Em breve</span>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
