// Mapa ÚNICO de status do admin: rótulo em português + cor do badge.
//
// Pedido do dono (2026-10-09): o mesmo status tem que aparecer igual em
// todas as telas — ex.: "cancelled" é sempre "Cancelado" em vermelho, nunca
// um traço numa tela e um badge na outra. Toda tela usa o StatusBadge
// (Shared/Components/DataTable/StatusBadge.vue), que lê daqui.
//
// Paleta (tons):
//   green  = sucesso / concluído
//   yellow = pendente / aguardando
//   blue   = informativo / enviado / em trânsito
//   red    = cancelado / erro / rejeitado
//   purple = processando / em andamento
//   gray   = neutro / externo / sem informação
//
// Uso:
//   <StatusBadge status="cancelled" />                    -> "Cancelado" vermelho
//   <StatusBadge status="authorized" context="invoice" /> -> "Emitida" verde
//   <StatusBadge :status="null" context="invoice" />      -> "Sem nota" cinza
//
// "context" só existe pra quando o mesmo valor significa outra coisa naquela
// entidade (nota "authorized" = Emitida; pagamento "authorized" = Autorizado)
// ou pra concordância (nota fiscal é "Cancelada"). A COR de um mesmo
// significado é sempre a mesma.

export const TONE_CLASSES = {
    green: 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300',
    yellow: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/50 dark:text-yellow-300',
    blue: 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300',
    red: 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300',
    purple: 'bg-purple-100 text-purple-700 dark:bg-purple-900/50 dark:text-purple-300',
    gray: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
};

// Apelidos de tom, pra quem quiser passar a cor direto (tone="success").
const TONE_ALIASES = {
    success: 'green',
    warning: 'yellow',
    info: 'blue',
    danger: 'red',
    error: 'red',
    neutral: 'gray',
    slate: 'gray',
    amber: 'yellow',
    violet: 'purple',
    default: 'gray',
};

export const resolveTone = (tone) => {
    if (!tone) return null;
    const key = String(tone).toLowerCase();
    if (TONE_CLASSES[key]) return key;
    return TONE_ALIASES[key] ?? null;
};

// Status genéricos — valem pra qualquer tela que não passe "context".
// [rótulo, tom]
const DEFAULT = {
    // Pedido / genéricos
    pending: ['Pendente', 'yellow'],
    awaiting_payment: ['Aguardando pagamento', 'yellow'],
    paid: ['Pago', 'green'],
    shipped: ['Enviado', 'blue'],
    delivered: ['Entregue', 'green'],
    not_delivered: ['Não entregue', 'red'],
    completed: ['Concluído', 'green'],
    cancelled: ['Cancelado', 'red'],
    canceled: ['Cancelado', 'red'],
    cancelada: ['Cancelado', 'red'],
    cancelado: ['Cancelado', 'red'],
    in_cancel: ['Em cancelamento', 'red'],
    returned: ['Devolvido', 'yellow'],
    refunded: ['Reembolsado', 'gray'],

    // Ativo / inativo
    active: ['Ativo', 'green'],
    inactive: ['Inativo', 'gray'],
    enabled: ['Ativo', 'green'],
    disabled: ['Desativado', 'gray'],
    'in stock': ['Em estoque', 'green'],
    'out of stock': ['Sem estoque', 'red'],

    // Fluxo de trabalho
    draft: ['Rascunho', 'yellow'],
    open: ['Aberto', 'yellow'],
    opened: ['Aberto', 'yellow'],
    closed: ['Fechado', 'green'],
    resolved: ['Resolvido', 'green'],
    in_progress: ['Em andamento', 'purple'],
    processing: ['Processando', 'purple'],
    queued: ['Na fila', 'yellow'],
    claimed: ['Imprimindo', 'purple'],
    generating: ['Gerando', 'purple'],
    running: ['Em execução', 'purple'],
    retrying: ['Tentando novamente', 'purple'],
    ready_for_approval: ['Aguardando aprovação', 'yellow'],
    requires_confirmation: ['Aguardando confirmação', 'yellow'],
    approved: ['Aprovado', 'green'],
    accepted: ['Aceito', 'green'],
    confirmed: ['Confirmado', 'blue'],
    finished: ['Finalizado', 'green'],
    partial: ['Parcial', 'yellow'],
    dry_run: ['Prévia', 'blue'],
    expired: ['Expirado', 'gray'],

    // Envio / recebimento
    sent: ['Enviado', 'blue'],
    received: ['Recebido', 'green'],
    read: ['Lido', 'blue'],
    printed: ['Impresso', 'green'],
    published: ['Publicado', 'green'],
    label_ready: ['Etiqueta pronta', 'green'],
    label_downloaded: ['Etiqueta baixada', 'green'],
    ready_to_ship: ['Pronto para envio', 'yellow'],
    gerada: ['Gerada', 'green'],

    // Fiscal / pagamento
    authorized: ['Autorizado', 'green'],
    captured: ['Pago', 'green'],
    signed: ['Assinado', 'blue'],
    external: ['Externo', 'gray'],

    // Resultado
    success: ['Sucesso', 'green'],
    processed: ['Processado', 'green'],
    failed: ['Falhou', 'red'],
    error: ['Erro', 'red'],
    erro: ['Erro', 'red'],
    rejected: ['Rejeitado', 'red'],
    denied: ['Denegado', 'red'],
    skipped: ['Ignorado', 'gray'],
    ignored: ['Ignorado', 'gray'],

    // Integrações
    connected: ['Conectado', 'green'],
    disconnected: ['Desconectado', 'gray'],

    unknown: ['Desconhecido', 'gray'],
};

