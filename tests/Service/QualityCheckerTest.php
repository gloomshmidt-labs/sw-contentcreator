<?php declare(strict_types=1);

namespace ContentCreator\Tests\Service;

use ContentCreator\Service\QualityChecker;
use PHPUnit\Framework\TestCase;

/**
 * KI-Muster-Scoring gegen die ECHTEN Regeldaten (rules-de.json/rules-en.json) —
 * die Erwartungswerte hängen an den realen Pattern-Scores (perfekt=5, zudem=2,
 * "nicht nur"=6 mit Kontext "sondern auch", "eine welt voller"=15).
 */
class QualityCheckerTest extends TestCase
{
    private QualityChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new QualityChecker();
    }

    public function testUnauffaelligerTextErzieltScoreNull(): void
    {
        $result = $this->checker->analyse('Die Katze sitzt auf dem Sofa.', 'de-DE');

        self::assertSame(0, $result['score']);
        self::assertSame('excellent', $result['level']);
        self::assertSame([], $result['findings']);
    }

    public function testMusterWerdenErkanntUndSummiert(): void
    {
        // perfekt (5, medium) + zudem (2, weak) = 7 → noch excellent (≤10)
        $result = $this->checker->analyse('Das Spielzeug ist perfekt verarbeitet. Zudem liegt es gut in der Hand.', 'de-DE');

        self::assertSame(7, $result['score']);
        self::assertSame('excellent', $result['level']);
        $patterns = array_column($result['findings'], 'pattern');
        self::assertContains('perfekt', $patterns);
        self::assertContains('zudem', $patterns);
    }

    public function testDeutscheFlexionWirdErkannt(): void
    {
        // Adjektiv-Endung: "perfekte" matcht das Muster "perfekt"
        $result = $this->checker->analyse('Eine perfekte Ergänzung.', 'de-DE');

        self::assertCount(1, $result['findings']);
        self::assertSame('perfekt', $result['findings'][0]['pattern']);
        self::assertSame(1, $result['findings'][0]['count']);
        self::assertSame(5, $result['findings'][0]['score']);
    }

    public function testHtmlWirdVorDemScanEntfernt(): void
    {
        $result = $this->checker->analyse('<p>Das ist <strong>perfekt</strong> so.</p>', 'de-DE');

        self::assertSame(5, $result['score']);
    }

    public function testKontextMusterZaehltOhneKontextNurHalb(): void
    {
        // "nicht nur" (6) mit Kontext "sondern auch": voller Score nur im Duo
        $mitKontext = $this->checker->analyse('Das Theater ist nicht nur schön, sondern auch lehrreich.', 'de-DE');
        $ohneKontext = $this->checker->analyse('Das Theater ist nicht nur schön.', 'de-DE');

        self::assertSame(6, $mitKontext['findings'][0]['score']);
        self::assertSame(3, $ohneKontext['findings'][0]['score']);
    }

    public function testWhitelistNimmtMusterAusDerWertung(): void
    {
        $text = 'Das Spielzeug ist perfekt verarbeitet. Zudem liegt es gut in der Hand.';

        $ohne = $this->checker->analyse($text, 'de-DE');
        $mit = $this->checker->analyse($text, 'de-DE', ['perfekt']);

        self::assertSame(7, $ohne['score']);
        self::assertSame(2, $mit['score']);
        self::assertNotContains('perfekt', array_column($mit['findings'], 'pattern'));
        // "zudem" bleibt gewertet — die Whitelist wirkt gezielt
        self::assertContains('zudem', array_column($mit['findings'], 'pattern'));
    }

    public function testWhitelistWirktAlsTeilstringAufLaengereMuster(): void
    {
        // Eintrag "welt" deckt auch das Mehrwortmuster "eine welt voller" ab
        // (Teilstring-Match wie in der Admin-Anzeige)
        $text = 'Tauchen Sie ein in eine Welt voller Farben.';

        $ohne = $this->checker->analyse($text, 'de-DE');
        $mit = $this->checker->analyse($text, 'de-DE', ['welt']);

        self::assertContains('eine welt voller', array_column($ohne['findings'], 'pattern'));
        self::assertNotContains('eine welt voller', array_column($mit['findings'], 'pattern'));
    }

    public function testFindingsEnthaltenAlternativenUndSindNachScoreSortiert(): void
    {
        $result = $this->checker->analyse('Zudem ist das Set perfekt für unterwegs.', 'de-DE');

        $scores = array_column($result['findings'], 'score');
        $sorted = $scores;
        rsort($sorted);
        self::assertSame($sorted, $scores);

        foreach ($result['findings'] as $finding) {
            if ($finding['pattern'] === 'perfekt') {
                // Alternativen aus rules-de.json (Retry-Feedback-Basis)
                self::assertSame(['richtig', 'passend', 'genau richtig'], $finding['alternatives']);
            }
        }
    }

    public function testEnglischeRegelnWerdenBeiEnGenutzt(): void
    {
        $result = $this->checker->analyse('This set opens a world of possibilities.', 'en-GB');

        self::assertContains('a world of', array_column($result['findings'], 'pattern'));
        self::assertSame(15, $result['score']);
    }

    public function testScoreBaenderEntsprechenDerEngine(): void
    {
        // Bänder wie engine.js _getRating: ≤10/30/60/100, darüber critical
        $level = new \ReflectionMethod(QualityChecker::class, 'level');

        $expected = [
            0 => 'excellent', 10 => 'excellent',
            11 => 'good', 30 => 'good',
            31 => 'moderate', 60 => 'moderate',
            61 => 'poor', 100 => 'poor',
            101 => 'critical',
        ];
        foreach ($expected as $score => $band) {
            self::assertSame($band, $level->invoke($this->checker, $score), 'Score ' . $score);
        }
    }

    public function testParseWhitelistZerlegtUndTrimmt(): void
    {
        self::assertSame(
            ['hochwertig', 'perfekt', 'ideal'],
            QualityChecker::parseWhitelist(' hochwertig , perfekt ,,ideal ')
        );
        self::assertSame([], QualityChecker::parseWhitelist(''));
    }

    public function testPromptFeedbackNenntMusterUndAlternativen(): void
    {
        $findings = $this->checker->analyse('Das ist perfekt.', 'de-DE')['findings'];
        $feedback = $this->checker->promptFeedback($findings, 'de-DE');

        self::assertStringContainsString('KI-TYPISCHE MUSTER', $feedback);
        self::assertStringContainsString('"perfekt"', $feedback);
        self::assertStringContainsString('"richtig"', $feedback);

        self::assertSame('', $this->checker->promptFeedback([], 'de-DE'));
    }
}
