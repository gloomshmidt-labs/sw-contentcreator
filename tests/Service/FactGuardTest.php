<?php declare(strict_types=1);

namespace ContentCreator\Tests\Service;

use ContentCreator\Service\FactGuard;
use PHPUnit\Framework\TestCase;

/**
 * Fakten-Erhalt-Gate: Zahlen (inkl. Einheiten) und MPN des Originals müssen
 * im Kandidaten nachweisbar bleiben — normalisiert (Whitespace/Case/HTML egal).
 */
class FactGuardTest extends TestCase
{
    private FactGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new FactGuard();
    }

    public function testAlleFaktenErhaltenLiefertLeereListe(): void
    {
        $original = 'Die Handpuppe ist <b>30 cm</b> groß und wiegt 2,5 kg.';
        $candidate = 'Mit 30cm Größe und 2,5 KG Gewicht liegt sie gut in der Hand.';

        self::assertSame([], $this->guard->missingFacts($original, $candidate));
    }

    public function testFehlendeZahlMitEinheitWirdGemeldet(): void
    {
        $original = 'Maße: 30 cm hoch, Gewicht 2,5 kg.';
        $candidate = 'Die Puppe ist 30 cm hoch.';

        self::assertSame(['2,5 kg'], $this->guard->missingFacts($original, $candidate));
    }

    public function testProzentUndAltersangabenWerdenGeprueft(): void
    {
        $original = 'Bezug aus 100 % Baumwolle, geeignet ab 3 Jahren.';

        self::assertSame([], $this->guard->missingFacts($original, 'Aus 100% Baumwolle, ab 3 Jahren spielbar.'));
        self::assertContains('3 Jahren', $this->guard->missingFacts($original, 'Aus 100 % Baumwolle gefertigt.'));
    }

    public function testHtmlUndEntitiesImOriginalWerdenNormalisiert(): void
    {
        // &nbsp; zwischen Zahl und Einheit darf das Matching nicht brechen
        $original = '<p>Länge: 45&nbsp;mm</p>';

        self::assertSame([], $this->guard->missingFacts($original, 'Nur 45 mm lang.'));
    }

    public function testMpnMussErhaltenBleibenWennSieImOriginalVorkam(): void
    {
        $original = 'Die Handpuppe ABC123 besteht aus Plüsch.';

        $missing = $this->guard->missingFacts($original, 'Eine schöne Handpuppe aus Plüsch.', 'ABC123');
        self::assertContains('ABC123', $missing);

        // Erhalten (Groß-/Kleinschreibung egal) → keine Beanstandung der MPN
        $missing = $this->guard->missingFacts($original, 'Die Handpuppe abc123 aus Plüsch.', 'ABC123');
        self::assertNotContains('ABC123', $missing);
    }

    public function testMpnWirdNichtEingefordertWennSieImOriginalFehlt(): void
    {
        self::assertSame(
            [],
            $this->guard->missingFacts('Eine schöne Handpuppe.', 'Ganz neuer Text.', 'XYZA')
        );
    }

    public function testLeereOderFehlendeMpnWirdIgnoriert(): void
    {
        self::assertSame([], $this->guard->missingFacts('Schöner Text.', 'Anderer Text.', null));
        self::assertSame([], $this->guard->missingFacts('Schöner Text.', 'Anderer Text.', '  '));
    }

    public function testDoppelteFaktenWerdenNurEinmalGemeldet(): void
    {
        $original = 'Erst 30 cm, dann nochmal 30 cm.';

        self::assertSame(['30 cm'], $this->guard->missingFacts($original, 'Ganz ohne Maße.'));
    }

    public function testPromptFeedbackLeerBeiVollstaendigenFakten(): void
    {
        self::assertSame('', $this->guard->promptFeedback([], 'de'));
    }

    public function testPromptFeedbackNenntFehlendeFaktenInBeidenSprachen(): void
    {
        $de = $this->guard->promptFeedback(['30 cm'], 'de-DE');
        self::assertStringContainsString('ABGELEHNT', $de);
        self::assertStringContainsString('"30 cm"', $de);

        $en = $this->guard->promptFeedback(['30 cm'], 'en-GB');
        self::assertStringStartsWith('YOUR PREVIOUS ATTEMPT WAS REJECTED', $en);
        self::assertStringContainsString('"30 cm"', $en);
    }
}
