<?php

use App\Http\Controllers\Api\V1\AssessmentController;
use App\Http\Controllers\Api\V1\AssistanceController;
use App\Http\Controllers\Api\V1\AssistanceExecutionController;
use App\Http\Controllers\Api\V1\AssistanceExportController;
use App\Http\Controllers\Api\V1\AssistanceNomineeController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChangeRequestController;
use App\Http\Controllers\Api\V1\ClanStructureController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\Family\CoordinatorFamilyController;
use App\Http\Controllers\Api\V1\Family\CoordinatorSpaceController;
use App\Http\Controllers\Api\V1\Family\FamilyAccountController;
use App\Http\Controllers\Api\V1\Family\FamilyActivationController;
use App\Http\Controllers\Api\V1\Family\FamilyAuthCapabilitiesController;
use App\Http\Controllers\Api\V1\Family\FamilyCardController;
use App\Http\Controllers\Api\V1\Family\FamilyCardPdfController;
use App\Http\Controllers\Api\V1\Family\FamilyChangeRequestController;
use App\Http\Controllers\Api\V1\Family\FamilyHouseholdController;
use App\Http\Controllers\Api\V1\Family\FamilyMemberRevealController;
use App\Http\Controllers\Api\V1\Family\FamilyPasswordResetController;
use App\Http\Controllers\Api\V1\Family\FamilySelfController;
use App\Http\Controllers\Api\V1\Family\FamilySessionController;
use App\Http\Controllers\Api\V1\FamilyActivityController;
use App\Http\Controllers\Api\V1\FamilyAssistanceController;
use App\Http\Controllers\Api\V1\FamilyController;
use App\Http\Controllers\Api\V1\FamilyCredentialController;
use App\Http\Controllers\Api\V1\FamilyHouseholdDeclarationController;
use App\Http\Controllers\Api\V1\FamilyMemberController;
use App\Http\Controllers\Api\V1\FamilyResidenceController;
use App\Http\Controllers\Api\V1\HealthRecordController;
use App\Http\Controllers\Api\V1\ImportApplyController;
use App\Http\Controllers\Api\V1\InitialFamilyImportController;
use App\Http\Controllers\Api\V1\NeedController;
use App\Http\Controllers\Api\V1\PersonController;
use App\Http\Controllers\Api\V1\PersonCoordinatorController;
use App\Http\Controllers\Api\V1\PersonMobileTrustController;
use App\Http\Controllers\Api\V1\PublicCredentialController;
use App\Http\Controllers\Api\V1\ReferenceController;
use App\Http\Controllers\Api\V1\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
    ]);
});

// Staff authentication on the Sanctum session (docs/06 §59c, AUTH-ADR-057).
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Public Digital Family Card verification (docs/11 §19, FP-ADR-070): no
// authentication, outside every group. The token travels in the BODY — never
// in an API URL — and is posted by the browser from /verify/{token}, so the
// per-IP limiter sees the real client. One generic failure, no-store.
Route::post('/credentials/verify', [PublicCredentialController::class, 'verify'])
    ->middleware('throttle:credential-verify');

