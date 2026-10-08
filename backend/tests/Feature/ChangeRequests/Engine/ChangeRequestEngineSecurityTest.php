<?php

namespace Tests\Feature\ChangeRequests\Engine;

use App\Actions\ChangeRequests\ApplyChangeRequestAction;
use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\RejectChangeRequestAction;
use App\Actions\ChangeRequests\ResubmitChangeRequestAction;
use App\Actions\ChangeRequests\ReturnChangeRequestForClarificationAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Enums\ChangeRequestRejectionReason;
use App\Enums\ChangeRequestType;
use App\Enums\FamilyActivityType;
use App\Enums\ProfileReviewSection;
use App\Exceptions\ChangeRequestException;
use App\Models\FamilyActivity;
use App\Models\WorkflowEvent;
use App\Support\ChangeRequests\ChangeRequestPresentationContext;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\Handlers\ResidenceUpdateHandler;
use App\Support\FamilyActivityVisibility;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\Support\ChangeRequests\ChangeRequestFixtures;
use Tests\Support\ChangeRequests\FakeChangeRequestHandler;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-5b security and privacy: an empty Production registry, test handlers
 * only in tests, no payload / fingerprint / note leakage, safe activity
 * metadata and visibility. Synthetic data only.
 */
class ChangeRequestEngineSecurityTest extends TestCase
{
    use ChangeRequestFixtures, FamilyIdentityFixtures, RefreshDatabase;

    public function test_the_production_registry_holds_residence_update_only(): void
    {
        $this->assertSame(['RESIDENCE_UPDATE' => ResidenceUpdateHandler::class], ChangeRequestTypes::PRODUCTION);
        $registry = app(ChangeRequestTypes::class);
        $this->assertSame([ChangeRequestType::RESIDENCE_UPDATE], $registry->registered());
        $this->assertSame([ChangeRequestType::RESIDENCE_UPDATE], $registry->familySubmittable());
        foreach (ChangeRequestType::cases() as $type) {
            $this->assertSame($type === ChangeRequestType::RESIDENCE_UPDATE, $registry->has($type), $type->value);
        }

        // With the real container binding, an unregistered type cannot be submitted.
        $this->useFamilyAuthKey();
        $this->seed(RolePermissionSeeder::class);
        $context = $this->headContext();
        $submit = fn () => app(SubmitChangeRequestAction::class)->handle($context, new ChangeRequestSubmission(ChangeRequestType::BIRTH_REPORT, ['x' => 1], null, (string) Str::uuid()));
        // PWA-5e: the submission switch is off by default …
        foreach ([false => ChangeRequestException::SUBMISSION_DISABLED, true => ChangeRequestException::TYPE_UNAVAILABLE] as $enabled => $code) {
            config(['change_requests.family_submission_enabled' => (bool) $enabled]);
            try {
                $submit();
                $this->fail('A Production type was submittable');
            } catch (ChangeRequestException $e) {
                // … and even when it is on, BIRTH_REPORT has no handler yet.
                $this->assertSame($code, $e->reason);
            }
        }
    }

    public function test_test_handlers_can_only_be_registered_while_running_tests(): void
    {
        $env = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            ChangeRequestTypes::fake([ChangeRequestType::OTHER->value => new FakeChangeRequestHandler]);
            $this->fail('A test handler was registered outside tests');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        } finally {
            $this->app['env'] = $env;
        }

