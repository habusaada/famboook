<?php

namespace App\Support\Notifications;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A JSON OBJECT column (FB-NOTIFY-01B.2a): notification_messages.audience
 * and .sender_scope, notification_recipient_events.metadata. The database
 * accepts only a JSON object there (chk_nm_json, chk_nre_metadata); the
 * plain `array` cast would write an empty PHP array as the list `[]`, which
 * PostgreSQL refuses and SQLite would silently store.
 *
 * Reads as an associative PHP array. Writes an empty array as `{}`, a keyed
 * array as an object, and refuses a non-empty list or a scalar instead of
 * reshaping it. Nested values keep their JSON form. NULL stays NULL (the
 * NOT NULL columns reject it in the database).
 */
final class JsonObject implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value === null ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("The {$key} attribute must be a JSON object.");
        }

        return $value === [] ? '{}' : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
