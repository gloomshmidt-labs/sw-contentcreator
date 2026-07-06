<?php declare(strict_types=1);

namespace ContentCreator\Tests\Service;

use ContentCreator\Service\MediaRenamer;
use PHPUnit\Framework\TestCase;

/**
 * Pure Namens- und Redirect-Logik des MediaRenamer — die privaten Helfer
 * suggestName()/flattenRedirects() brauchen keine Abhängigkeiten, daher
 * Instanz ohne Konstruktor (kein DB-/FileSaver-Mock nötig).
 */
class MediaRenamerTest extends TestCase
{
    private MediaRenamer $renamer;

    protected function setUp(): void
    {
        $this->renamer = (new \ReflectionClass(MediaRenamer::class))->newInstanceWithoutConstructor();
    }

    private function suggestName(string $productName, string $alt, string $currentName = ''): string
    {
        return (new \ReflectionMethod(MediaRenamer::class, 'suggestName'))
            ->invoke($this->renamer, $productName, $alt, $currentName);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, string>
     */
    private function flattenRedirects(array $rows): array
    {
        return (new \ReflectionMethod(MediaRenamer::class, 'flattenRedirects'))
            ->invoke($this->renamer, $rows);
    }

    public function testAnchorAusAltemDateinamenStehtAmEnde(): void
    {
        self::assertSame(
            'folkmanis-handpuppe-schnecke-15601a',
            $this->suggestName('Folkmanis Handpuppe Schnecke', 'egal', '15601a')
        );
    }

    public function testStoppwoerterWerdenEntfernt(): void
    {
        self::assertSame(
            'handpuppe-schnecke-haus-123',
            $this->suggestName('Die Handpuppe mit der Schnecke und dem Haus', '', '123')
        );
    }

    public function testDoppelteWoerterWerdenDedupliziert(): void
    {
        self::assertSame('puppe-rot-77a', $this->suggestName('Puppe Puppe rot', '', '77a'));
    }

    public function testUmlauteWerdenTransliteriert(): void
    {
        self::assertSame('baer-fuessen-9', $this->suggestName('Bär mit Füßen', '', '9'));
    }

    public function testHashOriginaleBekommenKeinenAnker(): void
    {
        // 40 Hex-Zeichen = nichtssagender Hash → nur der Produkt-Slug
        $hash = str_repeat('a1', 20);
        self::assertSame('roba-puppenhaus', $this->suggestName('Roba Puppenhaus', '', $hash));
    }

    public function testAnkerWirdAuf20ZeichenGekapptUndNieAbgeschnitten(): void
    {
        $result = $this->suggestName(
            'Wunderschoenes Holzspielzeug Kinderzimmer Dekoration Extralang',
            '',
            'abcdefghij1234567890xyz'
        );

        // Anker (auf 20 gekappt) muss VOLLSTÄNDIG am Ende stehen, Gesamtlänge ≤ 70
        self::assertSame('wunderschoenes-holzspielzeug-kinderzimmer-abcdefghij1234567890', $result);
        self::assertStringEndsWith('-abcdefghij1234567890', $result);
        self::assertLessThanOrEqual(70, mb_strlen($result));
    }

    public function testBudgetKapptWortweiseNichtMittenImWort(): void
    {
        // Ist-Verhalten: das erste Wort, das das Budget sprengt, BEENDET die
        // Aufnahme — spätere (kürzere) Wörter werden nicht mehr nachgezogen
        $longWord = str_repeat('x', 65);
        self::assertSame(
            'handpuppe-15601a',
            $this->suggestName('Handpuppe ' . $longWord . ' Fuchs', '', '15601a')
        );
    }

    public function testLeererProduktnameLiefertNurDenAnker(): void
    {
        self::assertSame('4711', $this->suggestName('!!!', '', '4711'));
    }

    public function testOhneAnkerUndOhneVerwertbaresErgebnisLeer(): void
    {
        self::assertSame('', $this->suggestName('', '', ''));
    }

    public function testRedirectKetteWirdAufFinalesZielGeglaettet(): void
    {
        $redirects = $this->flattenRedirects([
            ['old_path' => '/media/a.jpg', 'new_path' => '/media/b.jpg', 'thumbnails' => null],
            ['old_path' => '/media/b.jpg', 'new_path' => '/media/c.jpg', 'thumbnails' => null],
            ['old_path' => '/media/c.jpg', 'new_path' => '/media/d.jpg', 'thumbnails' => null],
        ]);

        self::assertSame([
            '/media/a.jpg' => '/media/d.jpg',
            '/media/b.jpg' => '/media/d.jpg',
            '/media/c.jpg' => '/media/d.jpg',
        ], $redirects);
    }

    public function testThumbnailKettenWerdenProGroesseGeglaettet(): void
    {
        $redirects = $this->flattenRedirects([
            [
                'old_path' => '/media/o.jpg',
                'new_path' => '/media/n.jpg',
                'thumbnails' => json_encode(['/thumb/o_400.jpg' => '/thumb/n_400.jpg']),
            ],
            [
                'old_path' => '/media/n.jpg',
                'new_path' => '/media/f.jpg',
                'thumbnails' => json_encode(['/thumb/n_400.jpg' => '/thumb/f_400.jpg']),
            ],
        ]);

        self::assertSame([
            '/media/o.jpg' => '/media/f.jpg',
            '/thumb/o_400.jpg' => '/thumb/f_400.jpg',
            '/media/n.jpg' => '/media/f.jpg',
            '/thumb/n_400.jpg' => '/thumb/f_400.jpg',
        ], $redirects);
    }

    public function testZyklusErzeugtNurSelbstRedirectDerBeiAusgabeEntfaellt(): void
    {
        // a→b, dann zurück b→a: a→a ist Selbst-Redirect (wird bei der Ausgabe
        // übersprungen), wirksam bleibt nur b→a
        $redirects = $this->flattenRedirects([
            ['old_path' => '/media/a.jpg', 'new_path' => '/media/b.jpg', 'thumbnails' => null],
            ['old_path' => '/media/b.jpg', 'new_path' => '/media/a.jpg', 'thumbnails' => null],
        ]);

        self::assertSame(['/media/a.jpg' => '/media/a.jpg', '/media/b.jpg' => '/media/a.jpg'], $redirects);
        $this->assertNoTargetIsSource($redirects);
    }

    public function testInvarianteHaeltAuchBeiVerschraenktenUnabhaengigenKetten(): void
    {
        // Zwei unabhängige Ketten, chronologisch verschränkt: a→b→c und x→y→z
        $redirects = $this->flattenRedirects([
            ['old_path' => '/a', 'new_path' => '/b', 'thumbnails' => null],
            ['old_path' => '/x', 'new_path' => '/y', 'thumbnails' => null],
            ['old_path' => '/b', 'new_path' => '/c', 'thumbnails' => null],
            ['old_path' => '/y', 'new_path' => '/z', 'thumbnails' => null],
        ]);

        $this->assertNoTargetIsSource($redirects);
        self::assertSame('/c', $redirects['/a']);
        self::assertSame('/z', $redirects['/x']);
    }

    public function testBekannteGrenzeNamensWiederverwendungBleibtUngeglaettet(): void
    {
        // Ist-Verhalten (dokumentiert, KEIN Soll): Wird ein früher vergebener
        // Quell-Name (/c) später als ZIEL eines anderen Bildes wiederverwendet,
        // schaut die Glättung nicht "nach vorn" — a→c bleibt stehen, obwohl
        // c→d existiert (301-Kette a→c→d). In der Praxis kaum erreichbar, da
        // rename() Kollisionen per Suffix ausweicht statt Namen wiederzuverwenden.
        $redirects = $this->flattenRedirects([
            ['old_path' => '/c', 'new_path' => '/d', 'thumbnails' => null],
            ['old_path' => '/a', 'new_path' => '/c', 'thumbnails' => null],
        ]);

        self::assertSame(['/c' => '/d', '/a' => '/c'], $redirects);
    }

    /**
     * Invariante des nginx-Exports: kein wirksames Redirect-Ziel darf zugleich
     * Quelle eines anderen wirksamen Redirects sein (sonst 301-Ketten).
     *
     * @param array<string, string> $redirects
     */
    private function assertNoTargetIsSource(array $redirects): void
    {
        // "wirksam" = was exportNginx tatsächlich ausgibt (Selbst-Redirects entfallen)
        $effective = array_filter($redirects, static fn (string $new, string $old) => $old !== $new, \ARRAY_FILTER_USE_BOTH);
        foreach ($effective as $target) {
            self::assertArrayNotHasKey($target, $effective, sprintf('Ziel "%s" ist zugleich Quelle — 301-Kette!', $target));
        }
    }
}
