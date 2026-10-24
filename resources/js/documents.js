import { loadApiCollections, request } from './api';
import { paths } from './endpoints';
import { drawIcons } from './icons';
import { showAuth, toast } from './chrome';
import { escapeHtml, formatWhen } from './format';
import { render } from './views';
import { apiBase, apiMode, can, clearSession, documents, readSession } from './store';

export function findDocument(id) { return documents.find((doc) => doc.id === id); }
export function closeDocumentMenu() { document.querySelector('[data-document-menu-panel]')?.remove(); }
export function openDocumentMenu(button, documentId) {
	closeDocumentMenu();
	const doc = findDocument(documentId);
	if (!doc) return;
	const actions = [
		['download', 'Descargar'],
		['versions', 'Ver versiones'],
	];
	if (can('documents.write')) actions.push(['edit', 'Editar datos'], ['version', 'Nueva versión'], ['archive', 'Archivar']);
	if (can('documents.process')) actions.push(['ocr', 'Volver a extraer texto']);
	const menu = document.createElement('div');
	menu.className = 'row-menu';
	menu.setAttribute('role', 'menu');
	menu.dataset.documentMenuPanel = documentId;
	menu.innerHTML = actions.map(([action, label]) => `<button type="button" role="menuitem" class="${action === 'archive' ? 'is-danger' : ''}" data-document-action="${action}" data-document-id="${escapeHtml(documentId)}">${escapeHtml(label)}</button>`).join('');
	document.body.append(menu);
	const rect = button.getBoundingClientRect();
	const top = rect.bottom + menu.offsetHeight + 8 > window.innerHeight ? Math.max(8, rect.top - menu.offsetHeight - 6) : rect.bottom + 6;
	menu.style.top = `${top}px`;
	menu.style.left = `${Math.max(8, Math.min(rect.right - menu.offsetWidth, window.innerWidth - menu.offsetWidth - 8))}px`;
}
export async function downloadDocument(doc, version) {
	if (apiMode === 'demo') return toast('En el modo de demostración no hay un archivo real para descargar.');
	const versionId = version?.id || doc.currentVersion?.id;
	if (!versionId) return toast('Este documento todavía no tiene una versión descargable.');
	const token = readSession()?.accessToken;
	const response = await fetch(`${apiBase}${paths.download(doc.id, versionId)}`, { headers: { Accept: '*/*', ...(token ? { Authorization: `Bearer ${token}` } : {}) } });
	if (response.status === 401) { clearSession(); showAuth(true); return; }
	if (!response.ok) return toast('No se pudo descargar el documento.');
	const blob = await response.blob();
	const url = URL.createObjectURL(blob);
	const link = document.createElement('a');
	link.href = url;
	link.download = version?.fileName || doc.currentVersion?.fileName || doc.title;
	document.body.append(link);
	link.click();
	link.remove();
	URL.revokeObjectURL(url);
}
export function fillDocumentForm(id, doc) {
	const dialog = document.getElementById(id);
	dialog.querySelector('[name=documentId]').value = doc.id;
	dialog.querySelector('[data-document-label]').textContent = doc.title;
	drawIcons(dialog);
	return dialog;
}
export async function showDocumentVersions(doc) {
	const dialog = document.getElementById('document-versions-modal');
	const list = dialog.querySelector('[data-version-list]');
	dialog.querySelector('[data-document-label]').textContent = doc.title;
	list.innerHTML = '<div class="empty-state">Cargando versiones…</div>';
	if (!dialog.open) dialog.showModal();
	let versions = [];
	if (apiMode === 'demo') versions = [{ id: 'demo', versionNumber: doc.currentVersion?.versionNumber || 1, fileName: `${doc.title}.${(doc.type || 'pdf').toLowerCase()}`, changeNote: null, createdAt: doc.date }];
	else {
		try { versions = (await request(paths.versions(doc.id))).data || []; }
		catch { list.innerHTML = '<div class="empty-state">No se pudieron cargar las versiones.</div>'; return; }
	}
	list.innerHTML = versions.length ? versions.map((version) => `<div class="version-row"><span><strong>Versión ${escapeHtml(version.versionNumber)}</strong><small>${escapeHtml(version.fileName || 'Archivo')} · ${escapeHtml(version.changeNote || formatWhen(version.createdAt))}</small></span><button class="button button-quiet" type="button" data-document-action="download" data-document-id="${escapeHtml(doc.id)}" data-version-id="${escapeHtml(version.id)}" data-file-name="${escapeHtml(version.fileName || '')}">Descargar</button></div>`).join('') : '<div class="empty-state">Este documento no tiene versiones.</div>';
}
export async function runDocumentAction(action, documentId, versionId) {
	const doc = findDocument(documentId);
	if (!doc) return toast('El documento ya no está en la lista.');
	if (action === 'download') {
		const trigger = document.querySelector(`[data-document-action="download"][data-version-id="${CSS.escape(versionId || '')}"]`);
		const version = versionId && versionId !== 'demo' ? { id: versionId, fileName: trigger?.dataset.fileName || doc.currentVersion?.fileName } : null;
		return downloadDocument(doc, version);
	}
	if (action === 'versions') return showDocumentVersions(doc);
	if (action === 'ocr') {
		if (!can('documents.process')) return toast('Tu rol puede consultar documentos, no procesarlos.');
	} else if (!can('documents.write')) return toast('Tu rol puede consultar documentos, no modificarlos.');
	if (action === 'edit') {
		const dialog = fillDocumentForm('document-edit-modal', doc);
		dialog.querySelector('[name=title]').value = doc.title || '';
		dialog.querySelector('[name=description]').value = doc.description || '';
		dialog.showModal();
		return;
	}
	if (action === 'version') { fillDocumentForm('document-version-modal', doc).showModal(); return; }
	if (action === 'ocr') {
		if (apiMode === 'demo') { doc.status = 'processing'; render(); return toast('La extracción de texto quedó en cola.'); }
		try {
			const versionId = doc.currentVersion?.id;
			if (!versionId) return toast('Este documento no tiene una versión para procesar.');
			await request(paths.process(doc.id, versionId), { method: 'POST' });
			toast('La extracción de texto quedó en cola.');
			await loadApiCollections();
		}
		catch { toast('No se pudo encolar la extracción. Puede que ya haya un proceso activo.'); }
		return;
	}
	if (action === 'archive') {
		if (!window.confirm(`¿Archivar “${doc.title}”? Dejará de aparecer en la biblioteca.`)) return;
		if (apiMode === 'demo') { documents.splice(documents.indexOf(doc), 1); render(); return toast('Documento archivado.'); }
		try { await request(paths.archive(doc.id), { method: 'POST' }); toast('Documento archivado.'); await loadApiCollections(); }
		catch { toast('No se pudo archivar el documento.'); }
	}
}
