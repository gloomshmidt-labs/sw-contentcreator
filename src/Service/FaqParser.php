<?php declare(strict_types=1);

namespace ContentCreator\Service;

/**
 * Zerlegt den generierten FAQ-Block (<h3>Frage</h3><p>Antwort</p>-Paare) in
 * strukturierte Einträge — Basis für die Storefront-Anzeige und das
 * FAQPage-Rich-Snippet (JSON-LD).
 */
class FaqParser
{
    /**
     * @return list<array{question: string, answer: string}>
     */
    public function parse(?string $html): array
    {
        if ($html === null || trim($html) === '') {
            return [];
        }

        preg_match_all('/<h3[^>]*>(.*?)<\/h3>\s*((?:<p[^>]*>.*?<\/p>\s*)+)/is', $html, $matches, \PREG_SET_ORDER);

        $items = [];
        foreach ($matches as $match) {
            $question = trim(html_entity_decode(strip_tags($match[1]), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
            $answer = trim(html_entity_decode(strip_tags($match[2]), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
            if ($question !== '' && $answer !== '') {
                $items[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return $items;
    }
}
