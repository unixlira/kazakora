// Desempenho (pedido 2026-10-21): o SweetAlert2 (~80 KB) só é baixado na
// primeira vez que um aviso aparece, não junto com a loja.
let carregando = null;
const swal = () => (carregando ??= import('sweetalert2').then((m) => m.default));

let toast = null;
const Toast = async () => {
    if (toast) return toast;
    const Swal = await swal();
    toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3500,
        timerProgressBar: true,
        didOpen: (el) => {
            el.addEventListener('mouseenter', Swal.stopTimer);
            el.addEventListener('mouseleave', Swal.resumeTimer);
        },
    });
    return toast;
};

export { swal as carregarSwal };

export async function notifySuccess(message) {
    (await Toast()).fire({ icon: 'success', title: message });
}

export async function notifyError(message) {
    (await Toast()).fire({ icon: 'error', title: message, timer: 5000 });
}

export async function notifyWarning(message) {
    (await Toast()).fire({ icon: 'warning', title: message, timer: 4500 });
}

export async function confirmDelete(options = {}) {
    const Swal = await swal();
    const result = await Swal.fire({
        icon: options.icon ?? 'warning',
        title: options.title ?? 'Tem certeza?',
        text: options.text ?? 'Essa ação não pode ser desfeita.',
        showCancelButton: true,
        confirmButtonText: options.confirmButtonText ?? 'Sim, remover',
        cancelButtonText: options.cancelButtonText ?? 'Cancelar',
        confirmButtonColor: options.confirmButtonColor ?? '#dc2626',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true,
        focusCancel: options.focusCancel ?? false,
    });

    return result.isConfirmed;
}
