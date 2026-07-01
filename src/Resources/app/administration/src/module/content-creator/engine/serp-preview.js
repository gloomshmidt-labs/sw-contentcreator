/**
 * SERP-Snippet-Vorschau mit Pixel-Messung: Google schneidet Meta-Titles nach
 * PIXELN ab (nicht Zeichen) — Desktop ~580px bei 20px Arial für den Title,
 * ~920px bei 14px Arial für die Description. Messung via Canvas measureText.
 */

export const TITLE_LIMIT_PX = 580;
export const DESC_LIMIT_PX = 920;

const TITLE_FONT = '20px Arial, sans-serif';
const DESC_FONT = '14px Arial, sans-serif';

let ctx = null;

function context() {
    if (!ctx) {
        ctx = document.createElement('canvas').getContext('2d');
    }
    return ctx;
}

function measure(text, font) {
    const c = context();
    c.font = font;
    return Math.round(c.measureText(text || '').width);
}

export function titlePx(text) {
    return measure(text, TITLE_FONT);
}

export function descPx(text) {
    return measure(text, DESC_FONT);
}

/** Text an der Pixelgrenze abschneiden (mit Ellipse), wie Google es rendert. */
export function truncateToPx(text, font, limitPx) {
    if (measure(text, font) <= limitPx) {
        return text;
    }
    let result = text || '';
    while (result.length > 0 && measure(`${result} …`, font) > limitPx) {
        result = result.substring(0, result.length - 1);
    }
    return `${result.trimEnd()} …`;
}

export function truncateTitle(text) {
    return truncateToPx(text, TITLE_FONT, TITLE_LIMIT_PX);
}

export function truncateDesc(text) {
    return truncateToPx(text, DESC_FONT, DESC_LIMIT_PX);
}

/** Farbe für den Längenbalken: grün im Zielbereich, gelb knapp, rot drüber. */
export function barColor(px, limit) {
    if (px > limit) return '#ef4444';
    if (px > limit * 0.95 || px < limit * 0.4) return '#eab308';
    return '#22c55e';
}
