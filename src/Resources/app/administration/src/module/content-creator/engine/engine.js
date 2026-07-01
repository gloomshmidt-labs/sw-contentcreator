import { RULES_DE } from './rules-de';
import { RULES_EN } from './rules-en';

// ============================================================
// Text Optimisation Engine – E-Commerce Edition
// ============================================================

export class TextOptimiser {
  constructor(lang = 'de') {
    this.lang = lang;
    this.rules = lang === 'de' ? RULES_DE : RULES_EN;
  }

  setLanguage(lang) {
    this.lang = lang;
    this.rules = lang === 'de' ? RULES_DE : RULES_EN;
  }

  // ---- HTML Helpers ----

  // Strip HTML tags for analysis, return plain text
  _stripHtml(html) {
    return html
      // Handle tags with quoted attributes containing >
      .replace(/<[^>]*(?:"[^"]*"|'[^']*')*[^>]*>/g, ' ')
      // Standard HTML entities
      .replace(/&amp;/g, '&')
      .replace(/&lt;/g, '<').replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"').replace(/&#39;/g, "'")
      .replace(/&nbsp;/g, ' ')
      // German HTML entities
      .replace(/&auml;/g, 'ä').replace(/&Auml;/g, 'Ä')
      .replace(/&ouml;/g, 'ö').replace(/&Ouml;/g, 'Ö')
      .replace(/&uuml;/g, 'ü').replace(/&Uuml;/g, 'Ü')
      .replace(/&szlig;/g, 'ß')
      // Common typographic entities
      .replace(/&mdash;/g, '—').replace(/&ndash;/g, '–')
      .replace(/&hellip;/g, '…').replace(/&euro;/g, '€')
      // Numeric entities (common ones)
      .replace(/&#(\d+);/g, (match, dec) => String.fromCharCode(dec))
      .replace(/&#x([0-9a-f]+);/gi, (match, hex) => String.fromCharCode(parseInt(hex, 16)))
      // Cleanup whitespace
      .replace(/\s+/g, ' ').trim();
  }

  // Check if text contains HTML tags
  _hasHtml(text) {
    return /<[a-z/][^>]*>/i.test(text);
  }

  // ---- German Inflection Helpers ----

  _isSingleWord(pattern) {
    return !pattern.includes(' ') && pattern.length > 2;
  }

  _buildInflectedRegex(pattern, flags = 'gi') {
    const escaped = this._escapeRegex(pattern);
    if (this.lang === 'de' && this._isSingleWord(pattern)) {
      // German single words: match with inflection endings (adjective + verb)
      return new RegExp(`\\b${escaped}(e[rsnm]?|em|en)?\\b`, flags);
    }
    if (this.lang === 'de' && pattern.includes(' ')) {
      // German multi-word: flex each word for verb conjugation + last word adjective endings
      const words = pattern.split(/\s+/);
      const flexParts = words.map((word, idx) => {
        const esc = this._escapeRegex(word);
        return this._germanWordFlex(esc, word, idx === words.length - 1);
      });
      return new RegExp(`\\b${flexParts.join('\\s+')}(?=\\s|[.,;:!?)]|$)`, flags);
    }
    if (this._isSingleWord(pattern)) {
      // English single words: strict word boundaries to avoid partial matches
      return new RegExp(`\\b${escaped}\\b`, flags);
    }
    // Multi-word patterns: use word boundary at start, lookahead at end
    // This prevents matching inside other words (e.g., "deine" matching "eine welt")
    return new RegExp(`\\b${escaped}(?=\\s|[.,;:!?)]|$)`, flags);
  }

  // Flex a German word for verb conjugation (eignet↔eignen, fördert↔fördern, etc.)
  _germanWordFlex(escaped, word, isLastWord) {
    // Skip short function words (sich, die, der, Sie, und, von, etc.)
    if (word.length <= 4) return escaped;

    // Verb conjugation: match common present-tense forms
    // -tet (arbeitet): stem + tet/ten/te/test
    if (word.endsWith('tet'))
      return escaped.slice(0, -3) + '(?:tet|ten|te|test)';
    // -ert (fördert): stem + ert/ern/ere/erst
    if (word.endsWith('ert') && word.length > 4)
      return escaped.slice(0, -3) + '(?:ert|ern|ere|erst)';
    // -et (eignet, bietet): stem + et/en/e/est
    if (word.endsWith('et'))
      return escaped.slice(0, -2) + '(?:et|en|e|est)';
    // -en (entdecken, ermöglichen): stem + en/et/t/e/est
    if (word.endsWith('en'))
      return escaped.slice(0, -2) + '(?:en|et|t|e|est)';
    // -t (ermöglicht, überzeugt): stem + t/en/e/est
    if (word.endsWith('t'))
      return escaped.slice(0, -1) + '(?:t|en|e|est)';

    // Last word: also allow adjective endings (hervorragend → hervorragende/hervorragenden)
    if (isLastWord) return escaped + '(?:e[rsnm]?|em|en)?';

    return escaped;
  }

  _transferGermanEnding(matchedText, basePatternLength, replacement) {
    if (this.lang !== 'de') return replacement;
    const ending = matchedText.slice(basePatternLength);
    if (!ending) return replacement;

    const indeclinable = ['super', 'prima', 'klasse', 'extra', 'mega', 'top', 'cool',
      'genau richtig', 'gut geeignet', 'jede Menge', 'viel', 'viele', 'zahlreiche',
      'toll', 'wichtig', 'nützlich', 'hilfreich'];

    // For multi-word replacements, add ending to the last word
    if (replacement.includes(' ')) {
      const words = replacement.split(' ');
      const lastWord = words[words.length - 1];
      const lowerLast = lastWord.toLowerCase();
      if (indeclinable.some(w => w.toLowerCase() === replacement.toLowerCase())) return replacement;
      if (lowerLast.endsWith('s') || lowerLast.endsWith(ending.toLowerCase())) return replacement;
      words[words.length - 1] = lastWord + ending;
      return words.join(' ');
    }

    const lower = replacement.toLowerCase();
    if (lower.endsWith('s')) return replacement;
    if (indeclinable.some(w => w.toLowerCase() === lower)) return replacement;
    if (lower.endsWith(ending.toLowerCase())) return replacement;
    return replacement + ending;
  }

  // ---- Full Analysis ----
  analyse(text) {
    // Guard against null/undefined/empty input
    if (!text || typeof text !== 'string' || !text.trim()) {
      return {
        original: text || '',
        plainText: '',
        isHtml: false,
        aiScore: 0,
        findings: [],
        stopwordsFound: [],
        patternsFound: [],
        structuralIssues: [],
        naturalnessMarkers: [],
        suggestions: [],
        optimised: null,
        rating: this._getRating(0)
      };
    }

    const isHtml = this._hasHtml(text);
    const plainText = isHtml ? this._stripHtml(text) : text;

    const result = {
      original: text,
      plainText,
      isHtml,
      aiScore: 0,
      findings: [],
      stopwordsFound: [],
      patternsFound: [],
      structuralIssues: [],
      naturalnessMarkers: [],
      suggestions: [],
      optimised: null
    };

    // All analysis runs on plain text
    this._findStopwords(plainText, result);
    this._detectAiPatterns(plainText, result);
    this._analyseStructure(plainText, result);
    this._detectNaturalness(plainText, result);
    this._detectPassive(plainText, result);
    this._detectLoremIpsum(plainText, result);
    if (isHtml) this._detectHtmlIssues(text, result);
    if (this.lang === 'en') this._detectTranslationArtifacts(plainText, result);

    result.aiScore = Math.max(0, result.aiScore);
    result.rating = this._getRating(result.aiScore);

    // Count sentences above rewrite threshold (score >= 5)
    let sentences;
    if (isHtml) {
      const blockTexts = this._splitHtmlIntoBlockTexts(text);
      sentences = blockTexts.flatMap(block => this._splitIntoSentences(block));
    } else {
      sentences = this._splitIntoSentences(plainText);
    }
    const validSentences = sentences.filter(s => s.trim().length >= 10);
    result.sentenceCount = validSentences.length;
    result.rewriteCandidates = validSentences.filter(s => {
      const a = this._analyseSentence(s.trim());
      return a.score >= 5;
    }).length;

    return result;
  }

  // ============================================================
  // OPTION B: Full Sentence Rewriting with LLM
  // ============================================================

  async optimiseWithRewriting(text, options = {}) {
    if (!text || typeof text !== 'string' || !text.trim()) {
      return { text: text || '', results: [], stats: {} };
    }

    const onProgress = options.onProgress || (() => {});
    const isHtml = this._hasHtml(text);

    // Split into sentences — block-aware for HTML so sentences never span
    // across block-level elements (h2, p, li, etc.)
    let sentences;
    if (isHtml) {
      const blockTexts = this._splitHtmlIntoBlockTexts(text);
      sentences = blockTexts.flatMap(block => this._splitIntoSentences(block));
    } else {
      sentences = this._splitIntoSentences(text);
    }
    _log('[ENGINE] Split into sentences:', sentences.length);

    const results = [];
    let rewrittenText = text;
    let totalImproved = 0;
    let totalKept = 0;
    let totalErrors = 0;

    for (let i = 0; i < sentences.length; i++) {
      // Check for abort signal
      if (options.signal?.aborted) {
        _log('[ENGINE] Aborted by user');
        break;
      }

      const sentence = sentences[i].trim();
      if (!sentence || sentence.length < 10) continue;

      onProgress('analyzing', Math.round((i / sentences.length) * 100), sentence);

      // Analyze this sentence for AI patterns
      const analysis = this._analyseSentence(sentence);

      if (analysis.score < 5) {
        // Sentence has no significant AI markers
        _log(`[ENGINE] Sentence ${i + 1}: Score ${analysis.score} - OK, skipping`);
        results.push({
          original: sentence,
          rewritten: null,
          action: 'kept-good',
          score: analysis.score,
          reason: this.lang === 'de' ? 'Bereits gut' : 'Already good'
        });
        totalKept++;
        continue;
      }

      const patternNames = analysis.patterns.map(p => p.pattern);
      _log(`[ENGINE] Sentence ${i + 1}: Score ${analysis.score} (patterns: ${patternNames.join(', ')}) - needs rewriting`);
      onProgress('rewriting', Math.round((i / sentences.length) * 100), sentence);

      // LLM rewriting with retry (patterns include alternatives for targeted replacement)
      const maxAttempts = 2;
      let accepted = false;
      let lastFailedRewrite = null;
      let lastFailureReason = null;

      for (let attempt = 1; attempt <= maxAttempts; attempt++) {
        if (options.signal?.aborted) break;

        // Pass detected patterns with alternatives to LLM
        const combined = await llmValidator.rewriteWithFacts(
          sentence, this.lang, analysis.patterns,
          attempt > 1 ? { failedRewrite: lastFailedRewrite, reason: lastFailureReason } : null
        );
        const facts = combined.facts || [];
        const rewriteResult = { rewritten: combined.rewritten, error: combined.error };

        if (!rewriteResult.rewritten || rewriteResult.error) {
          _log(`[ENGINE] Rewrite failed (attempt ${attempt}):`, rewriteResult.error);
          if (attempt === maxAttempts) {
            results.push({
              original: sentence,
              rewritten: null,
              action: 'error',
              score: analysis.score,
              reason: rewriteResult.error || 'Rewrite failed'
            });
            totalErrors++;
          }
          continue;
        }

        const rewritten = rewriteResult.rewritten;

        // Check if facts are preserved
        const factsCheck = llmValidator.checkFactsPreserved(facts, rewritten);
        if (!factsCheck.preserved) {
          _log(`[ENGINE] Facts lost (attempt ${attempt}):`, factsCheck.missing);
          lastFailedRewrite = rewritten;
          lastFailureReason = `facts lost: ${factsCheck.missing.join(', ')}`;
          if (attempt === maxAttempts) {
            results.push({
              original: sentence,
              rewritten: rewritten,
              action: 'rejected-facts',
              score: analysis.score,
              reason: `${this.lang === 'de' ? 'Fakten verloren' : 'Facts lost'}: ${factsCheck.missing.join(', ')}`
            });
            totalKept++;
          }
          continue;
        }

        // Check if score improved vs ORIGINAL (not pre-optimised)
        const newAnalysis = this._analyseSentence(rewritten);
        if (newAnalysis.score >= analysis.score) {
          _log(`[ENGINE] Score not improved (attempt ${attempt}): ${analysis.score} → ${newAnalysis.score}`);
          lastFailedRewrite = rewritten;
          const remainingPatterns = newAnalysis.patterns.map(p => p.pattern);
          lastFailureReason = `score not improved, remaining patterns: ${remainingPatterns.join(', ')}`;
          if (attempt === maxAttempts) {
            results.push({
              original: sentence,
              rewritten: rewritten,
              action: 'rejected-score',
              oldScore: analysis.score,
              newScore: newAnalysis.score,
              reason: `${this.lang === 'de' ? 'Score nicht verbessert' : 'Score not improved'}: ${analysis.score} → ${newAnalysis.score}`
            });
            totalKept++;
          }
          continue;
        }

        // All checks passed - use rewritten version
        _log(`[ENGINE] ✓ Improved (attempt ${attempt}): ${analysis.score} → ${newAnalysis.score}`);
        accepted = true;

        // Replace ORIGINAL sentence in text (not pre-optimised)
        rewrittenText = this._replaceSentenceInText(rewrittenText, sentence, rewritten);

        results.push({
          original: sentence,
          rewritten: rewritten,
          action: 'improved',
          oldScore: analysis.score,
          newScore: newAnalysis.score,
          facts: facts,
          reason: `Score: ${analysis.score} → ${newAnalysis.score}`
        });
        totalImproved++;
        break; // Exit retry loop on success
      } // End retry for-loop
    } // End sentence for-loop

    // Final cleanup
    rewrittenText = this._cleanup(rewrittenText);
    if (options.decodeEntities !== false) {
      rewrittenText = this._decodeHtmlEntities(rewrittenText);
    }

    onProgress('done', 100);

    return {
      text: rewrittenText,
      results,
      stats: {
        total: sentences.length,
        improved: totalImproved,
        kept: totalKept,
        errors: totalErrors
      }
    };
  }

  // Split HTML into plaintext segments at block-level boundaries.
  // Each segment contains the text content of one block-level element,
  // so sentence splitting never produces cross-block sentences.
  _splitHtmlIntoBlockTexts(html) {
    // Replace block-level tags with double-newline separators
    const blockTags = 'h[1-6]|p|div|ul|ol|li|table|tr|td|th|thead|tbody|section|article|blockquote|pre|hr|br|dd|dt|figcaption';
    const withSeps = html.replace(new RegExp(`<\\/?(${blockTags})\\b[^>]*>`, 'gi'), '\n\n');
    // Strip remaining inline tags and decode entities
    const plain = withSeps
      .replace(/<[^>]*>/g, ' ')
      .replace(/&amp;/g, '&')
      .replace(/&lt;/g, '<').replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"').replace(/&#39;/g, "'")
      .replace(/&nbsp;/g, ' ')
      .replace(/&auml;/g, 'ä').replace(/&Auml;/g, 'Ä')
      .replace(/&ouml;/g, 'ö').replace(/&Ouml;/g, 'Ö')
      .replace(/&uuml;/g, 'ü').replace(/&Uuml;/g, 'Ü')
      .replace(/&szlig;/g, 'ß')
      .replace(/&mdash;/g, '—').replace(/&ndash;/g, '–')
      .replace(/&hellip;/g, '…').replace(/&euro;/g, '€')
      .replace(/&#(\d+);/g, (m, dec) => String.fromCharCode(dec))
      .replace(/&#x([0-9a-f]+);/gi, (m, hex) => String.fromCharCode(parseInt(hex, 16)));
    // Split on double-newlines, clean up each block
    return plain
      .split(/\n{2,}/)
      .map(block => block.replace(/\s+/g, ' ').trim())
      .filter(block => block.length > 0);
  }

  // Common abbreviations that should not trigger sentence splits
  static _ABBREVS = new Set([
    'z.b', 'u.a', 'o.ä', 'd.h', 'bzw', 'evtl', 'ggf', 'inkl', 'exkl', 'zzgl',
    'ca', 'nr', 'dr', 'mr', 'mrs', 'ms', 'prof', 'str', 'tel', 'abs', 'bsp',
    'etc', 'vgl', 'usw', 'sog', 'max', 'min', 'approx', 'dept', 'fig', 'vol',
    'vs', 'st', 'jr', 'sr', 'ltd', 'inc', 'corp', 'e.g', 'i.e', 'p.s',
    'u.u', 'm.e', 's.o', 's.u', 'o.g', 'i.d', 'u.v'
  ]);

  // Split text into sentences (handles abbreviations, decimals, ellipsis)
  _splitIntoSentences(text) {
    const sentences = [];
    let current = '';

    for (let i = 0; i < text.length; i++) {
      current += text[i];

      if (text[i] === '.' || text[i] === '!' || text[i] === '?') {
        // Skip ellipsis (... or …)
        if (text[i] === '.' && (text[i + 1] === '.' || text[i - 1] === '.')) continue;

        const nextChar = text[i + 1] || '';
        const isEndOfText = nextChar === '';
        const isFollowedBySpace = nextChar === ' ' || nextChar === '\n';

        if (!isEndOfText && !isFollowedBySpace) continue;

        // Check for decimal numbers: "45.5", "3.14"
        if (text[i] === '.') {
          const charBefore = text[i - 1] || '';
          const charAfter = text[i + 1] || '';
          if (/\d/.test(charBefore) && /\d/.test(charAfter)) continue;
        }

        // Check for abbreviations: word before the dot
        if (text[i] === '.') {
          const before = current.trimEnd();
          const wordMatch = before.match(/([a-zA-ZäöüÄÖÜß.]{1,6})\.$/);
          if (wordMatch) {
            const abbr = wordMatch[1].toLowerCase().replace(/\.$/, '');
            if (TextOptimiser._ABBREVS.has(abbr)) continue;
          }
          // Single uppercase letter (initials): "A. Einstein"
          if (/\b[A-ZÄÖÜ]\.$/.test(before)) continue;

          // Spaced abbreviations: "z. B.", "u. a.", "d. h.", "o. Ä." etc.
          // When we see a single letter + dot, look ahead for another single letter + dot
          const spacedMatch = before.match(/(?:^|\s)([a-zA-ZäöüÄÖÜß])\.$/);
          if (spacedMatch) {
            const rest = text.substring(i + 1).trimStart();
            const nextPart = rest.match(/^([a-zA-ZäöüÄÖÜß])\./);
            if (nextPart) {
              const combined = (spacedMatch[1] + '.' + nextPart[1]).toLowerCase();
              if (TextOptimiser._ABBREVS.has(combined)) continue;
            }
          }
        }

        // Check if next non-space char is lowercase → not a new sentence
        if (isFollowedBySpace && text[i] === '.') {
          const rest = text.substring(i + 1).trimStart();
          if (rest.length > 0 && /^[a-zäöü]/.test(rest)) continue;
        }

        sentences.push(current.trim());
        current = '';
      }
    }

    if (current.trim()) {
      sentences.push(current.trim());
    }

    return sentences.filter(s => s.length > 0);
  }

  // Analyze a single sentence for AI patterns
  _analyseSentence(sentence) {
    const lower = sentence.toLowerCase();
    let score = 0;
    const patterns = [];

    const allPatterns = [
      ...this.rules.aiPatterns.strong,
      ...this.rules.aiPatterns.medium,
      ...this.rules.aiPatterns.weak
    ];

    // Build alternatives lookup for detected patterns
    const allAlts = {
      ...this.rules.alternatives.strong,
      ...this.rules.alternatives.medium,
      ...this.rules.alternatives.weak
    };

    for (const p of allPatterns) {
      const regex = this._buildInflectedRegex(p.pattern, 'gi');
      if (regex.test(lower)) {
        let s = p.score;
        if (p.context && !lower.includes(p.context.toLowerCase())) {
          s = Math.floor(s / 2);
        }
        score += s;
        // Include alternatives if available
        const alts = allAlts[p.pattern] || [];
        patterns.push({ pattern: p.pattern, alternatives: alts });
      }
    }

    return { score, patterns };
  }

  // Replace a sentence in text (handles both plain and HTML)
  _replaceSentenceInText(text, original, replacement) {
    // Strategy 1: Direct regex (fast, safe — works when no HTML tags within sentence)
    const escaped = original.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const pattern = escaped.replace(/\s+/g, '\\s+');
    const regex = new RegExp(pattern, 'g');

    const safeReplacement = replacement.replace(/\$/g, '$$$$');
    const simpleResult = text.replace(regex, safeReplacement);
    if (simpleResult !== text) return simpleResult;

    // Strategy 2: Position-map approach for sentences with inline HTML
    try {
      // Build map: plaintext char index → HTML char index (skipping tags)
      const map = [];
      let inTag = false;
      for (let i = 0; i < text.length; i++) {
        if (text[i] === '<') { inTag = true; continue; }
        if (inTag) { if (text[i] === '>') inTag = false; continue; }
        map.push(i);
      }
      if (map.length === 0) return text;

      // Build plaintext from mapped positions
      const plain = map.map(i => text[i]).join('');

      // Find sentence in plaintext
      const match = new RegExp(pattern).exec(plain);
      if (!match) return text;

      // Map boundaries back to HTML positions
      const endIdx = match.index + match[0].length - 1;
      if (endIdx >= map.length) return text;
      const htmlStart = map[match.index];
      const htmlEnd = map[endIdx] + 1;

      // Safety: refuse to replace if the segment contains block-level tags
      const segment = text.substring(htmlStart, htmlEnd);
      if (/<\/?(h[1-6]|p|div|ul|ol|li|table|tr|td|th|thead|tbody|section|article|blockquote|pre|hr)\b/i.test(segment)) {
        _log('[ENGINE] Skipping replacement: sentence spans block-level HTML tags');
        return text;
      }

      // Map closing tags from segment to their plain-text position,
      // then re-insert them at the same position in the replacement
      const segMap = [];
      let inSegTag = false;
      for (let si = 0; si < segment.length; si++) {
        if (segment[si] === '<') { inSegTag = true; continue; }
        if (inSegTag) { if (segment[si] === '>') inSegTag = false; continue; }
        segMap.push(si);
      }

      const segRe = /<(\/?)([a-z][a-z0-9]*)\b[^>]*\/?>/gi;
      let tm;
      const localStack = [];
      const insertions = [];
      while ((tm = segRe.exec(segment)) !== null) {
        if (tm[1] === '/') {
          const tag = tm[2].toLowerCase();
          const idx = localStack.lastIndexOf(tag);
          if (idx >= 0) { localStack.splice(idx, 1); continue; }
          // Find plain-text position: count mapped chars before this tag
          let plainPos = 0;
          for (let k = 0; k < segMap.length; k++) {
            if (segMap[k] < tm.index) plainPos = k + 1; else break;
          }
          insertions.push({ plainPos, fullTag: tm[0] });
        } else if (!tm[0].endsWith('/>')) {
          localStack.push(tm[2].toLowerCase());
        }
      }

      // Insert closing tags into replacement (from end to preserve positions)
      let patched = replacement;
      insertions.sort((a, b) => b.plainPos - a.plainPos);
      for (const ins of insertions) {
        const pos = Math.min(ins.plainPos, patched.length);
        patched = patched.substring(0, pos) + ins.fullTag + patched.substring(pos);
      }

      return text.substring(0, htmlStart) + patched + text.substring(htmlEnd);
    } catch (e) {
      _log('[ENGINE] Position-map replacement failed:', e.message);
      return text;
    }
  }

  // ---- Optimise Text ----
  // Runs replacements on the original text (including HTML tags)
  // so HTML structure is preserved
  // Options: { decodeEntities: false } - set to false to keep HTML entities like &amp;
  optimise(text, options = {}) {
    // Guard against null/undefined/empty input
    if (!text || typeof text !== 'string' || !text.trim()) {
      return text || '';
    }

    // Default: decode HTML entities (UTF-8 doesn't need them)
    const decodeEntities = options.decodeEntities !== false;

    let optimised = text;
    optimised = this._applyAlternatives(optimised);
    optimised = this._removeStopwords(optimised);
    optimised = this._applyReplacements(optimised);
    optimised = this._applyPassiveToActive(optimised);
    optimised = this._fixArticles(optimised);  // Fix a/an mismatches
    optimised = this._cleanup(optimised);

    // Decode HTML entities by default
    if (decodeEntities) {
      optimised = this._decodeHtmlEntities(optimised);
    }

    return optimised;
  }

  // Fix English a/an article mismatches
  _fixArticles(text) {
    if (this.lang !== 'en') return text;

    // Words that start with vowel sounds (including silent h)
    const vowelSounds = /^[aeiou]/i;
    const consonantSounds = /^[bcdfghjklmnpqrstvwxyz]/i;

    // Fix "an" before consonant sounds
    let result = text.replace(/\b(an)\s+([a-z])/gi, (match, article, nextChar) => {
      if (consonantSounds.test(nextChar)) {
        return (article[0] === 'A' ? 'A' : 'a') + ' ' + nextChar;
      }
      return match;
    });

    // Fix "a" before vowel sounds
    result = result.replace(/\b(a)\s+([aeiou])/gi, (match, article, nextChar) => {
      return (article === 'A' ? 'An' : 'an') + ' ' + nextChar;
    });

    return result;
  }

  // ---- Internal: Find Stopwords ----
  _findStopwords(text, result) {
    const lower = text.toLowerCase();
    const allStopwords = Object.values(this.rules.stopwords).flat();

    for (const sw of allStopwords) {
      const regex = this._buildInflectedRegex(sw, 'gi');
      let match;
      while ((match = regex.exec(lower)) !== null) {
        const matchedText = match[0];
        result.stopwordsFound.push({
          phrase: matchedText,
          position: match.index,
          length: matchedText.length
        });
        result.findings.push({
          type: 'stopword',
          severity: 'high',
          phrase: matchedText,
          message: this.lang === 'de'
            ? `Stopword: "${matchedText}"`
            : `Stopword: "${matchedText}"`,
          position: match.index
        });
      }
    }
  }

  // ---- Internal: Detect AI Patterns ----
  _detectAiPatterns(text, result) {
    const lower = text.toLowerCase();
    const allPatterns = [
      ...this.rules.aiPatterns.strong,
      ...this.rules.aiPatterns.medium,
      ...this.rules.aiPatterns.weak
    ];

    for (const p of allPatterns) {
      const regex = this._buildInflectedRegex(p.pattern, 'gi');
      let match;
      while ((match = regex.exec(lower)) !== null) {
        const matchedText = match[0];
        let score = p.score;
        if (p.context) {
          const surrounding = lower.substring(
            Math.max(0, match.index - 50),
            Math.min(lower.length, match.index + matchedText.length + 50)
          );
          if (!surrounding.includes(p.context.toLowerCase())) {
            score = Math.floor(score / 2);
          }
        }
        result.aiScore += score;
        const severity = score >= 10 ? 'high' : score >= 4 ? 'medium' : 'low';
        result.patternsFound.push({
          pattern: matchedText, score, position: match.index,
          severity, length: matchedText.length
        });
        result.findings.push({
          type: 'ai-pattern', severity, phrase: matchedText,
          message: this.lang === 'de'
            ? `KI-Muster: "${matchedText}" (+${score})`
            : `AI pattern: "${matchedText}" (+${score})`,
          position: match.index, score
        });
      }
    }
  }

  // ---- Internal: Structural Analysis ----
  _analyseStructure(text, result) {
    const sentences = this._splitSentences(text);
    if (sentences.length < 3) return;

    // Paragraph length variance
    const paragraphs = text.split(/\n\s*\n/).filter(p => p.trim());
    if (paragraphs.length >= 2) {
      const lengths = paragraphs.map(p => p.length);
      const mean = lengths.reduce((a, b) => a + b, 0) / lengths.length;
      const variance = lengths.reduce((a, b) => a + (b - mean) ** 2, 0) / lengths.length;
      if (variance < 1000) {
        result.aiScore += 10;
        result.structuralIssues.push({
          type: 'low-paragraph-variance',
          message: this.lang === 'de'
            ? `Geringe Absatzlängen-Varianz (${Math.round(variance)}) – KI-typisch (+10)`
            : `Low paragraph length variance (${Math.round(variance)}) – AI-typical (+10)`,
          score: 10
        });
      }
    }

    // Sentence length uniformity
    const wordCounts = sentences.map(s => s.split(/\s+/).length);
    const inRange = wordCounts.filter(w => w >= 15 && w <= 25).length;
    const ratio = inRange / wordCounts.length;
    if (ratio > 0.7) {
      result.aiScore += 15;
      result.structuralIssues.push({
        type: 'uniform-sentence-length',
        message: this.lang === 'de'
          ? `${Math.round(ratio * 100)}% Sätze mit 15-25 Wörtern – zu gleichförmig (+15)`
          : `${Math.round(ratio * 100)}% sentences 15-25 words – too uniform (+15)`,
        score: 15
      });
    }

    this._checkRepetitiveStarts(sentences, result);
  }

  _checkRepetitiveStarts(sentences, result) {
    const firstWords = sentences.map(s => s.trim().split(/\s+/)[0]?.toLowerCase()).filter(Boolean);
    const wordCounts = {};
    for (const w of firstWords) wordCounts[w] = (wordCounts[w] || 0) + 1;
    for (const [word, count] of Object.entries(wordCounts)) {
      if (count >= 3) {
        const penalty = 3 * (count - 2);
        result.aiScore += penalty;
        result.structuralIssues.push({
          type: 'repetitive-start-1',
          message: this.lang === 'de'
            ? `"${word}" beginnt ${count} Sätze (+${penalty})`
            : `"${word}" starts ${count} sentences (+${penalty})`,
          score: penalty
        });
      }
    }

    const twoWordStarts = sentences.map(s => {
      const words = s.trim().split(/\s+/);
      return words.length >= 2 ? (words[0] + ' ' + words[1]).toLowerCase() : '';
    }).filter(Boolean);
    const twoCounts = {};
    for (const w of twoWordStarts) twoCounts[w] = (twoCounts[w] || 0) + 1;
    for (const [phrase, count] of Object.entries(twoCounts)) {
      if (count >= 2) {
        const penalty = 5 * (count - 1);
        result.aiScore += penalty;
        result.structuralIssues.push({
          type: 'repetitive-start-2',
          message: this.lang === 'de'
            ? `"${phrase}" beginnt ${count} Sätze (+${penalty})`
            : `"${phrase}" starts ${count} sentences (+${penalty})`,
          score: penalty
        });
      }
    }

    const threeWordStarts = sentences.map(s => {
      const words = s.trim().split(/\s+/);
      return words.length >= 3 ? (words[0] + ' ' + words[1] + ' ' + words[2]).toLowerCase() : '';
    }).filter(Boolean);
    const threeCounts = {};
    for (const w of threeWordStarts) threeCounts[w] = (threeCounts[w] || 0) + 1;
    for (const [phrase, count] of Object.entries(threeCounts)) {
      if (count >= 2) {
        const penalty = 8 * (count - 1);
        result.aiScore += penalty;
        result.structuralIssues.push({
          type: 'repetitive-start-3',
          message: this.lang === 'de'
            ? `"${phrase}" beginnt ${count} Sätze (+${penalty})`
            : `"${phrase}" starts ${count} sentences (+${penalty})`,
          score: penalty
        });
      }
    }
  }

  // ---- Internal: Naturalness Markers ----
  _detectNaturalness(text, result) {
    const lower = text.toLowerCase();
    const nat = this.rules.naturalness;

    for (const word of nat.colloquialFillers.words) {
      if (new RegExp(`\\b${this._escapeRegex(word)}\\b`, 'gi').test(lower)) {
        result.aiScore += nat.colloquialFillers.bonus;
        result.naturalnessMarkers.push({ type: 'colloquial-filler', word, bonus: nat.colloquialFillers.bonus });
      }
    }
    for (const word of nat.colloquialExpressions.words) {
      if (new RegExp(`\\b${this._escapeRegex(word)}\\b`, 'gi').test(lower)) {
        result.aiScore += nat.colloquialExpressions.bonus;
        result.naturalnessMarkers.push({ type: 'colloquial-expression', word, bonus: nat.colloquialExpressions.bonus });
      }
    }
    for (const pattern of nat.personalAnecdotes.patterns) {
      if (lower.includes(pattern)) {
        result.aiScore += nat.personalAnecdotes.bonus;
        result.naturalnessMarkers.push({ type: 'personal-anecdote', pattern, bonus: nat.personalAnecdotes.bonus });
      }
    }
    const questionCount = (text.match(/\?/g) || []).length;
    if (questionCount >= nat.directQuestions.minCount) {
      result.aiScore += nat.directQuestions.bonus;
      result.naturalnessMarkers.push({ type: 'direct-questions', count: questionCount, bonus: nat.directQuestions.bonus });
    }

    // Specific product details (measurements, ages, etc.) suggest human author
    if (nat.specificDetails) {
      for (const pattern of nat.specificDetails.patterns) {
        if (new RegExp(pattern, 'gi').test(lower)) {
          result.aiScore += nat.specificDetails.bonus;
          result.naturalnessMarkers.push({ type: 'specific-detail', pattern, bonus: nat.specificDetails.bonus });
        }
      }
    }
  }

  // ---- Internal: Passive Voice ----
  _detectPassive(text, result) {
    for (const rule of this.rules.passiveToActive) {
      const regex = new RegExp(this._escapeRegex(rule.passive), 'gi');
      let match;
      while ((match = regex.exec(text)) !== null) {
        result.findings.push({
          type: 'passive-voice', severity: 'medium',
          phrase: rule.passive,
          message: this.lang === 'de'
            ? `Passiv: "${rule.passive}" → "${rule.active}"`
            : `Passive: "${rule.passive}" → "${rule.active}"`,
          position: match.index, suggestion: rule.active
        });
        result.suggestions.push({ original: rule.passive, replacement: rule.active, type: 'passive-to-active' });
      }
    }
  }

  // ---- Internal: Lorem Ipsum Detection ----
  _detectLoremIpsum(text, result) {
    if (/lorem\s+ipsum/i.test(text)) {
      result.aiScore += 100;
      result.structuralIssues.push({
        type: 'lorem-ipsum',
        message: this.lang === 'de'
          ? 'Platzhaltertext (Lorem ipsum) gefunden – muss ersetzt werden (+100)'
          : 'Placeholder text (Lorem ipsum) found – must be replaced (+100)',
        score: 100
      });
    }
  }

  // ---- Internal: HTML Quality Issues ----
  _detectHtmlIssues(html, result) {
    // Detect multiple H1 tags in same content block (more than 1 = problematic)
    const h1Count = (html.match(/<h1[\s>]/gi) || []).length;
    if (h1Count > 1) {
      result.structuralIssues.push({
        type: 'multiple-h1',
        message: this.lang === 'de'
          ? `${h1Count} <h1>-Tags im selben Textblock – nur eine H1 pro Seite verwenden.`
          : `${h1Count} <h1> tags in same text block – use only one H1 per page.`,
        score: 0
      });
    }
    // Detect empty paragraph tags
    const emptyPCount = (html.match(/<p>\s*<\/p>/gi) || []).length;
    if (emptyPCount > 0) {
      result.structuralIssues.push({
        type: 'empty-paragraphs',
        message: this.lang === 'de'
          ? `${emptyPCount} leere <p>-Tags gefunden – sollten entfernt werden.`
          : `${emptyPCount} empty <p> tags found – should be removed.`,
        score: 0
      });
    }
    // Detect &nbsp; abuse (multiple consecutive or used for spacing)
    const nbspDoubles = (html.match(/&nbsp;\s*&nbsp;/gi) || []).length;
    if (nbspDoubles > 0) {
      result.structuralIssues.push({
        type: 'nbsp-abuse',
        message: this.lang === 'de'
          ? `${nbspDoubles}x doppelte &amp;nbsp; gefunden – reguläre Leerzeichen verwenden.`
          : `${nbspDoubles}x double &amp;nbsp; found – use regular spaces.`,
        score: 0
      });
    }
  }

  // ---- Internal: DE→EN Translation Artifact Detection ----
  _detectTranslationArtifacts(text, result) {
    const artifacts = [
      { pattern: /\bchildrens\b/gi, fix: "children's", desc: 'missing apostrophe' },
      { pattern: /\bpuppets\s+head\b/gi, fix: "puppet's head", desc: 'missing apostrophe' },
      { pattern: /\bIn addition,?\s+also\b/gi, fix: 'Also', desc: 'redundant phrase (DE calque)' },
      { pattern: /\bthe outfits is to be\b/gi, fix: 'the outfits should be', desc: 'grammar (DE calque "ist zu")' },
      { pattern: /\bis to be hand washed\b/gi, fix: 'should be hand washed', desc: 'grammar (DE calque "ist zu")' },
      // Live-data additions (2026-02-09)
      { pattern: /\bwall tattoos?\b/gi, fix: 'wall decal(s)', desc: 'DE calque "Wandtattoo"' },
      { pattern: /\breal babys\b/gi, fix: 'real babies', desc: 'grammar (irregular plural)' },
      { pattern: /\bspontaneous sympathetic\b/gi, fix: 'instantly endearing', desc: 'DE calque' },
      { pattern: /\bbuxom bott?ies\b/gi, fix: 'weighted bottoms', desc: 'awkward DE translation' },
      { pattern: /\bleeways\b/gi, fix: 'flexibility', desc: 'incorrect plural (leeway is uncountable)' },
      { pattern: /\bexeptional\b/gi, fix: 'exceptional', desc: 'typo' },
      { pattern: /\blike-able\b/gi, fix: 'likeable', desc: 'typo' }
    ];
    for (const art of artifacts) {
      const matches = text.match(art.pattern);
      if (matches) {
        result.findings.push({
          type: 'translation-artifact', severity: 'medium',
          phrase: matches[0],
          message: `Translation artifact: "${matches[0]}" → "${art.fix}" (${art.desc})`,
          suggestion: art.fix
        });
        result.suggestions.push({ original: matches[0], replacement: art.fix, type: 'translation-fix' });
      }
    }
  }

  // ---- Optimisation: Remove Stopwords ----
  // Operates on full text (preserves HTML tags)
  _removeStopwords(text) {
    const allStopwords = Object.values(this.rules.stopwords).flat();
    allStopwords.sort((a, b) => b.length - a.length);

    let result = text;
    for (const sw of allStopwords) {
      const wordPattern = (this.lang === 'de' && this._isSingleWord(sw))
        ? this._escapeRegex(sw) + '(?:e[rsnm]?|em|en)?'
        : this._escapeRegex(sw);
      const regex = new RegExp(
        `(^|[\\s,;:!?("'])${wordPattern}([\\s,;:!?.)"']|$)`,
        'gi'
      );
      result = result.replace(regex, (match, before, after) => {
        return before.trim() ? ' ' : after;
      });
    }
    return result;
  }

  // Deterministic hash for reproducible replacements (same input → same output)
  _hash(str) {
    let h = 0;
    for (let i = 0; i < str.length; i++) {
      h = ((h << 5) - h + str.charCodeAt(i)) | 0;
    }
    return Math.abs(h);
  }

  // ---- Optimisation: Word Replacements ----
  _applyReplacements(text) {
    let result = text;
    if (this.rules.replacements.natural) {
      for (const [formal, alternatives] of Object.entries(this.rules.replacements.natural)) {
        const regex = this._buildInflectedRegex(formal, 'gi');
        result = result.replace(regex, (match, offset) => {
          const h = this._hash(match + offset);
          if (h % 10 < 3) { // deterministic ~30% replacement rate
            const alt = alternatives[h % alternatives.length];
            const transferred = this._transferGermanEnding(match, formal.length, alt);
            return this._matchCase(match, transferred);
          }
          return match;
        });
      }
    }
    return result;
  }

  // ---- Optimisation: Passive to Active ----
  _applyPassiveToActive(text) {
    let result = text;
    for (const rule of this.rules.passiveToActive) {
      // Handle "Es wird X" → "Wir X" pattern (German)
      if (this.lang === 'de' && rule.passive.startsWith('wird ')) {
        // Match "Es wird X" at sentence start or after punctuation
        const esPattern = new RegExp(
          `(^|[.!?]\\s*)Es\\s+${this._escapeRegex(rule.passive)}`,
          'gi'
        );
        result = result.replace(esPattern, (match, prefix) => {
          const capitalActive = rule.active.charAt(0).toUpperCase() + rule.active.slice(1);
          return prefix + capitalActive;
        });
      }
      // Standard replacement for remaining cases
      const regex = new RegExp(this._escapeRegex(rule.passive), 'gi');
      result = result.replace(regex, match => this._matchCase(match, rule.active));
    }
    return result;
  }

  // ---- Optimisation: AI Phrase Alternatives ----
  _applyAlternatives(text) {
    let result = text;
    const allAlts = {
      ...this.rules.alternatives.strong,
      ...this.rules.alternatives.medium,
      ...this.rules.alternatives.weak
    };
    const sorted = Object.entries(allAlts).sort((a, b) => b[0].length - a[0].length);

    for (const [phrase, alternatives] of sorted) {
      const regex = this._buildInflectedRegex(phrase, 'gi');
      result = result.replace(regex, (match, offset) => {
        // Get the word immediately following the match
        const afterMatch = result.substring(offset + match.length);
        const nextWordMatch = afterMatch.match(/^\s*([a-zA-ZäöüÄÖÜß]+)/);
        const nextWord = nextWordMatch ? nextWordMatch[1].toLowerCase() : '';

        // Filter alternatives that would create doubled words
        const safeAlternatives = alternatives.filter(alt => {
          const altWords = alt.toLowerCase().split(/\s+/);
          const lastAltWord = altWords[altWords.length - 1];
          return lastAltWord !== nextWord;
        });

        // If no safe alternatives, keep original
        if (safeAlternatives.length === 0) {
          return match;
        }

        const alt = safeAlternatives[this._hash(match + offset) % safeAlternatives.length];
        const transferred = this._transferGermanEnding(match, phrase.length, alt);
        let replaced = this._matchCase(match, transferred);
        // Capitalize German nouns
        replaced = this._capitalizeGermanNouns(replaced);
        return replaced;
      });
    }
    return result;
  }

  _cleanup(text) {
    let result = text
      // Preserve ellipsis first (normalize various forms)
      .replace(/\.{3,}/g, '…')                 // Three+ dots → ellipsis character
      .replace(/…\s*…/g, '…')                  // Multiple ellipsis → single
      .replace(/,\s*…/g, '…')                  // Comma before ellipsis → just ellipsis
      .replace(/…\s*\./g, '…')                 // Ellipsis followed by period → just ellipsis
      .replace(/  +/g, ' ')                    // Multiple spaces → single space
      .replace(/\n{3,}/g, '\n\n')              // Multiple newlines → double newline
      .replace(/ ([.,;:!?…])/g, '$1')          // Space before punctuation → remove
      .replace(/^[,;:\s]+/gm, '')              // Leading punctuation at line start → remove
      .replace(/([.!?])\s*,/g, '$1')           // Period followed by comma → just period
      .replace(/,\s*,/g, ',')                  // Double commas → single comma
      .replace(/\s+,/g, ',')                   // Space before comma → no space
      .replace(/,\s*\./g, '.')                 // Comma followed by period → just period
      .replace(/([.!?])([.!?])+/g, '$1')       // Multiple sentence-ending punctuation → single
      .replace(/([.!?…])\s+([a-zäöüß])/g, (m, p, c) => p + ' ' + c.toUpperCase())  // Capitalize after sentence end
      // Remove doubled words (case-insensitive, preserve first occurrence's case)
      .replace(/\b([a-zA-ZäöüÄÖÜß]+)\s+\1\b/gi, '$1');

    // HTML cleanup (safe for both HTML and plain text)
    result = result
      .replace(/<p>\s*<\/p>/gi, '')             // Remove empty <p> tags
      .replace(/&nbsp;\s*&nbsp;/g, '&nbsp;');   // Double &nbsp; → single

    // EN: Fix common DE→EN translation grammar errors
    if (this.lang === 'en') {
      result = result
        .replace(/\bchildrens\b/gi, "children's")
        .replace(/\bpuppets\s+head\b/gi, "puppet's head")
        .replace(/\bIn addition,?\s+also\b/g, 'Also')
        .replace(/\bthe outfits is to be\b/gi, 'the outfits should be')
        .replace(/\bis to be hand washed\b/gi, 'should be hand washed')
        // Live-data additions (2026-02-09)
        .replace(/\bwall tattoos\b/gi, 'wall decals')
        .replace(/\bwall tattoo\b/gi, 'wall decal')
        .replace(/\breal babys\b/gi, 'real babies')
        .replace(/\bexeptional\b/gi, 'exceptional')
        .replace(/\blike-able\b/gi, 'likeable')
        .replace(/\ba curated range\b/gi, 'a selected range')
        .replace(/\bcurated selection\b/gi, 'hand-picked selection')
        .replace(/\bcurated\b/gi, 'selected');
    }

    return result.trim();
  }

  // Decode HTML entities to readable characters
  _decodeHtmlEntities(text) {
    return text
      // Standard HTML entities
      .replace(/&amp;/g, '&')
      .replace(/&lt;/g, '<').replace(/&gt;/g, '>')
      .replace(/&quot;/g, '"').replace(/&#39;/g, "'")
      .replace(/&nbsp;/g, ' ')
      // German HTML entities
      .replace(/&auml;/g, 'ä').replace(/&Auml;/g, 'Ä')
      .replace(/&ouml;/g, 'ö').replace(/&Ouml;/g, 'Ö')
      .replace(/&uuml;/g, 'ü').replace(/&Uuml;/g, 'Ü')
      .replace(/&szlig;/g, 'ß')
      // Typographic entities
      .replace(/&mdash;/g, '—').replace(/&ndash;/g, '–')
      .replace(/&hellip;/g, '…').replace(/&euro;/g, '€')
      .replace(/&copy;/g, '©').replace(/&reg;/g, '®')
      .replace(/&trade;/g, '™')
      // Numeric entities
      .replace(/&#(\d+);/g, (m, dec) => String.fromCharCode(dec))
      .replace(/&#x([0-9a-f]+);/gi, (m, hex) => String.fromCharCode(parseInt(hex, 16)));
  }

  // ---- Utility Methods ----
  _escapeRegex(str) {
    return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  _splitSentences(text) {
    return this._splitIntoSentences(text);
  }

  _matchCase(original, replacement) {
    if (!original || !replacement) return replacement;
    if (original === original.toUpperCase()) return replacement.toUpperCase();
    if (original[0] === original[0].toUpperCase()) {
      return replacement[0].toUpperCase() + replacement.slice(1);
    }
    return replacement.toLowerCase();
  }

  // Capitalize German nouns and formal pronouns in a phrase
  _capitalizeGermanNouns(text) {
    if (this.lang !== 'de') return text;

    // Common German nouns that should be capitalized
    const nouns = [
      'wahl', 'qualität', 'zweck', 'menge', 'freude', 'alltag', 'beispiel',
      'möglichkeit', 'gelegenheit', 'ort', 'adresse', 'unterricht', 'kindergarten',
      'rahmen', 'raum', 'fantasie', 'kreativität', 'vorstellungskraft', 'hersteller',
      'auswahl', 'vielfalt', 'spielen', 'lust', 'angebot', 'produkt', 'produkte',
      'kind', 'kinder', 'eltern', 'familie', 'spaß', 'freunde', 'geschenk',
      'qualität', 'preis', 'lieferung', 'versand', 'bestellung', 'artikel',
      'farbe', 'größe', 'material', 'design', 'modell', 'kollektion', 'serie',
      'wesentlichen', 'grunde', 'aspekte', 'bedürfnisse', 'werkzeug', 'leben',
      'überlegung', 'punkte', 'entscheidung', 'lösung', 'welt', 'herausforderung'
    ];

    // Formal German pronouns (Sie/Ihr/Ihnen/Ihren/Ihre/Ihrem)
    const formalPronouns = ['sie', 'ihr', 'ihnen', 'ihren', 'ihre', 'ihrem', 'ihrer'];

    let result = text;

    // Capitalize nouns (use custom word boundary for German umlauts)
    for (const noun of nouns) {
      // Custom word boundary that works with German umlauts
      const regex = new RegExp(`(?<![a-zA-ZäöüÄÖÜß])${noun}(?![a-zA-ZäöüÄÖÜß])`, 'gi');
      result = result.replace(regex, match => {
        return match.charAt(0).toUpperCase() + match.slice(1);
      });
    }

    // Capitalize formal pronouns (but not at sentence start where they could be "sie" = "they")
    for (const pronoun of formalPronouns) {
      // Match pronoun preceded by space, comma, or other punctuation (not sentence start)
      const regex = new RegExp(`([\\s,;:])${pronoun}\\b`, 'gi');
      result = result.replace(regex, (match, prefix) => {
        // Extract the actual matched pronoun (everything after prefix)
        const matchedPronoun = match.slice(prefix.length);
        // Always capitalize (the loop variable tells us which pronoun to capitalize)
        return prefix + pronoun.charAt(0).toUpperCase() + pronoun.slice(1);
      });
    }

    return result;
  }

  _getRating(score) {
    if (score <= 10) return {
      level: 'excellent', label: this.lang === 'de' ? 'Ausgezeichnet' : 'Excellent',
      color: '#22c55e',
      description: this.lang === 'de' ? 'Text wirkt natürlich und menschlich.' : 'Text appears natural and human.'
    };
    if (score <= 30) return {
      level: 'good', label: this.lang === 'de' ? 'Gut' : 'Good',
      color: '#84cc16',
      description: this.lang === 'de' ? 'Weitgehend natürlich, wenige KI-Merkmale.' : 'Largely natural with few AI characteristics.'
    };
    if (score <= 60) return {
      level: 'moderate', label: this.lang === 'de' ? 'Mäßig' : 'Moderate',
      color: '#eab308',
      description: this.lang === 'de' ? 'Erkennbare KI-Muster. Überarbeitung empfohlen.' : 'Recognisable AI patterns. Revision recommended.'
    };
    if (score <= 100) return {
      level: 'poor', label: this.lang === 'de' ? 'Schlecht' : 'Poor',
      color: '#f97316',
      description: this.lang === 'de' ? 'Deutliche KI-Merkmale. Umfassende Überarbeitung nötig.' : 'Clear AI characteristics. Comprehensive revision needed.'
    };
    return {
      level: 'critical', label: this.lang === 'de' ? 'Kritisch' : 'Critical',
      color: '#ef4444',
      description: this.lang === 'de' ? 'Sehr wahrscheinlich KI-generiert. Komplett überarbeiten.' : 'Very likely AI-generated. Complete rewrite needed.'
    };
  }
}
