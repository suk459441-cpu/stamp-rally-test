const StampRallyApi = {
    async initialize() {
        // Future Slim/PHP endpoint: LINE ID must be read from the server-side cookie.
        return null;
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
