const StampRallyDataLoader = {
    files: {
        ENDING_MASTER: 'endingMaster.json',
        CHARACTERS: 'characters.json',
        DIALOGUES: 'dialogues.json',
        DIALOGUES_LOOP2: 'dialoguesLoop2.json',
        CHOICES_DATA: 'choices.json'
    },

    resolvePath(basePath, fileName) {
        const normalizedBase = basePath.endsWith('/') ? basePath : `${basePath}/`;
        return `${normalizedBase}${fileName}`;
    },

    async loadJson(path) {
        const response = await fetch(path);
        if (!response.ok) {
            throw new Error(`Failed to load ${path}: ${response.status}`);
        }
        return response.json();
    },

    async loadStampRallyData(basePath = 'json') {
        const entries = await Promise.all(
            Object.entries(this.files).map(async ([key, fileName]) => {
                const data = await this.loadJson(this.resolvePath(basePath, fileName));
                return [key, data];
            })
        );

        const data = Object.fromEntries(entries);
        window.StampRallyData = data;
        return data;
    }
};
