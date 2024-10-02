<?php

declare(strict_types=1);

namespace src;

use Carbon\Carbon;
use GuzzleHttp\Promise\Create;
use Prometheus\CollectorRegistry;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Exception\RequestException;
use Throwable;

class CustomPromMetric
{
    private Carbon $startTime;

    public function __construct(
        private string $service_name,
    )
    {
        $this->startTime = now();
    }

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            return $handler($request, $options)->then(
                $this->handleSuccess($request),
                $this->handleFailure($request),
            );
        };
    }

    /**
     * This method will write log in case of 2xx http results.
     *
     * @param RequestInterface $request
     * @return callable
     */
    private function handleSuccess(
        RequestInterface $request,
    ): callable
    {
        return function (ResponseInterface $response) use ($request) {
            $this->writeLog(
                request: $request,
                response: $response,
            );

            return $response;
        };
    }

    /**
     * This method will handle 4xx-5xx http results.
     *
     * @param RequestInterface $request
     * @return callable
     */
    private function handleFailure(
        RequestInterface $request,
    ): callable
    {
        return function (Throwable $reason) use ($request) {
            $response = $reason instanceof RequestException ? $reason->getResponse() : null;

            $this->writeLog(
                request: $request,
                response: $response,
                reason: $reason,
            );

            return Create::rejectionFor($reason);
        };
    }

    private function writeLog(
        RequestInterface $request,
        ResponseInterface $response = null,
        Throwable|null $reason = null,
    ): void
    {
        /** @var CollectorRegistry $collector */
        $collector = app()->get(CollectorRegistry::class);

        $histogram = $collector->getOrRegisterHistogram(
            namespace: '',
            name: 'http_histogram_request_duration_seconds',
            help: 'Histogram of HTTP request durations',
            labels: ['service_name', 'domain', 'status_code'],
            buckets: [0.1, 0.2, 0.3, 0.5, 1, 2.5, 5, 10],
        );

        $durationInSeconds = now()->diffInMilliseconds($this->startTime) / 1000;

        $histogram->observe(
            value: $durationInSeconds,
            labels: [$this->service_name, $request->getUri()->getHost(), (string) ($response?->getStatusCode() ?? 0)],
        );
    }
}
