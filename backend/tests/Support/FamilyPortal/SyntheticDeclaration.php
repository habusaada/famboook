<?php

namespace Tests\Support\FamilyPortal;

/**
 * TESTS ONLY (FB-FAMILY-ADD-01 RR-02): an APPROVED synthetic declaration, so an admission
 * can be accepted at all. The shipped catalogue holds a draft only, which is never
 * accepted. This text is not the proposed wording and approves nothing.
 */
final class SyntheticDeclaration
{
    public const TEXT = 'إقرار تجريبي للاختبار فقط، وليس النص المعتمد.';

    /** @return array<string, mixed> config entries that approve $version for one test */
    public static function approved(string $version): array
    {
        return [
            'family_registry_admission.declaration_version' => $version,
            'family_registry_admission.declarations' => [$version => ['approved' => true, 'text' => self::TEXT]],
        ];
    }
}
