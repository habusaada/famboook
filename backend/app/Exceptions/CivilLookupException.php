<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A Civil Registry Lookup refusal (FB-CIVIL-02A, ADR §L-12): a fixed code and
 * status, never a database message, a National ID or a search term.
 */
class CivilLookupException extends RuntimeException
{
    public const DISABLED = 'LOOKUP_DISABLED';

    public const NOT_CONFIGURED = 'LOOKUP_NOT_CONFIGURED';

    public const UNAVAILABLE = 'LOOKUP_UNAVAILABLE';

    public const NO_PUBLISHED_RELEASE = 'NO_PUBLISHED_RELEASE';

    public const NOT_ALLOWED = 'LOOKUP_NOT_ALLOWED';

    public const QUOTA_EXHAUSTED = 'QUOTA_EXHAUSTED';

    public const REFERENCE_INVALID = 'REFERENCE_INVALID';

    public const RELEASE_CHANGED = 'RELEASE_CHANGED';

    public const LINKS_DISABLED = 'LINKS_DISABLED';

    public const RATE_LIMITED = 'RATE_LIMITED';

    /** FB-CIVIL-02D: the per-user access changed since the editor loaded it. */
    public const ACCESS_CHANGED = 'ACCESS_CHANGED';

    private const STATUS = [
        self::DISABLED => 503, self::NOT_CONFIGURED => 503, self::UNAVAILABLE => 503, self::NO_PUBLISHED_RELEASE => 503,
        self::NOT_ALLOWED => 403, self::LINKS_DISABLED => 403, self::QUOTA_EXHAUSTED => 429, self::RATE_LIMITED => 429,
        self::REFERENCE_INVALID => 422, self::RELEASE_CHANGED => 409, self::ACCESS_CHANGED => 409,
    ];

    private const MESSAGES = [
        self::DISABLED => 'The civil registry lookup is not enabled.',
        self::NOT_CONFIGURED => 'The civil registry lookup is not configured on this server.',
        self::UNAVAILABLE => 'The civil registry lookup is temporarily unavailable.',
        self::NO_PUBLISHED_RELEASE => 'No civil registry release is published.',
        self::NOT_ALLOWED => 'You are not allowed to use the civil registry lookup.',
        self::QUOTA_EXHAUSTED => 'Your daily civil registry lookup quota is used up.',
        self::REFERENCE_INVALID => 'The lookup reference is invalid or expired.',
        self::RELEASE_CHANGED => 'The published civil registry release changed; search again.',
        self::LINKS_DISABLED => 'This relationship lookup is not enabled.',
        self::RATE_LIMITED => 'Too many lookup requests; try again later.',
        self::ACCESS_CHANGED => 'The access of this user changed since it was loaded; reload it and try again.',
    ];

    /** @param  array<string, mixed>  $extra  safe values only (e.g. the quota status) */
    public function __construct(public readonly string $reason, public readonly array $extra = [])
    {
        parent::__construct($reason);
    }

    public function status(): int
    {
        return self::STATUS[$this->reason] ?? 500;
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => self::MESSAGES[$this->reason] ?? 'Lookup refused.', 'code' => $this->reason] + $this->extra,
            $this->status())->header('Cache-Control', 'no-store, private');
    }
}
