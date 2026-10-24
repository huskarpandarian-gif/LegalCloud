const statusName = {
    indexed: 'Indexado',
    uploaded: 'Cargado',
    processing: 'Procesando',
    review: 'En revisión',
    open: 'Abierto',
    in_review: 'En revisión',
    closed: 'Cerrado',
    draft: 'Borrador',
    failed: 'Error',
    archived: 'Archivado',
};

export function escapeHtml(value = '') {
    return String(value).replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[char]);
}

export function initials(name = '') {
    return name.split(' ').map((part) => part[0]).filter(Boolean).slice(0, 2).join('').toUpperCase();
}

export function formatWhen(value) {
    const date = new Date(value || '');
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleString('es', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

export function pill(status) {
    return `<span class="status-pill ${escapeHtml(status)}">${escapeHtml(statusName[status] || status)}</span>`;
}
