import Swal from 'sweetalert2';

const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3500,
    timerProgressBar: true,
    didOpen: (toast) => {
        toast.addEventListener('mouseenter', Swal.stopTimer);
        toast.addEventListener('mouseleave', Swal.resumeTimer);
    },
});

export function notifySuccess(message) {
    Toast.fire({ icon: 'success', title: message });
}

export function notifyError(message) {
    Toast.fire({ icon: 'error', title: message, timer: 5000 });
}

export function notifyWarning(message) {
    Toast.fire({ icon: 'warning', title: message, timer: 4500 });
}

export async function confirmDelete(options = {}) {
    const result = await Swal.fire({
        icon: 'warning',
        title: options.title ?? 'Tem certeza?',
        text: options.text ?? 'Essa ação não pode ser desfeita.',
        showCancelButton: true,
        confirmButtonText: options.confirmButtonText ?? 'Sim, remover',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true,
    });

    return result.isConfirmed;
}
