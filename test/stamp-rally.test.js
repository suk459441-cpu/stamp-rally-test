const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const ROOT = path.resolve(__dirname, '..');
const SCRIPT_DIR = path.join(ROOT, 'js');

function createElement() {
    const children = [];

    return {
        children,
        className: '',
        innerHTML: '',
        innerText: '',
        style: {},
        tagName: 'DIV',
        appendChild(child) {
            children.push(child);
        },
        classList: {
            add() {},
            remove() {}
        },
        closest() {
            return null;
        }
    };
}

function createAudioContext() {
    const node = {
        connect() {},
        start() {},
        stop() {},
        frequency: {
            setValueAtTime() {},
            exponentialRampToValueAtTime() {}
        },
        gain: {
            setValueAtTime() {},
            exponentialRampToValueAtTime() {},
            linearRampToValueAtTime() {}
        }
    };

    return {
        currentTime: 0,
        state: 'running',
        createOscillator() {
            return { ...node };
        },
        createGain() {
            return { ...node };
        },
        resume() {}
    };
}

function runScript(context, filename) {
    const source = fs.readFileSync(path.join(SCRIPT_DIR, filename), 'utf8');
    vm.runInContext(source, context, { filename: path.join(SCRIPT_DIR, filename) });
}

async function loadRally({ search = '', savedState = null, loadApp = true, stubApi = true } = {}) {
    const elements = new Map();
    const storage = new Map();
    const apiCalls = [];

    if (savedState) {
        storage.set('mystery_game_save', JSON.stringify(savedState));
    }

    const context = {
        console,
        URLSearchParams,
        clearTimeout,
        setTimeout(fn) {
            return fn;
        },
        confirm() {
            return true;
        },
        async fetch(requestPath) {
            const filePath = path.join(ROOT, requestPath);
            try {
                const body = await fs.promises.readFile(filePath, 'utf8');
                return {
                    ok: true,
                    status: 200,
                    async json() {
                        return JSON.parse(body);
                    }
                };
            } catch (e) {
                return {
                    ok: false,
                    status: 404,
                    async json() {
                        return null;
                    }
                };
            }
        },
        window: {
            location: {
                search,
                pathname: '/index.html'
            },
            scrollTo() {},
            AudioContext: createAudioContext,
            webkitAudioContext: createAudioContext
        },
        document: {
            body: {
                scrollHeight: 1000
            },
            addEventListener() {},
            createElement,
            getElementById(id) {
                if (!elements.has(id)) {
                    elements.set(id, createElement());
                }
                return elements.get(id);
            },
            querySelectorAll() {
                return [];
            }
        },
        localStorage: {
            getItem(key) {
                return storage.has(key) ? storage.get(key) : null;
            },
            setItem(key, value) {
                storage.set(key, value);
            },
            removeItem(key) {
                storage.delete(key);
            }
        },
        Vue: {
            createApp(definition) {
                context.__appDefinition = definition;
                return {
                    mount(selector) {
                        const instance = {
                            ...definition.data()
                        };

                        Object.entries(definition.computed || {}).forEach(([name, getter]) => {
                            Object.defineProperty(instance, name, {
                                get: getter.bind(instance)
                            });
                        });

                        Object.entries(definition.methods || {}).forEach(([name, method]) => {
                            instance[name] = method.bind(instance);
                        });

                        context.__mountedSelector = selector;
                        context.__app = instance;

                        if (definition.mounted) {
                            context.__mountedResult = definition.mounted.call(instance);
                        }

                        return instance;
                    }
                };
            }
        }
    };

    context.window.window = context.window;
    context.window.document = context.document;
    context.location = context.window.location;
    context.AudioContext = createAudioContext;
    context.webkitAudioContext = createAudioContext;

    vm.createContext(context);
    context.__apiCalls = apiCalls;

    runScript(context, 'dataLoader.js');
    runScript(context, 'audio.js');
    runScript(context, 'stampRallyApi.js');

    if (stubApi) {
        vm.runInContext(`
            StampRallyApi.initialize = async () => {
                globalThis.__apiCalls.push({ method: 'initialize' });
                return globalThis.__initializeResult || null;
            };
            StampRallyApi.saveChoice = async (choices) => {
                globalThis.__apiCalls.push({ method: 'saveChoice', choices });
                return { choices };
            };
            StampRallyApi.acquireStamp = async (token) => {
                globalThis.__apiCalls.push({ method: 'acquireStamp', token });
                return globalThis.__stampResults?.[token] || { ok: true, stamp: 1, stamps: [1] };
            };
            StampRallyApi.saveEnding = async (endingId) => {
                globalThis.__apiCalls.push({ method: 'saveEnding', endingId });
                return { endingId };
            };
        `, context);
    }

    if (loadApp) {
        runScript(context, 'app.js');
        await context.window.StampRallyAppPromise;
        await context.__mountedResult;
    }

    return { context, app: context.__app, elements, storage, apiCalls };
}

