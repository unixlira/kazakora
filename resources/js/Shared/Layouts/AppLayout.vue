<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import { useStorefrontAssets } from '@/Shared/useStorefrontAssets';
import { notifyError, notifySuccess, notifyWarning } from '@/Shared/notify';

useStorefrontAssets();
onUnmounted(useStorefrontAssets());

const page = usePage();
const cartCount = computed(() => page.props.cart?.count ?? 0);
const user = computed(() => page.props.auth?.user);

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) notifySuccess(flash.success);
        if (flash?.error) notifyError(flash.error);
        if (flash?.warning) notifyWarning(flash.warning);
    },
    { immediate: true, deep: true },
);

const search = ref('');

const submitSearch = () => {
    router.get('/', { search: search.value || undefined }, { preserveState: true });
};

const logout = () => {
    router.post('/sair');
};

const WHATSAPP_NUMBER = '5511965723990';
const WHATSAPP_DISPLAY = '(11) 96572-3990';
</script>

<template>
    <div>
        <!-- Faixa de frete grátis -->
        <div class="w-100 text-center py-2" style="background:#28a745;color:#fff;font-size:.9rem;">
            🚚 Frete grátis para compras acima de R$ 299,00 · Pague no PIX e economize!
        </div>

        <!-- Topbar -->
        <div class="container-fluid px-5 d-none border-bottom d-lg-block">
            <div class="row gx-0 align-items-center">
                <div class="col-lg-4 text-center text-lg-start mb-lg-0">
                    <div class="d-inline-flex align-items-center" style="height: 45px;">
                        <Link href="/" class="text-muted me-2">Início</Link><small> / </small>
                        <a :href="`https://wa.me/${WHATSAPP_NUMBER}`" target="_blank" class="text-muted mx-2">Suporte</a><small> / </small>
                        <a href="mailto:contato@kazakora.com" class="text-muted ms-2">Contato</a>
                    </div>
                </div>
                <div class="col-lg-4 text-center d-flex align-items-center justify-content-center">
                    <small class="text-dark">Ligue:</small>
                    <a :href="`https://wa.me/${WHATSAPP_NUMBER}`" target="_blank" class="text-muted">{{ WHATSAPP_DISPLAY }}</a>
                </div>
                <div class="col-lg-4 text-center text-lg-end">
                    <div class="d-inline-flex align-items-center" style="height: 45px;">
                        <div v-if="user" class="dropdown">
                            <button type="button" class="btn btn-link p-0 border-0 text-muted text-decoration-none dropdown-toggle d-inline-flex align-items-center"
                                data-bs-toggle="dropdown">
                                <img v-if="user.avatar_url" :src="user.avatar_url" class="rounded-circle me-2" style="width:28px;height:28px;object-fit:cover;" alt="">
                                <span v-else class="rounded-circle bg-secondary text-white d-inline-flex align-items-center justify-content-center me-2"
                                    style="width:28px;height:28px;font-size:.7rem;">{{ user.initials }}</span>
                                {{ user.name }}
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li><Link class="dropdown-item" href="/perfil"><i class="fas fa-user me-2 text-muted"></i>Meu Perfil</Link></li>
                                <li><Link class="dropdown-item" href="/configuracoes"><i class="fas fa-gear me-2 text-muted"></i>Configurações</Link></li>
                                <li v-if="user.role === 'admin'"><Link class="dropdown-item" href="/admin"><i class="fas fa-gauge me-2 text-muted"></i>Painel Admin</Link></li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <button type="button" class="dropdown-item" @click="logout">
                                        <i class="fas fa-arrow-right-from-bracket me-2 text-muted"></i>Sair
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <template v-else>
                            <Link href="/entrar" class="text-muted me-2">Entrar</Link><small> / </small>
                            <Link href="/cadastro" class="text-muted ms-2">Cadastrar</Link>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Header principal -->
        <div class="container-fluid px-5 py-4 d-none d-lg-block">
            <div class="row gx-0 align-items-center text-center">
                <div class="col-md-4 col-lg-3 text-center text-lg-start">
                    <Link href="/" class="navbar-brand p-0">
                        <h1 class="display-5 text-primary m-0"><i class="fas fa-leaf text-secondary me-2"></i>KazaKora</h1>
                    </Link>
                </div>
                <div class="col-md-4 col-lg-6 text-center">
                    <form class="position-relative ps-4" @submit.prevent="submitSearch">
                        <div class="d-flex border rounded-pill">
                            <input v-model="search" class="form-control border-0 rounded-pill w-100 py-3" type="text"
                                placeholder="O que você procura?">
                            <button type="submit" class="btn btn-primary rounded-pill py-3 px-5" style="border: 0;">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </form>
                </div>
                <div class="col-md-4 col-lg-3 text-center text-lg-end">
                    <div class="d-inline-flex align-items-center">
                        <Link href="/carrinho" class="text-muted d-flex align-items-center justify-content-center">
                            <span class="rounded-circle btn-md-square border position-relative">
                                <i class="fas fa-shopping-cart"></i>
                                <span v-if="cartCount > 0"
                                    class="position-absolute badge rounded-pill bg-secondary"
                                    style="top:-6px;right:-6px;font-size:.65rem;">{{ cartCount }}</span>
                            </span>
                            <span class="text-dark ms-2">Carrinho</span>
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navbar -->
        <div class="container-fluid nav-bar p-0">
            <div class="row gx-0 bg-primary px-5 align-items-center">
                <div class="col-12">
                    <nav class="navbar navbar-expand-lg navbar-light bg-primary">
                        <Link href="/" class="navbar-brand d-block d-lg-none">
                            <h1 class="display-5 text-secondary m-0"><i class="fas fa-leaf text-white me-2"></i>KazaKora</h1>
                        </Link>
                        <button class="navbar-toggler ms-auto" type="button" data-bs-toggle="collapse"
                            data-bs-target="#navbarCollapse">
                            <span class="fa fa-bars fa-1x"></span>
                        </button>
                        <div class="collapse navbar-collapse" id="navbarCollapse">
                            <div class="navbar-nav ms-auto py-0">
                                <Link href="/" class="nav-item nav-link">Catálogo</Link>
                                <Link href="/carrinho" class="nav-item nav-link">Carrinho</Link>
                                <Link href="/finalizacao" class="nav-item nav-link">Checkout</Link>
                                <Link href="/admin" class="nav-item nav-link me-2">Admin</Link>
                            </div>
                            <a class="btn btn-secondary rounded-pill py-2 px-4 px-lg-3 mb-3 mb-md-3 mb-lg-0"
                                :href="`https://wa.me/${WHATSAPP_NUMBER}`" target="_blank">
                                <i class="fab fa-whatsapp me-2"></i> {{ WHATSAPP_DISPLAY }}
                            </a>
                        </div>
                    </nav>
                </div>
            </div>
        </div>

        <!-- Conteúdo da página -->
        <slot />

        <!-- Footer -->
        <div class="container-fluid footer py-5">
            <div class="container py-5">
                <div class="row g-4 rounded mb-5" style="background: rgba(255, 255, 255, .03);">
                    <div class="col-md-6 col-lg-6 col-xl-4">
                        <div class="rounded p-4">
                            <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center mb-4"
                                style="width: 70px; height: 70px;">
                                <i class="fas fa-map-marker-alt fa-2x text-primary"></i>
                            </div>
                            <div>
                                <h4 class="text-white">Endereço</h4>
                                <p class="mb-2">São Paulo - SP</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-6 col-xl-4">
                        <div class="rounded p-4">
                            <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center mb-4"
                                style="width: 70px; height: 70px;">
                                <i class="fas fa-envelope fa-2x text-primary"></i>
                            </div>
                            <div>
                                <h4 class="text-white">E-mail</h4>
                                <p class="mb-2">contato@kazakora.com</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-6 col-xl-4">
                        <div class="rounded p-4">
                            <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center mb-4"
                                style="width: 70px; height: 70px;">
                                <i class="fab fa-whatsapp fa-2x text-primary"></i>
                            </div>
                            <div>
                                <h4 class="text-white">WhatsApp</h4>
                                <p class="mb-2">{{ WHATSAPP_DISPLAY }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-5">
                    <div class="col-md-6 col-lg-6 col-xl-4">
                        <div class="footer-item d-flex flex-column">
                            <h4 class="text-primary mb-4">KazaKora</h4>
                            <p class="mb-3">Decoração para transformar sua casa, com entrega para todo o Brasil.</p>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-6 col-xl-4">
                        <div class="footer-item d-flex flex-column">
                            <h4 class="text-primary mb-4">Atendimento</h4>
                            <a href="mailto:contato@kazakora.com"><i class="fas fa-angle-right me-2"></i> Fale Conosco</a>
                            <a :href="`https://wa.me/${WHATSAPP_NUMBER}`" target="_blank"><i class="fas fa-angle-right me-2"></i> WhatsApp</a>
                            <Link href="/carrinho"><i class="fas fa-angle-right me-2"></i> Meu Carrinho</Link>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-6 col-xl-4">
                        <div class="footer-item d-flex flex-column">
                            <h4 class="text-primary mb-4">Minha Conta</h4>
                            <Link v-if="!user" href="/entrar"><i class="fas fa-angle-right me-2"></i> Entrar</Link>
                            <Link v-if="!user" href="/cadastro"><i class="fas fa-angle-right me-2"></i> Cadastrar</Link>
                            <Link v-if="user" href="/finalizacao"><i class="fas fa-angle-right me-2"></i> Meus Pedidos</Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Copyright -->
        <div class="container-fluid copyright py-4">
            <div class="container">
                <div class="row g-4 align-items-center">
                    <div class="col-12 text-center text-white small">
                        © 2026 KazaKora · CNPJ: 65.604.590/0001-07 · Todos os direitos reservados
                    </div>
                </div>
            </div>
        </div>

        <!-- WhatsApp flutuante -->
        <a :href="`https://wa.me/${WHATSAPP_NUMBER}`" target="_blank" rel="noopener"
            class="position-fixed d-flex align-items-center justify-content-center rounded-circle"
            style="bottom:24px;right:24px;width:56px;height:56px;background:#25D366;color:#fff;font-size:28px;box-shadow:0 4px 12px rgba(0,0,0,.3);z-index:1050;">
            <i class="fab fa-whatsapp"></i>
        </a>
    </div>
</template>
