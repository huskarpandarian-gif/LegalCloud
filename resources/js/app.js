import { loadApiCollections, request, webRequest } from './api';
import { paths, viewFromPath, viewPaths } from './endpoints';
import { closeNavigation, paintChrome, showAuth, toast } from './chrome';
import { closeDocumentMenu, findDocument, openDocumentMenu, runDocumentAction } from './documents';
import { drawIcons } from './icons';
import { escapeHtml } from './format';
import { render } from './views';
import {
    allowedViews, apiBase, apiMode, can, cases, categories, clearSession, content, documents, readSession, state, users, viewNames, writeSession,
} from './store';

function goHome() {
	const home = state.user?.home || '/dashboard';
	const view = viewFromPath(home) || 'dashboard';
	state.view = allowedViews().includes(view) ? view : 'dashboard';
	const path = viewPaths[state.view] || '/dashboard';
	if (location.pathname !== path) history.replaceState({ view: state.view }, '', path);
}
function denyView() {
	toast('No tienes acceso a esa sección.');
	state.view = 'dashboard';
	history.replaceState({ view: 'dashboard' }, '', '/dashboard');
	closeNavigation();
	render();
}
function changeView(view, push = true) {
	if (!viewNames[view] || !allowedViews().includes(view)) {
		if (viewNames[view]) denyView();
		return;
	}
	state.view = view; state.query = ''; state.filter = 'all'; state.categoryFilter = 'all'; state.searchHits = null;
	if (push && viewPaths[view] && location.pathname !== viewPaths[view]) history.pushState({ view }, '', viewPaths[view]);
	closeNavigation();
	render(); window.scrollTo({ top: 0, behavior: 'smooth' });
}
function openDialog(id) {
	if (id === 'upload-modal') document.querySelector('[data-case-select]').innerHTML = cases.map((item)=>`<option value="${escapeHtml(item.id)}">${escapeHtml(item.reference)} · ${escapeHtml(item.title)}</option>`).join('');
	document.getElementById(id).showModal(); drawIcons(document.getElementById(id));
}
async function ask(question) {
	if (!can('assistant.use')) return toast('Tu rol no tiene acceso al asistente.');
	const clean = question.trim(); if (!clean) return;
	state.chat.push({ role: 'user', text: clean }, { role: 'assistant', text: 'Estoy revisando la documentación pertinente…' }); render();
	if (apiMode !== 'demo') {
		try {
			if (!state.conversationId) {
				const conversation = await request(paths.conversations, { method: 'POST', body: JSON.stringify({ title: clean.slice(0,80) }) });
				state.conversationId = conversation.data?.id || conversation.id;
			}
			const answer = await request(paths.messages(state.conversationId), { method: 'POST', body: JSON.stringify({ question: clean }) });
			const citations = (Array.isArray(answer.citations) ? answer.citations : []).map((item) => ({ title: item.title || 'Documento', detail: `Página ${item.page || '—'} · ${item.excerpt || ''}` }));
			state.chat[state.chat.length-1] = { role:'assistant', text:answer.content, citations };
		} catch { state.chat[state.chat.length-1].text='La consulta no pudo completarse. Verifica que el backend y el servicio RAG estén disponibles.'; }
		render(); return;
	}
	window.setTimeout(()=>{state.chat[state.chat.length-1]={role:'assistant',text:'La Resolución 118/2024 establece que la unidad requirente debe acreditar la disponibilidad presupuestaria, definir los criterios de evaluación antes de publicar la convocatoria y conservar constancia de las ofertas recibidas. El reglamento también exige motivar la selección de la propuesta más conveniente.',citation:{title:'Resolución 118/2024 · Artículos 8, 12 y 19',detail:'Páginas 4–6 · versión 3 · Relevancia 96%'}};render();},650);
}
document.addEventListener('click',async(event)=>{
	const menuButton=event.target.closest('[data-document-menu]');
	if(menuButton){const id=menuButton.dataset.documentMenu;const open=document.querySelector('[data-document-menu-panel]');if(open?.dataset.documentMenuPanel===id)closeDocumentMenu();else openDocumentMenu(menuButton,id);return;}
	const documentAction=event.target.closest('[data-document-action]');
	if(documentAction){const {documentAction:action,documentId,versionId}=documentAction.dataset;closeDocumentMenu();await runDocumentAction(action,documentId,versionId);return;}
	if(!event.target.closest('[data-document-menu-panel]'))closeDocumentMenu();
	const nav=event.target.closest('[data-nav]');if(nav)return changeView(nav.dataset.nav);
	const action=event.target.closest('[data-action]')?.dataset.action;
	if(action==='new-case'||action==='upload'){if(action==='new-case'&&!can('cases.write'))return toast('Tu rol puede consultar expedientes, no crearlos.');if(action==='upload'&&!can('documents.write'))return toast('Tu rol puede consultar documentos, no cargarlos.');if(action==='upload'&&!cases.length)return toast('Crea un expediente antes de cargar un documento.');return openDialog(action==='new-case'?'case-modal':'upload-modal');}
	if(action==='new-category'||action==='edit-category'){if(!can('categories.manage'))return toast('Tu rol puede consultar categorías, no modificarlas.');const form=document.querySelector('[data-category-form]');form.reset();delete form.dataset.categoryId;if(action==='edit-category'){const category=categories.find((item)=>item.id===event.target.closest('[data-action="edit-category"]').dataset.categoryId);if(!category)return;form.dataset.categoryId=category.id;form.querySelector('[name=name]').value=category.name||'';form.querySelector('[name=description]').value=category.description||'';}return openDialog('category-modal');}
	if(action==='deactivate-category'){if(!can('categories.manage'))return toast('Tu rol no puede desactivar categorías.');const id=event.target.closest('[data-action="deactivate-category"]').dataset.categoryId;if(!window.confirm('¿Desactivar esta categoría?'))return;try{await request(`${paths.categories}/${id}/desactivar`,{method:'POST'});toast('Categoría desactivada.');await loadApiCollections();}catch{toast('No se pudo desactivar la categoría.');}return;}
	if(action==='invite-user'){if(apiMode==='demo')return toast('En el modo de demostración los usuarios son de ejemplo.');if(!can('users.manage'))return toast('Solo un administrador puede crear usuarios.');return openDialog('user-modal');}
	if(action==='delete-user'||action==='reactivate-user'){const button=event.target.closest(`[data-action="${action}"]`);if(apiMode==='demo'||!button?.dataset.userId)return toast('En el modo de demostración los usuarios son de ejemplo.');if(!can('users.manage'))return toast('Solo un administrador puede cambiar el acceso de los usuarios.');const name=button.dataset.userName||'este usuario';const suspending=action==='delete-user';if(!window.confirm(suspending?`¿Suspender a ${name}? Perderá el acceso hasta que un administrador lo reactive.`:`¿Reactivar a ${name}?`))return;try{await request(`${paths.user(button.dataset.userId)}/${suspending?'suspender':'reactivar'}`,{method:'POST'});toast(suspending?'Usuario suspendido.':'Usuario reactivado.');await loadApiCollections();}catch{toast('No se pudo actualizar el usuario.');}return;}
	if(event.target.closest('[data-logout]')){if(apiMode==='demo')return;try{await request(paths.logout,{method:'POST'});}catch{}clearSession();cases.length=0;documents.length=0;users.length=0;state.chat=[];state.conversationId=null;showAuth(true);return;}
	if(event.target.closest('[data-dismiss]'))event.target.closest('dialog').close();
	if(event.target.closest('[data-menu-toggle]')){document.querySelector('#sidebar').classList.toggle('is-open');document.querySelector('[data-scrim]').classList.toggle('is-visible');}
	if(event.target.closest('[data-scrim]')){document.querySelector('#sidebar').classList.remove('is-open');document.querySelector('[data-scrim]').classList.remove('is-visible');}
	if(event.target.closest('[data-notifications]')){const pop=document.querySelector('[data-notification-popover]');pop.hidden=!pop.hidden;}
	const readNotification=event.target.closest('[data-notification-read]');
	if(readNotification){if(apiMode!=='demo'){try{await request(paths.notificationRead(readNotification.dataset.notificationRead),{method:'POST'});await loadApiCollections();}catch{toast('No se pudo marcar la notificación.');}}return;}
	const settingsSection=event.target.closest('[data-settings-section]');
	if(settingsSection){state.settingsSection=settingsSection.dataset.settingsSection;const next=state.settingsSection==='password'?'/settings/password':state.settingsSection==='appearance'?'/settings/appearance':'/settings/profile';if(location.pathname!==next)history.pushState({view:'settings'},'',next);render();return;}
	if(event.target.closest('[data-delete-profile]')){if(apiMode==='demo')return toast('En el modo de demostración la cuenta es de ejemplo.');if(!window.confirm('¿Suspender tu cuenta? Tendrás que pedir a un administrador que la reactive.'))return;try{await webRequest('/settings/profile',{method:'DELETE'});}catch{}clearSession();showAuth(true);return;}
	const suggestion=event.target.closest('[data-prompt]');if(suggestion){changeView('assistant');ask(suggestion.dataset.prompt);}
	if(event.target.closest('[data-chat-clear]')){state.chat=[];state.conversationId=null;render();}
});
document.addEventListener('input',(event)=>{
	if(event.target.matches('[data-list-search]')){state.query=event.target.value;const position=event.target.selectionStart;render();const input=content().querySelector('[data-list-search]');input?.focus();input?.setSelectionRange(position,position);}
	if(event.target.matches('[data-upload-form] input[type=file]')){const file=event.target.files[0];const title=document.querySelector('[data-upload-form] [name=title]');if(file&&!title.value)title.value=file.name.replace(/\.[^.]+$/,'');}
});
document.addEventListener('change',(event)=>{
	if(event.target.matches('[data-status-filter]')){state.filter=event.target.value;render();}
	if(event.target.matches('[data-category-filter]')){state.categoryFilter=event.target.value;render();}
	if(event.target.matches('[data-case-status]')){const select=event.target;const id=select.dataset.caseStatus;const status=select.value;if(!can('cases.write'))return;if(apiMode==='demo'){const item=cases.find((entry)=>entry.id===id);if(item)item.status=status;toast('Estado actualizado.');return;}request(`${paths.cases}/${id}/transiciones`,{method:'POST',body:JSON.stringify({status})}).then(()=>loadApiCollections()).then(()=>toast('Estado actualizado.')).catch(()=>{toast('No se pudo cambiar el estado.');loadApiCollections();});}
	if(event.target.matches('[data-user-role]')){const select=event.target;if(!can('users.manage')||apiMode==='demo')return toast('En el modo de demostración los roles son de ejemplo.');request(`${paths.user(select.dataset.userRole)}/roles`,{method:'PUT',body:JSON.stringify({roles:[select.value]})}).then(()=>loadApiCollections()).then(()=>toast('Rol actualizado.')).catch(()=>{toast('No se pudo cambiar el rol.');loadApiCollections();});}
	if(event.target.matches('[data-appearance]')){localStorage.setItem('legalcloud.appearance',event.target.value);document.documentElement.dataset.theme=event.target.value;toast('Apariencia guardada en este navegador.');}
});
document.addEventListener('submit',async(event)=>{
	if(event.target.matches('[data-login-form]')){
		event.preventDefault();const form=event.target,error=form.querySelector('[data-login-error]'),payload=Object.fromEntries(new FormData(form));error.hidden=true;
		try{const response=await fetch(`${apiBase}${paths.login}`,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify(payload)});if(!response.ok)throw new Error('invalid');const session=await response.json();const user={...session.user,home:session.home||'/dashboard'};writeSession({accessToken:session.accessToken,user,organization:session.organization});state.user=user;state.organization=session.organization;showAuth(false);goHome();paintChrome();render();await loadApiCollections();}catch{error.textContent='Correo o contraseña incorrectos.';error.hidden=false;}
	}
	if(event.target.matches('[data-category-form]')){
		event.preventDefault();const form=event.target,payload=Object.fromEntries(new FormData(form));
		if(apiMode!=='demo'){try{if(form.dataset.categoryId)await request(`${paths.categories}/${form.dataset.categoryId}`,{method:'PATCH',body:JSON.stringify(payload)});else await request(paths.categories,{method:'POST',body:JSON.stringify(payload)});await loadApiCollections();}catch{return toast('No se pudo guardar la categoría.');}form.closest('dialog').close();form.reset();delete form.dataset.categoryId;toast('Categoría guardada.');return;}
		categories.unshift({...payload,id:`cat-${Date.now()}`,active:true});form.closest('dialog').close();form.reset();toast('Categoría creada.');if(state.view==='categories')render();
	}
	if(event.target.matches('[data-settings-profile]')){
		event.preventDefault();
		if(apiMode==='demo')return toast('En el modo de demostración el perfil es de ejemplo.');
		try{const profile=await webRequest('/settings/profile',{method:'PATCH',body:JSON.stringify(Object.fromEntries(new FormData(event.target)))});state.user={...state.user,...profile.data};writeSession({...readSession(),user:state.user});paintChrome();toast('Perfil actualizado.');}catch{toast('No se pudo actualizar el perfil.');}
	}
	if(event.target.matches('[data-settings-password]')){
		event.preventDefault();
		if(apiMode==='demo')return toast('En el modo de demostración no se cambia la contraseña.');
		try{await webRequest('/settings/password',{method:'PUT',body:JSON.stringify(Object.fromEntries(new FormData(event.target)))});event.target.reset();toast('Contraseña actualizada.');}catch{toast('No se pudo cambiar la contraseña. Revisa la actual y que la nueva tenga al menos 12 caracteres.');}
	}
	if(event.target.matches('[data-user-form]')){
		event.preventDefault();const form=event.target,payload=Object.fromEntries(new FormData(form));
		try{await request(paths.users,{method:'POST',body:JSON.stringify(payload)});form.closest('dialog').close();form.reset();toast('Usuario creado.');await loadApiCollections();}catch{toast('No se pudo crear el usuario. Revisa el correo y que la contraseña tenga al menos 12 caracteres.');}
	}
	if(event.target.matches('[data-case-form]')){
		event.preventDefault();const form=event.target,payload=Object.fromEntries(new FormData(form));
		if(apiMode!=='demo'){try{await request(paths.cases,{method:'POST',body:JSON.stringify(payload)});await loadApiCollections();}catch{return toast('No se pudo crear el expediente. Revisa los datos y el backend.');}form.closest('dialog').close();form.reset();toast('Expediente creado correctamente.');return;}
		cases.unshift({...payload,id:`case-${Date.now()}`,status:'draft',documents:0,owner:'Mariana García',updated:'Ahora'});form.closest('dialog').close();form.reset();toast('Expediente creado correctamente.');if(state.view==='cases')render();
	}
	if(event.target.matches('[data-document-edit-form]')){
		event.preventDefault();const form=event.target,payload=Object.fromEntries(new FormData(form)),doc=findDocument(payload.documentId);if(!doc)return;
		if(apiMode!=='demo'){try{await request(paths.document(doc.id),{method:'PATCH',body:JSON.stringify({title:payload.title,description:payload.description||null})});await loadApiCollections();}catch{return toast('No se pudieron guardar los datos del documento.');}form.closest('dialog').close();toast('Datos del documento actualizados.');return;}
		doc.title=payload.title;doc.description=payload.description;form.closest('dialog').close();toast('Datos del documento actualizados.');render();
	}
	if(event.target.matches('[data-document-version-form]')){
		event.preventDefault();const form=event.target,data=new FormData(form),file=data.get('file'),doc=findDocument(data.get('documentId'));if(!doc)return;if(!(file instanceof File)||file.size>25*1024*1024)return toast('El archivo supera el límite de 25 MB.');
		if(apiMode!=='demo'){data.delete('documentId');try{await request(`${paths.versions(doc.id)}`,{method:'POST',body:data});await loadApiCollections();}catch{return toast('No se pudo cargar la nueva versión.');}form.closest('dialog').close();form.reset();toast('Nueva versión cargada. La extracción de texto volverá a ejecutarse.');return;}
		doc.status='processing';doc.size=`${Math.max(1,Math.round(file.size/1024))} KB`;doc.date='Ahora';doc.type=file.name.split('.').pop().toUpperCase();form.closest('dialog').close();form.reset();toast('Nueva versión cargada.');render();
	}
	if(event.target.matches('[data-upload-form]')){
		event.preventDefault();const form=event.target,data=new FormData(form),file=data.get('file');if(!(file instanceof File)||file.size>25*1024*1024)return toast('El archivo supera el límite de 25 MB.');
		if(apiMode!=='demo'){try{await request('/documentos/archivo',{method:'POST',body:data});await loadApiCollections();}catch{return toast('No se pudo cargar el documento. Revisa el expediente y el archivo.');}form.closest('dialog').close();form.reset();toast('Documento cargado. Si tenía texto, ya puedes consultarlo.');return;}
		const related=cases.find((item)=>item.id===data.get('caseId'));documents.unshift({id:`doc-${Date.now()}`,title:data.get('title'),caseId:data.get('caseId'),caseName:related?.title||'Expediente',type:file.name.split('.').pop().toUpperCase(),status:'processing',date:'Ahora',size:`${Math.max(1,Math.round(file.size/1024))} KB`,owner:'Mariana García'});form.closest('dialog').close();form.reset();toast('Documento cargado. El OCR comenzará en breve.');if(['documents','dashboard'].includes(state.view))render();
	}
	if(event.target.matches('[data-search-form]')){
		event.preventDefault();const formData=new FormData(event.target);state.query=formData.get('query')||'';state.categoryFilter=formData.get('categoryId')||'all';state.searchHits=null;
		if(apiMode!=='demo')try{
			const body={query:state.query,mode:'hybrid'};if(formData.get('categoryId'))body.categoryId=formData.get('categoryId');
			const response=await request(paths.searches,{method:'POST',body:JSON.stringify(body)});
			state.searchHits=response.data.map((hit)=>({...hit.document,caseName:cases.find((item)=>item.id===hit.document.caseId)?.title,excerpt:hit.excerpt,page:hit.page,score:hit.score}));
		}catch{state.searchHits=[];toast('No se pudo completar la búsqueda. Revisa el servicio de indexación.');}
		render();
	}
	if(event.target.matches('[data-quick-ask],[data-chat-form]')){event.preventDefault();const question=new FormData(event.target).get('question');if(event.target.matches('[data-quick-ask]'))changeView('assistant');ask(question);}
});
document.querySelector('[data-global-search]').addEventListener('keydown',async(event)=>{if(event.key!=='Enter')return;const query=event.currentTarget.value;changeView('search');state.query=query;if(apiMode!=='demo'&&query.trim()){try{const response=await request(paths.searches,{method:'POST',body:JSON.stringify({query,mode:'hybrid'})});state.searchHits=(response.data||[]).map((hit)=>({...hit.document,type:'PDF',caseName:cases.find((item)=>item.id===hit.document.caseId)?.title,excerpt:hit.excerpt,page:hit.page,score:hit.score}));}catch{state.searchHits=[];toast('No se pudo completar la búsqueda.');}}render();});
document.addEventListener('keydown',(event)=>{if(event.key==='/'&&!['INPUT','TEXTAREA'].includes(document.activeElement.tagName)){event.preventDefault();document.querySelector('[data-global-search]').focus();}if(event.key==='Escape'){document.querySelector('[data-notification-popover]').hidden=true;closeDocumentMenu();}});
window.addEventListener('scroll',closeDocumentMenu,true);
window.addEventListener('resize',closeDocumentMenu);
function applyInitialView() {
	const initialView = viewFromPath(location.pathname) || document.documentElement.dataset.initialView;
	if (viewNames[initialView] && allowedViews().includes(initialView)) { state.view = initialView; return; }
	if (viewNames[initialView]) denyView();
}
function boot(){
	drawIcons();
	state.settingsSection = document.documentElement.dataset.settingsSection || 'profile';
	const savedTheme = localStorage.getItem('legalcloud.appearance');
	if (savedTheme) document.documentElement.dataset.theme = savedTheme;
	window.addEventListener('popstate', () => { const view = viewFromPath(location.pathname); if (view) changeView(view, false); });
	if(apiMode==='demo'){applyInitialView();showAuth(false);paintChrome();render();return;}
	cases.length=0;documents.length=0;users.length=0;categories.length=0;
	const session=readSession();
	if(!session?.accessToken){showAuth(true);return;}
	state.user=session.user;state.organization=session.organization;applyInitialView();showAuth(false);paintChrome();render();loadApiCollections();
}
boot();