module.exports = {
    ROOT,
    SCRIPT_DIR,
    loadRally
};

test('dataLoader.js loads the rally master data onto window', async () => {
    const { context } = await loadRally({ loadApp: false });
    const keys = await vm.runInContext(`
        StampRallyDataLoader.loadStampRallyData().then(() => Object.keys(window.StampRallyData.ENDING_MASTER))
    `, context);

    assert.ok(keys.includes('AAA'));
    assert.ok(keys.includes('AI_SELF_DESTRUCT'));
    assert.equal(vm.runInContext('window.StampRallyData.CHARACTERS.kiriko.name', context), 'キリコ');
    assert.equal(vm.runInContext('window.StampRallyData.CHOICES_DATA[2].options.length', context), 2);
});

test('dialogue JSON uses senderId references instead of embedded sender names', () => {
    for (const fileName of ['dialogues.json', 'dialoguesLoop2.json']) {
        const data = JSON.parse(fs.readFileSync(path.join(ROOT, 'json', fileName), 'utf8'));
        for (const messages of Object.values(data)) {
            for (const message of messages) {
                assert.equal(Object.hasOwn(message, 'sender'), false);
                assert.equal(typeof message.senderId, 'string');
            }
        }
    }
});

test('dataLoader.js normalizes paths and rejects missing JSON', async () => {
    const { context } = await loadRally({ loadApp: false });
    const result = await vm.runInContext(`
        Promise.all([
            StampRallyDataLoader.resolvePath('json', 'choices.json'),
            StampRallyDataLoader.resolvePath('json/', 'choices.json'),
            StampRallyDataLoader.loadJson('json/missing.json').catch((error) => error.message)
        ])
    `, context);

    assert.deepEqual(JSON.parse(JSON.stringify(result)), [
        'json/choices.json',
        'json/choices.json',
        'Failed to load json/missing.json: 404'
    ]);
});

test('API initialize calls the server without sending userId', async () => {
    const { context } = await loadRally({ loadApp: false, stubApi: false });

    context.__apiFetchCalls = [];
    context.fetch = async (requestPath, options) => {
        context.__apiFetchCalls.push({ requestPath, options });
        return {
            ok: true,
            status: 200,
            async json() {
                return {
                    ok: true,
                    created: false,
                    record: { id: 1, value: { LINE_ID: 'U123', loop_count: 1 } }
                };
            }
        };
    };

    const result = await vm.runInContext('StampRallyApi.initialize()', context);

    assert.deepEqual(JSON.parse(JSON.stringify(result)), {
        ok: true,
        created: false,
        record: { id: 1, value: { LINE_ID: 'U123', loop_count: 1 } }
    });
    assert.deepEqual(JSON.parse(JSON.stringify(context.__apiFetchCalls)), [
        {
            requestPath: '/api/stamp-rally/init',
            options: {
                method: 'GET',
                credentials: 'include',
                headers: { Accept: 'application/json' }
            }
        }
    ]);
    assert.equal(JSON.stringify(context.__apiFetchCalls).includes('userId'), false);
});

