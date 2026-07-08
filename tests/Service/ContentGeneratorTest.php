<?php declare(strict_types=1);

namespace ContentCreator\Tests\Service;

use ContentCreator\Service\ContentGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Nur die puren Parser-Helfer des Generators (extractJson/cleanContent) —
 * die Orchestrierung selbst braucht Provider/DAL und bleibt untestbar ohne
 * Integrationsumgebung. Instanz ohne Konstruktor, Aufruf per Reflection.
 */
class ContentGeneratorTest extends TestCase
{
    private ContentGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = (new \ReflectionClass(ContentGenerator::class))->newInstanceWithoutConstructor();
    }

    private function extractJson(string $text): string
    {
        return (new \ReflectionMethod(ContentGenerator::class, 'extractJson'))->invoke($this->generator, $text);
    }

    private function cleanContent(string $text): string
    {
        return (new \ReflectionMethod(ContentGenerator::class, 'cleanContent'))->invoke($this->generator, $text);
    }

    public function testExtractJsonLaesstReinesJsonDurch(): void
    {
        self::assertSame('{"metaTitle":"X"}', $this->extractJson('{"metaTitle":"X"}'));
    }

    public function testExtractJsonEntferntCodeblockMarkierungen(): void
    {
        self::assertSame(
            '{"metaTitle":"X"}',
            $this->extractJson("```json\n{\"metaTitle\":\"X\"}\n```"),
        );
    }

    public function testExtractJsonSchneidetUmgebendeProsaWeg(): void
    {
        self::assertSame(
            '{"a": {"b": 2}}',
            $this->extractJson('Hier ist das gewünschte JSON: {"a": {"b": 2}} Viel Erfolg!'),
        );
    }

    public function testExtractJsonOhneJsonLiefertDenTextZurueck(): void
    {
        self::assertSame('kein json enthalten', $this->extractJson('  kein json enthalten  '));
    }

    public function testCleanContentEntferntCodeFences(): void
    {
        self::assertSame('<p>Hallo</p>', $this->cleanContent("```html\n<p>Hallo</p>\n```"));
    }

    public function testCleanContentStreichtRechercheZitatKlammern(): void
    {
        // web_search-Zitate: "([domain](url))" fliegt komplett raus
        self::assertSame(
            'Handpuppen fördern das Rollenspiel.',
            $this->cleanContent('Handpuppen fördern das Rollenspiel ([example.com](https://example.com/quelle)).'),
        );
    }

    public function testCleanContentReduziertMarkdownLinksAufDenText(): void
    {
        self::assertSame(
            'Siehe Handpuppen im Sortiment.',
            $this->cleanContent('Siehe [Handpuppen](https://shop.example/handpuppen) im Sortiment.'),
        );
    }

    public function testCleanContentLaesstNormalesHtmlUnangetastet(): void
    {
        $html = '<h2>Titel</h2><p>Text mit (Klammern) und [eckigen Klammern].</p>';

        self::assertSame($html, $this->cleanContent($html));
    }
}