// The Family Portal API (docs/06 §22b, PWA-1F), OUTSIDE the Staff group.
// `family.side` only says the account is family-side; it authorizes no family
// data — family-data routes will resolve the Family context explicitly.
Route::prefix('family')->group(function () {
    // Family Auth capabilities (FU-14, FP-ADR-065): public, read-only and
    // OUTSIDE every Family Auth gate — the three global gates as booleans,
    // nothing user-specific, so the portal hides what is closed.
    Route::get('/auth/capabilities', FamilyAuthCapabilitiesController::class)->name('family.auth.capabilities');

    // Activation (docs/11 §30a): public and generic. The gate runs first —
    // while it is off nothing is looked up, recorded or sent. National IDs,
    // codes and passwords travel in the body, never in a URL.
    Route::prefix('auth/activation')->middleware(['family.activation', 'sms.after-response'])->group(function () {
        Route::post('/start', [FamilyActivationController::class, 'start'])
            ->middleware('throttle:family-activation-start')->name('family.activation.start');
        Route::post('/send', [FamilyActivationController::class, 'send'])
            ->middleware('throttle:family-activation-send')->name('family.activation.send');
        Route::post('/verify', [FamilyActivationController::class, 'verify'])
            ->middleware('throttle:family-activation-verify')->name('family.activation.verify');
        Route::post('/resend', [FamilyActivationController::class, 'resend'])
            ->middleware('throttle:family-activation-resend')->name('family.activation.resend');
        Route::post('/complete', [FamilyActivationController::class, 'complete'])
            ->middleware('throttle:family-activation-complete')->name('family.activation.complete');
    });

    // Login (docs/11 §30a): public, behind its own gate. Every credential,
    // account and context failure is the same 401.
    Route::post('/auth/login', [FamilySessionController::class, 'login'])
        ->middleware(['family.login', 'throttle:family-login'])->name('family.login');

    // Password reset (docs/11 §30a): public and generic, behind its own gate,
    // mirroring activation. The OTP goes only to the trusted mobile.
    Route::prefix('auth/password/reset')->middleware(['family.password-reset', 'sms.after-response'])->group(function () {
        Route::post('/start', [FamilyPasswordResetController::class, 'start'])
            ->middleware('throttle:family-password-reset-start')->name('family.password-reset.start');
        Route::post('/verify', [FamilyPasswordResetController::class, 'verify'])
            ->middleware('throttle:family-password-reset-verify')->name('family.password-reset.verify');
        Route::post('/resend', [FamilyPasswordResetController::class, 'resend'])
            ->middleware('throttle:family-password-reset-resend')->name('family.password-reset.resend');
        Route::post('/complete', [FamilyPasswordResetController::class, 'complete'])
            ->middleware('throttle:family-password-reset-complete')->name('family.password-reset.complete');
    });

    // Any authenticated account may end its own session.
    Route::post('/auth/logout', [FamilySessionController::class, 'logout'])->middleware('auth:sanctum');

    Route::middleware(['auth:sanctum', 'family.side'])->group(function () {
        Route::get('/me', [FamilySessionController::class, 'me']);

        // Family data (PWA-3A): the Family comes ONLY from the family.context
        // boundary (FamilyAccessResolver), never from the client.
        Route::middleware(['can:family-portal.access', 'family.context'])->group(function () {
            Route::get('/household', [FamilyHouseholdController::class, 'show']);
            Route::get('/household/members', [FamilyHouseholdController::class, 'members']);
            Route::get('/household/profile', [FamilyHouseholdController::class, 'profile']);

            // Household health (PWA-3B.6): the registered health facts of the
            // ACTIVE members of this Family, grouped by member_ref. No
            // parameter — nothing in the request chooses a member or Person.
            Route::get('/household/health', [FamilyHouseholdController::class, 'health']);

            // Needs and received assistance (PWA-3B.7): two separate reads of
            // this Family's records. No parameter — nothing in the request
            // chooses a Family, member or Person.
            Route::get('/household/needs', [FamilyHouseholdController::class, 'needs']);
            Route::get('/household/assistance', [FamilyHouseholdController::class, 'assistance']);

            // Household-member sensitive-value reveal (PWA-3B.4): one field of
            // ANOTHER member, named by the opaque member_ref (FU-13) and
            // resolved only inside this Family — never route-model bound; any
            // unavailable target is one generic 404; throttled per user.
            Route::post('/household/members/{memberRef}/reveal', [FamilyMemberRevealController::class, 'reveal'])
                ->middleware('throttle:family-member-reveal');

            // «بياناتي الشخصية» (PWA-3B.1): SELF only, masked sensitive
            // values; the full values are a separate reveal (PWA-3B.2).
            Route::get('/self', [FamilySelfController::class, 'show']);

            // Self sensitive-value reveal (PWA-3B.2): one OWN field per
            // request (field code only, no target), throttled per user,
            // no-store, recorded as a security event without the value.
            Route::post('/self/reveal', [FamilySelfController::class, 'reveal'])
                ->middleware('throttle:family-self-reveal');

            // «حسابي» (PWA-3B.5): SELF only — the activation date and the
            // current mobile trust state with the masked mobile; no-store.
            Route::get('/account', [FamilyAccountController::class, 'show']);

            // «بطاقة الأسرة الرقمية» (PWA-8.2): ensures the Family's ACTIVE
            // card (lazy, idempotent issuance) and returns it with its QR.
            // POST — never a write on GET.
            Route::post('/card', [FamilyCardController::class, 'ensure'])
                ->middleware('throttle:family-card');

            // The card as a PDF (PWA-8.3): the EXISTING ACTIVE card only — a GET
            // with no side effects, never an issuance. In memory, no-store.
            Route::get('/card/pdf', [FamilyCardPdfController::class, 'show'])
                ->middleware('throttle:family-card-pdf');

            // Change Requests (PWA-5e; docs/11 FP-ADR-073): the Family's whole
            // request history (Family-subject), read with family-portal.access;
            // submit / resubmit / cancel each need their change-request.*
            // permission and a per-user throttle. A request is looked up inside
            // this Family only — never route-model bound. /types before {uuid}.
            Route::get('/change-requests', [FamilyChangeRequestController::class, 'index']);
            Route::get('/change-requests/types', [FamilyChangeRequestController::class, 'types']);
            Route::get('/change-requests/{changeRequest}', [FamilyChangeRequestController::class, 'show']);
            Route::post('/change-requests', [FamilyChangeRequestController::class, 'store'])
                ->middleware(['can:change-request.submit', 'throttle:family-change-request-submit']);
            Route::post('/change-requests/{changeRequest}/resubmit', [FamilyChangeRequestController::class, 'resubmit'])
                ->middleware(['can:change-request.resubmit', 'throttle:family-change-request-action']);
            Route::post('/change-requests/{changeRequest}/cancel', [FamilyChangeRequestController::class, 'cancel'])
                ->middleware(['can:change-request.cancel', 'throttle:family-change-request-action']);
        });

        // Coordinator Space (docs/11 §8, PWA-1H): the scope comes ONLY from
        // the coordinator.space boundary, never from the client.
        Route::prefix('coordinator')->middleware('coordinator.space')->group(function () {
            Route::get('/context', [CoordinatorSpaceController::class, 'context']);

            // Family SUMMARIES inside the scope. No route-model binding: the
            // code is looked up inside the authorized query only.
            Route::middleware('can:coordinator-family.view-summary')->group(function () {
                Route::get('/families', [CoordinatorFamilyController::class, 'index']);
                Route::get('/families/{familyCode}', [CoordinatorFamilyController::class, 'show'])
                    ->where('familyCode', '[A-Za-z0-9_\-]{1,50}');
            });
        });
    });
});