test('API initialize redirects to LINE login when the server requires login', async () => {
    const { context } = await loadRally({ loadApp: false, stubApi: false });

    context.fetch = async () => ({
        ok: false,
        status: 401,
        async json() {
            return {
                ok: false,
                requiresLogin: true,
                loginUrl: '/auth/line/start'
            };
        }
    });

    const result = await vm.runInContext('StampRallyApi.initialize()', context);

    assert.deepEqual(JSON.parse(JSON.stringify(result)), {
        ok: false,
        requiresLogin: true,
        loginUrl: '/auth/line/start'
    });
    assert.equal(context.window.location.href, '/auth/line/start');
});

test('API initialize uses APP_BASE_PATH-style pathname for init and login URLs', async () => {
    const { context } = await loadRally({ loadApp: false, stubApi: false });

    context.window.location.pathname = '/rally/index.html';
    context.__apiFetchCalls = [];
    context.fetch = async (requestPath, options) => {
        context.__apiFetchCalls.push({ requestPath, options });
        return {
            ok: false,
            status: 401,
            async json() {
                return {
                    ok: false,
                    requiresLogin: true,
                    loginUrl: '/auth/line/start'
                };
            }
        };
    };

    const result = await vm.runInContext('StampRallyApi.initialize()', context);

    assert.equal(context.__apiFetchCalls[0].requestPath, '/rally/api/stamp-rally/init');
    assert.equal(context.window.location.href, '/rally/auth/line/start');
    assert.equal(result.loginUrl, '/rally/auth/line/start');
});

test('API saveChoice posts choices without sending userId', async () => {
    const { context } = await loadRally({ loadApp: false, stubApi: false });

    context.__apiFetchCalls = [];
    context.fetch = async (requestPath, options) => {
        context.__apiFetchCalls.push({ requestPath, options });
        return {
            ok: true,
            status: 200,
            async json() {
                return {
                    ok: true,
                    column: 'loop1_choices',
                    choices: ['A', 'B'],
                    record: { id: 1, value: { loop1_choices: 'A,B' } }
                };
            }
        };
    };

    const result = await vm.runInContext(`StampRallyApi.saveChoice(['A', 'B'])`, context);

    assert.deepEqual(JSON.parse(JSON.stringify(result)), {
        ok: true,
        column: 'loop1_choices',
        choices: ['A', 'B'],
        record: { id: 1, value: { loop1_choices: 'A,B' } }
    });
    assert.deepEqual(JSON.parse(JSON.stringify(context.__apiFetchCalls)), [
        {
            requestPath: '/api/stamp-rally/choice',
            options: {
                method: 'POST',
                credentials: 'include',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ choices: ['A', 'B'] })
            }
        }
    ]);
    assert.equal(JSON.stringify(context.__apiFetchCalls).includes('userId'), false);
});

test('API acquireStamp posts token without sending userId', async () => {
    const { context } = await loadRally({ loadApp: false, stubApi: false });

    context.__apiFetchCalls = [];
    context.fetch = async (requestPath, options) => {
        context.__apiFetchCalls.push({ requestPath, options });
        return {
            ok: true,
            status: 200,
            async json() {
                return {
                    ok: true,
                    stamp: 2,
                    stamps: [1, 2],
                    alreadyAcquired: false
                };
            }
        };
    };

    const result = await vm.runInContext(`StampRallyApi.acquireStamp('token-2')`, context);

    assert.deepEqual(JSON.parse(JSON.stringify(result)), {
        ok: true,
        stamp: 2,
        stamps: [1, 2],
        alreadyAcquired: false
    });
    assert.deepEqual(JSON.parse(JSON.stringify(context.__apiFetchCalls)), [
        {
            requestPath: '/api/stamp-rally/stamp',
            options: {
                method: 'POST',
                credentials: 'include',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ token: 'token-2' })
            }
        }
    ]);
    assert.equal(JSON.stringify(context.__apiFetchCalls).includes('userId'), false);
});

