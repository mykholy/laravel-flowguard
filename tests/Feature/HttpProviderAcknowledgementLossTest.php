<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mykholy\Flowguard\Contracts\OutboxPublisher;
use Mykholy\Flowguard\Outbox\DatabaseOutboxLeaseStore;
use Mykholy\Flowguard\Outbox\DatabaseOutboxRecorder;
use Mykholy\Flowguard\Outbox\OutboxDispatcher;
use Mykholy\Flowguard\Outbox\OutboxMessage;
use Psr\Log\NullLogger;
use RuntimeException;

final class HttpProviderAcknowledgementLossTest extends TestCase
{
    private ?string $providerState = null;

    /** @var resource|null */
    private $server = null;

    /** @var array<int, resource> */
    private array $serverPipes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('flowguard_http_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 120);
            $table->char('event_key_hash', 64);
            $table->string('topic', 120);
            $table->string('subject_ref', 128);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('delivery_attempts')->default(0);
            $table->char('lease_hash', 64)->nullable();
            $table->dateTime('lease_expires_at', 6)->nullable();
            $table->dateTime('available_at', 6)->nullable();
            $table->dateTime('delivered_at', 6)->nullable();
            $table->string('last_failure_code', 120)->nullable();
            $table->timestamps(6);
            $table->unique(['scope', 'event_key_hash']);
        });
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
        }

        foreach ($this->serverPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->server)) {
            proc_close($this->server);
        }

        if ($this->providerState !== null) {
            @unlink($this->providerState);
        }

        parent::tearDown();
    }

    public function test_http_acceptance_followed_by_lost_acknowledgement_does_not_duplicate_receiver_effect(): void
    {
        if (! function_exists('proc_open') || ! function_exists('stream_socket_server')) {
            self::markTestSkipped('Independent HTTP receiver needs proc_open and local sockets.');
        }

        $url = $this->startProvider();

        DB::transaction(static function (): void {
            (new DatabaseOutboxRecorder(DB::connection(), 'flowguard_http_outbox'))
                ->record('tenant.42:orders', 'paid-over-http', 'order.paid', 'order-42');
        });

        $publisher = new class($url) implements OutboxPublisher
        {
            public int $attempts = 0;

            public function __construct(private string $url) {}

            public function publish(OutboxMessage $message): void
            {
                $this->attempts++;

                $body = json_encode([
                    'id' => $message->id,
                    'scope' => $message->scope,
                    'topic' => $message->topic,
                ], JSON_THROW_ON_ERROR);

                $context = stream_context_create(['http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $body,
                    'timeout' => 2,
                ]]);

                $response = file_get_contents($this->url.'/events', false, $context);

                if ($response === false) {
                    throw new RuntimeException('HTTP provider did not acknowledge acceptance.');
                }

                $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

                if ($result['accepted'] !== true) {
                    throw new RuntimeException('HTTP provider rejected the event.');
                }

                if ($this->attempts === 1) {
                    // The remote process committed the event, but the local
                    // publisher loses certainty before acknowledging its lease.
                    throw new RuntimeException('provider_acknowledgement_lost');
                }
            }
        };

        $dispatcher = new OutboxDispatcher(
            new DatabaseOutboxLeaseStore(DB::connection(), 'flowguard_http_outbox'),
            $publisher,
            new NullLogger,
            60,
            0,
        );

        try {
            $dispatcher->dispatchOne();
            self::fail('First delivery must simulate lost acknowledgement.');
        } catch (RuntimeException $exception) {
            self::assertSame('provider_acknowledgement_lost', $exception->getMessage());
        }

        self::assertSame('pending', DB::table('flowguard_http_outbox')->value('status'));
        self::assertTrue($dispatcher->dispatchOne());
        self::assertSame('delivered', DB::table('flowguard_http_outbox')->value('status'));
        self::assertSame(2, (int) DB::table('flowguard_http_outbox')->value('delivery_attempts'));

        $state = json_decode((string) file_get_contents($this->providerState), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $state['requests']);
        self::assertCount(1, $state['events']);
        self::assertSame(2, $publisher->attempts);
    }

    private function startProvider(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($socket === false) {
            throw new RuntimeException('Cannot allocate test-only HTTP port.');
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        if (! is_string($address)) {
            throw new RuntimeException('Cannot identify provider port.');
        }

        $state = tempnam(sys_get_temp_dir(), 'flowguard-provider-');

        if ($state === false) {
            throw new RuntimeException('Cannot create provider fixture state.');
        }

        $this->providerState = $state;
        $pipes = [];
        $server = proc_open(
            [PHP_BINARY, '-S', $address, 'tests/Fixtures/http-provider.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
            array_merge($_ENV, ['FLOWGUARD_TEST_PROVIDER_STATE' => $state]),
        );

        if (! is_resource($server)) {
            throw new RuntimeException('Could not launch local HTTP receiver.');
        }

        fclose($pipes[0]);
        $this->server = $server;
        $this->serverPipes = [$pipes[1], $pipes[2]];
        $url = 'http://'.$address;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $context = stream_context_create(['http' => ['timeout' => 0.15]]);
            $health = @file_get_contents($url.'/health', false, $context);

            if ($health === '{"ready":true}') {
                return $url;
            }

            usleep(50000);
        }

        throw new RuntimeException('Local HTTP provider did not start.');
    }
}
