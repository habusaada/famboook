<?php

namespace App\Support;

use App\Exceptions\MissingRelationshipTypeException;
use App\Models\RelationshipType;

/**
 * Lookup of the canonical membership relationship types (docs/02 §15).
 *
 * relationship_type_id stays nullable in the schema (memberships predating
 * the reference table; docs/04 §19), so the DB cannot require it. Flows that
 * MUST record a known relationship call required() BEFORE any registry write:
 * a missing or inactive seed then fails loudly instead of producing a HEAD /
 * SPOUSE membership without a type. RegisterFamilyAction requires HEAD; the
 * future import Apply will require HEAD and SPOUSE.
 */
final class RelationshipTypes
{
    public const HEAD = 'HEAD';

    public const SPOUSE = 'SPOUSE';

    /** Id of an active type; MissingRelationshipTypeException otherwise. */
    public static function required(string $code): int
    {
        $id = RelationshipType::query()->where('code', $code)->where('is_active', true)->value('id');
        if ($id === null) {
            throw new MissingRelationshipTypeException($code);
        }

        return (int) $id;
    }

    /**
     * The relationship options for proposing a NEW member of a household
     * (FP-ADR-076; CO-02.6a for the Coordinator form): the ACTIVE types
     * except HEAD — a new member is never the head — by stable code and
     * Arabic name, in the registry's order. The one definition shared by
     * the family and Coordinator forms; the add-member validation applies
     * the same rule (AddFamilyMemberHandler).
     *
     * @return list<array{code: string, name: string}>
     */
    public static function memberOptions(): array
    {
        return RelationshipType::query()
            ->where('is_active', true)
            ->where('code', '!=', self::HEAD)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn (RelationshipType $type) => ['code' => $type->code, 'name' => $type->name])
            ->values()
            ->all();
    }
}
