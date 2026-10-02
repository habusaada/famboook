<?php

namespace Tests\Feature\FamilyAuth;

use App\Contracts\SmsSender;
use App\Support\Sms\LogSmsSender;
use App\Support\Sms\SmsDeliveryException;
use App\Support\Sms\SmsMessage;
use App\Support\Sms\UnconfiguredSmsSender;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeSmsSender;
use Tests\TestCase;

/**
 * PWA-1E: the provider-neutral SMS contract and its safe drivers (docs/11
 * §30a). No vendor exists; the default refuses. Synthetic data only.
 */
class SmsSenderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = storage_path('framework/testing/family-sms-'.bin2hex(random_bytes(6)).'.log');
        config(['family_auth.sms.log_path' => $this->path]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function message(): SmsMessage
    {
        return new SmsMessage('0591234567', 'رمز التحقق: 482913', 'ACTIVATION');
    }

    public function test_the_default_binding_is_unconfigured_and_refuses_delivery(): void
    {
        // phpunit configures no driver.
        $this->assertNull(config('family_auth.sms.driver'));
        $sender = app(SmsSender::class);
        $this->assertInstanceOf(UnconfiguredSmsSender::class, $sender);

        $this->expectException(SmsDeliveryException::class);
        $sender->send($this->message());
    }

    public function test_an_unknown_or_empty_driver_fails_closed(): void
    {
        foreach ([null, '', 'twilio', 'LOG', 'null', 'array'] as $driver) {
            config(['family_auth.sms.driver' => $driver]);
            $this->assertInstanceOf(UnconfiguredSmsSender::class, app(SmsSender::class), json_encode($driver));
        }
    }

    public function test_the_log_driver_is_bound_only_when_configured(): void
    {
        config(['family_auth.sms.driver' => 'log']);

        $this->assertInstanceOf(LogSmsSender::class, app(SmsSender::class));
    }

    public function test_the_log_driver_writes_a_masked_line_to_its_own_file(): void
    {
        config(['family_auth.sms.driver' => 'log']);

        app(SmsSender::class)->send($this->message());

        $written = file_get_contents($this->path);
        // The developer can read the code…
        $this->assertStringContainsString('482913', $written);
        $this->assertStringContainsString('purpose=ACTIVATION', $written);
        // …but the destination is masked.
        $this->assertStringContainsString('to=********67', $written);
        $this->assertStringNotContainsString('0591234567', $written);
        $this->assertSame('********67', LogSmsSender::mask('0591234567'));
    }

    public function test_the_log_driver_never_writes_to_the_application_log(): void
    {
        config(['family_auth.sms.driver' => 'log']);
        Log::spy();

        app(SmsSender::class)->send($this->message());

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('log');
        $this->assertNotSame(storage_path('logs/laravel.log'), config('family_auth.sms.log_path'));
        $this->assertStringEndsNotWith('laravel.log', (string) (require base_path('config/family_auth.php'))['sms']['log_path']);
    }

    public function test_the_log_driver_refuses_production_and_any_other_environment(): void
    {
        $sender = new LogSmsSender($this->path);

        foreach (['production', 'staging', 'pilot', 'development'] as $environment) {
            $this->app['env'] = $environment;
            try {
                $sender->send($this->message());
                $this->fail("The development SMS log ran in {$environment}.");
            } catch (SmsDeliveryException $e) {
                // The refusal carries neither the destination nor the code.
                $this->assertStringNotContainsString('0591234567', $e->getMessage());
                $this->assertStringNotContainsString('482913', $e->getMessage());
            }
        }
        $this->assertFileDoesNotExist($this->path);

        foreach (LogSmsSender::SAFE_ENVIRONMENTS as $environment) {
            $this->app['env'] = $environment;
            $sender->send($this->message());
        }
        $this->app['env'] = 'testing';
        $this->assertSame(2, substr_count(file_get_contents($this->path), 'purpose=ACTIVATION'));
    }

    public function test_an_unwritable_log_is_a_delivery_failure(): void
    {
        // A directory where the file should be.
        mkdir($this->path, 0775, true);
        try {
            (new LogSmsSender($this->path))->send($this->message());
            $this->fail('An unwritable development log did not fail.');
        } catch (SmsDeliveryException $e) {
            $this->assertStringNotContainsString('482913', $e->getMessage());
        } finally {
            rmdir($this->path);
        }
    }

    public function test_the_fake_sender_is_deterministic(): void
    {
        $fake = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $fake);

        $this->assertNull($fake->last());
        $this->assertNull($fake->lastCode());

        app(SmsSender::class)->send($this->message());
        app(SmsSender::class)->send(new SmsMessage('0567654321', 'رمز التحقق: 000042 صالح 5 دقائق', 'PASSWORD_RESET'));

        $this->assertSame(2, $fake->attempts);
        $this->assertCount(2, $fake->sent);
        $this->assertSame('0567654321', $fake->last()->destination);
        $this->assertSame('PASSWORD_RESET', $fake->last()->purpose);
        $this->assertSame('000042', $fake->lastCode());

        $fake->failing = true;
        try {
            $fake->send($this->message());
            $this->fail('A failing fake delivered.');
        } catch (SmsDeliveryException) {
        }
        $this->assertSame(3, $fake->attempts);
        $this->assertCount(2, $fake->sent);
    }

    public function test_a_message_is_immutable(): void
    {
        $message = $this->message();

        $this->expectException(\Error::class);
        $message->destination = '0567654321';
    }
}
