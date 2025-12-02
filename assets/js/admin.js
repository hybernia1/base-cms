const adminNotify = (() => {
    const container = document.getElementById('toastContainer');
    const template = document.getElementById('toastTemplate');
    const toastSupported = container && template && typeof bootstrap !== 'undefined' && !!bootstrap.Toast;

    const createToast = (options = {}) => {
        if (!toastSupported) {
            return null;
        }

        const toastEl = template.cloneNode(true);
        toastEl.id = '';
        toastEl.classList.remove('d-none');
        const variant = options.variant || 'primary';
        toastEl.className = `toast align-items-center text-bg-${variant} border-0`;

        const titleEl = toastEl.querySelector('[data-role="toast-title"]');
        const messageEl = toastEl.querySelector('[data-role="toast-message"]');
        const actionsEl = toastEl.querySelector('[data-role="toast-actions"]');

        if (titleEl) titleEl.textContent = options.title || '';
        if (messageEl) messageEl.textContent = options.message || '';

        if (actionsEl) {
            actionsEl.innerHTML = '';
            if (options.actions && options.actions.length) {
                actionsEl.classList.remove('d-none');
                options.actions.forEach((action) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = `btn btn-${action.variant || 'light'} btn-sm`;
                    btn.textContent = action.label || '';
                    if (typeof action.onClick === 'function') {
                        btn.addEventListener('click', action.onClick);
                    }
                    actionsEl.appendChild(btn);
                });
            } else {
                actionsEl.classList.add('d-none');
            }
        }

        container.appendChild(toastEl);
        const toast = new bootstrap.Toast(toastEl, {
            autohide: options.autohide !== false,
            delay: options.delay ?? 4000,
        });

        toastEl.addEventListener('hidden.bs.toast', () => {
            toastEl.remove();
            if (typeof options.onHidden === 'function') {
                options.onHidden();
            }
        });

        toast.show();
        return {toast, element: toastEl};
    };

    return {
        show(options = {}) {
            if (!toastSupported) {
                if (options && options.message) {
                    alert(options.message);
                }
                return null;
            }

            return createToast(options);
        },
        confirm(message, options = {}) {
            if (!toastSupported) {
                return Promise.resolve(window.confirm(message || ''));
            }

            return new Promise((resolve) => {
                let resolved = false;
                const cleanup = (result) => {
                    if (resolved) return;
                    resolved = true;
                    resolve(result);
                };

                let toastData = null;
                const actions = [
                    {
                        label: options.confirmText || 'Ano',
                        variant: options.variant || 'warning',
                        onClick: () => {
                            toastData?.toast.hide();
                            cleanup(true);
                        },
                    },
                    {
                        label: options.cancelText || 'Ne',
                        variant: 'secondary',
                        onClick: () => {
                            toastData?.toast.hide();
                            cleanup(false);
                        },
                    },
                ];

                toastData = createToast({
                    message,
                    title: options.title || 'Potvrzení',
                    variant: options.variant || 'warning',
                    autohide: false,
                    actions,
                    onHidden: () => cleanup(false),
                });
            });
        },
    };
})();

