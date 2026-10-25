import '../css/main.css';

export const GlitterConnect = {
    identifier: 'glitter-connect',
    apiBase: '/api/modules/glitter-connect',
};

if (typeof window !== 'undefined') {
    (window as Window & { GlitterConnect?: typeof GlitterConnect }).GlitterConnect = GlitterConnect;

    type G7CoreApiClient = {
        get: (url: string, config?: {headers?: Record<string, string>; params?: Record<string, unknown>}) => Promise<any>;
        post: (url: string, body?: unknown, config?: {headers?: Record<string, string>}) => Promise<any>;
        put: (url: string, body?: unknown, config?: {headers?: Record<string, string>}) => Promise<any>;
        patch: (url: string, body?: unknown, config?: {headers?: Record<string, string>}) => Promise<any>;
        delete: (url: string, config?: {headers?: Record<string, string>; data?: unknown}) => Promise<any>;
    };

    const request = async (url: string, method: string, body?: Record<string, FormDataEntryValue>): Promise<any> => {
        const api = (window as Window & {G7Core?: {api?: G7CoreApiClient}}).G7Core?.api;
        if (!api) throw new Error('G7Core API client is unavailable.');

        const endpoint = `${GlitterConnect.apiBase}${url}`;
        const config = {headers: {'X-Requested-With': 'XMLHttpRequest'}};
        switch (method.toUpperCase()) {
            case 'GET':
                return api.get(endpoint, { ...config, ...(body && {params: body}) });
            case 'POST':
                return api.post(endpoint, body, config);
            case 'PUT':
                return api.put(endpoint, body, config);
            case 'PATCH':
                return api.patch(endpoint, body, config);
            case 'DELETE':
                return api.delete(endpoint, {...config, ...(body && {data: body})});
            default:
                throw new Error(`Unsupported request method: ${method}`);
        }
    };

    const errorMessage = (error: unknown): string => {
        if (!error || typeof error !== 'object') return '';
        const apiError = error as {message?: unknown; response?: {data?: {message?: unknown}}};
        if (typeof apiError.response?.data?.message === 'string') return apiError.response.data.message;
        return typeof apiError.message === 'string' ? apiError.message : '';
    };

    const showError = (error: unknown, output?: HTMLOutputElement | null): void => {
        const message = errorMessage(error);
        if (!message) return;
        if (output) {
            output.textContent = message;
            return;
        }
        (window as Window & {G7Core?: {toast?: {error: (message: string) => void}}}).G7Core?.toast?.error(message);
    };

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-glitter-connect-form]')) return;
        event.preventDefault();
        const endpoint = form.dataset.endpoint;
        if (!endpoint) return;
        const data = Object.fromEntries(new FormData(form).entries());
        const output = form.querySelector<HTMLOutputElement>('output');
        try {
            const result = await request(endpoint, form.dataset.method ?? 'POST', data);
            if (result.data?.secret) {
                if (output) output.textContent = `${result.data.endpoint_key ?? ''} ${result.data.secret}`;
                form.reset();
                return;
            }
            if (output) output.textContent = result.data?.status === 'configured' ? 'configured' : '';
            if (form.dataset.method === 'PATCH') {
                window.location.reload();
                return;
            }
            form.reset();
            window.location.reload();
        } catch (error) {
            showError(error, output);
        }
    });

    document.addEventListener('change', (event) => {
        const select = event.target;
        if (!(select instanceof HTMLSelectElement)) return;
        if (select.matches('[data-glitter-signature-required]')) {
            const secret = select.form?.querySelector<HTMLInputElement>('input[name="secret"]');
            if (secret) secret.required = select.value === '1';
        }
        if (select.matches('[data-outbound-signature-version]')) {
            const output = select.form?.querySelector<HTMLOutputElement>('[data-outbound-signature-warning]');
            const translate = (window as Window & {G7Core?: {t?: (key: string) => string}}).G7Core?.t;
            if (select.dataset.currentVersion === 'v1' && select.value === 'v0') {
                if (output) output.textContent = translate?.('glitter-connect.admin.subscriptions.signature_downgrade_warning') ?? '';
            } else if (output) output.textContent = '';
        }
    });

    document.addEventListener('click', async (event) => {
        const button = (event.target as Element | null)?.closest<HTMLButtonElement>('[data-glitter-connect-action]');
        if (!button || button.disabled) return;
        const endpoint = button.dataset.endpoint;
        const method = button.dataset.method ?? 'POST';
        if (!endpoint) return;
        button.disabled = true;
        try {
            const body = button.dataset.payloadStatus ? {status: button.dataset.payloadStatus} : undefined;
            const result = await request(endpoint, method, body);
            if (result.data?.secret) {
                const output = button.parentElement?.querySelector<HTMLOutputElement>('output');
                if (output) output.textContent = result.data.secret;
                button.disabled = false;
                return;
            }
            if (endpoint.endsWith('/test')) {
                const translate = (window as Window & {G7Core?: {t?: (key: string) => string}}).G7Core?.t;
                const message = translate?.('glitter-connect.admin.connections.test_result')
                    ?? 'Connection configuration exists; external connectivity was not tested.';
                button.parentElement?.querySelector<HTMLOutputElement>('output')?.replaceChildren(document.createTextNode(message));
            } else window.location.reload();
        } catch (error) {
            const output = button.parentElement?.querySelector<HTMLOutputElement>('output');
            showError(error, output);
        } finally {
            button.disabled = false;
        }
    });
}