test('API saveEnding returns only the requested payload', async () => {
    const { context } = await loadRally({ loadApp: false, stubApi: false });
    const result = await vm.runInContext(`StampRallyApi.saveEnding('END-01')`, context);

    assert.deepEqual(JSON.parse(JSON.stringify(result)), { endingId: 'END-01' });
    assert.equal(JSON.stringify(result).includes('userId'), false);
});

test('app mounts, restores saved state, and keeps image paths under img/', async () => {
    const savedState = {
        loopCount: 2,
        stamps: [1, 2],
        currentChoices: ['A'],
        discoveredEndings: ['AAA'],
        currentSpot: 2
    };
    const { context, app, apiCalls } = await loadRally({ savedState });

    assert.equal(context.__mountedSelector, '#app-container');
    assert.equal(app.state.loopCount, 2);
    assert.deepEqual(Array.from(app.state.stamps), [1, 2]);
    assert.equal(app.stamps.every((stamp) => stamp.image.startsWith('img/')), true);
    assert.deepEqual(JSON.parse(JSON.stringify(apiCalls)), [{ method: 'initialize' }]);
});

test('app applies Exment record values returned by initialization', async () => {
    const { context, storage } = await loadRally({ loadApp: false });

    context.__initializeResult = {
        ok: true,
        created: false,
        record: {
            value: {
                loop_count: '2',
                collected_endings: 'END-01, END-05',
                collected_stamps: '1,2,3',
                loop1_choices: 'A,A,A',
                loop2_choices: 'B, A'
            }
        }
    };

    runScript(context, 'app.js');
    await context.window.StampRallyAppPromise;
    await context.__mountedResult;

    const app = context.__app;

    assert.equal(app.state.loopCount, 2);
    assert.deepEqual(Array.from(app.state.stamps), [1, 2, 3]);
    assert.deepEqual(Array.from(app.state.discoveredEndings), ['END-01', 'END-05']);
    assert.deepEqual(Array.from(app.state.currentChoices), ['B', 'A']);

    const saved = JSON.parse(storage.get('mystery_game_save'));
    assert.equal(saved.loopCount, 2);
    assert.deepEqual(saved.discoveredEndings, ['END-01', 'END-05']);
});

test('app keeps locally restored progress when optional Exment fields are missing', async () => {
    const { context } = await loadRally({
        loadApp: false,
        savedState: {
            loopCount: 2,
            stamps: [1, 2],
            currentChoices: ['B', 'A'],
            discoveredEndings: ['END-01'],
            currentSpot: 2
        }
    });

    context.__initializeResult = {
        ok: true,
        created: false,
        record: {
            value: {
                loop_count: '2',
                loop1_choices: 'A,A,A'
            }
        }
    };

    runScript(context, 'app.js');
    await context.window.StampRallyAppPromise;
    await context.__mountedResult;

    const app = context.__app;
    assert.deepEqual(Array.from(app.state.discoveredEndings), ['END-01']);
    assert.deepEqual(Array.from(app.state.currentChoices), ['B', 'A']);
});

test('switchView updates the active Vue view', async () => {
    const { app } = await loadRally();

    app.switchView('stamps');
    assert.equal(app.activeView, 'stamps');

    app.switchView('endings');
    assert.equal(app.activeView, 'endings');
});

test('selectChoice saves the choice locally and calls the API without userId', async () => {
    const { app, storage, apiCalls, elements } = await loadRally();

    await app.selectChoice('B');

    assert.deepEqual(Array.from(app.state.currentChoices), ['B']);
    assert.equal(elements.get('next-guide-container').style.display, 'block');
    assert.deepEqual(JSON.parse(JSON.stringify(apiCalls.at(-1))), { method: 'saveChoice', choices: ['B'] });

    const saved = JSON.parse(storage.get('mystery_game_save'));
    assert.deepEqual(saved.currentChoices, ['B']);
    assert.equal(JSON.stringify(apiCalls).includes('userId'), false);
});

