<?php

namespace App\Support\Notifications;

use App\Enums\NotificationAudienceType;
use App\Exceptions\NotificationAudienceException;

/**
 * A requested notification audience (FB-NOTIFY-01B.2b.3): the criteria
 * stored in notification_messages.audience — never the resolved families.
 * Parsed strictly: an unknown type or key, a non-integer id, an empty or
 * oversized selection is INVALID, never "repaired". The ids are canonical
 * database ids chosen on the server side (a request layer maps public
 * references to them); they are criteria only and authorize nothing.
 *
 *   {"type": "STAFF_FAMILIES", "family_ids": [..]}
 *   {"type": "STAFF_ALL_ACTIVE"}
 *   {"type": "COORDINATOR_SCOPE"}
 *   {"type": "COORDINATOR_SELECTION", "family_ids": [..], "branch_ids": [..],
 *    "branch_group_ids": [..], "clan_ids": [..]}   (at least one non-empty)
 *   {"type": "SYSTEM_ORIGIN"}
 */
final class NotificationAudience
{
    /** The most ids one audience may name, all lists together. */
    public const MAX_SELECTED = 500;

    public const SELECTION_KEYS = ['family_ids', 'branch_ids', 'branch_group_ids', 'clan_ids'];

    /**
     * @param  array<string, list<int>>  $selection  sorted, de-duplicated ids per selection key
     */
    private function __construct(
        public readonly NotificationAudienceType $type,
        public readonly array $selection,
    ) {}

    public static function fromArray(mixed $raw): self
    {
        if (! is_array($raw) || ! is_string($raw['type'] ?? null)) {
            throw new NotificationAudienceException(NotificationAudienceException::INVALID);
        }
        $type = NotificationAudienceType::tryFrom($raw['type'])
            ?? throw new NotificationAudienceException(NotificationAudienceException::INVALID);

        $allowed = match ($type) {
            NotificationAudienceType::STAFF_FAMILIES => ['family_ids'],
            NotificationAudienceType::COORDINATOR_SELECTION => self::SELECTION_KEYS,
            default => [],
        };
        if (array_diff(array_keys($raw), ['type', ...$allowed]) !== []) {
            throw new NotificationAudienceException(NotificationAudienceException::INVALID);
        }

        $selection = [];
        foreach ($allowed as $key) {
            $ids = self::parseIds($raw[$key] ?? []);
            if ($ids !== []) {
                $selection[$key] = $ids;
            }
        }
        $total = array_sum(array_map('count', $selection));
        if (($allowed !== [] && $total === 0) || $total > self::MAX_SELECTED) {
            throw new NotificationAudienceException(NotificationAudienceException::INVALID);
        }

        return new self($type, $selection);
    }

    public static function systemOrigin(): self
    {
        return new self(NotificationAudienceType::SYSTEM_ORIGIN, []);
    }

    /** @return list<int> */
    public function ids(string $key): array
    {
        return $this->selection[$key] ?? [];
    }

    /** The JSON object stored in notification_messages.audience. */
    public function toArray(): array
    {
        return ['type' => $this->type->value, ...$this->selection];
    }

    /** @return list<int> */
    private static function parseIds(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new NotificationAudienceException(NotificationAudienceException::INVALID);
        }
        foreach ($raw as $id) {
            if (! is_int($id) || $id < 1) {
                throw new NotificationAudienceException(NotificationAudienceException::INVALID);
            }
        }
        $ids = array_values(array_unique($raw));
        sort($ids);

        return $ids;
    }
}
