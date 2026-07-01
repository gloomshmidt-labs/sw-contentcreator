/**
 * Anzeige-Helfer für die Analyse: Markiert-Ansicht (KI-Muster-Highlighting mit
 * Overlap-Auflösung), Wort-Level-LCS-Diff, HTML-aware-Diff und Flesch-Index.
 * Portiert aus dem Textoptimierung-Tool (app.js highlightText/generateDiff/
 * generateHtmlDiff, local-validation-browser.js calculateFleschIndex) —
 * Klassen durch Inline-Styles ersetzt (helles Admin-Theme, kein SCSS nötig).
 */

const STYLES = {
    'ai-strong': 'background:#fecaca;color:#991b1b;border-radius:3px;padding:0 2px;',
    'ai-medium': 'background:#fed7aa;color:#9a3412;border-radius:3px;padding:0 2px;',
    'ai-weak': 'background:#fef08a;color:#854d0e;border-radius:3px;padding:0 2px;',
    stopword: 'background:#e0e7ff;color:#3730a3;border-radius:3px;padding:0 2px;',
    removed: 'background:#fee2e2;color:#991b1b;text-decoration:line-through;border-radius:3px;padding:0 2px;',
    added: 'background:#dcfce7;color:#166534;border-radius:3px;padding:0 2px;',
};

export function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
}

/**
 * Markiert Stopwords + KI-Muster im Plaintext des Analyse-Ergebnisses
 * (TextOptimiser.analyse()). Überlappungen werden positionsweise entfernt.
 */
export function highlightText(result) {
    const highlights = [];

    for (const sw of result.stopwordsFound || []) {
        highlights.push({ start: sw.position, length: sw.length, style: STYLES.stopword, title: 'Stopword' });
    }
    for (const p of result.patternsFound || []) {
        const style = p.severity === 'high' ? STYLES['ai-strong'] : (p.severity === 'medium' ? STYLES['ai-medium'] : STYLES['ai-weak']);
        highlights.push({ start: p.position, length: p.length || p.pattern.length, style, title: `KI-Muster (Score ${p.score})` });
    }

    highlights.sort((a, b) => b.start - a.start);
    const used = new Set();
    const filtered = [];
    for (const h of highlights) {
        let overlap = false;
        for (let i = h.start; i < h.start + h.length; i++) {
            if (used.has(i)) { overlap = true; break; }
        }
        if (!overlap) {
            filtered.push(h);
            for (let i = h.start; i < h.start + h.length; i++) used.add(i);
        }
    }

    let highlighted = result.plainText || '';
    for (const h of filtered) {
        const before = highlighted.substring(0, h.start);
        const match = highlighted.substring(h.start, h.start + h.length);
        const after = highlighted.substring(h.start + h.length);
        highlighted = `${before}<span style="${h.style}" title="${escapeHtml(h.title)}">${escapeHtml(match)}</span>${after}`;
    }
    return highlighted;
}

/** Wort-Level-Diff via LCS (DP + Backtracking). */
export function generateDiff(original, optimised, lang = 'de') {
    if (original === optimised) {
        return `<p style="color:#758ca3;text-align:center;padding:20px">${lang === 'de' ? 'Keine Änderungen.' : 'No changes.'}</p>`;
    }

    const tokenize = (text) => {
        const tokens = [];
        let current = '';
        for (const char of text) {
            if (/\s/.test(char)) {
                if (current) tokens.push({ type: 'word', value: current });
                tokens.push({ type: 'space', value: char });
                current = '';
            } else {
                current += char;
            }
        }
        if (current) tokens.push({ type: 'word', value: current });
        return tokens;
    };

    const origTokens = tokenize(original);
    const origWords = origTokens.filter(t => t.type === 'word').map(t => t.value);
    const optWords = tokenize(optimised).filter(t => t.type === 'word').map(t => t.value);

    const m = origWords.length;
    const n = optWords.length;
    const dp = Array(m + 1).fill(null).map(() => Array(n + 1).fill(0));
    for (let i = 1; i <= m; i++) {
        for (let j = 1; j <= n; j++) {
            dp[i][j] = origWords[i - 1] === optWords[j - 1]
                ? dp[i - 1][j - 1] + 1
                : Math.max(dp[i - 1][j], dp[i][j - 1]);
        }
    }

    const origStatus = Array(m).fill('removed');
    const optStatus = Array(n).fill('added');
    let i = m;
    let j = n;
    while (i > 0 && j > 0) {
        if (origWords[i - 1] === optWords[j - 1]) {
            origStatus[i - 1] = 'same';
            optStatus[j - 1] = 'same';
            i--; j--;
        } else if (dp[i - 1][j] > dp[i][j - 1]) {
            i--;
        } else {
            j--;
        }
    }

    let html = '';
    let oi = 0;
    let ni = 0;
    for (const token of origTokens) {
        if (token.type === 'space') {
            html += token.value === '\n' ? '<br>' : ' ';
            continue;
        }
        if (origStatus[oi] === 'removed') {
            html += `<span style="${STYLES.removed}">${escapeHtml(token.value)}</span>`;
        } else {
            while (ni < optStatus.length && optStatus[ni] === 'added') {
                html += `<span style="${STYLES.added}">${escapeHtml(optWords[ni])}</span> `;
                ni++;
            }
            html += escapeHtml(token.value);
            ni++;
        }
        oi++;
    }
    while (ni < optWords.length) {
        if (optStatus[ni] === 'added') {
            html += ` <span style="${STYLES.added}">${escapeHtml(optWords[ni])}</span>`;
        }
        ni++;
    }

    return html;
}