test('selectChoice ignores concurrent save requests while one is in flight', async () => {
    const { context, app, apiCalls } = await loadRally();
    app.state.currentChoices = [];

    await vm.runInContext(`
        StampRallyApi.saveChoice = (choices) => new Promise((resolve) => {
            globalThis.__apiCalls.push({ method: 'saveChoice', choices });
            globalThis.__resolveChoiceSave = resolve;
        });
    `, context);

    const firstSave = app.selectChoice('A');
    const secondSave = app.selectChoice('B');
    const saveChoiceCalls = apiCalls.filter((call) => call.method === 'saveChoice');

    assert.equal(app.isChoiceSaveInFlight, true);
    assert.equal(saveChoiceCalls.length, 1);
    assert.deepEqual(JSON.parse(JSON.stringify(saveChoiceCalls[0])), { method: 'saveChoice', choices: ['A'] });
    assert.deepEqual(Array.from(app.state.currentChoices), []);

    await vm.runInContext(`globalThis.__resolveChoiceSave({ choices: ['A'] });`, context);
    await firstSave;
    await secondSave;

    assert.deepEqual(Array.from(app.state.currentChoices), ['A']);
    assert.equal(app.isChoiceSaveInFlight, false);
});

test('selectChoice does not append duplicate choice when save fails', async () => {
    const { context, app, elements } = await loadRally();
    app.currentChoiceData = { options: ['A', 'B'] };
    app.state.currentChoices = ['A'];

    await vm.runInContext(`
        StampRallyApi.saveChoice = async () => {
            throw new Error('save failed');
        };
    `, context);

    await assert.rejects(() => app.selectChoice('A'), /save failed/);

    assert.deepEqual(Array.from(app.state.currentChoices), ['A']);
    assert.equal(app.currentChoiceData !== null, true);
    assert.equal(elements.has('next-guide-container'), false);
    assert.equal(app.isChoiceSaveInFlight, false);
});

test('triggerEnding deduplicates endings, advances loop count, and saves END id', async () => {
    const { app, apiCalls, elements } = await loadRally();

    await app.triggerEnding('AAA');
    await app.triggerEnding('AAA');

    assert.deepEqual(Array.from(app.state.discoveredEndings), ['AAA']);
    assert.equal(app.state.loopCount, 2);
    assert.deepEqual(
        JSON.parse(JSON.stringify(apiCalls.filter((call) => call.method === 'saveEnding'))),
        [
            { method: 'saveEnding', endingId: 'END-01' },
            { method: 'saveEnding', endingId: 'END-01' }
        ]
    );
    assert.equal(elements.get('chat-box').children.length, 2);
});

test('processUrlParams ignores direct stamp parameters', async () => {
    const { app, apiCalls, elements } = await loadRally({ search: '?stamp=1' });

    assert.deepEqual(Array.from(app.state.stamps), []);
    assert.equal(app.state.currentSpot, 0);
    assert.equal(elements.has('story-location-tag'), false);
    assert.deepEqual(JSON.parse(JSON.stringify(apiCalls)), [{ method: 'initialize' }]);
});