document.addEventListener('DOMContentLoaded', () => {
    const parseJsonArray = (value) => {
        try {
            return value ? JSON.parse(value) : [];
        } catch (error) {
            console.warn('Invalid JSON payload in data attribute', error);
            return [];
        }
    };

    const flashMessages = {
        success: parseJsonArray(document.body.dataset.flashSuccess),
        error: parseJsonArray(document.body.dataset.flashError),
    };

    flashMessages.success.forEach((msg) => {
        adminNotify.show({
            message: msg,
            title: 'Hotovo',
            variant: 'success',
        });
    });

    flashMessages.error.forEach((msg) => {
        adminNotify.show({
            message: msg,
            title: 'Chyba',
            variant: 'danger',
            autohide: false,
        });
    });

    document.querySelectorAll('.menu-toggle').forEach((toggle) => {
        const targetSelector = toggle.getAttribute('data-bs-target');
        const target = targetSelector ? document.querySelector(targetSelector) : null;
        const icon = toggle.querySelector('.menu-chevron');

        if (!target) {
            return;
        }

        const collapse = new bootstrap.Collapse(target, {toggle: false});

        const updateIcon = () => {
            if (!icon) return;
            icon.classList.toggle('rotate', target.classList.contains('show'));
        };

        target.addEventListener('shown.bs.collapse', updateIcon);
        target.addEventListener('hidden.bs.collapse', updateIcon);
        toggle.addEventListener('click', (event) => {
            event.preventDefault();
            collapse.toggle();
        });

        updateIcon();
    });

    const buildConfirmOptions = (element) => ({
        title: element.dataset.confirmTitle || 'Potvrzení',
        confirmText: element.dataset.confirmConfirm || 'Ano',
        cancelText: element.dataset.confirmCancel || 'Ne',
        variant: element.dataset.confirmVariant || 'danger',
    });

    const attachConfirmHandlers = (scope = document) => {
        scope.querySelectorAll('[data-confirm]').forEach((element) => {
            if (element.dataset.confirmAttached === '1') {
                return;
            }

            const message = element.getAttribute('data-confirm') || 'Opravdu pokračovat?';
            const options = buildConfirmOptions(element);

            if (element.tagName === 'FORM') {
                element.addEventListener('submit', async (event) => {
                    if (element.dataset.confirmHandled === '1') {
                        return;
                    }

                    event.preventDefault();
                    const confirmed = await adminNotify.confirm(message, options);
                    if (confirmed) {
                        element.dataset.confirmHandled = '1';
                        element.submit();
                    }
                });
            } else {
                element.addEventListener('click', async (event) => {
                    if (element.dataset.confirmHandled === '1') {
                        return;
                    }

                    event.preventDefault();
                    const confirmed = await adminNotify.confirm(message, options);
                    if (confirmed) {
                        element.dataset.confirmHandled = '1';
                        if (element.tagName === 'A' && element.getAttribute('href')) {
                            window.location.href = element.getAttribute('href');
                        } else if (element.tagName === 'BUTTON' && element.type === 'submit' && element.form) {
                            element.form.submit();
                        }
                    }
                });
            }

            element.dataset.confirmAttached = '1';
        });
    };

    const appendAjaxParam = (url) => {
        try {
            const parsed = new URL(url, window.location.origin);
            parsed.searchParams.set('ajax', '1');
            return parsed.toString();
        } catch (error) {
            return window.location.href;
        }
    };

    const normalizeUrl = (url) => {
        try {
            const parsed = new URL(url, window.location.origin);
            parsed.searchParams.delete('ajax');
            return parsed.pathname + (parsed.search || '');
        } catch (error) {
            return window.location.pathname + window.location.search;
        }
    };

    const escapeHtml = (value) => {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    const renderActions = (actions = [], size = 'sm') => {
        if (!actions.length) return '';

        const buttons = actions.map((action) => {
            const tooltip = action.title || action.label || '';
            if (action.type === 'link') {
                return `
                    <a href="${escapeHtml(action.href)}"
                       class="btn btn-outline-${escapeHtml(action.variant || 'secondary')} btn-icon"
                       title="${escapeHtml(tooltip)}"
                       aria-label="${escapeHtml(tooltip)}">
                        ${action.icon ? `<i class="bi ${escapeHtml(action.icon)}"></i>` : ''}
                    </a>`;
            }

            if (action.type === 'form') {
                const hidden = action.hidden || {};
                const hiddenInputs = Object.entries(hidden)
                    .map(([name, value]) => `<input type="hidden" name="${escapeHtml(name)}" value="${escapeHtml(value)}">`)
                    .join('');

                return `
                    <form method="${escapeHtml(action.method || 'post')}"
                          action="${escapeHtml(action.action)}"
                          class="d-inline"
                          ${action.ajax ? 'data-ajax-delete="1"' : ''}
                          ${action.confirm ? `data-confirm="${escapeHtml(action.confirm)}"` : ''}>
                        ${hiddenInputs}
                        <button type="submit"
                                class="btn btn-outline-${escapeHtml(action.variant || 'secondary')} btn-icon"
                                title="${escapeHtml(tooltip)}"
                                aria-label="${escapeHtml(tooltip)}">
                            ${action.icon ? `<i class="bi ${escapeHtml(action.icon)}"></i>` : ''}
                        </button>
                    </form>`;
            }

            return '';
        }).join('');

        return `<div class="btn-group btn-group-${escapeHtml(size)}" role="group">${buttons}</div>`;
    };

    const renderPagination = (pagination) => {
        if (!pagination || pagination.pages <= 1) return '';

        const pageLinks = (pagination.page_numbers || []).map((page) => {
            const active = page === pagination.page ? 'active' : '';
            const url = pagination.page_urls?.[page] || '#';
            return `<li class="page-item ${active}"><a class="page-link fw-semibold" href="${escapeHtml(url)}">${escapeHtml(page)}</a></li>`;
        }).join('');

        return `
            <nav aria-label="Stránkování" class="mt-3">
                <ul class="pagination pagination-sm justify-content-center pagination-soft">
                    <li class="page-item ${pagination.has_prev ? '' : 'disabled'}">
                        <a class="page-link d-flex align-items-center gap-2" href="${escapeHtml(pagination.prev_url || '#')}" aria-label="Předchozí">
                            <i class="bi bi-chevron-left"></i>
                            <span class="d-none d-md-inline">Předchozí</span>
                        </a>
                    </li>
                    ${pageLinks}
                    <li class="page-item ${pagination.has_next ? '' : 'disabled'}">
                        <a class="page-link d-flex align-items-center gap-2" href="${escapeHtml(pagination.next_url || '#')}" aria-label="Další">
                            <span class="d-none d-md-inline">Další</span>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>`;
    };

    const ajaxRenderers = {
        'admin/terms/_list.twig': (context = {}) => {
            if (!context.items || context.items.length === 0) {
                return '<div class="alert alert-info">Zatím zde nejsou žádné termy.</div>';
            }

            const types = context.types || {};
            const contentTypes = context.content_types || {};
            const currentType = context.current_term_type || null;

            const rows = context.items.map((item) => {
                const allowedFor = Array.isArray(item.allowed_for) ? item.allowed_for : [];
                const contentBadges = allowedFor.length
                    ? allowedFor.map((typeKey) => `<span class="badge text-bg-light text-body border">${escapeHtml(contentTypes[typeKey]?.name || typeKey)}</span>`).join('')
                    : '<span class="text-muted small">Vše</span>';

                const actions = renderActions([
                    {
                        type: 'link',
                        href: `/admin/terms/${item.id}/edit`,
                        variant: 'primary',
                        icon: 'bi-pencil',
                        label: 'Upravit',
                    },
                    {
                        type: 'form',
                        action: `/admin/terms/${item.id}/delete`,
                        variant: 'danger',
                        confirm: 'Opravdu smazat tento term?',
                        icon: 'bi-trash',
                        ajax: true,
                        hidden: {
                            redirect: context.pagination?.current_url || '',
                            type: currentType?.key || item.type,
                        },
                    },
                ]);

                return `
                    <tr>
                        <td class="fw-semibold">
                            <span class="badge text-bg-light text-body border me-2">${escapeHtml(types[item.type] || item.type)}</span>
                            ${escapeHtml(item.name)}
                            <div class="text-muted small mt-1"><code>${escapeHtml(item.slug)}</code></div>
                        </td>
                        <td>${contentBadges}</td>
                        <td>${item.updated_at_formatted ? escapeHtml(item.updated_at_formatted) : ''}</td>
                        <td class="text-end">${actions}</td>
                    </tr>`;
            }).join('');

            return `
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Název</th>
                                <th>Typy obsahu</th>
                                <th>Upraveno</th>
                                <th class="text-end">Akce</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
                ${renderPagination(context.pagination)}`;
        },
        'admin/content/_list.twig': (context = {}) => {
            const counts = context.counts || {};
            const statuses = [
                {key: 'published', label: 'Publikované'},
                {key: 'draft', label: 'Koncepty'},
                {key: 'trash', label: 'Koš'},
                {key: 'all', label: 'Vše'},
            ];
            const currentStatus = context.current_status || 'all';
            const statusTabs = statuses.map((status) => `
                <li class="nav-item">
                    <a class="nav-link ${currentStatus === status.key ? 'active' : ''}"
                       href="/admin/content/${context.current_type?.slug || ''}${status.key !== 'all' ? `?status=${status.key}` : ''}"
                       data-ajax-link>
                        ${escapeHtml(status.label)}
                        <span class="badge text-bg-light ms-1">${counts[status.key] ?? 0}</span>
                    </a>
                </li>`).join('');

            if (!context.items || context.items.length === 0) {
                let emptyMessage = 'Zatím zde není žádný obsah.';
                if (currentStatus === 'published') emptyMessage = 'Zatím zde není žádný publikovaný obsah.';
                if (currentStatus === 'draft') emptyMessage = 'Zatím zde nejsou žádné koncepty.';
                if (currentStatus === 'trash') emptyMessage = 'Koš je prázdný.';

                return `<ul class="nav nav-pills mb-3">${statusTabs}</ul><div class="alert alert-info">${escapeHtml(emptyMessage)}</div>`;
            }

            const rows = context.items.map((item) => {
                const actions = renderActions([
                    {
                        type: 'link',
                        href: `/admin/content/${context.current_type?.slug || ''}/${item.id}/edit`,
                        variant: 'primary',
                        icon: 'bi-pencil',
                        label: 'Upravit',
                    },
                    {
                        type: 'form',
                        action: `/admin/content/${context.current_type?.slug || ''}/${item.id}/delete`,
                        variant: 'danger',
                        confirm: 'Opravdu smazat tento obsah?',
                        icon: 'bi-trash',
                        ajax: true,
                        hidden: {redirect: context.pagination?.current_url || ''},
                    },
                ]);

                const statusBadge = item.status === 'published'
                    ? '<span class="badge text-bg-success">Publ.</span>'
                    : '<span class="badge text-bg-secondary">Draft</span>';

                return `
                    <tr>
                        <td class="fw-semibold">
                            <div class="d-flex align-items-center gap-2">${statusBadge}<span>${escapeHtml(item.title)}</span></div>
                            <div class="small text-muted mt-1"><code>${escapeHtml(item.slug)}</code></div>
                        </td>
                        <td>${item.updated_at_formatted ? escapeHtml(item.updated_at_formatted) : ''}</td>
                        <td class="text-end">${actions}</td>
                    </tr>`;
            }).join('');

            return `
                <ul class="nav nav-pills mb-3">${statusTabs}</ul>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr><th>Název</th><th>Upraveno</th><th class="text-end">Akce</th></tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
                ${renderPagination(context.pagination)}`;
        },
        'admin/comments/_list.twig': (context = {}) => {
            const counts = context.counts || {};
            const statuses = [
                {key: 'all', label: 'Vše'},
                {key: 'pending', label: 'Čeká na schválení'},
                {key: 'approved', label: 'Schválené'},
                {key: 'trash', label: 'Koš'},
            ];

            const currentStatus = context.status || 'pending';
            const isTrash = currentStatus === 'trash';

            const headerActions = isTrash
                ? `<button type="submit" class="btn btn-danger" form="emptyCommentsTrash" data-confirm="Chcete nenávratně smazat všechny komentáře v koši?" data-confirm-title="Trvalé smazání"><i class="bi bi-trash me-1"></i>Vysypat koš</button>`
                : '';

            const pageHeader = `
                <div class="page-header">
                    <h1 class="h3 mb-0">Komentáře</h1>
                    ${headerActions ? `<div class="page-header-actions">${headerActions}</div>` : ''}
                </div>`;

            const emptyTrashForm = isTrash
                ? '<form id="emptyCommentsTrash" action="/admin/comments/trash/empty" method="post"></form>'
                : '';
            const statusTabs = statuses.map((status) => `
                <li class="nav-item">
                    <a href="/admin/comments?status=${status.key}" class="nav-link ${currentStatus === status.key ? 'active' : ''}" data-ajax-link>
                        ${escapeHtml(status.label)}
                        <span class="badge text-bg-light ms-1">${counts[status.key] ?? 0}</span>
                    </a>
                </li>`).join('');

            if (!context.comments || context.comments.length === 0) {
                return `${pageHeader}${emptyTrashForm}<ul class="nav nav-pills mb-3">${statusTabs}</ul><div class="alert alert-info">Žádné komentáře pro zvolený filtr.</div>`;
            }

            const contentMap = context.content_map || {};
            const parentMap = context.parent_map || {};
            const childCounts = context.child_counts || {};

            const rows = context.comments.map((comment) => {
                const parent = comment.parent_id ? parentMap[comment.parent_id] : null;
                const replyCount = childCounts[comment.id] || 0;
                const content = contentMap[comment.content_id];
                const statusBadge = currentStatus === 'trash'
                    ? '<span class="badge bg-secondary-subtle text-secondary">V koši</span>'
                    : (comment.status === 'approved'
                        ? '<span class="badge bg-success-subtle text-success">Schváleno</span>'
                        : '<span class="badge bg-warning-subtle text-warning">Čeká</span>');

                const approveForm = comment.status !== 'approved' && currentStatus !== 'trash'
                    ? `<form method="post" action="/admin/comments/${comment.id}/approve" class="d-inline ms-2"><button type="submit" class="btn btn-sm btn-success">Schválit</button></form>`
                    : '';

                const deleteConfirm = currentStatus === 'trash'
                    ? 'Opravdu nenávratně smazat tento komentář?'
                    : 'Přesunout komentář do koše?';

                const parentInfo = comment.parent_id
                    ? `<div class="d-flex align-items-center gap-2"><span class="badge bg-secondary-subtle text-secondary">Odpověď</span><span>#${comment.parent_id}</span></div>${parent ? `<div class="text-truncate mt-1" title="${escapeHtml(parent.body)}">${escapeHtml(parent.body.slice(0, 90))}${parent.body.length > 90 ? '…' : ''}</div>` : ''}`
                    : '<span class="badge bg-primary-subtle text-primary">Rodič</span>';

                const repliesInfo = replyCount > 0 ? `<div class="mt-1">${replyCount} reakce</div>` : '';

                const contentInfo = content
                    ? `<div class="fw-semibold">${escapeHtml(content.title)}</div><div>${escapeHtml(content.type)}</div>`
                    : '<em>Nenalezeno</em>';

                const bodyPreview = comment.body.length > 180
                    ? `${escapeHtml(comment.body.slice(0, 180))}…`
                    : escapeHtml(comment.body);

                return `
                    <tr>
                        <td>
                            <div class="fw-semibold">${escapeHtml(comment.author_name || 'Anonym')}</div>
                            <div class="text-muted small">${escapeHtml(comment.author_email || '')}</div>
                            <div class="text-muted small">${escapeHtml(comment.created_at_formatted || '')}</div>
                        </td>
                        <td>${bodyPreview}</td>
                        <td class="text-muted small">${parentInfo}${repliesInfo}</td>
                        <td class="text-muted small">${contentInfo}</td>
                        <td>${statusBadge}</td>
                        <td class="text-end">
                            <a href="/admin/comments/${comment.id}/edit" class="btn btn-sm btn-outline-secondary">Detail</a>
                            ${approveForm}
                            <form method="post" action="/admin/comments/${comment.id}/delete" class="d-inline ms-2" data-confirm="${deleteConfirm}" data-confirm-title="${currentStatus === 'trash' ? 'Trvalé smazání' : 'Přesun do koše'}">
                                <button type="submit" class="btn btn-sm ${currentStatus === 'trash' ? 'btn-outline-danger' : 'btn-outline-warning'}">${currentStatus === 'trash' ? 'Smazat navždy' : 'Do koše'}</button>
                            </form>
                        </td>
                    </tr>`;
            }).join('');

            return `
                ${pageHeader}
                ${emptyTrashForm}
                <ul class="nav nav-pills mb-3">${statusTabs}</ul>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr><th>Autor</th><th>Text</th><th>Vazba</th><th>Obsah</th><th>Stav</th><th class="text-end">Akce</th></tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
                ${renderPagination(context.pagination)}`;
        },
        'admin/users/_list.twig': (context = {}) => {
            const roles = context.roles || {};
            const rows = (context.users || []).map((user) => {
                const actions = renderActions([
                    {
                        type: 'link',
                        href: `/admin/users/${user.id}/edit`,
                        variant: 'primary',
                        icon: 'bi-pencil',
                        label: 'Upravit',
                    },
                    {
                        type: 'form',
                        action: `/admin/users/${user.id}/delete`,
                        variant: 'danger',
                        confirm: 'Opravdu smazat tohoto uživatele?',
                        icon: 'bi-trash',
                        ajax: true,
                        hidden: {redirect: context.pagination?.current_url || ''},
                    },
                ], 'sm');

                const banForm = user.is_banned
                    ? `<form method="post" action="/admin/users/${user.id}/unban" class="d-inline"><button type="submit" class="btn btn-sm btn-outline-success">Odblokovat</button></form>`
                    : `<form method="post" action="/admin/users/${user.id}/ban" class="d-inline d-flex align-items-center gap-2"><input type="text" name="reason" class="form-control form-control-sm w-auto" placeholder="Důvod" aria-label="Důvod blokace"><button type="submit" class="btn btn-sm btn-outline-warning">Zablokovat</button></form>`;

                const statusBadge = user.is_banned
                    ? `<span class="badge bg-danger">Zablokován</span>${user.ban_reason ? `<div class="small text-muted">${escapeHtml(user.ban_reason)}</div>` : ''}`
                    : '<span class="badge bg-success">Aktivní</span>';

                return `
                    <tr>
                        <td>${escapeHtml(user.email)}</td>
                        <td>${escapeHtml(roles[user.role] || user.role)}</td>
                        <td>${statusBadge}</td>
                        <td class="text-end">
                            <div class="d-flex flex-wrap justify-content-end gap-2 align-items-center">${actions}${banForm}</div>
                        </td>
                    </tr>`;
            }).join('');

            const body = rows || '<tr><td colspan="4" class="text-center p-4 text-muted">Zatím žádní uživatelé.</td></tr>';

            return `
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>E-mail</th><th>Role</th><th>Stav</th><th class="text-end">Akce</th></tr>
                            </thead>
                            <tbody>${body}</tbody>
                        </table>
                    </div>
                </div>
                ${renderPagination(context.pagination)}`;
        },
        'admin/media/_list.twig': (context = {}) => {
            const items = context.items || [];
            const images = items.filter((item) => item.is_image);
            const files = items.filter((item) => !item.is_image);

            const imageCards = images.length
                ? images.map((item) => {
                    const badge = item.webp_filename
                        ? '<span class="badge bg-success position-absolute top-0 end-0 m-2 badge-webp">WebP</span>'
                        : '<span class="badge bg-warning text-dark position-absolute top-0 end-0 m-2 badge-webp">Bez WebP</span>';

                    const actions = renderActions([
                        {
                            type: 'link',
                            href: '#',
                            variant: 'primary',
                            icon: 'bi-pencil',
                            label: 'Upravit',
                            title: 'Otevřít detail pro úpravy',
                        },
                        {
                            type: 'form',
                            action: `/admin/media/${item.id}/delete`,
                            variant: 'danger',
                            confirm: 'Opravdu smazat tento soubor?',
                            icon: 'bi-trash',
                            ajax: true,
                        },
                    ]);

                    const previewUrl = `/${item.path}/${item.webp_filename || item.filename}`;
                    const created = item.created_at || '';
                    const updated = item.updated_at || '';

                    return `
                        <div class="col">
                            <div class="card h-100 media-card position-relative" role="button" tabindex="0"
                                 data-media-id="${item.id}"
                                 data-name="${escapeHtml(item.original_name || item.filename)}"
                                 data-alt="${escapeHtml(item.alt || '')}"
                                 data-mime="${escapeHtml(item.mime_type || '')}"
                                 data-size="${escapeHtml(item.size || '')}"
                                 data-created="${escapeHtml(created)}"
                                 data-path="/${escapeHtml(item.path || '')}"
                                 data-filename="${escapeHtml(item.filename || '')}"
                                 data-webp-filename="${escapeHtml(item.webp_filename || '')}"
                                 data-preview-url="${escapeHtml(previewUrl)}">
                                ${badge}
                                <img src="${escapeHtml(previewUrl)}" class="card-img-top" alt="${escapeHtml(item.alt || '')}">
                                <div class="card-body">
                                    <h6 class="card-title small mb-1">${escapeHtml(item.original_name || item.filename)}</h6>
                                    <div class="text-muted small">${escapeHtml(item.mime_type || '')} · ${escapeHtml(item.size)} B</div>
                                    <div class="text-muted small">Aktualizováno: ${escapeHtml(updated)}</div>
                                </div>
                                <div class="card-footer bg-light text-end">${actions}</div>
                            </div>
                        </div>`;
                }).join('')
                : '<div class="col"><div class="alert alert-info">Zatím žádné obrázky.</div></div>';

            const fileRows = files.length
                ? files.map((item) => {
                    const actions = renderActions([
                        {
                            type: 'link',
                            href: `/${item.path}/${item.filename}`,
                            variant: 'secondary',
                            icon: 'bi-box-arrow-up-right',
                            label: 'Otevřít',
                        },
                        {
                            type: 'form',
                            action: `/admin/media/${item.id}/delete`,
                            variant: 'danger',
                            confirm: 'Opravdu smazat tento soubor?',
                            icon: 'bi-trash',
                            ajax: true,
                        },
                    ]);

                    return `
                        <tr>
                            <td class="fw-semibold">${escapeHtml(item.original_name || item.filename)}</td>
                            <td>${escapeHtml(item.mime_type || '')}</td>
                            <td>${escapeHtml(item.size)} B</td>
                            <td>${escapeHtml(item.created_at || '')}</td>
                            <td class="text-end">${actions}</td>
                        </tr>`;
                }).join('')
                : '<tr><td colspan="5"> <div class="alert alert-info mb-0">Zatím žádné nahrané soubory.</div></td></tr>';

            return `
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#images" type="button" role="tab">Obrázky</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#files" type="button" role="tab">Soubory</button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="images" role="tabpanel">
                        <div class="row row-cols-1 row-cols-md-3 g-3">${imageCards}</div>
                    </div>
                    <div class="tab-pane fade" id="files" role="tabpanel">
                        ${files.length ? `<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Název</th><th>MIME</th><th>Velikost</th><th>Vytvořeno</th><th class="text-end">Akce</th></tr></thead><tbody>${fileRows}</tbody></table></div>` : fileRows}
                    </div>
                </div>
                ${renderPagination(context.pagination)}`;
        },
    };

    ajaxRenderers['admin/content/_container.twig'] = (context = {}) => {
        const type = context.current_type || {};
        const isTrash = context.current_status === 'trash';
        const title = type.plural_name || 'Obsah';
        const slug = type.slug || '';
        const actionLabel = `Nový ${(type.name || 'obsah').toLowerCase()}`;

        const headerActions = isTrash
            ? `<button type="submit" class="btn btn-danger" form="emptyContentTrash" data-confirm="Opravdu vysypat koš? Tato akce nenávratně smaže všechny položky." data-confirm-title="Trvalé smazání"><i class="bi bi-trash me-1"></i>Vysypat koš</button>`
            : `<a href="/admin/content/${slug}/create" class="btn btn-primary"><i class="bi bi-plus me-1"></i>${escapeHtml(actionLabel)}</a>`;

        const pageHeader = `
            <div class="page-header">
                <h1 class="h3 mb-0">${escapeHtml(title)}</h1>
                <div class="page-header-actions">${headerActions}</div>
            </div>`;

        const emptyTrashForm = isTrash
            ? `<form id="emptyContentTrash" action="/admin/content/${slug}/trash/empty" method="post"></form>`
            : '';

        const listRenderer = ajaxRenderers['admin/content/_list.twig'];
        const listHtml = typeof listRenderer === 'function' ? listRenderer(context) : '';

        return `${pageHeader}${emptyTrashForm}${listHtml}`;
    };

    const loadAjaxContainer = async (container, targetUrl, options = {}) => {
        if (!targetUrl || !container) return;

        const requestUrl = appendAjaxParam(targetUrl);
        container.classList.add('opacity-50');

        try {
            const response = await fetch(requestUrl, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            });

            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                const bodyPreview = (await response.text()).slice(0, 2000);
                console.error('Neočekávaná AJAX odpověď', bodyPreview);
                throw new Error('Server vrátil neočekávanou odpověď.');
            }

            const data = await response.json();
            if (!response.ok || data.error || data.success === false) {
                throw new Error(data.error || data.message || 'Načtení selhalo.');
            }

            const renderer = ajaxRenderers[data.view?.template];
            if (!renderer) {
                throw new Error('Chybí renderer pro AJAX odpověď.');
            }

            const viewHtml = renderer(data.view?.context || {});
            container.innerHTML = viewHtml;
            const stateUrl = data.state_url ? normalizeUrl(data.state_url) : normalizeUrl(targetUrl);
            if (stateUrl) {
                container.dataset.currentUrl = stateUrl;
                if (!options.silent) {
                    window.history.replaceState({}, '', stateUrl);
                }
            }

            attachConfirmHandlers(container);
            attachAjaxDeleteHandlers(container);
        } catch (error) {
            adminNotify.show({
                title: 'Chyba',
                message: error.message || 'Načtení selhalo.',
                variant: 'danger',
                autohide: false,
            });
        } finally {
            container.classList.remove('opacity-50');
        }
    };

    const attachAjaxDeleteHandlers = (scope = document) => {
        scope.querySelectorAll('form[data-ajax-delete]').forEach((form) => {
            if (form.dataset.ajaxDeleteAttached === '1') {
                return;
            }

            form.addEventListener('submit', async (event) => {
                if (event.defaultPrevented) {
                    return;
                }

                event.preventDefault();
                const targetUrl = form.getAttribute('action') || window.location.href;
                const method = (form.getAttribute('method') || 'post').toUpperCase();
                const container = form.closest('[data-ajax-container]');
                const row = form.closest('tr') || form.closest('.media-card');

                try {
                    const response = await fetch(appendAjaxParam(targetUrl), {
                        method,
                        body: new FormData(form),
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                    });

                    const data = await response.json();

                    if (!response.ok || data.error || data.success === false) {
                        throw new Error(data.error || data.message || 'Akce selhala.');
                    }

                    if (row) {
                        row.remove();
                    }

                    if (container) {
                        const reloadUrl = container.dataset.currentUrl || targetUrl;
                        loadAjaxContainer(container, reloadUrl, {silent: true});
                    }

                    adminNotify.show({
                        title: 'Hotovo',
                        message: data.message || 'Záznam byl odstraněn.',
                        variant: 'success',
                    });
                } catch (error) {
                    adminNotify.show({
                        title: 'Chyba',
                        message: error.message || 'Akce se nezdařila.',
                        variant: 'danger',
                        autohide: false,
                    });
                }
            });

            form.dataset.ajaxDeleteAttached = '1';
        });
    };

    const initAjaxContainers = () => {
        document.querySelectorAll('[data-ajax-container]').forEach((container) => {
            if (!container.dataset.currentUrl) {
                container.dataset.currentUrl = window.location.pathname + window.location.search;
            }

            container.addEventListener('click', (event) => {
                const link = event.target.closest('.pagination a.page-link, [data-ajax-link]');
                if (!link || !container.contains(link)) {
                    return;
                }

                const listItem = link.closest('.page-item');
                if (listItem && listItem.classList.contains('disabled')) {
                    event.preventDefault();
                    return;
                }

                const href = link.getAttribute('href');
                if (!href) {
                    return;
                }

                event.preventDefault();
                loadAjaxContainer(container, href);
            });
        });
    };

    attachConfirmHandlers();
    attachAjaxDeleteHandlers();
    initAjaxContainers();
});