        $this->expectException(InvalidArgumentException::class);
        ChangeRequestTypes::fake(['NOT_A_TYPE' => new FakeChangeRequestHandler]);
    }

    public function test_the_handler_contract_exposes_presentation_and_profile_sections_per_audience(): void
    {
        $this->setUpChangeRequestEngine();
        $context = $this->headContext();
        $context->family->forceFill(['paper_form_no' => 'PF-OLD'])->save();
        $request = $this->submit($context, ['paper_form_no' => 'PF-NEW']);
        $handler = app(ChangeRequestTypes::class)->handler(self::FAKE_TYPE);

        $this->assertSame(['rows' => [['label' => 'رقم الاستمارة', 'current' => null, 'proposed' => 'PF-NEW']]],
            $handler->present($request, ChangeRequestPresentationContext::family()));
        $this->assertSame(['rows' => [['label' => 'رقم الاستمارة', 'current' => 'PF-OLD', 'proposed' => 'PF-NEW']]],
            $handler->present($request, ChangeRequestPresentationContext::staff(null)));
        $this->assertSame([ProfileReviewSection::FAMILY], $handler->profileSections());
    }

    public function test_no_payload_fingerprint_or_note_leaks_into_logs_events_or_activity(): void
    {
        Log::spy();
        $this->setUpChangeRequestEngine();
        $context = $this->headContext();
        $reviewer = $this->staff();
        $secret = 'PF-0590000000';
        $request = $this->submit($context, ['paper_form_no' => $secret], reason: 'سبب خاص 123456789');

        app(StartChangeRequestReviewAction::class)->handle($request, $reviewer);
        app(ReturnChangeRequestForClarificationAction::class)->handle($request, $reviewer, 'وضّح', 'ملاحظة داخلية سرية');
        app(ResubmitChangeRequestAction::class)->handle($context, $request, 'الرد');
        app(StartChangeRequestReviewAction::class)->handle($request, $reviewer);
        app(RejectChangeRequestAction::class)->handle($request, $reviewer, ChangeRequestRejectionReason::CANNOT_VERIFY);

        $events = json_encode(WorkflowEvent::all()->map->getAttributes()->all(), JSON_UNESCAPED_UNICODE);
        $activity = json_encode(FamilyActivity::all()->map->getAttributes()->all(), JSON_UNESCAPED_UNICODE);
        foreach ([$secret, '123456789', $request->fresh()->base_fingerprint] as $value) {
            $this->assertStringNotContainsString($value, $events);
            $this->assertStringNotContainsString($value, $activity);
        }
        // The internal note stays on its event only.
        $this->assertStringNotContainsString('ملاحظة داخلية سرية', $activity);
        $noted = WorkflowEvent::whereNotNull('internal_note')->sole();
        $this->assertSame('وضّح', $noted->public_message);
        $this->assertSame(0, WorkflowEvent::where('public_message', 'like', '%ملاحظة داخلية%')->count());
        foreach (FamilyActivity::where('event_type', 'like', 'CHANGE_REQUEST_%')->get() as $entry) {
            $this->assertSame(['request_type' => 'OTHER'], $entry->metadata);
        }
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public function test_change_request_activity_is_visible_only_with_change_request_view(): void
    {
        $this->setUpChangeRequestEngine();
        $context = $this->headContext();
        $reviewer = $this->staff();
        $request = $this->submit($context);
        app(StartChangeRequestReviewAction::class)->handle($request, $reviewer);
        app(ApproveChangeRequestAction::class)->handle($request, $reviewer);
        app(ApplyChangeRequestAction::class)->handle($request, $reviewer);

        $types = fn (string $role) => FamilyActivityVisibility::apply(FamilyActivity::query(), $this->staff($role))
            ->pluck('event_type')->map(fn ($t) => $t->value)->all();

        foreach (['DATA_ENTRY', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $this->assertEmpty(array_intersect(['CHANGE_REQUEST_SUBMITTED', 'CHANGE_REQUEST_APPLIED'], $types($role)), $role);
        }
        $this->assertContains('CHANGE_REQUEST_SUBMITTED', $types('REVIEWER'));
        $this->assertContains('CHANGE_REQUEST_APPLIED', $types('REVIEWER'));
        // The canonical action's own entry follows its usual visibility.
        $this->assertContains(FamilyActivityType::FAMILY_UPDATED->value, $types('DATA_ENTRY'));
    }
}
