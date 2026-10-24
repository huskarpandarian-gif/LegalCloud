const root = document.documentElement;

export const apiBase = root.dataset.apiBase || '/api/v1';
export const apiMode = root.dataset.apiMode || 'demo';
export const sessionKey = 'legalcloud.session';

export const roleLabel = {
    administrator: 'Administrador',
    records_manager: 'Responsable documental',
    analyst: 'Especialista / analista',
    consultant: 'Usuario consultor',
};

export const viewNames = {
    dashboard: 'Resumen',
    cases: 'Expedientes',
    documents: 'Documentos',
    categories: 'Categorías',
    ocr: 'Procesamiento',
    search: 'Búsqueda inteligente',
    assistant: 'Asistente IA',
    users: 'Usuarios y roles',
    notifications: 'Notificaciones',
    settings: 'Configuración',
    audit: 'Auditoría',
};

export const categories = [
    { id: 'cat-normas', name: 'Normativa', description: 'Resoluciones, reglamentos y circulares.', active: true },
    { id: 'cat-expedientes', name: 'Actuaciones', description: 'Escritos y anexos de cada expediente.', active: true },
];

export const cases = [
    { id: 'case-018', reference: 'EXP-2024-018', title: 'Revisión normativa de contratación pública', status: 'in_review', documents: 18, owner: 'Mariana García', updated: 'Hoy, 10:42' },
    { id: 'case-011', reference: 'EXP-2024-011', title: 'Procedimiento de licencias ambientales', status: 'open', documents: 32, owner: 'Diego Navarro', updated: 'Hoy, 09:18' },
    { id: 'case-023', reference: 'EXP-2024-023', title: 'Protección de datos personales', status: 'open', documents: 11, owner: 'Lucía Torres', updated: 'Ayer, 16:35' },
    { id: 'case-007', reference: 'EXP-2023-007', title: 'Recursos administrativos — Distrito Norte', status: 'closed', documents: 46, owner: 'Mariana García', updated: '14 oct, 12:10' },
    { id: 'case-025', reference: 'EXP-2026-025', title: 'Actualización de lineamientos internos', status: 'draft', documents: 4, owner: 'Andrés Ríos', updated: '13 oct, 15:24' },
];

export const documents = [
    { id: 'doc-1', title: 'Resolución 118/2024 — Requisitos de contratación', caseId: 'case-018', caseName: 'Contratación pública', type: 'PDF', status: 'indexed', date: '16 oct 2026', size: '2.4 MB', owner: 'Mariana García' },
    { id: 'doc-2', title: 'Reglamento de compras y suministros', caseId: 'case-018', caseName: 'Contratación pública', type: 'PDF', status: 'indexed', date: '15 oct 2026', size: '1.8 MB', owner: 'Diego Navarro' },
    { id: 'doc-3', title: 'Informe técnico de impacto ambiental', caseId: 'case-011', caseName: 'Licencias ambientales', type: 'DOCX', status: 'processing', date: '15 oct 2026', size: '856 KB', owner: 'Lucía Torres' },
    { id: 'doc-4', title: 'Circular 07 — Protección de datos', caseId: 'case-023', caseName: 'Protección de datos', type: 'PDF', status: 'indexed', date: '14 oct 2026', size: '640 KB', owner: 'Mariana García' },
    { id: 'doc-5', title: 'Anexo II — Matriz de evaluación', caseId: 'case-018', caseName: 'Contratación pública', type: 'XLS', status: 'review', date: '14 oct 2026', size: '312 KB', owner: 'Andrés Ríos' },
    { id: 'doc-6', title: 'Acta de resolución de expediente', caseId: 'case-007', caseName: 'Recursos administrativos', type: 'PDF', status: 'indexed', date: '12 oct 2026', size: '1.1 MB', owner: 'Diego Navarro' },
];

export const users = [
    { initials: 'MG', name: 'Mariana García', email: 'mariana@navarro.legal', role: 'Administradora', status: 'Activo' },
    { initials: 'DN', name: 'Diego Navarro', email: 'diego@navarro.legal', role: 'Responsable documental', status: 'Activo' },
    { initials: 'LT', name: 'Lucía Torres', email: 'lucia@navarro.legal', role: 'Especialista / analista', status: 'Activo' },
    { initials: 'AR', name: 'Andrés Ríos', email: 'andres@navarro.legal', role: 'Usuario consultor', status: 'Invitado' },
];

export const state = {
    view: 'dashboard',
    filter: 'all',
    categoryFilter: 'all',
    query: '',
    searchHits: null,
    conversationId: null,
    chat: [],
    user: null,
    organization: null,
    metrics: null,
    activity: [],
    notifications: [],
};

export function content() {
    return document.querySelector('#page-content');
}

export function readSession() {
    try {
        return JSON.parse(sessionStorage.getItem(sessionKey) || 'null');
    } catch {
        return null;
    }
}

export function writeSession(session) {
    sessionStorage.setItem(sessionKey, JSON.stringify(session));
}

export function clearSession() {
    sessionStorage.removeItem(sessionKey);
    state.user = null;
    state.organization = null;
}

const permissionsByRole = {
    administrator: ['users.manage', 'cases.read', 'cases.write', 'documents.read', 'documents.write', 'documents.process', 'categories.read', 'categories.manage', 'search.use', 'assistant.use', 'audit.read', 'notifications.read'],
    records_manager: ['cases.read', 'cases.write', 'documents.read', 'documents.write', 'documents.process', 'categories.read', 'search.use', 'assistant.use', 'audit.read', 'notifications.read'],
    analyst: ['cases.read', 'cases.write', 'documents.read', 'documents.write', 'documents.process', 'categories.read', 'search.use', 'assistant.use', 'notifications.read'],
    consultant: ['cases.read', 'documents.read', 'categories.read', 'search.use', 'assistant.use', 'notifications.read'],
};

const demoPermissions = [
    'users.manage', 'cases.read', 'cases.write', 'documents.read', 'documents.write', 'documents.process',
    'categories.read', 'categories.manage', 'search.use', 'assistant.use', 'audit.read', 'notifications.read',
];

export function permissions() {
    if (apiMode === 'demo') return demoPermissions;
    if (Array.isArray(state.user?.permissions)) return state.user.permissions;
    return permissionsByRole[state.user?.role] || permissionsByRole.consultant;
}

export function can(permission) {
    return permissions().includes(permission);
}

export function canWrite() {
    return can('cases.write') || can('documents.write');
}

export function canAudit() {
    return can('audit.read');
}

export function canManageUsers() {
    return can('users.manage');
}

export function allowedViews() {
    const views = ['dashboard', 'settings'];
    if (can('cases.read')) views.push('cases');
    if (can('documents.read')) views.push('documents');
    if (can('categories.read')) views.push('categories');
    if (can('documents.process')) views.push('ocr');
    if (can('search.use')) views.push('search');
    if (can('assistant.use')) views.push('assistant');
    if (can('notifications.read')) views.push('notifications');
    if (can('users.manage')) views.push('users');
    if (can('audit.read')) views.push('audit');
    return views;
}
