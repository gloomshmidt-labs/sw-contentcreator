<?php declare(strict_types=1);

namespace ContentCreator\Service;

/**
 * Portierte KI-Muster-Listen aus dem Textoptimierung-Tool (rules-de.js / rules-en.js).
 *
 * - STRONG:      eindeutige KI-Klischees (im Tool score 8-15) -> im Generier-Prompt komplett verboten.
 * - ADJECTIVES:  werbliche/KI-typische Adjektive aus der medium-Liste, die sich sicher verbieten lassen,
 *                ohne natürliches Schreiben zu behindern (neutrale Verben wie "bietet/ermöglicht"
 *                bleiben bewusst außen vor – die deckt das client-seitige Scoring kontextgewichtet ab).
 */
final class ForbiddenPhrases
{
    private const STRONG_DE = [
        'eine Welt voller', 'ein Paradies für', 'Traumziel für', 'sicher begeistern',
        'Freude in Ihr Leben', 'Freude in den Alltag', 'bringen Sie Freude', 'für Ihre Bedürfnisse',
        'wertvolles pädagogisches Werkzeug', 'wertvolles Werkzeug', 'qualitativ hochwertig',
        'auf spielerische Weise', 'Kreativität und Fantasie', 'Kreativität und Vorstellungskraft',
        'eignet sich hervorragend', 'eine hervorragende Wahl', 'besticht durch', 'kreative Möglichkeit',
        'entdecken Sie die Welt', 'entdecken Sie die Vielfalt', 'entdecken Sie unsere',
        'entdecken Sie heute', 'erkunden Sie unsere', 'ein führender Hersteller', 'steht für hochwertig',
        'voll auszuleben', 'einen sicheren Raum', 'kann eine Herausforderung sein',
        'berücksichtigen Sie diese Aspekte', 'regen die Fantasie an', 'magische Momente',
        'lassen Sie sich verzaubern', 'lassen Sie sich von der Magie', 'zum Leben erwecken',
        'Geschichten zum Leben', 'groß und Klein', 'begeistert groß und Klein', 'mit Liebe zum Detail',
        'zu einem echten Erlebnis', 'ein echtes Erlebnis', 'eine Vielzahl von', 'Synergien',
        'unvergleichlich', 'unnachahmlich', 'zum Verlieben', 'voller Charme', 'neue Maßstäbe',
        'tauchen Sie ein', 'erleben Sie', 'unendliche Möglichkeiten', 'echter Klassiker',
        'unvergessliche Momente', 'unvergesslich', 'pädagogisch wertvoll', 'aus hochwertigen Materialien',
        'Fantasie und Kreativität', 'die Welt der', 'liebevoll gestaltet', 'zauberhafte Welt',
        'wunderbare Welt', 'willkommen in der Welt', 'treuer Begleiter', 'Kreativität und Spielspaß',
        'für jeden Geschmack', 'lassen Sie sich inspirieren', 'seit seiner Gründung', 'für jeden Anlass',
        // Nachportiert aus den SEO-Skills (GAP-Abgleich 2026-07-01):
        'In einer Welt, in der', 'Egal ob ... oder (als Satzanfang)', 'Nicht nur ... sondern auch',
        'Dabei ist es wichtig zu beachten', 'es ist wichtig zu betonen', 'Darüber hinaus', 'Des Weiteren',
        'Bestellen Sie jetzt', 'Unser Sortiment', 'Ob als ... oder', 'Von klassisch bis modern',
        'überzeugt durch', 'zeichnet sich aus',
    ];

    private const ADJECTIVES_DE = [
        'ideal', 'perfekt', 'einzigartig', 'vielfältig', 'unverzichtbar', 'außergewöhnlich',
        'beeindruckend', 'faszinierend', 'exzellent', 'makellos',
        'große Auswahl', 'große Vielfalt', 'wunderschön', 'erstklassig', 'bezaubernd', 'zauberhaft',
        'unwiderstehlich', 'atemberaubend', 'zeitlos', 'Spielspaß', 'Spielvergnügen', 'liebevoll',
        'hervorragend', 'umfangreich', 'traditionsreich', 'maßgeschneidert',
    ];

    /**
     * Skill-Nuance statt Komplettverbot: sparsam erlaubt (max. 1x, nur wenn belegbar).
     */
    private const SPARING_DE = ['hochwertig', 'hohe Qualität', 'insbesondere'];

    private const STRONG_EN = [
        'a world of', 'a paradise for', 'a dream destination for', 'a haven for', 'sure to delight',
        'sure to impress', 'bring joy to', 'bring joy to your life', 'brighten up your', 'for your needs',
        'for all your needs', 'valuable educational tool', 'invaluable tool', 'creativity and imagination',
        'perfect for any', 'ideal for any', 'explore our', 'discover our', 'discover the world',
        'explore the world', 'discover the variety', 'a leading manufacturer', 'an excellent choice',
        'stands out for', 'can be a challenge', 'encourage creativity', 'foster creativity',
        'spark imagination', 'stimulate the imagination', 'safe space', 'in a playful way',
        'lends itself perfectly', 'stands for quality', 'take it to the next level',
        'the perfect addition to', 'designed to meet', 'curated selection', 'unparalleled quality',
        'testament to', "in today's fast-paced world", 'game-changer', 'paradigm shift',
        'perfect companion', 'perfect gift', 'ideal gift', 'more than just', 'comes to life',
        'bring to life', 'the world of', 'welcome to the world', 'immerse yourself',
        'unforgettable moments', 'sets new standards', 'since its founding', 'for every occasion',
        'for every taste', 'let yourself be inspired', 'true classic', 'full of charm',
        'endless possibilities',
    ];

    private const ADJECTIVES_EN = [
        'ideal', 'perfect', 'unique', 'diverse', 'versatile', 'indispensable', 'exceptional', 'stunning',
        'remarkable', 'outstanding', 'exquisite', 'flawless', 'impeccable', 'meticulous', 'craftsmanship',
        'excellence', 'fascinating', 'delightful', 'wide range', 'wide selection', 'high quality',
        'premium quality', 'wonderfully', 'incredibly', 'irresistibly', 'timeless', 'endearing',
        'enchanting', 'captivating', 'whimsical', 'must-have', 'first-class', 'charming',
    ];

    /**
     * Kuratierte Verbotsliste für den Generier-Prompt (Strong + werbliche Adjektive).
     *
     * @return string[]
     */
    public static function forPrompt(string $lang): array
    {
        if (self::normalize($lang) === 'en') {
            return array_merge(self::STRONG_EN, self::ADJECTIVES_EN);
        }

        return array_merge(self::STRONG_DE, self::ADJECTIVES_DE);
    }

    /**
     * Kompakter, in den Prompt einsetzbarer Block (kommagetrennt, in Anführungszeichen).
     */
    public static function promptBlock(string $lang): string
    {
        $quoted = array_map(static fn (string $p): string => '"' . $p . '"', self::forPrompt($lang));

        return implode(', ', $quoted);
    }

    /**
     * Nur sparsam erlaubte Wörter (max. 1x, nur wenn belegbar) — Skill-Nuance.
     */
    public static function sparingLine(string $lang): string
    {
        if (self::normalize($lang) === 'en' || self::SPARING_DE === []) {
            return '';
        }

        $quoted = array_map(static fn (string $p): string => '"' . $p . '"', self::SPARING_DE);

        return implode(', ', $quoted);
    }

    private static function normalize(string $lang): string
    {
        return str_starts_with(strtolower($lang), 'en') ? 'en' : 'de';
    }
}