test('processUrlParams acquires stamps from server-validated tokens and opens each checkpoint event', async () => {
    const { context, app, apiCalls, elements } = await loadRally({ search: '?token=token-1' });
    context.__stampResults = {
        'token-2': { ok: true, stamp: 2, stamps: [1, 2] },
        'token-3': { ok: true, stamp: 3, stamps: [1, 2, 3] },
        'token-4': { ok: true, stamp: 4, stamps: [1, 2, 3, 4] },
        'token-5': { ok: true, stamp: 5, stamps: [1, 2, 3, 4, 5] }
    };

    assert.deepEqual(Array.from(app.state.stamps), [1]);
    assert.equal(app.state.currentSpot, 1);
    assert.equal(elements.get('story-location-tag').innerText, 'CHECKPOINT 01 LOGS');
    assert.equal(elements.get('next-dialogue-btn').style.display, 'block');
    assert.equal(elements.get('chat-controls').style.display, 'block');

    for (const stampId of [2, 3, 4, 5]) {
        context.window.location.search = `?token=token-${stampId}`;
        await app.processUrlParams();

        assert.deepEqual(Array.from(app.state.stamps), Array.from({ length: stampId }, (_, index) => index + 1));
        assert.equal(app.state.currentSpot, stampId);
        assert.equal(elements.get('story-location-tag').innerText, `CHECKPOINT 0${stampId} LOGS`);
        assert.equal(elements.get('start-btn').style.display, 'none');
        assert.equal(elements.get('next-dialogue-btn').style.display, 'block');
        assert.equal(elements.get('chat-controls').style.display, 'block');
    }

    assert.deepEqual(
        JSON.parse(JSON.stringify(apiCalls.filter((call) => call.method === 'acquireStamp'))),
        [
            { method: 'acquireStamp', token: 'token-1' },
            { method: 'acquireStamp', token: 'token-2' },
            { method: 'acquireStamp', token: 'token-3' },
            { method: 'acquireStamp', token: 'token-4' },
            { method: 'acquireStamp', token: 'token-5' }
        ]
    );
});

test('processUrlParams shows an error when token acquisition fails', async () => {
    const { context, app, elements } = await loadRally({ search: '', loadApp: false });

    await vm.runInContext(`
        StampRallyApi.acquireStamp = async () => {
            throw new Error('out_of_order_stamp');
        };
    `, context);
    runScript(context, 'app.js');
    await context.window.StampRallyAppPromise;
    await context.__mountedResult;
    context.window.location.search = '?token=bad-token';
    await context.__app.processUrlParams();

    assert.deepEqual(Array.from(context.__app.state.stamps), []);
    assert.equal(context.__app.state.currentSpot, 0);
    assert.match(elements.get('chat-box').innerHTML, /QR/);
    assert.equal(elements.get('chat-controls').style.display, 'none');
});

test('loadState falls back to the initial state for invalid localStorage JSON', async () => {
    const { app, storage } = await loadRally();

    storage.set('mystery_game_save', '{invalid json');
    app.state.loopCount = 3;
    app.loadState();

    assert.equal(app.state.loopCount, 1);
    assert.deepEqual(Array.from(app.state.stamps), []);
});

test('startInvestigation waits for a QR token before opening the first spot', async () => {
    const { app, elements } = await loadRally();

    app.startInvestigation();

    assert.deepEqual(Array.from(app.state.stamps), []);
    assert.equal(app.state.currentSpot, 0);
    assert.equal(elements.get('start-btn').style.display, 'none');
    assert.equal(elements.get('next-dialogue-btn').style.display, 'block');
    assert.match(elements.get('chat-box').innerHTML, /QR/);

    app.state.stamps = [1];
    app.startInvestigation();

    assert.deepEqual(Array.from(app.state.stamps), [1]);
    assert.equal(app.state.currentSpot, 1);
});

test('endingEntries and isEndingDiscovered expose ending collection state', async () => {
    const { app } = await loadRally({
        savedState: {
            loopCount: 1,
            stamps: [],
            currentChoices: [],
            discoveredEndings: ['AAA'],
            currentSpot: 0
        }
    });

    assert.ok(app.endingEntries.some((ending) => ending.key === 'AAA' && ending.id === 'END-01'));
    assert.equal(app.isEndingDiscovered('AAA'), true);
    assert.equal(app.isEndingDiscovered('BBB'), false);
});

test('advanceDialogue shows choices, ordinary next guide, first-loop ending, and second-loop ending', async () => {
    const { app, apiCalls, elements } = await loadRally();

    app.state.currentSpot = 2;
    app.currentDialogueList = [];
    app.dialogueIndex = 0;
    app.advanceDialogue();
    assert.equal(app.currentChoiceData, app.currentChoiceData && app.currentChoiceData);
    assert.equal(app.currentChoiceData.options.length, 2);

    app.currentChoiceData = null;
    app.state.currentSpot = 1;
    app.currentDialogueList = [];
    app.advanceDialogue();
    assert.equal(elements.get('next-guide-container').style.display, 'block');

    app.state.currentSpot = 5;
    app.state.currentChoices = ['A', 'A', 'A'];
    app.state.loopCount = 1;
    app.currentDialogueList = [];
    await app.advanceDialogue();
    assert.equal(apiCalls.at(-1).endingId, 'END-01');

    app.state.currentSpot = 5;
    app.state.loopCount = 2;
    app.currentDialogueList = [];
    await app.advanceDialogue();
    assert.equal(apiCalls.at(-1).endingId, 'END-AI');
});

