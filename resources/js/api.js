import { paintChrome, showAuth, toast } from './chrome';
import { render } from './views';
import { formatWhen, initials } from './format';
import { paths } from './endpoints';
import {
    apiBase, apiMode, can, canAudit, canManageUsers, cases, categories, clearSession, documents, readSession, roleLabel, state, users,
} from './store';

export async function request(path, options = {}) {
	const headers = { Accept: 'application/json', ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }), ...options.headers };
	const token = readSession()?.accessToken;
	if (token) headers.Authorization = `Bearer ${token}`;
	const response = await fetch(`${apiBase}${path}`, { ...options, headers });
	if (response.status === 401 && apiMode !== 'demo') { clearSession(); showAuth(true); }
	if (!response.ok) throw new Error(`API ${response.status}`);
	return response.status === 204 ? null : response.json();
}
export async function webRequest(path, options = {}) {
	const csrf = decodeURIComponent(document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=') || '');
	const headers = { Accept: 'application/json', 'X-XSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }), ...options.headers };
	const token = readSession()?.accessToken;
	if (token) headers.Authorization = `Bearer ${token}`;
	const response = await fetch(path, { credentials: 'same-origin', ...options, headers });
	if (response.status === 401 && apiMode !== 'demo') { clearSession(); showAuth(true); }
	if (!response.ok) throw new Error(`WEB ${response.status}`);
	return response.status === 204 ? null : response.json();
}
export async function loadApiCollections() {
	if (apiMode === 'demo' || !readSession()?.accessToken) return;
	const [caseResult, documentResult, userResult, dashboardResult, auditResult, notificationResult, categoryResult] = await Promise.allSettled([
		request(`${paths.cases}?perPage=100`), request(`${paths.documents}?perPage=100`), canManageUsers() ? request(`${paths.users}?perPage=100`) : Promise.resolve({ data: [] }), request(paths.dashboard), canAudit() ? request(`${paths.audit}?perPage=8`) : Promise.resolve({ data: [] }), request(`${paths.notifications}?perPage=8`), can('categories.read') ? request(paths.categories) : Promise.resolve({ data: [] }),
	]);
	if (caseResult.status === 'rejected' && documentResult.status === 'rejected') {
		toast('No se pudo conectar con el API. Revisa la sesión y el backend.');
		return;
	}
	if (caseResult.status === 'fulfilled') cases.splice(0, cases.length, ...(caseResult.value.data || []).map((item) => ({
		...item, documents: item.documentCount || 0, owner: users.find((user) => user.id === item.ownerId)?.name || 'Equipo legal', updated: formatWhen(item.updatedAt),
	})));
	if (userResult.status === 'fulfilled') users.splice(0, users.length, ...(userResult.value.data || []).map((item) => ({
		id: item.id, initials: initials(item.name), name: item.name, email: item.email, role: roleLabel[item.role] || item.role, roleId: item.role,
		statusId: item.status, status: item.status === 'active' ? 'Activo' : item.status === 'invited' ? 'Invitado' : 'Suspendido',
	})));
	if (caseResult.status === 'fulfilled') cases.forEach((item) => { item.owner = users.find((user) => user.id === item.ownerId)?.name || 'Equipo legal'; });
	if (documentResult.status === 'fulfilled') documents.splice(0, documents.length, ...(documentResult.value.data || []).map((item) => ({
		...item, caseName: cases.find((caseItem) => caseItem.id === item.caseId)?.title || 'Expediente',
		type: item.mimeType?.includes('word') ? 'DOCX' : item.mimeType?.includes('image') ? 'IMG' : 'PDF',
		date: formatWhen(item.createdAt), size: item.sizeBytes ? `${Math.max(1, Math.round(item.sizeBytes / 1024))} KB` : '—', owner: 'Equipo legal',
	})));
	state.metrics = dashboardResult.status === 'fulfilled' ? dashboardResult.value : state.metrics;
	state.activity = auditResult.status === 'fulfilled' ? (auditResult.value.data || []) : [];
	state.notifications = notificationResult.status === 'fulfilled' ? (notificationResult.value.data || []) : [];
	if (categoryResult.status === 'fulfilled') categories.splice(0, categories.length, ...(categoryResult.value.data || []));
	paintChrome();
	render();
}
