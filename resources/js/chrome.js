import { icon, drawIcons } from './icons';
import { escapeHtml, formatWhen, initials } from './format';
import { allowedViews, apiMode, can, cases, roleLabel, state } from './store';

export function applyRoleNav() {
	const allowed = new Set(allowedViews());
	document.querySelectorAll('.side-nav [data-nav]').forEach((button) => { button.hidden = !allowed.has(button.dataset.nav); });
	const adminLabel = document.querySelector('.nav-label-spaced');
	if (adminLabel) adminLabel.hidden = !['users', 'audit'].some((view) => allowed.has(view));
	const search = document.querySelector('.global-search');
	if (search) search.hidden = !can('search.use');
}

export function showAuth(visible) {
	const gate = document.querySelector('[data-auth-gate]');
	const shell = document.querySelector('.app-shell');
	if (gate) gate.hidden = !visible;
	if (shell) shell.hidden = visible;
}
export function paintChrome() {
	const organization = state.organization?.name || 'Tu organización';
	const user = state.user;
	document.querySelector('[data-workspace-name]').textContent = organization;
	document.querySelector('[data-org-label]').textContent = organization;
	document.querySelector('[data-workspace-avatar]').textContent = organization.slice(0, 1).toUpperCase();
	document.querySelector('[data-profile-name]').textContent = user?.name || 'Cuenta';
	document.querySelector('[data-profile-role]').textContent = roleLabel[user?.role] || 'Sesión';
	document.querySelector('[data-profile-avatar]').textContent = user?.name ? initials(user.name) : 'LC';
	applyRoleNav();
	document.querySelector('[data-case-count]').textContent = String(cases.length);
	document.querySelector('[data-today]').textContent = new Date().toLocaleDateString('es', { weekday: 'long', day: 'numeric', month: 'short' });
	const logout = document.querySelector('[data-logout]');
	if (logout) logout.hidden = apiMode === 'demo';
	const unread = state.notifications.filter((item) => !item.readAt).length;
	document.querySelector('[data-notification-count]').textContent = unread ? `${unread} nuevas` : 'Al día';
	document.querySelector('[data-notification-dot]') || document.querySelector('.notification-dot')?.toggleAttribute('hidden', apiMode !== 'demo' && unread === 0);
	const list = document.querySelector('[data-notification-list]');
	if (list && apiMode !== 'demo') {
		list.innerHTML = state.notifications.length ? state.notifications.map((item) => `<div class="notification-item"><span class="notification-icon mint">${icon('scan')}</span><span><strong>${escapeHtml(item.title)}</strong><small>${escapeHtml(item.body || formatWhen(item.createdAt))}</small></span></div>`).join('') : '<div class="empty-state">No hay notificaciones.</div>';
	}
}

export function toast(message) {
	const item = document.createElement('div'); item.className = 'toast'; item.innerHTML = `${icon('check')}<span>${escapeHtml(message)}</span>`;
	document.querySelector('[data-toast-region]').append(item); window.setTimeout(() => item.remove(), 3300);
}

export function closeNavigation() {
    document.querySelector('#sidebar').classList.remove('is-open');
    document.querySelector('[data-scrim]').classList.remove('is-visible');
}
