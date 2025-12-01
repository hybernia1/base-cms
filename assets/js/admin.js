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
        const parsed = new URL(url, window.location.origin);
        parsed.searchParams.set('ajax', '1');
        return parsed.toString();
    };

    const normalizeUrl = (url) => {
        const parsed = new URL(url, window.location.origin);
        parsed.searchParams.delete('ajax');
        return parsed.pathname + (parsed.search || '');
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

            const data = await response.json();
            if (!response.ok || data.error) {
                throw new Error(data.error || 'Načtení selhalo.');
            }

            container.innerHTML = data.html || '';
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

                    if (!response.ok || data.error) {
                        throw new Error(data.error || 'Akce selhala.');
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
