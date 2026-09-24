<?php

use App\Http\Controllers\Api\V1\FamilyController;
use App\Http\Controllers\Api\V1\FamilyMemberController;
use App\Http\Controllers\Api\V1\FamilyResidenceController;
use App\Http\Controllers\Api\V1\HealthRecordController;
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

    Route::get('/reference/disability-types', [ReferenceController::class, 'disabilityTypes'])
        ->middleware('can:reference-data.view');

    Route::get('/reference/relationship-types', [ReferenceController::class, 'relationshipTypes'])
        ->middleware('can:reference-data.view');
});
