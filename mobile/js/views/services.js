import { CATALOG } from '../data.js';
import { can, serviceOf, ValidationError } from '../domain.js';
import { refresh } from '../router.js';
import { store } from '../store.js';
import { backButton, confirmDialog, emptyState, icon, openSheet, toast } from '../ui.js';
import { esc, plural } from '../util.js';

export default function services({ state, user }) {
    if (!can(user, 'services.manage')) {
        return { html: `<header class="appbar">${backButton('/')}<h1>Serviços</h1></header>${emptyState({ icon: 'info', title: 'Só o administrador edita os serviços' })}` };
    }

    return {
        html: `<header class="appbar">${backButton('/')}<h1>Serviços e documentos</h1></header>
            <p class="muted" style="margin-bottom:6px">A lista de documentos é copiada para cada atendimento na hora de agendar. Mudar aqui não altera os atendimentos que já existem.</p>
            ${CATALOG.map((category) => {
                const items = state.services.filter((s) => s.categorySlug === category.slug && !s.deleted);
                return `<div class="section-title"><h2>${esc(category.name)}</h2></div>
                    <div class="card" style="padding:6px 14px">${items
                        .map(
                            (s) => `<button type="button" class="service-row ${s.active ? '' : 'is-off'}" data-service="${s.slug}">
                                <span class="grow"><span class="row-title" style="display:block;white-space:normal">${esc(s.name)}</span><span class="row-sub">${plural(s.documents.length, 'documento', 'documentos')}${s.active ? '' : ' · desativado'}</span></span>
                                <span class="row-chevron">${icon('chev-r')}</span></button>`,
                        )
                        .join('')}</div>`;
            }).join('')}`,

        mount(root) {
            root.querySelectorAll('[data-service]').forEach((button) => button.addEventListener('click', () => openService(button.dataset.service)));
        },
    };
}

function openService(slug) {
    const service = serviceOf(store.state, slug);

    openSheet({
        title: service.name,
        lead: 'Documentos que o atendido deve trazer, na ordem em que aparecem.',
        html: `<div id="docs">${docsHtml(service)}</div>
            <form class="add-doc" id="add" novalidate>
                <label class="sr-only" for="doc-name">Novo documento</label>
                <input class="input" id="doc-name" placeholder="Novo documento" autocomplete="off">
                <button class="btn btn-primary btn-sm" type="submit">Adicionar</button>
            </form>
            <label class="toggle-row" style="margin-top:16px"><input type="checkbox" id="active" ${service.active ? 'checked' : ''}><span>Serviço ativo<small>Desativado, não aparece para novos agendamentos, mas segue no histórico.</small></span></label>`,

        mount(sheet, close) {
            const docs = sheet.querySelector('#docs');

            const run = async (name, ...args) => {
                try {
                    await store.action(name, ...args);
                } catch (error) {
                    if (!(error instanceof ValidationError)) throw error;
                    toast(error.message);
                }
                docs.innerHTML = docsHtml(serviceOf(store.state, slug));
            };

            docs.addEventListener('click', (event) => {
                const remove = event.target.closest('[data-remove]');
                if (remove) run('removeServiceDocument', slug, Number(remove.dataset.remove));
            });

            sheet.querySelector('#add').addEventListener('submit', async (event) => {
                event.preventDefault();
                const input = sheet.querySelector('#doc-name');
                await run('addServiceDocument', slug, input.value);
                input.value = '';
            });

            sheet.querySelector('#active').addEventListener('change', (event) => {
                run('setServiceActive', slug, event.target.checked);
            });

            sheet.addEventListener('click', async (event) => {
                if (event.target.closest('[data-done]')) {
                    close();
                    refresh();
                }

                if (event.target.closest('[data-delete]')) {
                    const sure = await confirmDialog({
                        title: 'Excluir serviço?',
                        message: 'Ele some da lista e dos novos agendamentos; os atendimentos antigos continuam com ele no histórico.',
                        confirmLabel: 'Excluir',
                        danger: true,
                    });
                    if (!sure) return;

                    try {
                        await store.action('deleteService', slug);
                        close();
                        refresh();
                        toast('Serviço excluído do catálogo.');
                    } catch (error) {
                        if (!(error instanceof ValidationError)) throw error;
                        toast(error.message);
                    }
                }
            });
            sheet.insertAdjacentHTML(
                'beforeend',
                '<button class="btn btn-secondary btn-block" type="button" data-done style="margin-top:16px">Pronto</button><button class="btn btn-danger btn-block" type="button" data-delete style="margin-top:8px">Excluir serviço</button>',
            );
        },
    });
}

const docsHtml = (service) =>
    service.documents.length
        ? service.documents.map((name, i) => `<div class="doc-edit"><span>${esc(name)}</span><button class="icon-btn" type="button" data-remove="${i}" aria-label="Remover ${esc(name)}">${icon('x')}</button></div>`).join('')
        : '<p class="muted" style="padding:6px 0">Nenhum documento. Adicione abaixo.</p>';
