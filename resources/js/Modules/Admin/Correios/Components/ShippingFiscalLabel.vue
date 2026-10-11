<script setup>
import { computed } from 'vue';
import QrCodeSvg from '@/Shared/Components/QrCodeSvg.vue';
import Code128Barcode from '@/Shared/Components/Code128Barcode.vue';

const props = defineProps({
    recipient: { type: Object, required: true },
    sender: { type: Object, required: true },
    invoice: { type: Object, default: null },
    contentItems: { type: Array, default: () => [] },
    qrPayload: { type: String, default: null },
    codigoObjeto: { type: String, default: null },
    correiosId: { type: String, default: null },
    serviceLabel: { type: String, default: 'Correios' },
    weightGrams: { type: [Number, String], default: null },
    orderLabel: { type: String, default: null },
});

const logisticsCode = computed(() => props.codigoObjeto || props.correiosId || props.qrPayload || 'GERADO APÓS PRÉ-POSTAGEM');
const recipientZip = computed(() => props.recipient?.zip || props.recipient?.addressZip || extractZip(props.recipient?.addressLines ?? []) || '—');
const senderDisplayName = computed(() => 'KazaKora');
const senderLines = computed(() => props.sender?.addressLines ?? []);
const invoiceAccessKey = computed(() => onlyDigits(props.invoice?.chaveAcesso || props.invoice?.chaveFormatada || ''));
const invoiceDisplayCode = computed(() => props.invoice?.chaveFormatada || formatAccessKey(invoiceAccessKey.value) || 'NF-e não vinculada');
const invoiceNumberLabel = computed(() => {
    if (! props.invoice?.numero) {
        return 'NF-e';
    }

    return `NF-e ${props.invoice.numero}${props.invoice.serie ? ` / série ${props.invoice.serie}` : ''}`;
});
const declaredContent = computed(() => {
    const labels = props.contentItems
        .map((item) => ({
            name: compactLabel(item?.conteudo || item?.content || item?.name || 'Produto KazaKora', 48),
            quantity: Number(item?.quantidade || item?.quantity || 0),
        }))
        .filter((item) => item.name);

    if (! labels.length) {
        return 'Produto KazaKora';
    }

    const text = labels
        .map((item) => item.quantity > 1 ? `${item.name} (${item.quantity} un.)` : item.name)
        .join(' / ');

    return compactLabel(text, 92);
});

function extractZip(lines) {
    const line = lines.find((value) => /CEP\s*\d/i.test(String(value)));
    return line?.match(/CEP\s*([^—]+)/i)?.[1]?.trim() ?? null;
}

function onlyDigits(value) {
    return String(value ?? '').replace(/\D/g, '');
}

function formatAccessKey(value) {
    const digits = onlyDigits(value);

    return digits ? digits.replace(/(.{4})/g, '$1 ').trim() : null;
}

function compactLabel(value, maxLength) {
    const text = String(value ?? '').replace(/\s+/g, ' ').trim();

    if (text.length <= maxLength) {
        return text;
    }

    return text.slice(0, maxLength).replace(/\s+\S*$/, '').trim() || text.slice(0, maxLength).trim();
}
</script>

<template>
    <div class="shipping-label-print rounded-xl border border-slate-300 bg-white text-slate-950 shadow-sm">
        <div class="shipping-label-card flex flex-col bg-white text-slate-950">
            <section class="label-section label-logistics grid grid-cols-[0.82fr_1.18fr] border-b-4 border-slate-950">
                <div class="qr-panel flex items-start justify-center border-r-4 border-slate-950">
                    <div class="qr-code-box flex items-center justify-center border-2 border-slate-950 bg-white">
                        <QrCodeSvg v-if="qrPayload" :value="qrPayload" />
                        <span v-else class="px-2 text-center text-base font-black uppercase leading-tight text-slate-500">QR Code após gerar</span>
                    </div>
                </div>

                <div class="code-panel flex flex-col justify-start text-center">
                    <p class="label-kicker font-black uppercase text-slate-700">Código de postagem</p>
                    <p class="object-code mt-1 break-all font-mono font-black leading-tight">{{ logisticsCode }}</p>

                    <div class="cep-block border-t-4 border-slate-950">
                        <p class="label-kicker font-black uppercase text-slate-700">CEP destinatário</p>
                        <p class="recipient-zip mt-1 font-mono font-black leading-none">{{ recipientZip }}</p>
                    </div>
                </div>
            </section>

            <section class="label-section sender-section border-b-4 border-slate-950 text-slate-950">
                <div class="sender-grid grid grid-cols-[0.58fr_1.42fr] gap-3">
                    <div>
                        <p class="label-kicker sender-kicker font-black uppercase text-slate-700">Remetente</p>
                        <h3 class="sender-name mt-1 font-black uppercase leading-none">{{ senderDisplayName }}</h3>
                        <p v-if="sender.document" class="sender-document mt-1 font-black leading-tight">CNPJ: {{ sender.document }}</p>
                    </div>
                    <div class="sender-address font-black leading-tight">
                        <p v-for="line in senderLines" :key="line">{{ line }}</p>
                    </div>
                </div>
            </section>

            <section class="label-section fiscal-section text-slate-950">
                <div class="fiscal-stack flex h-full flex-col gap-1.5">
                    <div class="declaration-block border-b-2 border-slate-950 pb-1">
                        <p class="label-kicker font-black uppercase text-slate-700">Declaração do produto</p>
                        <p class="declared-content mt-0.5 font-black leading-tight">{{ declaredContent }}</p>
                    </div>

                    <div class="barcode-panel min-h-0 flex-1">
                        <div class="mb-1 flex items-center justify-between gap-2">
                            <p class="label-kicker font-black uppercase text-slate-700">Código DANFE</p>
                            <p class="fiscal-tag font-black uppercase text-slate-950">DANFE / {{ invoiceNumberLabel }}</p>
                        </div>
                        <Code128Barcode
                            v-if="invoiceAccessKey"
                            :value="invoiceAccessKey"
                            :height="32"
                            :aria-label="`Código DANFE ${invoiceAccessKey}`"
                        />
                        <div v-else class="barcode-empty flex items-center justify-center border-2 border-dashed border-slate-400 font-black uppercase text-slate-500">
                            NF-e não vinculada
                        </div>
                    </div>

                    <div class="danfe-code-block text-center">
                        <p class="danfe-code whitespace-nowrap font-mono font-black leading-tight">{{ invoiceDisplayCode }}</p>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>