test('advanceDialogue renders system and character messages', async () => {
    const { app, context, elements } = await loadRally();
    const chatBox = context.document.getElementById('chat-box');
    const systemSenderId = context.window.StampRallyData.DIALOGUES[2][0].senderId;
    const characterSenderId = context.window.StampRallyData.DIALOGUES[1][0].senderId;

    app.currentDialogueList = [
        { senderId: systemSenderId, text: 'system line' },
        { senderId: characterSenderId, text: 'character line' }
    ];
    app.dialogueIndex = 0;

    app.advanceDialogue();
    app.advanceDialogue();

    assert.equal(chatBox.children.length, 2);
    assert.equal(chatBox.children[0].className, 'system-msg');
    assert.match(chatBox.children[1].className, /chat-bubble/);
    assert.match(chatBox.children[1].innerHTML, /キリコ/);
});

test('getCharacter falls back for unknown senderId values', async () => {
    const { app } = await loadRally();

    assert.deepEqual(JSON.parse(JSON.stringify(app.getCharacter('missing'))), {
        name: 'missing',
        avatar: '??',
        type: 'character'
    });
});

test('triggerEnding handles unknown endings without resetting higher loop counts', async () => {
    const { app, apiCalls } = await loadRally();

    app.state.loopCount = 3;
    await app.triggerEnding('UNKNOWN');

    assert.equal(app.state.loopCount, 3);
    assert.deepEqual(Array.from(app.state.discoveredEndings), ['UNKNOWN']);
    assert.equal(apiCalls.at(-1).endingId, 'END-EX');
});

test('confirmReset clears saved progress and navigates to the current path', async () => {
    const { app, storage, context } = await loadRally({
        savedState: {
            loopCount: 2,
            stamps: [1, 2, 3],
            currentChoices: ['A'],
            discoveredEndings: ['AAA'],
            currentSpot: 3
        }
    });

    app.confirmReset();

    assert.equal(storage.has('mystery_game_save'), true);
    assert.equal(JSON.parse(storage.get('mystery_game_save')).loopCount, 1);
    assert.equal(context.window.location.href, '/index.html');
});

test('audio controls initialize, toggle, and play sound effects', async () => {
    const { context, elements } = await loadRally({ loadApp: false });

    vm.runInContext(`
        initAudio();
        playTapSound();
        toggleAudio();
        toggleAudio();
        playSE('stamp');
        playSE('scan');
    `, context);

    assert.match(elements.get('audio-toggle').innerText, /BGM ON|BGM OFF/);
});

test('audio resumes a suspended context and ignores unknown sound effects', async () => {
    const { context } = await loadRally({ loadApp: false });

    vm.runInContext(`
        audioCtx = {
            state: 'suspended',
            currentTime: 0,
            destination: {},
            resumed: false,
            resume() { this.resumed = true; this.state = 'running'; },
            createOscillator() {
                return {
                    connect() {},
                    start() {},
                    stop() {},
                    frequency: {
                        setValueAtTime() {},
                        exponentialRampToValueAtTime() {}
                    }
                };
            },
            createGain() {
                return {
                    connect() {},
                    gain: {
                        setValueAtTime() {},
                        exponentialRampToValueAtTime() {},
                        linearRampToValueAtTime() {}
                    }
                };
            }
        };
        initAudio();
        playSE('unknown');
        audioCtx.resumed;
    `, context);

    assert.equal(vm.runInContext('audioCtx.resumed', context), true);
});