// Sobrescritas por entidade. "__empty" = rótulo quando não há status
// (ex.: pedido ainda sem nota) — em vez de traço solto, mostra badge cinza.
const CONTEXTS = {
    order: {
        __empty: ['Sem status', 'gray'],
    },
    invoice: {
        __empty: ['Sem nota', 'gray'],
        pending: ['Pendente', 'yellow'],
        signed: ['Assinada', 'blue'],
        sent: ['Enviada à SEFAZ', 'blue'],
        authorized: ['Emitida', 'green'],
        rejected: ['Rejeitada', 'red'],
        denied: ['Denegada', 'red'],
        cancelled: ['Cancelada', 'red'],
        error: ['Erro', 'red'],
        external: ['Emitida pelo canal', 'gray'],
    },
    // Histórico de tentativas de emissão (InvoiceGenerationLog).
    invoice_log: {
        success: ['Sucesso', 'green'],
        retrying: ['Tentando novamente', 'purple'],
        failed: ['Falhou', 'red'],
    },
    // Envio da nota pro canal (ChannelInvoiceSubmission).
    channel_invoice: {
        __empty: ['Não enviada', 'gray'],
        pending: ['Pendente', 'yellow'],
        sent: ['Enviada', 'blue'],
        accepted: ['Aceita', 'green'],
        rejected: ['Rejeitada', 'red'],
        error: ['Erro', 'red'],
    },
    payment: {
        __empty: ['Sem pagamento', 'gray'],
        requires_confirmation: ['Aguardando confirmação', 'yellow'],
        authorized: ['Autorizado', 'blue'],
        captured: ['Pago', 'green'],
        canceled: ['Cancelado', 'red'],
        failed: ['Recusado', 'red'],
        refunded: ['Reembolsado', 'gray'],
    },
    // Etiqueta/envio no canal (ChannelShipment.status).
    shipment: {
        __empty: ['Sem envio', 'gray'],
        pending: ['Aguardando confirmação', 'yellow'],
        confirmed: ['Aguardando etiqueta do canal', 'purple'],
        label_ready: ['Etiqueta pronta', 'green'],
        label_downloaded: ['Etiqueta baixada', 'green'],
        error: ['Canal não liberou a etiqueta', 'red'],
    },
    // E-mail de recibo (OrderEmailLog).
    email: {
        __empty: ['Não enviado', 'gray'],
        sent: ['Enviado', 'green'],
        failed: ['Falhou', 'red'],
        skipped: ['Ignorado', 'gray'],
    },
    // Linha do tempo do pedido (OrderFulfillmentEvent).
    fulfillment: {
        success: ['OK', 'green'],
        pending: ['Pendente', 'yellow'],
        failed: ['Falhou', 'red'],
    },
    // Pré-postagem dos Correios (QR).
    correios: {
        __empty: ['Sem QR', 'gray'],
        gerada: ['QR gerado', 'green'],
        erro: ['Falhou', 'red'],
        cancelada: ['Cancelada', 'red'],
    },
    // Fila de impressão / etiquetas manuais (PrintJob).
    print_job: {
        queued: ['Na fila', 'yellow'],
        claimed: ['Imprimindo', 'purple'],
        printed: ['Concluída', 'green'],
        failed: ['Falhou', 'red'],
    },
    // Webhooks recebidos dos canais (ChannelWebhookLog).
    webhook: {
        received: ['Recebido', 'blue'],
        processed: ['Processado', 'green'],
        ignored: ['Ignorado', 'gray'],
        rejected: ['Rejeitado', 'red'],
        failed: ['Falhou', 'red'],
    },
    // Anúncio do produto num canal (ProductChannelListing).
    listing: {
        __empty: ['Não publicado', 'gray'],
        draft: ['Não publicado', 'gray'],
        pending: ['Publicando', 'purple'],
        published: ['Publicado', 'green'],
        error: ['Erro', 'red'],
    },
    // Devolução/reclamação do Mercado Livre (claims).
    claim: {
        opened: ['Aberta', 'yellow'],
        closed: ['Fechada', 'green'],
    },
    // Central de devoluções (MarketplaceReturn.situacao).
    return: {
        aguardando_resposta: ['Aguardando resposta', 'red'],
        em_mediacao: ['Em mediação', 'yellow'],
        aguardando_envio: ['Aguardando envio', 'yellow'],
        em_transito: ['Em trânsito', 'blue'],
        entregue: ['Entregue', 'blue'],
        conferida: ['Conferida', 'green'],
        encerrada: ['Encerrada', 'gray'],
        cancelada: ['Cancelada', 'red'],
    },
    // Situação do envio Flex (FlexControlService).
    flex: {
        cancelada: ['Cancelada', 'red'],
        devolvida: ['Devolvida pelo ML', 'yellow'],
        entregue: ['Entregue ao comprador', 'green'],
        nao_entregue: ['Não entregue', 'red'],
        em_rota: ['Em rota', 'blue'],
        com_entregador: ['Com o entregador, sem rota', 'purple'],
        pronta: ['Pronta, esperando coleta', 'yellow'],
        na_loja: ['Na loja', 'gray'],
    },
    service_order: {
        open: ['Aberta', 'yellow'],
        in_progress: ['Em andamento', 'purple'],
        completed: ['Concluída', 'green'],
        cancelled: ['Cancelada', 'red'],
    },
    purchase_order: {
        draft: ['Rascunho', 'yellow'],
        sent: ['Enviado', 'blue'],
        received: ['Recebido', 'green'],
        cancelled: ['Cancelado', 'red'],
    },
    // Fotos de anúncio geradas por IA (MarketplaceAdPhotoBrief).
    ad_photo: {
        draft: ['Rascunho', 'yellow'],
        ready_for_approval: ['Matriz pronta', 'yellow'],
        approved: ['Matriz aprovada', 'blue'],
        queued: ['Na fila', 'yellow'],
        generating: ['Gerando', 'purple'],
        completed: ['Concluído', 'green'],
        failed: ['Falhou', 'red'],
    },
    // Disparos de WhatsApp (campanhas).
    campaign: {
        draft: ['Rascunho', 'yellow'],
        dry_run: ['Prévia', 'blue'],
        running: ['Enviando', 'purple'],
        finished: ['Finalizada', 'green'],
        partial: ['Parcial', 'yellow'],
        failed: ['Falhou', 'red'],
    },
    whatsapp_message: {
        pending: ['Enviando', 'yellow'],
        sent: ['Enviada', 'blue'],
        delivered: ['Entregue', 'green'],
        read: ['Lida', 'green'],
        failed: ['Falhou', 'red'],
    },
    conversation: {
        open: ['Aberta', 'yellow'],
        resolved: ['Encerrada', 'green'],
    },
    marketplace_account: {
        __empty: ['Não conectada', 'gray'],
        connected: ['Conectada', 'green'],
        disconnected: ['Desconectada', 'gray'],
        error: ['Erro', 'red'],
    },
    // Histórico de downloads de vídeo (status já vem em português da tela).
    video_download: {
        processando: ['Processando', 'purple'],
        'concluído': ['Concluído', 'green'],
        erro: ['Erro', 'red'],
    },
    audit_action: {
        create: ['Criação', 'green'],
        update: ['Edição', 'yellow'],
        delete: ['Exclusão', 'red'],
    },
};

const normalize = (status) => (status === null || status === undefined || status === ''
    ? null
    : String(status).toLowerCase());

const lookup = (status, context) => {
    const key = normalize(status);
    const ctx = context ? CONTEXTS[context] : null;

    if (key === null) {
        return ctx?.__empty ?? ['Sem status', 'gray'];
    }

    return ctx?.[key] ?? DEFAULT[key] ?? null;
};

/** Rótulo em português do status (cai no próprio valor se não for conhecido). */
export const statusLabel = (status, context = null) => lookup(status, context)?.[0] ?? String(status);

/** Tom (green/yellow/blue/red/purple/gray) do status. */
export const statusTone = (status, context = null) => lookup(status, context)?.[1] ?? 'gray';

/** Opções prontas pra <select>: [{ value, label }]. */
export const statusOptions = (values, context = null) => (values ?? []).map((value) => ({
    value,
    label: statusLabel(value, context),
}));
