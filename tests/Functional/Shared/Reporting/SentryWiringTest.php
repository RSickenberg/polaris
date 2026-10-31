<?php

declare(strict_types=1);

namespace Polaris\Tests\Functional\Shared\Reporting;

use PHPUnit\Framework\Attributes\CoversNothing;
use Polaris\Shared\Reporting\BreadcrumbFilter;
use Polaris\Shared\Reporting\EventFilter;
use Polaris\Shared\Reporting\LogFilter;
use Polaris\Tests\Support\ProdKernel;
use Sentry\Client;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Options;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableHttpClientForV6;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\Tracing\TransactionContext;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The Sentry bundle exists in the prod environment only (as the Flex recipe registers it), so these tests boot the prod
 * kernel and read what the real configuration produced. They never reach the network: a transport that records what the
 * SDK hands it replaces the HTTP one.
 *
 * What stays unverified until a real event reaches a Sentry project: that Sentry accepts the DSN, the release, and the
 * shape of what the SDK sends.
 */
#[CoversNothing]
final class SentryWiringTest extends KernelTestCase
{
    private const string VIN = '5YJ3E7EB2NF000091';
    private const string DSN = 'https://owner@o2.ingest.sentry.io/2';

    /** @var list<string> */
    private array $env = [];

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new ProdKernel('prod', false);
    }

    public static function tearDownAfterClass(): void
    {
        new Filesystem()->remove(ProdKernel::cacheDirectory());
        parent::tearDownAfterClass();
    }

    private mixed $errorHandler = null;
    private mixed $exceptionHandler = null;

    protected function setUp(): void
    {
        $this->errorHandler = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        $this->exceptionHandler = set_exception_handler(static fn (): null => null);
        restore_exception_handler();
    }

    protected function tearDown(): void
    {
        // The Sentry bundle installs its own error and exception handlers when it boots: give back the ones PHPUnit had.
        for ($i = 0; $i < 10 && $this->errorHandler !== ($current = set_error_handler(static fn (): bool => false)); ++$i) {
            restore_error_handler();
            restore_error_handler();
        }
        if ($i < 10) {
            restore_error_handler();
        }
        for ($i = 0; $i < 10 && $this->exceptionHandler !== ($current = set_exception_handler(static fn (): null => null)); ++$i) {
            restore_exception_handler();
            restore_exception_handler();
        }
        if ($i < 10) {
            restore_exception_handler();
        }

        foreach ($this->env as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }

        parent::tearDown();
    }

    public function testWithoutAnInstanceOptInNothingIsSentAndNothingConnects(): void
    {
        $this->bootWith(['POLARIS_ERROR_REPORTING' => 'off', 'SENTRY_DSN' => self::DSN]);
        $client = $this->hub()->getClient();

        self::assertInstanceOf(Client::class, $client);
        self::assertNull($client->getOptions()->getDsn());
        self::assertFalse($client->getOptions()->isSpotlightEnabled());
        // Without a DSN the transport answers "skipped" before it builds a request: no connection is opened.
        self::assertEquals(ResultStatus::skipped(), $client->getTransport()->send(Event::createEvent())->getStatus());
        self::assertEquals(ResultStatus::skipped(), $client->getTransport()->send(Event::createTransaction())->getStatus());
    }

    public function testAnEmptySwitchBehavesAsOff(): void
    {
        $this->bootWith(['POLARIS_ERROR_REPORTING' => '', 'SENTRY_DSN' => self::DSN]);

        self::assertNull($this->hub()->getClient()?->getOptions()->getDsn());
    }

    public function testTheMaintainerOptionSendsNothingWhileTheShippedDsnIsEmpty(): void
    {
        $this->bootWith(['POLARIS_ERROR_REPORTING' => 'maintainer', 'SENTRY_DSN' => self::DSN]);

        self::assertNull($this->hub()->getClient()?->getOptions()->getDsn());
    }

    public function testTheOwnersOwnDsnIsUsedWithTheCustomOption(): void
    {
        $this->bootWith(['POLARIS_ERROR_REPORTING' => 'custom', 'SENTRY_DSN' => self::DSN, 'SENTRY_RELEASE' => '0.1.0']);
        $options = $this->hub()->getClient()?->getOptions();

        self::assertNotNull($options);
        self::assertSame(self::DSN, (string) $options->getDsn());
        self::assertSame('prod', $options->getEnvironment());
        self::assertSame('0.1.0', $options->getRelease());
    }

    public function testTheOptionsAreConservative(): void
    {
        $this->bootWith(['POLARIS_ERROR_REPORTING' => 'custom', 'SENTRY_DSN' => self::DSN, 'SENTRY_TRACES_SAMPLE_RATE' => '']);
        $options = $this->hub()->getClient()?->getOptions();

        self::assertNotNull($options);
        self::assertSame([], $options->getTracePropagationTargets());
        self::assertEqualsWithDelta(0.05, $options->getTracesSampleRate(), 0.0001);
        self::assertNull($options->getProfilesSampleRate());
        self::assertTrue($options->getEnableLogs());
        self::assertInstanceOf(EventFilter::class, $options->getBeforeSendCallback());
        self::assertInstanceOf(EventFilter::class, $options->getBeforeSendTransactionCallback());
        self::assertInstanceOf(LogFilter::class, $options->getBeforeSendLogCallback());
        self::assertInstanceOf(BreadcrumbFilter::class, $options->getBeforeBreadcrumbCallback());

        $collection = $options->getDataCollection();
        self::assertNotNull($collection);
        self::assertFalse($collection->shouldCollectUserInfo());
        self::assertFalse($collection->shouldCollectDatabaseQueryData());
        self::assertSame([], $collection->getHttpBodies());
        self::assertSame(0, $collection->getFrameContextLines());
        self::assertSame('off', $collection->getCookies()->getMode());
        self::assertSame('off', $collection->getUrlQueryParams()->getMode());
        self::assertSame('off', $collection->getStackFrameVariables()->getMode());
        foreach ($collection->getHttpHeaders() as $headers) {
            self::assertSame('off', $headers->getMode());
        }
    }

    public function testTheTraceRateComesFromTheEnvironment(): void
    {
        $this->bootWith(['POLARIS_ERROR_REPORTING' => 'custom', 'SENTRY_DSN' => self::DSN, 'SENTRY_TRACES_SAMPLE_RATE' => '0.5']);

        self::assertEqualsWithDelta(0.5, $this->hub()->getClient()?->getOptions()->getTracesSampleRate(), 0.0001);
    }

    public function testOneMessageNeverSeesTheContextOfThePreviousOne(): void
    {
        $this->bootWith(['POLARIS_ERROR_REPORTING' => 'custom', 'SENTRY_DSN' => self::DSN]);

        self::assertTrue(ProdKernel::$messengerListenerArguments[3] ?? false, 'isolate_context_by_message');
        self::assertTrue(ProdKernel::$messengerListenerArguments[2] ?? false, 'isolate_breadcrumbs_by_message');
    }

    public function testAnExceptionIsSentScrubbedWhenTheOwnerOptedIn(): void
    {
        $this->bootWith(['POLARIS_ERROR_REPORTING' => 'custom', 'SENTRY_DSN' => self::DSN, 'SENTRY_TRACES_SAMPLE_RATE' => '1']);
        $transport = new RecordingTransport();
        $hub = $this->hubSendingTo($transport);

        $hub->captureException(new \RuntimeException('Fleet API refused ' . self::VIN . ' for jane.doe@example.test with Bearer abc.def'));

        self::assertCount(1, $transport->events);
        $event = $transport->events[0];
        self::assertNull($event->getUser());
        $message = $event->getExceptions()[0]->getValue();
        self::assertStringContainsString('Fleet API refused [Filtered] for [Filtered] with [Filtered]', $message);
        self::assertStringNotContainsString(self::VIN, $message);
    }

    public function testATransactionIsSentScrubbed(): void
    {
        $this->bootWith(['POLARIS_ERROR_REPORTING' => 'custom', 'SENTRY_DSN' => self::DSN, 'SENTRY_TRACES_SAMPLE_RATE' => '1']);
        $transport = new RecordingTransport();
        $hub = $this->hubSendingTo($transport);

        $context = new TransactionContext('GET /vehicles/' . self::VIN);
        $context->setSampled(true);
        $transaction = $hub->startTransaction($context);
        $hub->setSpan($transaction);
        $spanContext = new \Sentry\Tracing\SpanContext();
        $spanContext->setOp('http.client');
        $spanContext->setDescription('GET https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/vehicles/' . self::VIN . '/vehicle_data');
        $span = $transaction->startChild($spanContext);
        $span->finish();
        $transaction->finish();

        self::assertCount(1, $transport->events);
        $json = json_encode([
            $transport->events[0]->getTransaction(),
            array_map(static fn ($span): ?string => $span->getDescription(), $transport->events[0]->getSpans()),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString(self::VIN, $json);
        self::assertStringContainsString('/api/1/vehicles/[Filtered]/vehicle_data', $json);
    }

    public function testNoTraceHeaderLeavesForAnotherHost(): void
    {
        $this->bootWith(['POLARIS_ERROR_REPORTING' => 'custom', 'SENTRY_DSN' => self::DSN, 'SENTRY_TRACES_SAMPLE_RATE' => '1']);

        $configured = $this->headersOfAnOutgoingRequest($this->hub());
        self::assertStringNotContainsString('sentry-trace', $configured);
        self::assertStringNotContainsString('baggage', $configured);

        // The same request with the SDK default (every host) carries the headers: the check above can fail.
        $everyHost = new Hub(ClientBuilder::create(['dsn' => self::DSN, 'traces_sample_rate' => 1.0, 'trace_propagation_targets' => null])->setTransport(new RecordingTransport())->getClient());
        self::assertStringContainsString('sentry-trace', $this->headersOfAnOutgoingRequest($everyHost));
    }

    /**
     * @param array<string, string> $env
     */
    private function bootWith(array $env): void
    {
        foreach ($env as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv($name . '=' . $value);
            $this->env[] = $name;
        }

        self::bootKernel();
    }

    private function hub(): HubInterface
    {
        $hub = self::$kernel?->getContainer()->get(HubInterface::class);
        \assert($hub instanceof HubInterface);

        return $hub;
    }

    /**
     * The real options, with a transport that records instead of connecting.
     */
    private function hubSendingTo(TransportInterface $transport): HubInterface
    {
        $options = $this->hub()->getClient()?->getOptions();
        \assert($options instanceof Options);

        return new Hub(new Client($options, $transport, null, null, new RepresentationSerializer($options)));
    }

    private function headersOfAnOutgoingRequest(HubInterface $hub): string
    {
        $seen = '';
        $inner = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = '';
            foreach (\is_array($options['headers'] ?? null) ? $options['headers'] : [] as $header) {
                $seen .= (\is_string($header) ? $header : '') . "\n";
            }

            return new MockResponse('{}');
        });

        $context = new TransactionContext('request');
        $context->setSampled(true);
        $hub->setSpan($hub->startTransaction($context));

        new TraceableHttpClientForV6($inner, $hub)->request('GET', 'https://fleet-api.prd.eu.vn.cloud.tesla.com/api/1/vehicles')->getStatusCode();

        return $seen;
    }
}

/**
 * Keeps what the SDK would have sent.
 */
final class RecordingTransport implements TransportInterface
{
    /** @var list<Event> */
    public array $events = [];

    public function send(Event $event): Result
    {
        $this->events[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }
}
