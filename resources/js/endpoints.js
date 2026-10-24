export const paths = {
    login: '/auth/iniciar-sesion',
    logout: '/auth/cerrar-sesion',
    users: '/usuarios',
    user: (id) => `/usuarios/${id}`,
    cases: '/expedientes',
    documents: '/documentos',
    document: (id) => `/documentos/${id}`,
    archive: (id) => `/documentos/${id}/archivar`,
    versions: (id) => `/documentos/${id}/versiones`,
    download: (documentId, versionId) => `/documentos/${documentId}/versiones/${versionId}/descarga`,
    process: (documentId, versionId) => `/documentos/${documentId}/versiones/${versionId}/procesamiento`,
    categories: '/categorias',
    searches: '/busquedas',
    conversations: '/conversaciones',
    messages: (id) => `/conversaciones/${id}/mensajes`,
    notifications: '/notificaciones',
    notificationRead: (id) => `/notificaciones/${id}/lectura`,
    dashboard: '/dashboard',
    audit: '/audit-events',
};

export const viewPaths = {
    dashboard: '/dashboard',
    cases: '/expedientes',
    documents: '/documentos',
    categories: '/categorias',
    ocr: '/ocr',
    search: '/busqueda',
    assistant: '/asistente',
    users: '/usuarios',
    notifications: '/notificaciones',
    settings: '/settings/profile',
    audit: '/auditoria',
};

const pathViews = Object.entries(viewPaths).reduce((map, [view, path]) => {
    map[path] = view;
    return map;
}, { '/': 'dashboard' });

pathViews['/settings/password'] = 'settings';
pathViews['/settings/appearance'] = 'settings';

export function viewFromPath(pathname) {
    if (pathViews[pathname]) return pathViews[pathname];
    if (pathname.startsWith('/expedientes/')) return 'cases';
    if (pathname.startsWith('/documentos/')) return 'documents';
    return null;
}
