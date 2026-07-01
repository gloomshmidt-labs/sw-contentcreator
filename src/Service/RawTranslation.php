<?php declare(strict_types=1);

namespace ContentCreator\Service;

/**
 * Liest die ROHE Übersetzung einer Entity für eine Zielsprache — also den
 * tatsächlich gespeicherten Übersetzungs-Datensatz, NICHT den geerbten/gemergten
 * Wert. Gemeinsame Basis für ContentWriter (Teaser-slotConfig-Merge),
 * ContentBackupService (exakter Alt-Zustand inkl. "Feld war leer") und
 * LineBreakScanner (Slot-Scan pro Sprache).
 *
 * Voraussetzung: die 'translations'-Association ist an der Entity geladen.
 */
final class RawTranslation
{
    /**
     * Liefert die Translation-Entity der Zielsprache oder null, wenn für diese
     * Sprache kein Übersetzungs-Datensatz existiert (oder die Entity null ist).
     */
    public static function forLanguage(?object $entity, string $languageId): ?object
    {
        foreach ($entity?->getTranslations()?->getElements() ?? [] as $translation) {
            if ($translation->getLanguageId() === $languageId) {
                return $translation;
            }
        }

        return null;
    }
}
