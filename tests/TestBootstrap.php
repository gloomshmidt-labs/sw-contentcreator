<?php declare(strict_types=1);

// Shop-Autoloader liefert Shopware-Core-/Symfony-Klassen. Das Plugin selbst ist
// dort NICHT registriert (im Betrieb lädt es der Shopware-Plugin-Loader) —
// daher eigener PSR-4-Fallback für ContentCreator\ und ContentCreator\Tests\.
require_once '/var/www/html/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $map = [
        'ContentCreator\\Tests\\' => __DIR__ . '/',
        'ContentCreator\\' => __DIR__ . '/../src/',
    ];
    foreach ($map as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $file = $baseDir . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
