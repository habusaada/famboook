<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Support\CivilLookup\CivilRegistrySource;
use Illuminate\Contracts\Foundation\Application;
use LogicException;

/**
 * The ONE place that may bind the published civil registry into FB-FAMILY-ADD-01
 * (RR-02). Only when `family_registry_admission.civil_registry_binding` is exactly true
 * (FAMILY_REGISTRY_ADMISSION_CIVIL_REGISTRY_BINDING; default false):
 *
 * - RegistryChildSource: CivilRegistryChildSource over the request's CivilRegistrySource
 *   (CivilRegistryReader, the identity_ref_lookup login);
 * - RegistryReleasePinVerifier: that same reader (identity_ref_lookup.release_pin_held,
 *   DBA-3), which must reach the same cluster and database as the application (DBA-4).
 *
 * It never binds a RegistryLifeVerifier (G-05: no authoritative source is approved) or
 * a RegistryChildAdmissibility. Binding opens nothing by itself: the flag, the
 * permission, the declaration, the life and gender gates and the pin still decide.
 */
final class RegistryAdmissionBindings
{
    public static function register(Application $app): void
    {
        if (config('family_registry_admission.civil_registry_binding') !== true) {
            return;
        }
        $app->scoped(RegistryChildSource::class, fn ($app) => new CivilRegistryChildSource($app->make(CivilRegistrySource::class)));
        $app->scoped(RegistryReleasePinVerifier::class, function ($app) {
            $reader = $app->make(CivilRegistrySource::class);
            if (! $reader instanceof RegistryReleasePinVerifier) {
                throw new LogicException('The civil registry reader cannot verify the release pin.');
            }

            return $reader;
        });
    }
}
