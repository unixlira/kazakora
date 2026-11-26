<script setup>
// Faixa de confiança em todas as páginas da loja (pedido do Lira 25/11/2026): a mesma da página
// de produto (círculo laranja com ícone branco). A home e a página de produto já têm a sua.
</script>

<template>
<section class="faixa-confianca" aria-label="Por que comprar na KazaKora">
    <div><i class="fas fa-truck-fast"></i><span><strong>Frete Grátis</strong>Entrega em todo Brasil</span></div>
    <div><i class="fas fa-credit-card"></i><span><strong>Parcelamento</strong>Em 12x nos cartões</span></div>
    <div><i class="fas fa-lock"></i><span><strong>Compra Segura</strong>Ambiente seguro para pagamentos online</span></div>
    <div><i class="far fa-face-smile"></i><span><strong>Satisfação Garantida</strong>Troca ou reembolso garantido</span></div>
</section>
</template>

<style scoped>
.faixa-confianca { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; align-items: stretch; max-width: 1320px; margin: 24px auto; padding: 0 24px; box-sizing: border-box; }
.faixa-confianca div { display: flex; align-items: center; justify-content: center; gap: 14px; padding: 16px 18px; border-radius: 12px; background: #fff; box-shadow: 0 8px 24px rgb(0 0 0 / .08); color: #000; font-size: 14px; line-height: 1.35; }
.faixa-confianca i { display: flex; align-items: center; justify-content: center; width: 48px; height: 48px; flex: 0 0 48px; border-radius: 999px; background: #f37021; color: #fff; font-size: 22px; }
.faixa-confianca strong { display: block; font-size: 16px; font-weight: 700; line-height: 1.2; color: #f37021; margin-bottom: 2px; }
.faixa-confianca span { color: #000; }
@media (max-width: 1024px) { .faixa-confianca { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; } }
@media (max-width: 767px) {
    .faixa-confianca { padding: 0 16px; }
    .faixa-confianca div { flex-direction: column; align-items: center; text-align: center; gap: 8px; padding: 14px 10px; font-size: 12.5px; }
    .faixa-confianca i { width: 44px; height: 44px; flex-basis: 44px; font-size: 20px; }
    .faixa-confianca strong { font-size: 14.5px; }
}
</style>
