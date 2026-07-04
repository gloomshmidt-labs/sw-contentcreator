<?php declare(strict_types=1);

namespace ContentCreator;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ContentCreator extends Plugin
{
    private const CONFIG_DEFAULTS = [
        'provider' => 'claude',
        'anthropicModel' => 'claude-opus-4-8',
        'batchModel' => 'claude-sonnet-4-6',
        'openaiModel' => 'gpt-4o',
        'includeAnimalProfile' => true,
        'includeFunFact' => true,
        'dailyFillEnabled' => false,
        'dailyFillLimit' => 25,
        'qualityMaxScore' => 30,
        'qualityMaxRetries' => 2,
        'researchEnabled' => false,
    ];

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);

        // Standardwerte setzen, falls noch nicht vorhanden (config.xml gibt es nicht mehr –
        // die Konfiguration läuft über die eigene Einstellungs-Seite im Modul).
        /** @var SystemConfigService $config */
        $config = $this->container->get(SystemConfigService::class);
        foreach (self::CONFIG_DEFAULTS as $key => $value) {
            if ($config->get('ContentCreator.config.' . $key) === null) {
                $config->set('ContentCreator.config.' . $key, $value);
            }
        }
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        // Bei "Daten behalten" nichts löschen
        if ($uninstallContext->keepUserData()) {
            return;
        }

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);

        // Eigene Tabellen entfernen (abhängige zuerst)
        $connection->executeStatement('DROP TABLE IF EXISTS `content_creator_usage`');
        $connection->executeStatement('DROP TABLE IF EXISTS `content_creator_media_rename`');
        $connection->executeStatement('DROP TABLE IF EXISTS `content_creator_batch_result`');
        $connection->executeStatement('DROP TABLE IF EXISTS `content_creator_backup`');
        $connection->executeStatement('DROP TABLE IF EXISTS `content_creator_generation_job`');

        // Plugin-Konfiguration entfernen
        $connection->executeStatement(
            "DELETE FROM system_config WHERE configuration_key LIKE 'ContentCreator.config.%'"
        );

        // Scheduled Task entfernen
        $connection->executeStatement(
            "DELETE FROM scheduled_task WHERE name = 'content_creator.fill_missing_content'"
        );
    }
}
