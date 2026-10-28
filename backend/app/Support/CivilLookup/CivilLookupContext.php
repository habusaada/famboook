<?php

namespace App\Support\CivilLookup;

use App\Exceptions\CivilLookupException;
use Illuminate\Http\Request;

/**
 * The account side a lookup is made from (FB-CIVIL-02B, ADR 02B §B-1). The
 * route decides it (SetCivilLookupContext), never the client: one service,
 * one quota and one audit serve the three mounts of the same endpoints.
 *
 *   STAFF        /api/v1/civil-registry/lookup                     (staff.side)
 *   FAMILY       /api/v1/family/civil-registry/lookup              (family.context: an eligible household head)
 *   COORDINATOR  /api/v1/family/coordinator/civil-registry/lookup  (coordinator.space: an effective assignment)
 *
 * The context is bound into every reference (CivilLookupRefs), so a result
 * obtained on one side can never be opened on another.
 */
final class CivilLookupContext
{
    public const STAFF = 'STAFF';

    public const FAMILY = 'FAMILY';

    public const COORDINATOR = 'COORDINATOR';

    public const ALL = [self::STAFF, self::FAMILY, self::COORDINATOR];

    /** The family-side contexts: each needs its own gate, permission, GRANTED side and role policy. */
    public const FAMILY_SIDE = [self::FAMILY, self::COORDINATOR];

    private const ATTRIBUTE = 'civil_lookup.context';

    public static function set(Request $request, string $context): void
    {
        $request->attributes->set(self::ATTRIBUTE, $context);
    }

    /** @throws CivilLookupException NOT_ALLOWED when no route set a context (fails closed) */
    public static function of(Request $request): string
    {
        $context = $request->attributes->get(self::ATTRIBUTE);

        return in_array($context, self::ALL, true) ? $context : throw new CivilLookupException(CivilLookupException::NOT_ALLOWED);
    }
}
