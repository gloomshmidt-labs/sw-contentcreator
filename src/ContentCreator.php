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

        // Noch nicht verarbeitete Batch-Nachrichten entfernen (Doctrine-Transport) —
        // nach der Deinstallation existiert die Message-Klasse nicht mehr und die
        // Nachrichten würden nur noch als Deserialisierungs-Fehler enden.
        if ($connection->fetchOne("SHOW TABLES LIKE 'messenger_messages'") !== false) {
            $connection->executeStatement(
                "DELETE FROM messenger_messages WHERE body LIKE '%BatchGenerateMessage%'",
            );
        }

        // Eigene Tabellen entfernen (abhängige zuerst)
        $connection->executeStatement('DROP TABLE IF EXISTS `content_creator_usage`');
        $connection->executeStatement('DROP TABLE IF EXISTS `content_creator_media_rename`');
        $connection->executeStatement('DROP TABLE IF EXISTS `content_creator_batch_result`');
        $connection->executeStatement('DROP TABLE IF EXISTS `content_creator_backup`');
        $connection->executeStatement('DROP TABLE IF EXISTS `content_creator_generation_job`');

        // Plugin-Konfiguration entfernen
        $connection->executeStatement(
            "DELETE FROM system_config WHERE configuration_key LIKE 'ContentCreator.config.%'",
        );

        // Scheduled Task entfernen
        $connection->executeStatement(
            "DELETE FROM scheduled_task WHERE name = 'content_creator.fill_missing_content'",
        );

        // Eigene customFields-Schlüssel aus den Übersetzungs-Tabellen entfernen
        // (das Plugin legt KEIN custom_field_set an, sondern schreibt die Keys
        // direkt ins custom_fields-JSON von Produkt/Kategorie/Hersteller).
        $this->removeCustomFieldKeys($connection);

        // Vom User im Admin geänderte Storefront-Snippets des Plugins entfernen
        // (die Datei-Snippets selbst verschwinden mit dem Plugin-Code)
        $connection->executeStatement(
            "DELETE FROM snippet WHERE translation_key LIKE 'contentCreator.%'",
        );

        // Eigene ACL-Privilegien aus den Admin-Rollen entfernen
        $this->removeAclPrivileges($connection);
    }

    /**
     * Entfernt die drei Plugin-Keys (Fokus-Keyword, Freshness-Stempel, FAQ)
     * aus dem custom_fields-JSON aller betroffenen Übersetzungs-Tabellen.
     */
    private function removeCustomFieldKeys(Connection $connection): void
    {
        $paths = "'$.content_creator_focus_keyword', '$.content_creator_generated_at', '$.content_creator_faq'";

        foreach (['product_translation', 'category_translation', 'product_manufacturer_translation'] as $table) {
            $connection->executeStatement(
                "UPDATE `{$table}`
                 SET `custom_fields` = JSON_REMOVE(`custom_fields`, {$paths})
                 WHERE `custom_fields` IS NOT NULL
                   AND JSON_CONTAINS_PATH(`custom_fields`, 'one', {$paths})",
            );
        }
    }

    /**
     * Entfernt die Plugin-Privilegien (content_creator.viewer/editor) aus allen
     * ACL-Rollen — die übrigen Privilegien der Rolle bleiben unangetastet.
     */
    private function removeAclPrivileges(Connection $connection): void
    {
        $roles = $connection->fetchAllAssociative(
            "SELECT `id`, `privileges` FROM `acl_role` WHERE `privileges` LIKE '%content_creator%'",
        );

        foreach ($roles as $role) {
            $privileges = json_decode((string) $role['privileges'], true);
            if (!\is_array($privileges)) {
                continue;
            }

            $cleaned = array_values(array_filter(
                $privileges,
                static fn (mixed $privilege): bool => !\is_string($privilege) || !str_starts_with($privilege, 'content_creator.'),
            ));

            if (\count($cleaned) !== \count($privileges)) {
                $connection->executeStatement(
                    'UPDATE `acl_role` SET `privileges` = :privileges, `updated_at` = NOW(3) WHERE `id` = :id',
                    ['privileges' => json_encode($cleaned), 'id' => $role['id']],
                );
            }
        }
    }
}
