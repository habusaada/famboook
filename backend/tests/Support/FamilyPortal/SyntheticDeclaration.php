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

    /** RR-02A: a synthetic, separate statement of current life (not the proposed wording). */
    public const LIFE_TEXT = 'إقرار حياة تجريبي للاختبار فقط.';

    /** @return array<string, mixed> config entries that approve $version (with a life statement when $withLife) for one test */
    public static function approved(string $version, bool $withLife = false): array
    {
        return [
            'family_registry_admission.declaration_version' => $version,
            'family_registry_admission.declarations' => [$version => ['approved' => true, 'text' => self::TEXT]
                + ($withLife ? ['life_text' => self::LIFE_TEXT] : [])],
        ];
    }

    /**
     * RR-02A: the revised life policy (D-06R) switched on for one test, with a synthetic
     * approval reference and the given living-scope releases ("7:2026-10-25,…").
     *
     * @return array<string, mixed>
     */
    public static function revisedLifePolicy(string $version, string $releases): array
    {
        return [...self::approved($version, withLife: true),
            'family_registry_admission.life_policy.mode' => 'REGISTRY_SCOPE_AND_DECLARATION',
            'family_registry_admission.life_policy.approval_reference' => 'TEST-D-06R-0001',
            'family_registry_admission.life_policy.living_scope_releases' => $releases,
        ];
    }
}
