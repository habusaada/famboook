<?php

use App\Http\Controllers\Api\V1\AssessmentController;
use App\Http\Controllers\Api\V1\AssistanceController;
use App\Http\Controllers\Api\V1\AssistanceNomineeController;
use App\Http\Controllers\Api\V1\FamilyActivityController;
use App\Http\Controllers\Api\V1\FamilyController;
use App\Http\Controllers\Api\V1\FamilyMemberController;
use App\Http\Controllers\Api\V1\FamilyResidenceController;
use App\Http\Controllers\Api\V1\HealthRecordController;
use App\Http\Controllers\Api\V1\NeedController;
use App\Http\Controllers\Api\V1\PersonController;
use App\Http\Controllers\Api\V1\ReferenceController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
    ]);
});

Route::middleware('auth:sanctum')->group(function () {
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

    Route::get('/people/{person}', [PersonController::class, 'show'])
        ->middleware('can:person.view');

    Route::patch('/people/{person}', [PersonController::class, 'update'])
        ->middleware('can:person.update');

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
});
