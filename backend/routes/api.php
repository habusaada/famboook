<?php

use App\Http\Controllers\Api\V1\AssessmentController;
use App\Http\Controllers\Api\V1\AssistanceController;
use App\Http\Controllers\Api\V1\AssistanceExecutionController;
use App\Http\Controllers\Api\V1\AssistanceExportController;
use App\Http\Controllers\Api\V1\AssistanceNomineeController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClanStructureController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\Family\CoordinatorFamilyController;
use App\Http\Controllers\Api\V1\Family\CoordinatorSpaceController;
use App\Http\Controllers\Api\V1\Family\FamilyActivationController;
use App\Http\Controllers\Api\V1\Family\FamilyPasswordResetController;
use App\Http\Controllers\Api\V1\Family\FamilySessionController;
use App\Http\Controllers\Api\V1\FamilyActivityController;
use App\Http\Controllers\Api\V1\FamilyAssistanceController;
use App\Http\Controllers\Api\V1\FamilyController;
use App\Http\Controllers\Api\V1\FamilyMemberController;
use App\Http\Controllers\Api\V1\FamilyResidenceController;
use App\Http\Controllers\Api\V1\HealthRecordController;
use App\Http\Controllers\Api\V1\ImportApplyController;
use App\Http\Controllers\Api\V1\InitialFamilyImportController;
use App\Http\Controllers\Api\V1\NeedController;
use App\Http\Controllers\Api\V1\PersonController;
use App\Http\Controllers\Api\V1\PersonCoordinatorController;
use App\Http\Controllers\Api\V1\PersonMobileTrustController;
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

// The Family Portal API (docs/06 §22b, PWA-1F), OUTSIDE the Staff group.
// `family.side` only says the account is family-side; it authorizes no family
// data — family-data routes will resolve the Family context explicitly.
Route::prefix('family')->group(function () {
    // Activation (docs/11 §30a): public and generic. The gate runs first —
    // while it is off nothing is looked up, recorded or sent. National IDs,
    // codes and passwords travel in the body, never in a URL.
    Route::prefix('auth/activation')->middleware(['family.activation', 'sms.after-response'])->group(function () {
        Route::post('/start', [FamilyActivationController::class, 'start'])
            ->middleware('throttle:family-activation-start')->name('family.activation.start');
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
