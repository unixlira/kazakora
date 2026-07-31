export function maskCnpj(value) {
    return (value ?? '')
        .replace(/\D/g, '')
        .slice(0, 14)
        .replace(/^(\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/^(\d{2})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3/$4')
        .replace(/^(\d{2})\.(\d{3})\.(\d{3})\/(\d{4})(\d)/, '$1.$2.$3/$4-$5');
}

export function maskPhone(value) {
    const digits = (value ?? '').replace(/\D/g, '').slice(0, 11);

    if (digits.length <= 10) {
        return digits
            .replace(/^(\d{2})(\d)/, '($1) $2')
            .replace(/(\d{4})(\d)/, '$1-$2');
    }

    return digits
        .replace(/^(\d{2})(\d)/, '($1) $2')
        .replace(/(\d{5})(\d)/, '$1-$2');
}

// Inscrição estadual não tem um formato único nacional (cada UF define o
// próprio). A empresa deste projeto é de SP, então aplicamos o agrupamento
// de SP (000.000.000.000) quando o valor é só dígitos; "ISENTO" (comum em
// MEI/alguns regimes) passa direto, sem tentar mascarar como número.
export function maskIe(value) {
    const raw = (value ?? '').toUpperCase();

    if (/[A-Z]/.test(raw)) {
        return raw.slice(0, 20);
    }

    return raw
        .replace(/\D/g, '')
        .slice(0, 12)
        .replace(/^(\d{3})(\d)/, '$1.$2')
        .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/^(\d{3})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3.$4');
}

export function isValidEmail(value) {
    if (!value) {
        return true;
    }

    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}
