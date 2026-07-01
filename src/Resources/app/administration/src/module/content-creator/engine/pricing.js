/**
 * Grobe Kosten-Schätzung aus Token-Zahlen (USD pro 1M Tokens, Stand 2026-07).
 * Längste Modell-Präfixe zuerst matchen (wie DEFAULT_PRICING im Tool).
 * Nur eine Orientierung — verbindlich ist die Provider-Abrechnung.
 */
const PRICING = {
    'claude-opus-4': { input: 15, output: 75 },
    'claude-sonnet-4': { input: 3, output: 15 },
    'claude-haiku-4': { input: 1, output: 5 },
    'gpt-4o-mini': { input: 0.15, output: 0.6 },
    'gpt-4o': { input: 2.5, output: 10 },
    'gpt-5-mini': { input: 0.25, output: 2 },
    'gpt-5-nano': { input: 0.05, output: 0.4 },
    'gpt-5': { input: 1.25, output: 10 },
};

export function estimateCost(model, inputTokens, outputTokens) {
    if (!model) {
        return null;
    }
    const key = Object.keys(PRICING)
        .sort((a, b) => b.length - a.length)
        .find((prefix) => model.startsWith(prefix));
    if (!key) {
        return null;
    }
    const price = PRICING[key];
    const cost = (inputTokens / 1e6) * price.input + (outputTokens / 1e6) * price.output;

    return Math.round(cost * 10000) / 10000;
}

export function formatCost(cost) {
    if (cost === null || cost === undefined) {
        return '';
    }

    return cost < 0.01 ? `< $0.01` : `~ $${cost.toFixed(2)}`;
}
