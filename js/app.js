async function bootstrapStampRallyApp() {
const RALLY_DATA = await StampRallyDataLoader.loadStampRallyData();
const RALLY_ENDING_MASTER = RALLY_DATA.ENDING_MASTER;
const RALLY_CHARACTERS = RALLY_DATA.CHARACTERS;
const RALLY_DIALOGUES = RALLY_DATA.DIALOGUES;
const RALLY_DIALOGUES_LOOP2 = RALLY_DATA.DIALOGUES_LOOP2;
const RALLY_CHOICES_DATA = RALLY_DATA.CHOICES_DATA;
const RALLY_STORY_GRAPH = RALLY_DATA.STORY_GRAPH;

const INITIAL_STATE = {
    loopCount: 1,
    stamps: [],
    currentChoices: [],
    discoveredEndings: [],
    currentSpot: 0,
    currentNodeId: null,
    pendingNodeId: null
};

return Vue.createApp({
    data() {
        return {
            activeView: 'top',
            state: { ...INITIAL_STATE },
            currentDialogueList: [],
            dialogueIndex: 0,
            currentChoiceData: null,
            isChoiceSaveInFlight: false,
            stamps: [
                { id: 1, image: 'img/stamp1.PNG', label: '01: START' },
                { id: 2, image: 'img/stamp2.PNG', label: '02: CP1' },
                { id: 3, image: 'img/stamp3.PNG', label: '03: CP2' },
                { id: 4, image: 'img/stamp4.PNG', label: '04: CP3' },
                { id: 5, image: 'img/stamp5.PNG', label: '05: GOAL' }
            ]
        };
    },

    computed: {
        endingEntries() {
            return Object.entries(RALLY_ENDING_MASTER).map(([key, item]) => ({ key, ...item }));
        }
    },

    mounted() {
        return this.initializeRally();
    },

    methods: {
        async initializeRally() {
            const params = new URLSearchParams(window.location.search);
            if (params.get('demo') === '1') {
                this.loadState();
                this.saveState();
                await this.processUrlParams();
                return;
            }

            const initResult = await StampRallyApi.initialize();
            this.loadState();
            this.applyServerRecord(initResult?.record);
            await this.processUrlParams();
        },

        applyServerRecord(record) {
            if (!record || !record.value) {
                return;
            }

            const value = record.value;
            const previousLoopCount = this.state.loopCount;
            const loopCount = parseInt(value.loop_count, 10);
            if (!Number.isNaN(loopCount) && loopCount > 0) {
                this.state.loopCount = loopCount;
            }

            if (Object.prototype.hasOwnProperty.call(value, 'collected_endings')) {
                this.state.discoveredEndings = this.parseCsv(value.collected_endings);
            }

            if (Object.prototype.hasOwnProperty.call(value, 'collected_stamps')) {
                this.state.stamps = this.parseCsv(value.collected_stamps)
                    .map((item) => parseInt(item, 10))
                    .filter((item) => !Number.isNaN(item) && item >= 1 && item <= 5);
            }

            const loopChoiceMap = {
                1: value.loop1_choices,
                2: value.loop2_choices,
                3: value.loop3_choices
            };
            if (Object.prototype.hasOwnProperty.call(loopChoiceMap, this.state.loopCount) && loopChoiceMap[this.state.loopCount] !== undefined) {
                this.state.currentChoices = this.parseCsv(loopChoiceMap[this.state.loopCount]);
            } else if (this.state.loopCount !== previousLoopCount) {
                this.state.currentChoices = [];
            }
            this.saveState();
        },

        parseCsv(value) {
            if (typeof value !== 'string' || value.trim() === '') {
                return [];
            }

            return value
                .split(',')
                .map((item) => item.trim())
                .filter(Boolean);
        },

        syncServerStamps(stamps) {
            if (!Array.isArray(stamps)) {
                return;
            }

            this.state.stamps = stamps
                .map((item) => parseInt(item, 10))
                .filter((item) => !Number.isNaN(item) && item >= 1 && item <= 5);
            this.saveState();
        },

        removeTokenFromUrl() {
            if (!window.history || typeof window.history.replaceState !== 'function') {
                return;
            }

            const params = new URLSearchParams(window.location.search);
            if (!params.has('token')) {
                return;
            }

            params.delete('token');
            const query = params.toString();
            const path = window.location.pathname || '/';
            const hash = window.location.hash || '';
            window.history.replaceState(null, '', `${path}${query ? `?${query}` : ''}${hash}`);
        },

        loadState() {
            const saved = localStorage.getItem('mystery_game_save');
            if (saved) {
                try {
                    this.state = { ...INITIAL_STATE, ...JSON.parse(saved) };
                } catch (e) {
                    this.state = { ...INITIAL_STATE };
                }
            }
        },

        saveState() {
            localStorage.setItem('mystery_game_save', JSON.stringify(this.state));
        },

        switchView(viewName) {
            this.activeView = viewName;
        },

        toggleAudio() {
            toggleAudio();
        },

        startInvestigation() {
            initAudio();
            document.getElementById('start-btn').style.display = 'none';
            document.getElementById('next-dialogue-btn').style.display = 'block';

            if (!this.state.stamps.includes(1)) {
                document.getElementById('chat-box').innerHTML = `
                    <div class="system-msg">
                        QRコードを読み取ってチェックポイントの記録を開始してください。
                    </div>
                `;
                document.getElementById('chat-controls').style.display = 'none';
                document.getElementById('next-guide-container').style.display = 'block';
            } else {
                const spotId = this.state.currentSpot || Math.max(...this.state.stamps);
                this.state.currentSpot = spotId;
                this.loadStorySpot(spotId);
            }
        },

        async processUrlParams() {
            const params = new URLSearchParams(window.location.search);
            const token = params.get('token');
            if (!token) return;

            this.triggerScanEffect();

            let result = null;
            try {
                result = this.isDemoMode()
                    ? this.acquireDemoStamp(token)
                    : await StampRallyApi.acquireStamp(token);
            } catch (error) {
                if (error?.code === 'stamp_already_acquired') {
                    this.syncServerStamps(error.result?.stamps);
                    const acquiredId = parseInt(error.result?.stamp, 10);
                    if (!Number.isNaN(acquiredId) && this.state.stamps.includes(acquiredId)) {
                        this.removeTokenFromUrl();
                        document.getElementById('start-btn').style.display = 'none';
                        document.getElementById('next-dialogue-btn').style.display = 'block';
                        this.state.currentSpot = acquiredId;
                        this.openStampStory(acquiredId);
                        return;
                    }

                    this.removeTokenFromUrl();
                    document.getElementById('chat-box').innerHTML = `
                        <div class="system-msg">
                            このQRは取得済みです。次のチェックポイントへ進んでください。
                        </div>
                    `;
                    document.getElementById('chat-controls').style.display = 'none';
                    document.getElementById('next-guide-container').style.display = 'block';
                    return;
                }

                const errorCode = error?.code || error?.message || 'unknown_error';
                const safeErrorCode = String(errorCode).replace(/[&<>"']/g, (char) => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                }[char]));
                this.removeTokenFromUrl();
                document.getElementById('chat-box').innerHTML = `
                    <div class="system-msg">
                        無効なQRコード、または読み取り順が正しくありません。次のチェックポイントを確認してください。
                        <br><span style="font-family: var(--font-mono); color: var(--warning-yellow);">ERROR: ${safeErrorCode}</span>
                    </div>
                `;
                document.getElementById('chat-controls').style.display = 'none';
                document.getElementById('next-guide-container').style.display = 'block';
                return;
            }

            const id = parseInt(result?.stamp, 10);
            if (Number.isNaN(id) || id < 1 || id > 5) {
                return;
            }

            if (Array.isArray(result.stamps)) {
                this.syncServerStamps(result.stamps);
            }

            if (this.state.stamps.includes(id)) {
                this.removeTokenFromUrl();
                document.getElementById('start-btn').style.display = 'none';
                document.getElementById('next-dialogue-btn').style.display = 'block';
                this.state.currentSpot = id;
                this.openStampStory(id);
            }
        },

        isDemoMode() {
            return new URLSearchParams(window.location.search).get('demo') === '1';
        },

        acquireDemoStamp(token) {
            const match = /^dev-stamp-token-([1-5])$/.exec(token);
            const stampId = match ? parseInt(match[1], 10) : Number.NaN;

            if (Number.isNaN(stampId)) {
                const error = new Error('unknown_token');
                error.code = 'unknown_token';
                error.result = { error: 'unknown_token' };
                throw error;
            }

            if (this.state.stamps.includes(stampId)) {
                const error = new Error('stamp_already_acquired');
                error.code = 'stamp_already_acquired';
                error.result = {
                    error: 'stamp_already_acquired',
                    stamp: stampId,
                    stamps: [...this.state.stamps]
                };
                throw error;
            }

            const expectedStampId = this.state.stamps.length + 1;
            if (stampId !== expectedStampId) {
                const error = new Error('out_of_order_stamp');
                error.code = 'out_of_order_stamp';
                error.result = {
                    error: 'out_of_order_stamp',
                    stamp: stampId,
                    stamps: [...this.state.stamps]
                };
                throw error;
            }

            this.state.stamps = [...this.state.stamps, stampId];
            this.saveState();
            return {
                ok: true,
                stamp: stampId,
                stamps: [...this.state.stamps]
            };
        },

        triggerScanEffect() {
            playSE('scan');
            const overlay = document.getElementById('scan-effect-overlay');
            if (overlay) {
                overlay.style.display = 'block';
                setTimeout(() => { overlay.style.display = 'none'; }, 800);
            }
        },

        acquireStamp(id) {
            if (!this.state.stamps.includes(id)) {
                this.state.stamps.push(id);
                playSE('stamp');
                this.saveState();
            }
            this.state.currentSpot = id;
            this.openStampStory(id);
        },

        openStampStory(stampId) {
            const entryNodes = { 1: '1', 2: '2', 3: '4', 4: '8', 5: null };
            const nodeId = this.state.pendingNodeId || entryNodes[stampId];
            this.state.pendingNodeId = null;
            if (nodeId) {
                this.loadStoryNode(nodeId);
                return;
            }

            const chatBox = document.getElementById('chat-box');
            chatBox.innerHTML = '';
            const message = document.createElement('div');
            message.className = 'system-msg';
            message.innerText = '先にチェックポイント3の選択肢を選んでください。';
            chatBox.appendChild(message);
            document.getElementById('next-dialogue-btn').style.display = 'none';
            document.getElementById('chat-controls').style.display = 'none';
            document.getElementById('next-guide-container').style.display = 'block';
            this.saveState();
        },

        loadStorySpot(spotId) {
            this.state.currentSpot = spotId;
            this.openStampStory(spotId);
        },

        loadStoryNode(nodeId) {
            const node = RALLY_STORY_GRAPH.nodes[String(nodeId)];
            if (!node) {
                this.showTemporalErrorEnding();
                return;
            }

            this.state.currentNodeId = String(nodeId);
            this.currentDialogueList = node.messages || RALLY_DIALOGUES[node.dialogueKey] || [];
            this.dialogueIndex = 0;
            this.currentChoiceData = null;
            const checkpointLabels = {
                start: 'START',
                checkpoint1: 'CHECKPOINT 01',
                checkpoint2: 'CHECKPOINT 02',
                checkpoint3: 'CHECKPOINT 03',
                goal: 'GOAL'
            };
            document.getElementById('story-location-tag').innerText = checkpointLabels[node.checkpoint] || 'STORY LOG';
            document.getElementById('chat-box').innerHTML = '';
            document.getElementById('next-guide-container').style.display = 'none';
            document.getElementById('chat-controls').style.display = 'block';
            this.saveState();
            this.advanceDialogue();
        },

        async advanceDialogue() {
            const chatBox = document.getElementById('chat-box');

            if (this.dialogueIndex < this.currentDialogueList.length) {
                const msg = this.currentDialogueList[this.dialogueIndex];
                if (msg.type === 'narrative') {
                    const narrative = document.createElement('div');
                    narrative.className = 'narrative-msg';
                    narrative.innerText = msg.text;
                    chatBox.appendChild(narrative);
                    this.dialogueIndex++;
                    window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
                    return;
                }
                const sender = this.getCharacter(msg.senderId);

                if (sender.type === 'system') {
                    const sysDiv = document.createElement('div');
                    sysDiv.className = 'system-msg';
                    sysDiv.innerText = `${sender.prefix || ''}${msg.text}`;
                    chatBox.appendChild(sysDiv);
                } else {
                    const bubble = document.createElement('div');
                    bubble.className = `chat-bubble ${sender.type === 'player' ? 'player' : ''}`;

                    bubble.innerHTML = `
                        <div class="chat-avatar">${sender.avatar || ''}</div>
                        <div>
                            <div class="chat-sender">${sender.name}</div>
                            <div class="chat-content">${msg.text}</div>
                        </div>
                    `;
                    chatBox.appendChild(bubble);
                }

                this.dialogueIndex++;
                window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
                return;
            }

            document.getElementById('chat-controls').style.display = 'none';

            const storyNode = RALLY_STORY_GRAPH.nodes[String(this.state.currentNodeId)];
            if (storyNode) {
                if (Array.isArray(storyNode.options) && storyNode.options.length > 0) {
                    this.currentChoiceData = storyNode;
                    return;
                }
                if (storyNode.endingKey) {
                    if (!this.hasAllStamps()) {
                        this.showStampRequirement();
                        return;
                    }
                    try {
                        await this.triggerEnding(storyNode.endingKey);
                    } catch (error) {
                        this.handleEndingSaveError();
                    }
                    return;
                }
                if (storyNode.next) {
                    this.loadStoryNode(storyNode.next);
                    return;
                }
            }

            if (this.state.loopCount >= 2) {
                if (this.state.currentSpot === 5) {
                    try {
                        await this.triggerEnding('AI_SELF_DESTRUCT');
                    } catch (error) {
                        this.handleEndingSaveError();
                    }
                } else {
                    document.getElementById('next-guide-container').style.display = 'block';
                }
                return;
            }

            if (RALLY_CHOICES_DATA[this.state.currentSpot]) {
                this.currentChoiceData = RALLY_CHOICES_DATA[this.state.currentSpot];
            } else if (this.state.currentSpot === 5) {
                const endingKey = this.state.currentChoices.join('');
                try {
                    await this.triggerEnding(endingKey);
                } catch (error) {
                    this.handleEndingSaveError();
                }
            } else {
                document.getElementById('next-guide-container').style.display = 'block';
            }
        },

        hasAllStamps() {
            return [1, 2, 3, 4, 5].every((stampId) => this.state.stamps.includes(stampId));
        },

        showStampRequirement() {
            const missingStamps = [1, 2, 3, 4, 5]
                .filter((stampId) => !this.state.stamps.includes(stampId))
                .join(', ');
            const chatBox = document.getElementById('chat-box');
            const message = document.createElement('div');
            message.className = 'system-msg';
            message.innerText = `全チェックポイントのスタンプが必要です。未取得: ${missingStamps}`;
            chatBox.appendChild(message);
            document.getElementById('chat-controls').style.display = 'none';
            document.getElementById('next-guide-container').style.display = 'block';
        },

        async selectChoice(val) {
            if (this.isChoiceSaveInFlight) {
                return;
            }

            const option = typeof val === 'string' ? { value: val } : val;
            const nextChoices = [...this.state.currentChoices, option.value];
            const isDemoMode = new URLSearchParams(window.location.search).get('demo') === '1';
            this.isChoiceSaveInFlight = true;
            try {
                if (!isDemoMode) {
                    await StampRallyApi.saveChoice(nextChoices);
                }
                this.state.currentChoices = nextChoices;
                this.saveState();
                this.currentChoiceData = null;
                if (typeof val !== 'string' && option.next) {
                    const nextNode = RALLY_STORY_GRAPH.nodes[String(option.next)];
                    const requiredStamp = nextNode?.stampGate || this.state.stamps.length;
                    if (requiredStamp > this.state.stamps.length) {
                        this.state.pendingNodeId = String(option.next);
                        this.saveState();
                        this.showNextStampRequirement(requiredStamp);
                    } else {
                        this.loadStoryNode(option.next);
                    }
                } else {
                    document.getElementById('next-guide-container').style.display = 'block';
                }
            } finally {
                this.isChoiceSaveInFlight = false;
            }
        },

        showNextStampRequirement(stampId) {
            const chatBox = document.getElementById('chat-box');
            const message = document.createElement('div');
            message.className = 'system-msg';
            message.innerText = `次の区間へ進むには、スタンプ${stampId}を取得してください。`;
            chatBox.appendChild(message);
            document.getElementById('chat-controls').style.display = 'none';
            document.getElementById('next-guide-container').style.display = 'block';
        },

        async triggerEnding(key) {
            const ending = RALLY_ENDING_MASTER[key];
            if (!ending) {
                this.showTemporalErrorEnding();
                return;
            }

            const updatedEndings = this.state.discoveredEndings.includes(ending.id)
                ? [...this.state.discoveredEndings]
                : [...this.state.discoveredEndings, ending.id];
            const completedCurrentLoop = this.hasAllStamps();
            const updatedLoopCount = completedCurrentLoop
                ? Math.min(this.state.loopCount + 1, 3)
                : (this.state.loopCount === 1 ? 2 : this.state.loopCount);
            const cleared = completedCurrentLoop
                && this.state.loopCount >= 3
                && key === 'AI_SELF_DESTRUCT';
            const isDemoMode = new URLSearchParams(window.location.search).get('demo') === '1';
            const result = isDemoMode ? null : await StampRallyApi.saveEnding(ending.id);
            if (result?.record) {
                this.applyServerRecord(result.record);
            } else {
                this.state.discoveredEndings = updatedEndings;
                this.state.loopCount = updatedLoopCount;
                if (isDemoMode && completedCurrentLoop) {
                    if (cleared) {
                        this.state.clearedAt = new Date().toISOString();
                    } else {
                        this.state.stamps = [];
                    }
                    this.state.currentSpot = 0;
                    this.state.currentNodeId = null;
                    this.state.pendingNodeId = null;
                }
                this.saveState();
            }

            const chatBox = document.getElementById('chat-box');
            const endPanel = document.createElement('div');
            endPanel.className = 'ending-display-panel';
            endPanel.innerHTML = `
                <div style="font-size:0.75rem; color:var(--warning-yellow); font-family:var(--font-mono); font-weight:bold;">--- MISSION COMPLETE ---</div>
                <div class="ending-display-title">${ending.id}: ${ending.name}</div>
                <div class="ending-display-desc">${ending.desc}</div>
                <p style="font-size:0.75rem; color:var(--accent-green);">${cleared ? '調査ログを初期化しました。' : `【${updatedLoopCount}周目解放】システムプロトコルが更新されました。`}<br>スタンプ画面から次なる調査を開始してください。</p>
            `;
            chatBox.appendChild(endPanel);
        },

        showTemporalErrorEnding() {
            const chatBox = document.getElementById('chat-box');
            const speaker = this.getCharacter('kiriko');
            const errorPanel = document.createElement('div');
            errorPanel.className = 'ending-display-panel temporal-error-panel';
            errorPanel.innerHTML = `
                <div class="temporal-error-kicker">--- TEMPORAL ERROR ---</div>
                <div class="ending-display-title">END-EX: 時空異常</div>
                <div class="ending-display-desc">
                    本来つながるはずのない記録に接続されています。
                </div>
                <div class="temporal-error-quote">
                    <span class="temporal-error-speaker">${speaker.name}</span>
                    <span>時空が歪んでる。おかしな分岐に入ってしまったみたい。このまま進めると、調査記録そのものが壊れるかもしれない。</span>
                </div>
            `;
            chatBox.appendChild(errorPanel);
            document.getElementById('chat-controls').style.display = 'none';
            document.getElementById('next-guide-container').style.display = 'block';
        },

        handleEndingSaveError() {
            const chatBox = document.getElementById('chat-box');
            const errorPanel = document.createElement('div');
            errorPanel.className = 'system-msg';
            errorPanel.innerText = '結末の保存に失敗しました。通信状況を確認してからもう一度お試しください。';
            chatBox.appendChild(errorPanel);
            document.getElementById('chat-controls').style.display = 'none';
            document.getElementById('next-guide-container').style.display = 'block';
        },

        getCharacter(senderId) {
            return RALLY_CHARACTERS[senderId] || {
                name: senderId || 'UNKNOWN',
                avatar: '??',
                type: 'character'
            };
        },

        confirmReset() {
            if (confirm('端末の全調査ログと進行状況を初期化します。よろしいですか？')) {
                localStorage.removeItem('mystery_game_save');
                this.state = { ...INITIAL_STATE };
                this.saveState();
                location.href = location.pathname;
            }
        },

        isEndingDiscovered(key) {
            const ending = RALLY_ENDING_MASTER[key];
            return this.state.discoveredEndings.includes(key)
                || (ending ? this.state.discoveredEndings.includes(ending.id) : false);
        }
    }
}).mount('#app-container');
}

window.StampRallyAppPromise = bootstrapStampRallyApp();
