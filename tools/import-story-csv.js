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

function senderForQuote(source, start, end) {
    const contextStart = Math.max(0, start - 60);
    const contextEnd = Math.min(source.length, end + 60);
    const context = source.slice(contextStart, contextEnd);
    const candidates = [
        ['キリコ', 'kiriko'],
        ['ブンジ', 'bunji'],
        ['管理AI', 'guideAi'],
        ['AI', 'guideAi'],
        ['あなた', 'player'],
        ['博士', 'doctor']
    ];
    let selected = null;

    for (const [name, senderId] of candidates) {
        const before = context.lastIndexOf(name, start - contextStart);
        const afterOffset = end - contextStart;
        const after = context.indexOf(name, afterOffset);
        const distanceBefore = before === -1 ? Infinity : start - (contextStart + before);
        const distanceAfter = after === -1 ? Infinity : contextStart + after - end;
        const distance = Math.min(distanceBefore, distanceAfter);
        if (selected === null || distance < selected.distance) {
            selected = { senderId, distance };
        }
    }

    return selected && selected.distance !== Infinity ? selected.senderId : 'system';
}

function toMessages(text) {
    const messages = [];
    const pattern = /「[\s\S]*?」|『[\s\S]*?』/g;
    let cursor = 0;
    let match;
    while ((match = pattern.exec(text)) !== null) {
        const narrative = text.slice(cursor, match.index).trim();
        if (narrative) messages.push({ senderId: 'system', text: narrative });
        messages.push({
            senderId: senderForQuote(text, match.index, pattern.lastIndex),
            text: match[0].slice(1, -1).trim()
        });
        cursor = pattern.lastIndex;
    }
    const remaining = text.slice(cursor).trim();
    if (remaining) messages.push({ senderId: 'system', text: remaining });
    return messages.length ? messages : [{ senderId: 'system', text }];
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

const graph = { start: '1', nodes: {} };
const source = fs.readFileSync(path.resolve(sourcePath), 'utf8').replace(/^\uFEFF/, '');
for (const row of parseCsv(source)) {
    const id = nodeId(row[0] || '');
    if (!id || !row[1]) continue;
    const node = { dialogueKey: dialogueKey(id) };
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