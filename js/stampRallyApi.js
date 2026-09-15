const StampRallyApi = {
    async initialize() {
        const response = await fetch('/api/stamp-rally/init', {
            method: 'GET',
            credentials: 'include',
            headers: {
                Accept: 'application/json'
            }
        });
        const result = await response.json();

        if (result && result.requiresLogin && result.loginUrl) {
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
