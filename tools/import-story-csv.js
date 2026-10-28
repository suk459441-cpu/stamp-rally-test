const fs = require('node:fs');
const path = require('node:path');

const sourcePath = process.argv[2];
if (!sourcePath) {
    throw new Error('Usage: node tools/import-story-csv.js <story.csv>');
}

function parseCsv(source) {
    const rows = [];
    let row = [];
    let field = '';
    let quoted = false;

    for (let index = 0; index < source.length; index += 1) {
        const character = source[index];
        const next = source[index + 1];
        if (quoted) {
            if (character === '"' && next === '"') {
                field += '"';
                index += 1;
            } else if (character === '"') {
                quoted = false;
            } else {
                field += character;
            }
        } else if (character === '"') {
            quoted = true;
        } else if (character === ',') {
            row.push(field);
            field = '';
        } else if (character === '\n') {
            row.push(field.replace(/\r$/, ''));
            if (row.some((value) => value.trim())) rows.push(row);
            row = [];
            field = '';
        } else {
            field += character;
        }
    }
    if (field || row.length) {
        row.push(field);
        if (row.some((value) => value.trim())) rows.push(row);
    }
    return rows;
}

const transitions = {
    '1': { A: '2', B: '3' }, '2': { A: '4', B: '5' },
    '3': { A: '6', B: '7' }, '4': { A: '8', B: '9' },
    '5': { A: '18a', B: '10' }, '6': { A: '11', B: '12' },
    '7': { A: '13', B: '14' }, '8': { A: '15', B: '16' },
    '9': { A: '17' }, '10': { A: '20', B: '19' },
    '11': { A: '21' }, '12': { A: '22', B: '23' },
    '13': { A: '24' }, '14': { A: '24' }
};

const endings = {
    '15': 'AI_SELF_DESTRUCT', '16': 'AI_SELF_DESTRUCT', '17': 'AI_DEFEATED',
    '18': 'ALL_ESCAPED', '19': 'ABANDONED_TEAM', '20': 'INFECTION_SPREAD',
    '21': 'LAST_SURVIVOR', '22': 'ALL_VR', '23': 'AI_COLLEGE_LIFE',
    '24': 'UNKNOWN_TRUTH'
};

function nodeId(rawId) {
    return /^18[^0-9]/.test(rawId.trim()) ? '18a' : rawId.trim();
}

function senderForQuote(source, start, end, quoteText) {
    const candidates = [
        ['キリコ', 'kiriko'],
        ['ブンジ', 'bunji'],
        ['管理AI', 'guideAi'],
        ['AI', 'guideAi'],
        ['あなた', 'player'],
        ['博士', 'doctor']
    ];
    const lineStart = source.lastIndexOf('\n', start - 1) + 1;
    const currentLine = source.slice(lineStart, start);
    const previousLineEnd = lineStart - 1;
    const previousLineStart = Math.max(0, source.lastIndexOf('\n', previousLineEnd - 1) + 1);
    const previousLine = source.slice(previousLineStart, previousLineEnd).trim();
    const contexts = [currentLine, previousLine];

    const text = quoteText.slice(1, -1).trim();
    const contentHints = [
        [/私を信じて/, 'kiriko'],
        [/管理AI……|管理AI…/, 'kiriko'],
        [/まだ動いてるの|おかしい。どうして/, 'bunji'],
        [/まちや、ラボ|まって、ラボ/, 'kiriko'],
        [/そ、そうだよ|そ、そうですよ/, 'bunji'],
        [/調査員の皆様へ|私は、この大学の管理AI|この大学で発生した|治療薬の製造方法|私は、あなた方を待って|私を助けてください/, 'guideAi'],
        [/その端末を起動してください|博士とこの端末|心配する必要はありません|この端末を起動してください/, 'guideAi'],
        [/……何を？|これも罠ではないの|僕も同意見/, 'player']
    ];
    for (const [pattern, senderId] of contentHints) {
        if (pattern.test(text)) return senderId;
    }

    for (const context of contexts) {
        let selected = null;
        let selectedIndex = -1;
        for (const [name, senderId] of candidates) {
            const index = context.lastIndexOf(name);
            if (index > selectedIndex) {
                selected = senderId;
                selectedIndex = index;
            }
        }
        if (selected) return selected;
    }

    return null;
}

function toMessages(text) {
    const messages = [];
    const pattern = /「[\s\S]*?」|『[\s\S]*?』/g;
    let cursor = 0;
    let match;
    while ((match = pattern.exec(text)) !== null) {
        const narrative = text.slice(cursor, match.index).trim();
        if (narrative) messages.push({ type: 'narrative', text: narrative });
        const senderId = senderForQuote(text, match.index, pattern.lastIndex, match[0]);
        if (senderId) {
            messages.push({
                type: 'dialogue',
                senderId,
                text: match[0].slice(1, -1).trim()
            });
        } else {
            messages.push({ type: 'narrative', text: match[0] });
        }
        cursor = pattern.lastIndex;
    }
    const remaining = text.slice(cursor).trim();
    if (remaining) messages.push({ type: 'narrative', text: remaining });
    return messages.length ? messages : [{ type: 'narrative', text }];
}

function label(value) {
    return value.trim().split(/→|->/)[0].replace(/^【選択】\s*/, '').trim();
}

function dialogueKey(id) {
    if (id === '1') return '1';
    if (['2', '3'].includes(id)) return '2';
    if (['4', '5', '6', '7'].includes(id)) return '3';
    if (['8', '9', '10', '11', '12', '13', '14'].includes(id)) return '4';
    return '5';
}

function checkpointFor(id) {
    if (id === '1') return 'start';
    if (['2', '3'].includes(id)) return 'checkpoint1';
    if (['4', '5', '6', '7'].includes(id)) return 'checkpoint2';
    if (['8', '9', '10', '11', '12', '13', '14'].includes(id)) return 'checkpoint3';
    return 'goal';
}

function stampGateFor(id) {
    if (id === '1') return 1;
    if (['2', '3'].includes(id)) return 2;
    if (['4', '5', '6', '7'].includes(id)) return 3;
    if (['8', '9', '10', '11', '12', '13', '14'].includes(id)) return 4;
    return 5;
}

const graph = { start: '1', nodes: {} };
const source = fs.readFileSync(path.resolve(sourcePath), 'utf8').replace(/^\uFEFF/, '');
for (const row of parseCsv(source)) {
    const id = nodeId(row[0] || '');
    if (!id || !row[1]) continue;
    const node = {
        checkpoint: checkpointFor(id),
        dialogueKey: dialogueKey(id),
        messages: toMessages(row[1].trim()),
        stampGate: stampGateFor(id),
    };
    if (endings[id]) node.endingKey = endings[id];
    const options = [row[11], row[13]].filter((value) => value && value.trim());
    const next = transitions[id] || {};
    if (options.length) {
        node.question = '選択してください';
        node.options = options.map((value, index) => ({
            value: index === 0 ? 'A' : 'B',
            label: label(value),
            next: next[index === 0 ? 'A' : 'B'] || null
        })).filter((option) => option.next !== null);
    }
    if (id === '18a') {
        node.next = '18';
    }
    graph.nodes[id] = node;
}

fs.writeFileSync(path.resolve(__dirname, '..', 'json', 'storyGraph.json'), `${JSON.stringify(graph, null, 2)}\n`);
console.log(`Imported ${Object.keys(graph.nodes).length} nodes.`);