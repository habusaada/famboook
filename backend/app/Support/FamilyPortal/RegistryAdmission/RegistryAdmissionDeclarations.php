<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

/**
 * The versioned declarations of FB-FAMILY-ADD-01 (ADR §6; O-K, G-02, RR-02).
 *
 * - The catalogue is `family_registry_admission.declarations`, in the committed config
 *   file: version => {text, approved}. A version's text never changes once it exists;
 *   a new wording is a new version. `approved` is set to true only by a reviewed commit
 *   that records the G-02 decision (it is NOT an environment switch).
 * - The active version is `family_registry_admission.declaration_version`
 *   (FAMILY_REGISTRY_ADMISSION_DECLARATION_VERSION), with no default.
 * - active() is that version only when it names a catalogue entry that is approved and
 *   has a text. Anything else is NULL: no declaration is delivered, discovery issues no
 *   reference, and every admission is refused DECLARATION_REQUIRED (fail closed).
 */
final class RegistryAdmissionDeclarations
{
    public static function active(): ?RegistryAdmissionDeclaration
    {
        $version = config('family_registry_admission.declaration_version');
        if (! is_string($version) || trim($version) === '' || $version !== trim($version)
            || strlen($version) > RegistryAdmissionRequest::DECLARATION_VERSION_MAX) {
            return null;
        }
        $entry = self::catalogue()[$version] ?? null;
        if (! is_array($entry) || ($entry['approved'] ?? null) !== true) {
            return null;
        }
        $text = $entry['text'] ?? null;

        return is_string($text) && trim($text) !== '' ? new RegistryAdmissionDeclaration($version, $text) : null;
    }

    /** Whether $version is the active, approved one; a constant-time comparison. */
    public static function accepts(string $version): bool
    {
        $active = self::active();

        return $active !== null && hash_equals($active->version, $version);
    }

    /** @return array<string, mixed> */
    public static function catalogue(): array
    {
        $catalogue = config('family_registry_admission.declarations');

        return is_array($catalogue) ? $catalogue : [];
    }
}
