<?php declare(strict_types=1);

// Code-Style nach Shopware-Konvention: PSR-12 als Basis, dazu die im
// Shopware-Core üblichen Zusatzregeln. blank_line_after_opening_tag ist
// bewusst deaktiviert, damit das einzeilige `<?php declare(strict_types=1);`
// des Bestandscodes erhalten bleibt.

$finder = (new PhpCsFixer\Finder())
    // tests/ existiert erst, sobald PHPUnit-Tests angelegt werden — Finder
    // wirft bei fehlendem Verzeichnis, daher der is_dir-Filter.
    ->in(array_filter([__DIR__ . '/src', __DIR__ . '/tests'], 'is_dir'));

return (new PhpCsFixer\Config())
    // declare_strict_types gilt als "risky" — hier gefahrlos, da bereits
    // jede Datei die Direktive trägt.
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'blank_line_after_opening_tag' => false,
        'array_syntax' => ['syntax' => 'short'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
        'phpdoc_trim' => true,
        'single_line_after_imports' => true,
    ])
    ->setFinder($finder);