// The authenticated Staff API. `staff.side` refuses every family-side account
// (FAMILY_USER / COORDINATOR, or either mixed with a Staff role) before any
// permission check (docs/06 §22b, AUTH-ADR-065). The /api/v1/family routes
// are registered OUTSIDE this group.
Route::middleware(['auth:sanctum', 'staff.side'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/families', [FamilyController::class, 'index'])
        ->middleware('can:family.view');

    Route::post('/families', [FamilyController::class, 'store'])
        ->middleware('can:family.create');

    Route::get('/families/{family}', [FamilyController::class, 'show'])
        ->middleware('can:family.view');

    Route::patch('/families/{family}', [FamilyController::class, 'update'])
        ->middleware('can:family.update');

    Route::patch('/families/{family}/residence', [FamilyResidenceController::class, 'update'])
        ->middleware('can:residence.update');

    // Declared Household Statistics (docs/03 §55c, FU-10): a NEW current
    // declaration; the previous one stays as history. Stale-write protected
    // by expected_current_declaration_id (409 HOUSEHOLD_DECLARATION_CHANGED).
    Route::post('/families/{family}/household-declarations', [FamilyHouseholdDeclarationController::class, 'store'])
        ->middleware('can:family.update');

    Route::post('/families/{family}/members', [FamilyMemberController::class, 'store'])
        ->middleware('can:person.create');

    // Membership corrections (AUTH-ADR-059), addressed by Family code and
    // Person code. No DELETE: an ended membership stays as history.
    Route::patch('/families/{family}/members/{person}/relationship', [FamilyMemberController::class, 'updateRelationship'])
        ->middleware('can:family-membership.update');

    Route::post('/families/{family}/members/{person}/end', [FamilyMemberController::class, 'end'])
        ->middleware('can:family-membership.end');

    // People registry (docs/03 §93a).
    Route::get('/people', [PersonController::class, 'index'])
        ->middleware('can:person.view');

    // Exact National ID duplicate pre-check (AUTH-ADR-058): authorized in
    // NationalIdCheckRequest (person.create or family.create), rate limited.
    Route::post('/people/national-id-check', [PersonController::class, 'nationalIdCheck'])
        ->middleware('throttle:national-id-check');

    Route::get('/people/{person}', [PersonController::class, 'show'])
        ->middleware('can:person.view');

    Route::patch('/people/{person}', [PersonController::class, 'update'])
        ->middleware('can:person.update');

    // Administrative National ID correction (AUTH-ADR-059). Body only,
    // never in a URL; shares the National ID rate limit because a refusal
    // tells whether a value is already registered.
    Route::put('/people/{person}/national-id', [PersonController::class, 'correctNationalId'])
        ->middleware(['can:person.national-id.update', 'throttle:national-id-check']);

    // Life status UNKNOWN → ALIVE (docs/03 §30a, FU-07): the lifecycle
    // authority person.record-death; the verification method is required.
    Route::post('/people/{person}/confirm-alive', [PersonController::class, 'confirmAlive'])
        ->middleware('can:person.record-death');

    // Official death of an existing Person (docs/03 §30, FU-10): ALIVE or
    // UNKNOWN → DECEASED, irreversible in V1. The death date is sent
    // explicitly (a date, or null = unknown); the verification method is
    // required. A household head stays head: no successor (FU-01).
    Route::post('/people/{person}/record-death', [PersonController::class, 'recordDeath'])
        ->middleware('can:person.record-death');

    // Digital Family Card (docs/06 §22b, docs/11 FP-ADR-070, PWA-8.2):
    // Staff-side only, one permission per operation, Domain Actions only. No
    // token or QR is ever returned here.
    Route::get('/families/{family}/card', [FamilyCredentialController::class, 'show'])
        ->middleware('can:family-card.view');

    Route::post('/families/{family}/card', [FamilyCredentialController::class, 'issue'])
        ->middleware('can:family-card.issue');

    Route::post('/families/{family}/card/revoke', [FamilyCredentialController::class, 'revoke'])
        ->middleware('can:family-card.revoke');

    Route::post('/families/{family}/card/reissue', [FamilyCredentialController::class, 'reissue'])
        ->middleware('can:family-card.reissue');

    // Change Requests — the Staff workflow API (PWA-5c; docs/06 AUTH-ADR-089).
    // Addressed by uuid only (ChangeRequest::resolveRouteBinding — after
    // authentication, so a guest always gets 401). Each step is its PWA-5b Domain Action, which
    // re-checks actor, transition, fresh canonical state and the base
    // fingerprint. Apply runs WITHOUT any surrounding transaction.
    Route::prefix('change-requests')->group(function () {
        Route::get('/', [ChangeRequestController::class, 'index'])
            ->middleware('can:change-request.view');
        Route::get('/{changeRequest}', [ChangeRequestController::class, 'show'])
            ->middleware('can:change-request.view');
        Route::post('/{changeRequest}/start-review', [ChangeRequestController::class, 'startReview'])
            ->middleware('can:change-request.review');
        Route::post('/{changeRequest}/return', [ChangeRequestController::class, 'return'])
            ->middleware('can:change-request.return');
        Route::post('/{changeRequest}/approve', [ChangeRequestController::class, 'approve'])
            ->middleware('can:change-request.approve');
        Route::post('/{changeRequest}/reject', [ChangeRequestController::class, 'reject'])
            ->middleware('can:change-request.reject');
        Route::post('/{changeRequest}/apply', [ChangeRequestController::class, 'apply'])
            ->middleware('can:change-request.apply');
    });

    // Mobile trust (docs/06 §22b, PWA-1E): Staff-side only. The mobile number
    // is never an input — the Person's stored mobile is what gets trusted.
    Route::get('/people/{person}/mobile-trust', [PersonMobileTrustController::class, 'show'])
        ->middleware('can:person-mobile-trust.view');

    Route::post('/people/{person}/mobile-trust', [PersonMobileTrustController::class, 'grant'])
        ->middleware('can:person-mobile-trust.grant');

    Route::post('/people/{person}/mobile-trust/revoke', [PersonMobileTrustController::class, 'revoke'])
        ->middleware('can:person-mobile-trust.revoke');

    // Coordinators (docs/06 §22b, PWA-1H): Staff-side only. The Person's
    // family-side account is found by the server; a Coordinator never
    // reaches these routes (staff.side) and never manages another.
    Route::get('/people/{person}/coordinator', [PersonCoordinatorController::class, 'show'])
        ->middleware('can:coordinator-scope.view');

    Route::post('/people/{person}/coordinator', [PersonCoordinatorController::class, 'grant'])
        ->middleware('can:coordinator-scope.manage');

    Route::post('/people/{person}/coordinator/revoke', [PersonCoordinatorController::class, 'revoke'])
        ->middleware('can:coordinator-scope.manage');

    Route::post('/people/{person}/coordinator/scopes', [PersonCoordinatorController::class, 'assignScope'])
        ->middleware('can:coordinator-scope.manage');

    Route::post('/coordinator-scopes/{coordinatorScope:uuid}/revoke', [PersonCoordinatorController::class, 'revokeScope'])
        ->middleware('can:coordinator-scope.manage');

    // Person-based health records (docs/06 §40): health-record.* only.
    Route::get('/families/{family}/health-records', [HealthRecordController::class, 'index'])
        ->middleware('can:health-record.view');

    Route::post('/families/{family}/health-records', [HealthRecordController::class, 'store'])
        ->middleware('can:health-record.create');

    Route::patch('/health-records/{healthRecord}', [HealthRecordController::class, 'update'])
        ->middleware('can:health-record.update');

    Route::post('/health-records/{healthRecord}/close', [HealthRecordController::class, 'close'])
        ->middleware('can:health-record.close');

    // Family Activity Log (docs/06 §57a): read-only. Entries are written
    // only by Domain Actions; there is no POST/PATCH/DELETE by design.
    Route::get('/families/{family}/activities', [FamilyActivityController::class, 'index'])
        ->middleware('can:activity-log.view');

    // Family-level assessments (docs/06 §47): assessment.* only. No
    // delete endpoint in V1; a COMPLETED assessment is never edited.
    Route::get('/families/{family}/assessments', [AssessmentController::class, 'index'])
        ->middleware('can:assessment.view');

    Route::post('/families/{family}/assessments', [AssessmentController::class, 'store'])
        ->middleware('can:assessment.create');

    // Cross-family registry (Assessments Pilot Workspace): read-only, no
    // notes; creation stays family-scoped.
    Route::get('/assessments', [AssessmentController::class, 'registry'])
        ->middleware('can:assessment.view');

    Route::get('/assessments/{assessment}', [AssessmentController::class, 'show'])
        ->middleware('can:assessment.view');

    Route::patch('/assessments/{assessment}', [AssessmentController::class, 'update'])
        ->middleware('can:assessment.update');

    Route::post('/assessments/{assessment}/complete', [AssessmentController::class, 'complete'])
        ->middleware('can:assessment.complete');

    // Needs (docs/06 §49): need.* only. No delete endpoint and no generic
    // status PATCH: resolution happens only through fulfill/close, both
    // governed by need.close in V1.
    Route::get('/needs', [NeedController::class, 'index'])
        ->middleware('can:need.view');

    Route::get('/families/{family}/needs', [NeedController::class, 'familyIndex'])
        ->middleware('can:need.view');

    Route::post('/families/{family}/needs', [NeedController::class, 'store'])
        ->middleware('can:need.create');

    Route::get('/needs/{need}', [NeedController::class, 'show'])
        ->middleware('can:need.view');

    Route::patch('/needs/{need}', [NeedController::class, 'update'])
        ->middleware('can:need.update');

    Route::post('/needs/{need}/fulfill', [NeedController::class, 'fulfill'])
        ->middleware('can:need.close');

    Route::post('/needs/{need}/close', [NeedController::class, 'close'])
        ->middleware('can:need.close');

    // Assistance V1-A (docs/06 §50): program definition, targeting and
    // nomination. No delete endpoints; approval/delivery belong to V1-B.
    Route::get('/assistances', [AssistanceController::class, 'index'])
        ->middleware('can:assistance.view');

    Route::post('/assistances', [AssistanceController::class, 'store'])
        ->middleware('can:assistance.create');

    Route::get('/assistances/{assistance}', [AssistanceController::class, 'show'])
        ->middleware('can:assistance.view');

    Route::patch('/assistances/{assistance}', [AssistanceController::class, 'update'])
        ->middleware('can:assistance.update');

    Route::post('/assistances/{assistance}/open', [AssistanceController::class, 'open'])
        ->middleware('can:assistance.open');

    // Read-only preview: persists nothing.
    Route::post('/assistances/{assistance}/targeting-preview', [AssistanceNomineeController::class, 'preview'])
        ->middleware('can:assistance.nominate');

    Route::get('/assistances/{assistance}/nominees', [AssistanceNomineeController::class, 'index'])
        ->middleware('can:assistance.view');

    Route::get('/assistances/{assistance}/nominee-candidates', [AssistanceNomineeController::class, 'candidates'])
        ->middleware('can:assistance.nominate');

    Route::post('/assistances/{assistance}/nominees/manual', [AssistanceNomineeController::class, 'manual'])
        ->middleware('can:assistance.nominate');

    Route::post('/assistances/{assistance}/nominees/from-needs', [AssistanceNomineeController::class, 'fromNeeds'])
        ->middleware('can:assistance.nominate');

    Route::post('/assistances/{assistance}/nominees/from-targeting', [AssistanceNomineeController::class, 'fromTargeting'])
        ->middleware('can:assistance.nominate');

    // History-preserving withdrawal (status REMOVED), not a DELETE.
    Route::post('/assistances/{assistance}/nominees/{nominee}/remove', [AssistanceNomineeController::class, 'remove'])
        ->middleware('can:assistance.nominate');

    // Assistance V1-B (docs/06 §50): approval, INTERNAL delivery and
    // EXTERNAL beneficiary lists. National IDs only travel in request
    // bodies of the delivery endpoints; never in URLs.
    Route::post('/assistances/{assistance}/complete', [AssistanceController::class, 'complete'])
        ->middleware('can:assistance.complete');

    Route::post('/assistances/{assistance}/nominees/bulk-approve', [AssistanceExecutionController::class, 'bulkApprove'])
        ->middleware('can:assistance.approve');

    Route::post('/assistances/{assistance}/nominees/{nominee}/approve', [AssistanceExecutionController::class, 'approve'])
        ->middleware('can:assistance.approve');

    Route::post('/assistances/{assistance}/nominees/{nominee}/reject', [AssistanceExecutionController::class, 'reject'])
        ->middleware('can:assistance.approve');

    Route::post('/assistances/{assistance}/nominees/{nominee}/delivery/verify', [AssistanceExecutionController::class, 'verifyDelivery'])
        ->middleware('can:assistance.deliver');

    Route::post('/assistances/{assistance}/nominees/{nominee}/delivery', [AssistanceExecutionController::class, 'deliver'])
        ->middleware('can:assistance.deliver');

    Route::post('/assistances/{assistance}/nominees/{nominee}/not-delivered', [AssistanceExecutionController::class, 'notDelivered'])
        ->middleware('can:assistance.deliver');

    Route::post('/assistance-deliveries/{delivery}/reverse', [AssistanceExecutionController::class, 'reverse'])
        ->middleware('can:assistance.reverse');

    Route::get('/assistances/{assistance}/export-fields', [AssistanceExportController::class, 'fields'])
        ->middleware('can:assistance.view');

    Route::put('/assistances/{assistance}/export-configuration', [AssistanceExportController::class, 'updateConfiguration'])
        ->middleware('can:assistance.export');

    Route::post('/assistances/{assistance}/beneficiary-lists/preview', [AssistanceExportController::class, 'preview'])
        ->middleware('can:assistance.export');

    Route::post('/assistances/{assistance}/beneficiary-lists', [AssistanceExportController::class, 'issue'])
        ->middleware('can:assistance.export');

    Route::get('/assistances/{assistance}/beneficiary-lists', [AssistanceExportController::class, 'index'])
        ->middleware('can:assistance.view');

    Route::get('/assistance-beneficiary-lists/{list}', [AssistanceExportController::class, 'show'])
        ->middleware('can:assistance.export');

    Route::get('/assistance-beneficiary-lists/{list}/download', [AssistanceExportController::class, 'download'])
        ->middleware('can:assistance.export');

    Route::get('/families/{family}/assistances', [FamilyAssistanceController::class, 'index'])
        ->middleware('can:assistance.view');

    // reference-data.view OR assistance.view (checked in the controller).
    Route::get('/reference/assistance-categories', [ReferenceController::class, 'assistanceCategories']);

    // reference-data.view OR need.view (checked in the controller).
    Route::get('/reference/need-categories', [ReferenceController::class, 'needCategories']);

    // reference-data.view OR assessment.view (checked in the controller):
    // everyone who may read assessments needs the domain vocabulary.
    Route::get('/reference/assessment-domains', [ReferenceController::class, 'assessmentDomains']);

    Route::get('/reference/disability-types', [ReferenceController::class, 'disabilityTypes'])
        ->middleware('can:reference-data.view');

    Route::get('/reference/relationship-types', [ReferenceController::class, 'relationshipTypes'])
        ->middleware('can:reference-data.view');

    // Clan → Branch Group → Branch structure (docs/06 §56a, AUTH-ADR-054).
    // Reads return the active structure (include_inactive=1 needs clan.manage).
    Route::middleware('can:clan.view')->group(function () {
        Route::get('/reference/clans', [ClanStructureController::class, 'index']);
        Route::get('/clans/{clan}/branch-groups', [ClanStructureController::class, 'groups']);
        Route::get('/clans/{clan}/branches', [ClanStructureController::class, 'branches']);
    });

    // Operational Dashboard V1 (docs/06 §59a, AUTH-ADR-055): derived
    // aggregates; sections the user may not read come back as null.
    Route::middleware('can:dashboard.view-operational')->group(function () {
        Route::get('/dashboard/scope-options', [DashboardController::class, 'scopeOptions']);
        Route::get('/dashboard', [DashboardController::class, 'show']);
    });

    // Reports V1 (docs/06 §59b, AUTH-ADR-056): report.view plus each
    // report's domain permissions (checked in the controller); XLSX
    // export additionally needs export.basic.
    Route::middleware('can:report.view')->prefix('reports')->group(function () {
        Route::get('/meta', [ReportController::class, 'meta']);
        Route::get('/scope-options', [DashboardController::class, 'scopeOptions']);
        Route::get('/population', [ReportController::class, 'population']);
        Route::get('/health', [ReportController::class, 'health']);
        Route::get('/needs', [ReportController::class, 'needs']);
        Route::get('/assessments', [ReportController::class, 'assessments']);
        Route::get('/assessments/families', [ReportController::class, 'assessmentFamilies']);
        Route::get('/assistance', [ReportController::class, 'assistance']);
        Route::get('/data-quality', [ReportController::class, 'dataQuality']);
        Route::get('/data-quality/records', [ReportController::class, 'dataQualityRecords']);
        Route::get('/{report}/export', [ReportController::class, 'export'])
            ->whereIn('report', ['population', 'health', 'needs', 'assessments', 'assistance', 'data-quality']);
    });

    // No DELETE routes: structures are deactivated, never removed.
    Route::middleware('can:clan.manage')->group(function () {
        Route::post('/clans', [ClanStructureController::class, 'storeClan']);
        Route::patch('/clans/{clan}', [ClanStructureController::class, 'updateClan']);
        Route::post('/clans/{clan}/branch-groups', [ClanStructureController::class, 'storeGroup']);
        Route::patch('/branch-groups/{branchGroup}', [ClanStructureController::class, 'updateGroup']);
        Route::post('/clans/{clan}/branches', [ClanStructureController::class, 'storeBranch']);
        Route::patch('/branches/{branch}', [ClanStructureController::class, 'updateBranch']);
    });

    // Import Wizard — staging, mapping and review (docs/03 §96a,
    // docs/06 §61, AUTH-ADR-060). No Apply endpoint in this phase.
    Route::prefix('imports/initial-families')->group(function () {
        // Steps 1–2: explicit Clan + import mode, workbook, worksheet.
        Route::middleware('can:import.upload')->group(function () {
            Route::post('/', [InitialFamilyImportController::class, 'store']);
            Route::post('/{importBatch}/file', [InitialFamilyImportController::class, 'replaceFile']);
            Route::put('/{importBatch}/worksheet', [InitialFamilyImportController::class, 'selectWorksheet']);
        });
        // Step 3: column mapping; confirming it stages the rows.
        Route::middleware('can:import.validate')->group(function () {
            Route::get('/{importBatch}/columns', [InitialFamilyImportController::class, 'columns']);
            Route::post('/{importBatch}/mapping', [InitialFamilyImportController::class, 'confirmMapping']);
            // Step 5: reconciliation with the registry (read-only on the registry; not Apply).
            Route::post('/{importBatch}/reconcile', [InitialFamilyImportController::class, 'reconcile']);
        });
        // Steps 4–5: read-only review.
        Route::middleware('can:import.review')->group(function () {
            Route::get('/', [InitialFamilyImportController::class, 'index']);
            Route::get('/{importBatch}', [InitialFamilyImportController::class, 'show']);
            Route::get('/{importBatch}/family-keys', [InitialFamilyImportController::class, 'familyKeys']);
            Route::get('/{importBatch}/rows', [InitialFamilyImportController::class, 'problemRows']);
            Route::get('/{importBatch}/reconciliation', [InitialFamilyImportController::class, 'reconciliationRows']);
            // Step 6 Dry Run: the pure Apply plan, read only (never import.apply).
            Route::get('/{importBatch}/dry-run', [InitialFamilyImportController::class, 'dryRun']);
            Route::get('/{importBatch}/dry-run/rows', [InitialFamilyImportController::class, 'dryRunRows']);
            // Apply progress, read only (never import.apply; no failing row number).
            Route::get('/{importBatch}/apply', [ImportApplyController::class, 'show']);
            // Step 4 decisions (creating a Branch additionally needs clan.manage,
            // enforced in ResolveFamilyKeyAction). Keys travel in the body.
            Route::put('/{importBatch}/family-keys/resolution', [InitialFamilyImportController::class, 'resolveFamilyKey']);
            Route::post('/{importBatch}/family-keys/resolution/clear', [InitialFamilyImportController::class, 'clearFamilyKey']);
            Route::post('/{importBatch}/family-keys/bulk', [InitialFamilyImportController::class, 'bulkResolveFamilyKeys']);
            // INITIAL: every unresolved key → existing exact Branch or a new BR_ Branch.
            Route::post('/{importBatch}/family-keys/auto-branches', [InitialFamilyImportController::class, 'autoCreateBranches']);
        });
        // Step 7 Apply (docs/03 §96b): import.apply — SUPER_ADMIN only, and only
        // while the Apply activation gate is open (config/import.php, docs/08).
        Route::middleware('can:import.apply')->group(function () {
            Route::post('/{importBatch}/apply/start', [ImportApplyController::class, 'start']);
            Route::post('/{importBatch}/apply/run', [ImportApplyController::class, 'run']);
            Route::post('/{importBatch}/apply/resume', [ImportApplyController::class, 'resume']);
        });
    });
});
