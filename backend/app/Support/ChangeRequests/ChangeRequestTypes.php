<?php

namespace App\Support\ChangeRequests;

use App\Enums\ChangeRequestType;
use App\Exceptions\ChangeRequestException;
use App\Support\ChangeRequests\Handlers\AddFamilyMemberHandler;
use App\Support\ChangeRequests\Handlers\ContactUpdateHandler;
use App\Support\ChangeRequests\Handlers\DeathReportHandler;
use App\Support\ChangeRequests\Handlers\PersonCorrectionHandler;
use App\Support\ChangeRequests\Handlers\RegisterFamilyHandler;
use App\Support\ChangeRequests\Handlers\ResidenceUpdateHandler;
use InvalidArgumentException;
use LogicException;

/**
 * The ONE authoritative mapping ChangeRequestType → handler (PWA-5b, AE-1).
 * A type without a handler here does not exist for the engine: it cannot be
 * submitted, approved or applied (CHANGE_REQUEST_TYPE_UNAVAILABLE).
 *
 * PRODUCTION holds the approved types only: RESIDENCE_UPDATE since PWA-6.1
 * (docs/11 FP-ADR-059). Registration makes a type known to the engine; a
 * family can submit it only while the submission switch is on. The container
 * binds production(); only the unit-test environment may build a registry
 * with other handlers (fake()), so a test handler can never become a
 * Production type.
 */
final class ChangeRequestTypes
{
    /**
     * Production registrations: type value => handler class.
     *
     * @var array<string, class-string<ChangeRequestHandler>>
     */
    public const PRODUCTION = [
        'RESIDENCE_UPDATE' => ResidenceUpdateHandler::class,
        'ADD_FAMILY_MEMBER' => AddFamilyMemberHandler::class,
        'PERSON_CORRECTION' => PersonCorrectionHandler::class,
        'DEATH_REPORT' => DeathReportHandler::class,
        // FP-ADR-079: families reach it only while FAMILY_CONTACT_UPDATE_ENABLED is on.
        'CONTACT_UPDATE' => ContactUpdateHandler::class,
        // CO-02.5 (FAMILY_ADD): never family-submittable; Coordinators reach it
        // only while CHANGE_REQUESTS_COORDINATOR_SUBMISSION_MODE is open.
        'REGISTER_FAMILY' => RegisterFamilyHandler::class,
    ];

    /** @param array<string, ChangeRequestHandler> $handlers keyed by type value */
    private function __construct(private readonly array $handlers)
    {
        foreach ($handlers as $type => $handler) {
            if (ChangeRequestType::tryFrom((string) $type) === null || ! $handler instanceof ChangeRequestHandler) {
                throw new InvalidArgumentException('Invalid change request handler registration.');
            }
        }
    }

    public static function production(): self
    {
        return new self(array_map(fn (string $class) => app($class), self::PRODUCTION));
    }

    /**
     * A registry with test handlers — unit tests only.
     *
     * @param  array<string, ChangeRequestHandler>  $handlers
     */
    public static function fake(array $handlers): self
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('Test change request handlers can only be registered in tests.');
        }

        return new self($handlers);
    }

    public function handler(ChangeRequestType $type): ChangeRequestHandler
    {
        return $this->handlers[$type->value] ?? throw new ChangeRequestException(ChangeRequestException::TYPE_UNAVAILABLE);
    }

    public function has(ChangeRequestType $type): bool
    {
        return isset($this->handlers[$type->value]);
    }

    /** @return list<ChangeRequestType> the types a family may submit now */
    public function familySubmittable(): array
    {
        return array_values(array_map(
            fn (string $type) => ChangeRequestType::from($type),
            array_keys(array_filter($this->handlers, fn (ChangeRequestHandler $h) => $h->familySubmittable())),
        ));
    }

    /** @return list<ChangeRequestType> every registered type */
    public function registered(): array
    {
        return array_map(fn (string $type) => ChangeRequestType::from($type), array_keys($this->handlers));
    }
}
