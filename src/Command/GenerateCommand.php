<?php declare(strict_types=1);

namespace ContentCreator\Command;

use ContentCreator\Service\ContentGenerator;
use ContentCreator\Service\ContentWriter;
use ContentCreator\Service\FactLoader;
use ContentCreator\Service\PromptBuilder;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI-Smoke-Test der Text-Generierung ohne Admin-UI/DB:
 *   bin/console content-creator:generate --type=product_description --name="Handpuppe Wombat" --manufacturer="Hansa Creation" --mpn=3767
 */
#[AsCommand(
    name: 'content-creator:generate',
    description: 'Generiert einen SEO-Text über den konfigurierten KI-Provider (Test/CLI).',
)]
class GenerateCommand extends Command
{
    public function __construct(
        private readonly ContentGenerator $generator,
        private readonly FactLoader $factLoader,
        private readonly ContentWriter $writer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Texttyp: ' . implode(', ', PromptBuilder::TYPES), PromptBuilder::TYPE_PRODUCT_DESCRIPTION)
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'Sprache (de/en)', 'de')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Produkt-/Kategoriename')
            ->addOption('manufacturer', null, InputOption::VALUE_REQUIRED, 'Hersteller/Marke')
            ->addOption('mpn', null, InputOption::VALUE_REQUIRED, 'MPN / Herstellernummer')
            ->addOption('keywords', null, InputOption::VALUE_REQUIRED, 'Bestehende Keywords')
            ->addOption('focus-keyword', null, InputOption::VALUE_REQUIRED, 'Fokus-Keyword (Pflicht-Platzierung Title/H1/erster Absatz)')
            ->addOption('existing', null, InputOption::VALUE_REQUIRED, 'Bestehender Text (Kontext)')
            ->addOption('image', null, InputOption::VALUE_REQUIRED, 'Bild-URL (nur media_alt)')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider erzwingen (claude/openai)')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Modell erzwingen')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Modus: create (neu) oder optimize (Bestand optimieren)', PromptBuilder::MODE_CREATE)
            ->addOption('meta-fields', null, InputOption::VALUE_REQUIRED, 'Nur diese Meta-Felder optimieren, kommagetrennt (metaTitle,metaDescription,metaKeywords)')
            ->addOption('product-id', null, InputOption::VALUE_REQUIRED, 'Produkt-ID (lädt Fakten aus dem Shop)')
            ->addOption('category-id', null, InputOption::VALUE_REQUIRED, 'Kategorie-ID (lädt Fakten aus dem Shop)')
            ->addOption('media-id', null, InputOption::VALUE_REQUIRED, 'Media-ID (lädt Fakten aus dem Shop)')
            ->addOption('sales-channel-id', null, InputOption::VALUE_REQUIRED, 'Sales-Channel-ID (Startseiten-Meta)')
            ->addOption('manufacturer-id', null, InputOption::VALUE_REQUIRED, 'Hersteller-ID (Hersteller-Portrait)')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Ergebnis in die geladene Entity zurückschreiben (Test)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $type = (string) $input->getOption('type');
        $lang = (string) $input->getOption('lang');

        $ctx = array_filter([
            'name' => $input->getOption('name'),
            'manufacturer' => $input->getOption('manufacturer'),
            'mpn' => $input->getOption('mpn'),
            'keywords' => $input->getOption('keywords'),
            'focusKeyword' => $input->getOption('focus-keyword'),
            'existingText' => $input->getOption('existing'),
            'imageUrl' => $input->getOption('image'),
        ], static fn ($v) => $v !== null && $v !== '');

        // Optional: Fakten aus dem Shop laden (validiert FactLoader gegen echte Entities)
        $writeEntityType = null;
        $writeId = null;
        try {
            $entityContext = Context::createDefaultContext();
            if (($pid = $input->getOption('product-id')) !== null) {
                $ctx = array_merge($this->factLoader->loadProduct((string) $pid, $entityContext), $ctx);
                $writeEntityType = 'product';
                $writeId = (string) $pid;
            } elseif (($cid = $input->getOption('category-id')) !== null) {
                $ctx = array_merge($this->factLoader->loadCategory((string) $cid, $entityContext), $ctx);
                $writeEntityType = 'category';
                $writeId = (string) $cid;
            } elseif (($mid = $input->getOption('media-id')) !== null) {
                $ctx = array_merge($this->factLoader->loadMedia((string) $mid, $entityContext), $ctx);
                $writeEntityType = 'media';
                $writeId = (string) $mid;
            } elseif (($scid = $input->getOption('sales-channel-id')) !== null) {
                $ctx = array_merge($this->factLoader->loadSalesChannel((string) $scid, $entityContext), $ctx);
                $writeEntityType = 'sales_channel';
                $writeId = (string) $scid;
            } elseif (($manId = $input->getOption('manufacturer-id')) !== null) {
                $ctx = array_merge($this->factLoader->loadManufacturer((string) $manId, $entityContext), $ctx);
                $writeEntityType = 'manufacturer';
                $writeId = (string) $manId;
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $mode = (string) $input->getOption('mode');
        $metaFields = $input->getOption('meta-fields')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('meta-fields')))))
            : null;

        $io->title('ContentCreator – ' . $type . ' (' . $lang . ', ' . $mode . ')');

        try {
            $result = $this->generator->generate(
                $type,
                $lang,
                $ctx,
                $input->getOption('provider') ?: null,
                $input->getOption('model') ?: null,
                $mode,
                $metaFields,
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->writeln('<info>Provider:</info> ' . $result['provider'] . '  <info>Modell:</info> ' . ($result['model'] ?? '?'));
        $io->writeln('<info>Tokens:</info> in=' . $result['usage']['input'] . ' out=' . $result['usage']['output']);

        $quality = $result['quality'];
        $io->writeln(sprintf(
            '<info>Qualität:</info> Score %d (%s, Schwelle %d) – %s nach %d Versuch(en)%s',
            $quality['score'],
            $quality['level'],
            $quality['threshold'],
            $quality['passed'] ? '<info>BESTANDEN</info>' : '<error>NICHT bestanden</error>',
            $quality['attempts'],
            $quality['originalScore'] !== null ? ' – Original-Score: ' . $quality['originalScore'] : '',
        ));
        foreach ($quality['findings'] as $finding) {
            $io->writeln(sprintf('  <comment>%s</comment> (%dx, Score %d)', $finding['pattern'], $finding['count'], $finding['score']));
        }
        foreach ($quality['lengthIssues'] as $issue) {
            $io->writeln(sprintf('  <error>Länge:</error> %s hat %d Zeichen (Ziel %d-%d)', $issue['field'], $issue['length'], $issue['min'], $issue['max']));
        }
        if ($quality['missingFacts'] !== []) {
            $io->writeln('  <error>Fehlende Fakten:</error> ' . implode(', ', $quality['missingFacts']));
        }
        if (($result['readability'] ?? null) !== null) {
            $rb = $result['readability'];
            $io->writeln(sprintf('<info>Lesbarkeit:</info> %d/%d Checks bestanden', $rb['passedCount'], $rb['total']));
            foreach ($rb['checks'] as $check) {
                $io->writeln('  ' . ($check['passed'] ? '<info>✓</info>' : '<comment>⚠</comment>') . ' ' . $check['key'] . ' (' . $check['detail'] . ')');
            }
        }
        if (($result['focusChecks'] ?? null) !== null) {
            $fc = $result['focusChecks'];
            $io->writeln(sprintf('<info>Fokus-Keyword "%s":</info> %d/%d Checks bestanden', $fc['keyword'], $fc['passedCount'], $fc['total']));
            foreach ($fc['checks'] as $check) {
                $io->writeln('  ' . ($check['passed'] ? '<info>✓</info>' : '<error>✗</error>') . ' ' . $check['key'] . ($check['detail'] !== '' ? ' (' . $check['detail'] . ')' : ''));
            }
        }
        $io->newLine();

        if ($result['meta'] !== null) {
            $io->section('Meta');
            foreach ($result['meta'] as $key => $value) {
                $io->writeln('<comment>' . $key . '</comment> (' . mb_strlen($value) . '): ' . $value);
            }
        } else {
            $io->section('Ergebnis');
            $io->writeln((string) $result['content']);
        }

        if ($input->getOption('write') && $writeEntityType !== null && $writeId !== null) {
            if (!($result['quality']['passed'] ?? false)) {
                $io->warning('Qualitäts-Gate NICHT bestanden — wird nur geschrieben, weil dies der CLI-Testmodus ist (Batch würde ablehnen).');
            }
            $this->writer->apply($writeEntityType, $writeId, Defaults::LANGUAGE_SYSTEM, $type, $result, Context::createDefaultContext());
            $io->success('Zurückgeschrieben in ' . $writeEntityType . ' ' . $writeId);
        }

        return Command::SUCCESS;
    }
}