<style>
/* Escopo global intencional: precisa alcançar body/admin shell e deixar só a etiqueta 10×15 paisagem visível na impressão térmica. */
@page {
    size: 150mm 100mm;
    margin: 0;
}

.shipping-label-print,
.shipping-label-print * {
    box-sizing: border-box;
}

.shipping-label-print {
    width: min(100%, 150mm);
    aspect-ratio: 3 / 2;
    overflow: hidden;
    print-color-adjust: exact;
    -webkit-print-color-adjust: exact;
}

.shipping-label-card {
    width: 100%;
    height: 100%;
    overflow: hidden;
}

.shipping-label-print .label-section {
    min-height: 0;
    overflow: hidden;
}

.shipping-label-print .label-logistics {
    flex: 0 0 42%;
}

.shipping-label-print .sender-section {
    flex: 0 0 24%;
    padding: 10px 18px;
}

.shipping-label-print .fiscal-section {
    flex: 1 1 34%;
    padding: 10px 14px 9px;
}

.shipping-label-print .qr-panel {
    padding: 10px 12px 8px;
}

.shipping-label-print .qr-code-box {
    width: min(64%, 35mm);
    aspect-ratio: 1 / 1;
    padding: 5px;
}

.shipping-label-print .code-panel {
    padding: 12px 18px 8px;
}

.shipping-label-print .label-kicker {
    font-size: 10px;
    line-height: 1.05;
    letter-spacing: 0.14em;
}

.shipping-label-print .object-code {
    font-size: 23px;
}

.shipping-label-print .cep-block {
    margin-top: 12px;
    padding-top: 8px;
}

.shipping-label-print .recipient-zip {
    font-size: 30px;
}

.shipping-label-print .sender-name {
    font-size: 19px;
}

.shipping-label-print .sender-document,
.shipping-label-print .sender-address {
    font-size: 11px;
    line-height: 1.1;
}

.shipping-label-print .sender-address {
    align-self: center;
}

.shipping-label-print .fiscal-tag,
.shipping-label-print .danfe-code,
.shipping-label-print .declared-content {
    font-size: 10px;
}

.shipping-label-print .code128-barcode {
    display: block;
    width: 100%;
    height: 34px;
}

.shipping-label-print .barcode-empty {
    height: 34px;
    font-size: 9px;
}

@media print {
    html,
    body {
        width: 150mm !important;
        height: 100mm !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden !important;
    }

    body * {
        visibility: hidden !important;
    }

    .shipping-label-print, .shipping-label-print * {
        visibility: visible !important;
    }

    .shipping-label-print {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        right: auto !important;
        bottom: auto !important;
        width: 150mm !important;
        height: 100mm !important;
        box-sizing: border-box !important;
        margin: 0 !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
        color: #000 !important;
        padding: 0 !important;
        overflow: hidden !important;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }

    .shipping-label-card {
        width: 150mm !important;
        height: 100mm !important;
    }

    .shipping-label-print .label-logistics {
        height: 42mm !important;
        min-height: 42mm !important;
        max-height: 42mm !important;
    }

    .shipping-label-print .sender-section {
        height: 24mm !important;
        min-height: 24mm !important;
        max-height: 24mm !important;
        padding: 2.2mm 4.5mm !important;
    }

    .shipping-label-print .fiscal-section {
        height: 34mm !important;
        min-height: 34mm !important;
        max-height: 34mm !important;
        padding: 2.2mm 3.8mm !important;
    }

    .shipping-label-print .qr-panel {
        padding: 2.5mm 3mm 2mm !important;
    }

    .shipping-label-print .qr-code-box {
        width: 32mm !important;
        height: 32mm !important;
        padding: 1.1mm !important;
    }

    .shipping-label-print .code-panel {
        padding: 3mm 5mm 2mm !important;
    }

    .shipping-label-print .label-kicker {
        font-size: 6.3pt !important;
        letter-spacing: 0.10em !important;
        line-height: 1.05 !important;
    }

    .shipping-label-print .object-code {
        font-size: 13.5pt !important;
        line-height: 1.02 !important;
    }

    .shipping-label-print .cep-block {
        margin-top: 3mm !important;
        padding-top: 2mm !important;
    }

    .shipping-label-print .recipient-zip {
        font-size: 18pt !important;
        line-height: 1 !important;
    }

    .shipping-label-print .sender-name {
        font-size: 12.5pt !important;
        line-height: 0.95 !important;
    }

    .shipping-label-print .sender-document,
    .shipping-label-print .sender-address {
        font-size: 6.8pt !important;
        line-height: 1.05 !important;
    }

    .shipping-label-print .fiscal-tag,
    .shipping-label-print .danfe-code,
    .shipping-label-print .declared-content {
        font-size: 6.2pt !important;
        line-height: 1.05 !important;
    }

    .shipping-label-print .code128-barcode,
    .shipping-label-print .barcode-empty {
        height: 9mm !important;
    }
}
</style>