/** HTML-aware Diff: Block-Struktur erhalten, Wort-Diff je Block. */
export function generateHtmlDiff(originalHtml, optimisedHtml, lang = 'de') {
    const splitBlocks = (html) => {
        const blocks = [];
        const regex = /<(h[1-6]|p|li|div|td|th|blockquote|figcaption|dd|dt)\b([^>]*)>([\s\S]*?)<\/\1>/gi;
        let match;
        while ((match = regex.exec(html))) {
            blocks.push({
                tag: match[1],
                attrs: match[2],
                html: match[3],
                text: match[3].replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim(),
            });
        }
        return blocks;
    };

    const origBlocks = splitBlocks(originalHtml || '');
    const optBlocks = splitBlocks(optimisedHtml || '');

    if (origBlocks.length === 0 || optBlocks.length === 0) {
        const plainOrig = (originalHtml || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        const plainOpt = (optimisedHtml || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        return generateDiff(plainOrig, plainOpt, lang);
    }

    let html = '';
    const maxLen = Math.max(origBlocks.length, optBlocks.length);
    for (let idx = 0; idx < maxLen; idx++) {
        const orig = origBlocks[idx];
        const opt = optBlocks[idx];
        if (!opt) {
            html += `<${orig.tag}${orig.attrs}><span style="${STYLES.removed}">${escapeHtml(orig.text)}</span></${orig.tag}>`;
        } else if (!orig) {
            html += `<${opt.tag}${opt.attrs}><span style="${STYLES.added}">${escapeHtml(opt.text)}</span></${opt.tag}>`;
        } else if (orig.text === opt.text) {
            html += `<${opt.tag}${opt.attrs}>${opt.html}</${opt.tag}>`;
        } else {
            html += `<${opt.tag}${opt.attrs}>${generateDiff(orig.text, opt.text, lang)}</${opt.tag}>`;
        }
    }

    return html;
}

/** Flesch Reading Ease (DE: 180 − ASL − 58.5·ASW, EN: 206.835 − 1.015·ASL − 84.6·ASW). */
export function calculateFleschIndex(text, lang = 'de') {
    const plainText = (text || '').replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
    if (!plainText) {
        return null;
    }

    const sentences = plainText.split(/[.!?]+/).filter(s => s.trim().length > 0);
    const sentenceCount = Math.max(1, sentences.length);
    const words = plainText.split(/\s+/).filter(w => w.match(/[a-zA-ZäöüÄÖÜß]/));
    const wordCount = Math.max(1, words.length);
    const syllableCount = words.reduce((sum, word) => sum + countSyllables(word, lang), 0);

    const asl = wordCount / sentenceCount;
    const asw = syllableCount / wordCount;
    let score = lang === 'de' ? (180 - asl - (58.5 * asw)) : (206.835 - (1.015 * asl) - (84.6 * asw));
    score = Math.max(0, Math.min(100, score));

    return {
        score: Math.round(score * 10) / 10,
        level: readabilityLevel(score, lang),
    };
}

function countSyllables(word, lang) {
    word = word.toLowerCase().replace(/[^a-zäöüß]/g, '');
    if (word.length <= 2) return 1;

    if (lang === 'de') {
        const vowelGroups = word.match(/[aeiouyäöü]+/gi) || [];
        let count = vowelGroups.length;
        if (word.match(/tion$/)) count = Math.max(2, count);
        if (word.match(/heit$|keit$|ung$/)) count = Math.max(2, count);
        return Math.max(1, count);
    }

    const vowelGroups = word.match(/[aeiouy]+/gi) || [];
    let count = vowelGroups.length;
    if (word.endsWith('e') && count > 1) count--;
    if (word.match(/[^aeiou]le$/)) count++;
    return Math.max(1, count);
}

/**
 * Duplicate-Content-Check: Jaccard-Ähnlichkeit über Wort-3-Gramme (0-100%).
 * Für den Vergleich von Kanal-Varianten — hohe Werte = zu ähnlich formuliert.
 */
export function similarity(a, b) {
    const shingles = (text) => {
        const words = (text || '')
            .toLowerCase()
            .replace(/<[^>]*>/g, ' ')
            .replace(/[^\p{L}\p{N}\s]/gu, '')
            .split(/\s+/)
            .filter(Boolean);
        const set = new Set();
        for (let i = 0; i < words.length - 2; i++) {
            set.add(`${words[i]} ${words[i + 1]} ${words[i + 2]}`);
        }
        return set;
    };

    const setA = shingles(a);
    const setB = shingles(b);
    if (!setA.size || !setB.size) {
        return null;
    }
    let intersection = 0;
    for (const shingle of setA) {
        if (setB.has(shingle)) intersection++;
    }

    return Math.round((intersection / (setA.size + setB.size - intersection)) * 100);
}

function readabilityLevel(score, lang) {
    const de = lang === 'de';
    if (score >= 60) return { label: de ? 'Leicht verständlich' : 'Easy to read', color: '#22c55e' };
    if (score >= 40) return { label: de ? 'Durchschnittlich' : 'Average', color: '#eab308' };
    if (score >= 20) return { label: de ? 'Etwas schwierig' : 'Somewhat difficult', color: '#f97316' };
    return { label: de ? 'Schwer verständlich' : 'Difficult', color: '#ef4444' };
}
