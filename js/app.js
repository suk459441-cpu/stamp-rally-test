async function bootstrapStampRallyApp() {
const RALLY_DATA = await StampRallyDataLoader.loadStampRallyData();
const RALLY_ENDING_MASTER = RALLY_DATA.ENDING_MASTER;
const RALLY_CHARACTERS = RALLY_DATA.CHARACTERS;
const RALLY_DIALOGUES = RALLY_DATA.DIALOGUES;
const RALLY_DIALOGUES_LOOP2 = RALLY_DATA.DIALOGUES_LOOP2;
const RALLY_CHOICES_DATA = RALLY_DATA.CHOICES_DATA;

const INITIAL_STATE = {
    loopCount: 1,
    stamps: [],
    currentChoices: [],
    discoveredEndings: [],
    currentSpot: 0
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
            const initResult = await StampRallyApi.initialize();
            this.loadState();
            this.applyServerRecord(initResult?.record);
            this.processUrlParams();
        },

        applyServerRecord(record) {
            if (!record || !record.value) {
                return;
            }

            const value = record.value;
            const loopCount = parseInt(value.loop_count, 10);
            if (!Number.isNaN(loopCount) && loopCount > 0) {
                this.state.loopCount = loopCount;
            }

            if (Object.prototype.hasOwnProperty.call(value, 'collected_endings')) {
                this.state.discoveredEndings = this.parseCsv(value.collected_endings);
            }

            const loopChoiceMap = {
                1: value.loop1_choices,
                2: value.loop2_choices,
                3: value.loop3_choices
            };
            if (Object.prototype.hasOwnProperty.call(loopChoiceMap, this.state.loopCount) && loopChoiceMap[this.state.loopCount] !== undefined) {
                this.state.currentChoices = this.parseCsv(loopChoiceMap[this.state.loopCount]);
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
                this.acquireStamp(1);
            } else {
                this.state.currentSpot = 1;
                this.loadStorySpot(1);
            }
        },

        processUrlParams() {
            const params = new URLSearchParams(window.location.search);
            const stampParam = params.get('stamp') || params.get('spot');
            if (!stampParam) return;

            const id = parseInt(stampParam, 10);
            if (id < 1 || id > 5) return;

            this.triggerScanEffect();

            const expectedNext = this.state.stamps.length + 1;
            if (id === expectedNext || this.state.stamps.includes(id)) {
                document.getElementById('start-btn').style.display = 'none';
                document.getElementById('next-dialogue-btn').style.display = 'block';
                this.acquireStamp(id);
                return;
            }

            document.getElementById('chat-box').innerHTML = `
                <div class="system-msg">
                    【エラー】正しい順番でQRコードを読み込んでください。<br>
                    次に探すべきチェックポイント: 0${expectedNext}
                </div>
            `;
            document.getElementById('chat-controls').style.display = 'none';
            document.getElementById('next-guide-container').style.display = 'block';
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
            this.loadStorySpot(id);
        },

        loadStorySpot(spotId) {
            document.getElementById('story-location-tag').innerText = `CHECKPOINT 0${spotId} LOGS`;
            const chatBox = document.getElementById('chat-box');
            chatBox.innerHTML = '';
            this.dialogueIndex = 0;
            this.currentChoiceData = null;

            this.currentDialogueList = this.state.loopCount >= 2
                ? RALLY_DIALOGUES_LOOP2[spotId] || []
                : RALLY_DIALOGUES[spotId] || [];

            document.getElementById('next-guide-container').style.display = 'none';
            document.getElementById('chat-controls').style.display = 'block';

            this.advanceDialogue();
        },

        advanceDialogue() {
            const chatBox = document.getElementById('chat-box');

            if (this.dialogueIndex < this.currentDialogueList.length) {
                const msg = this.currentDialogueList[this.dialogueIndex];
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

            if (this.state.loopCount >= 2) {
                if (this.state.currentSpot === 5) {
                    this.triggerEnding('AI_SELF_DESTRUCT');
                } else {
                    document.getElementById('next-guide-container').style.display = 'block';
                }
                return;
            }

            if (RALLY_CHOICES_DATA[this.state.currentSpot]) {
                this.currentChoiceData = RALLY_CHOICES_DATA[this.state.currentSpot];
            } else if (this.state.currentSpot === 5) {
                const endingKey = this.state.currentChoices.join('');
                this.triggerEnding(endingKey);
            } else {
                document.getElementById('next-guide-container').style.display = 'block';
            }
        },

        async selectChoice(val) {
            if (this.isChoiceSaveInFlight) {
                return;
            }

            const nextChoices = [...this.state.currentChoices, val];
            this.isChoiceSaveInFlight = true;
            try {
                await StampRallyApi.saveChoice(nextChoices);
                this.state.currentChoices = nextChoices;
                this.saveState();
                this.currentChoiceData = null;
                document.getElementById('next-guide-container').style.display = 'block';
            } finally {
                this.isChoiceSaveInFlight = false;
            }
        },

        async triggerEnding(key) {
            const ending = RALLY_ENDING_MASTER[key] || { id: 'END-EX', name: '未知の結末', desc: '記録にない結末に到達した。' };

            if (!this.state.discoveredEndings.includes(key)) {
                this.state.discoveredEndings.push(key);
            }

            if (this.state.loopCount === 1) {
                this.state.loopCount = 2;
            }

            this.saveState();
            await StampRallyApi.saveEnding(ending.id);

            const chatBox = document.getElementById('chat-box');
            const endPanel = document.createElement('div');
            endPanel.className = 'ending-display-panel';
            endPanel.innerHTML = `
                <div style="font-size:0.75rem; color:var(--warning-yellow); font-family:var(--font-mono); font-weight:bold;">--- MISSION COMPLETE ---</div>
                <div class="ending-display-title">${ending.id}: ${ending.name}</div>
                <div class="ending-display-desc">${ending.desc}</div>
                <p style="font-size:0.75rem; color:var(--accent-green);">【2周目解放】システムプロトコルが更新されました。<br>スタンプ画面から次なる調査を開始してください。</p>
            `;
            chatBox.appendChild(endPanel);
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
            return this.state.discoveredEndings.includes(key);
        }
    }
}).mount('#app-container');
}

window.StampRallyAppPromise = bootstrapStampRallyApp();
