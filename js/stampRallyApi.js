const StampRallyApi = {
    toPublicPath(path) {
        const href = window.location?.pathname || '';
        let basePath = '';

        if (typeof window.__APP_BASE_PATH__ === 'string') {
            basePath = window.__APP_BASE_PATH__;
        } else if (href !== '' && href !== '/' && href !== '/index.html') {
            basePath = href.endsWith('/index.html')
                ? href.slice(0, -'/index.html'.length)
                : href.replace(/\/+$/, '');
        }

        const normalizedBasePath = basePath === '' || basePath === '/'
            ? ''
            : `/${basePath.replace(/^\/+|\/+$/g, '')}`;
        const normalizedPath = String(path || '');

        if (/^https?:\/\//.test(normalizedPath) || normalizedPath.startsWith('//')) {
            return normalizedPath;
        }

        if (normalizedPath === '' || normalizedPath === '/') {
            return normalizedBasePath === '' ? '/' : `${normalizedBasePath}/`;
        }

        if (normalizedPath.startsWith('/') && normalizedBasePath !== '' && (normalizedPath === normalizedBasePath || normalizedPath.startsWith(`${normalizedBasePath}/`))) {
            return normalizedPath;
        }

        return `${normalizedBasePath}/${normalizedPath.replace(/^\/+/, '')}`;
    },

    async initialize() {
        const response = await fetch(this.toPublicPath('/api/stamp-rally/init'), {
            method: 'GET',
            credentials: 'include',
            headers: {
                Accept: 'application/json'
            }
        });
        const result = await response.json();

        if (result && result.requiresLogin && result.loginUrl) {
            result.loginUrl = this.toPublicPath(result.loginUrl);
            window.location.href = result.loginUrl;
        }

        if (!response.ok && !(result && result.requiresLogin)) {
            throw new Error(result?.message || result?.error || 'Failed to initialize stamp rally.');
        }

        return result;
    },

    async saveChoice(choice) {
        // Future: await fetch('/api/stamp-rally/choice', { method: 'POST', body: JSON.stringify({ choice }) })
        return { choice };
    },

    async saveEnding(endingId) {
        // Future: await fetch('/api/stamp-rally/ending', { method: 'POST', body: JSON.stringify({ endingId }) })
        return { endingId };
    }
};
