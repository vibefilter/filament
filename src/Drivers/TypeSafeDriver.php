<?php

namespace Vibefilter\Filament\Drivers;

use Closure;
use GuzzleHttp\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Vibefilter\Filament\Contracts\CountsRequests;
use Vibefilter\Filament\Contracts\DecisionDriver;
use Vibefilter\Filament\Contracts\ReportsCost;
use Vibefilter\Filament\Exceptions\DriverException;

/**
 * Sends rows to TypeSafe Jev in batches: one JSON state holding many rows,
 * and one Noul question per row. Each row gets a fresh random tag per request,
 * so the model never sees record ids or a predictable row order.
 *
 * A batch that hits a rate limit, a server error or a dropped connection is
 * sent again after a pause; if it still fails, it is split in half once and
 * the halves are sent. Batches that succeed are handed to $onScored right
 * away, so one failing batch doesn't throw away the others.
 */
class TypeSafeDriver implements CountsRequests, DecisionDriver
{
    protected int $lastRequestCount = 0;

    protected int $lastAttemptCount = 0;

    /** Requests that came back with an answer, and ones that came back failed, in the current call. */
    protected int $answered = 0;

    protected int $failedAttempts = 0;

    /** US dollars the service reported for the current call. */
    protected float $cost = 0.0;

    public function __construct(
        protected ?string $apiKey,
        protected string $baseUrl = 'https://api.typesafe.ai/v1',
        protected string $model = 'jev-1.13.0',
        protected int $timeout = 60,
        protected int $batchSize = 100,
        protected int $concurrency = 10,
        /** @var array<int> Pauses in milliseconds before each retry; empty means no retries. */
        protected array $retryDelays = [500, 2000, 5000],
    ) {}

    /**
     * The service and the model, e.g. "typesafe:jev-1.13.0", so scores from
     * different models never mix in the cache.
     */
    public function name(): string
    {
        return $this->service() . ':' . $this->model;
    }

    /**
     * Headers sent with every request on top of the key.
     *
     * @return array<string, string>
     */
    protected function extraHeaders(): array
    {
        return [];
    }

    protected function service(): string
    {
        return 'typesafe';
    }

    /**
     * The service's name in error messages.
     */
    protected function label(): string
    {
        return 'TypeSafe';
    }

    protected function missingKeyMessage(): string
    {
        return 'TypeSafe API key is missing. Set TYPESAFE_API_KEY in your .env file.';
    }

    public function lastRequestCount(): int
    {
        return $this->lastRequestCount;
    }

    public function lastAttemptCount(): int
    {
        return $this->lastAttemptCount;
    }

    public function decide(string $statement, array $texts, ?Closure $onScored = null, ?Closure $onProgress = null): array
    {
        $this->lastRequestCount = 0;
        $this->lastAttemptCount = 0;
        $this->answered = 0;
        $this->failedAttempts = 0;
        $this->cost = 0.0;

        if ($texts === []) {
            return [];
        }

        if (blank($this->apiKey)) {
            throw new DriverException($this->missingKeyMessage());
        }

        $batches = array_map(
            fn (array $chunk) => $this->buildBatch($statement, $chunk),
            array_chunk($texts, max(1, $this->batchSize), preserve_keys: true),
        );

        [$scores, $failed] = $this->run($batches, $onScored, $onProgress);

        // A batch that still fails after its retries, because the API is too slow or
        // overloaded, is split in half once and sent again. Smaller batches finish
        // sooner. No further splitting: if the halves fail too, give up.
        $halves = [];

        foreach ($failed as [$batch, $exception, $splittable]) {
            if (! $splittable || count($batch['keys']) < 2) {
                continue;
            }

            $keys = array_values($batch['keys']);

            foreach (array_chunk($keys, (int) ceil(count($keys) / 2)) as $half) {
                $halves[] = $this->buildBatch($statement, array_intersect_key($texts, array_flip($half)));
            }
        }

        if ($halves !== []) {
            $failed = array_filter($failed, fn (array $failure) => ! $failure[2] || count($failure[0]['keys']) < 2);
            [$halfScores, $halfFailed] = $this->run($halves, $onScored, $onProgress);
            $scores += $halfScores;
            $failed = [...$failed, ...$halfFailed];
        }

        if ($failed !== []) {
            throw $failed[array_key_first($failed)][1];
        }

        // Keep the caller's key order.
        return array_replace(array_intersect_key($texts, $scores), $scores);
    }

    /**
     * Sends the batches in parallel, with retries, hands each batch that comes
     * back to $onScored, and reports every response to $onProgress as it lands.
     * Returns the scores, and each failed batch with its exception and whether
     * splitting it could help.
     *
     * @param  list<array{payload: array<string, mixed>, keys: array<string, array-key>}>  $batches
     * @return array{0: array<array-key, float>, 1: list<array{0: array{payload: array<string, mixed>, keys: array<string, array-key>}, 1: DriverException, 2: bool}>}
     */
    protected function run(array $batches, ?Closure $onScored, ?Closure $onProgress = null): array
    {
        $this->lastRequestCount += count($batches);
        $countAttempt = Middleware::mapRequest(function (RequestInterface $request) {
            $this->lastAttemptCount++;

            return $request;
        });
        $reportProgress = Middleware::mapResponse(function (ResponseInterface $response) use ($onProgress) {
            if ($response->getStatusCode() < 400) {
                $this->answered++;
                $this->cost += $this->costOf($response);
            } else {
                $this->failedAttempts++;
            }

            if ($onProgress) {
                $onProgress($this->answered, $this->lastRequestCount, $this->failedAttempts, $this instanceof ReportsCost ? $this->cost : null);
            }

            return $response;
        });

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (int $index) => $pool->as((string) $index)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->withHeaders($this->extraHeaders())
                ->timeout($this->timeout)
                ->withMiddleware($countAttempt)
                ->withMiddleware($reportProgress)
                ->retry(
                    count($this->retryDelays) + 1,
                    fn (int $attempt, Throwable $exception) => $this->retryDelay($attempt, $exception),
                    fn (Throwable $exception) => $this->isRetryable($exception),
                    throw: false,
                )
                ->post(rtrim($this->baseUrl, '/') . '/systemone', $batches[$index]['payload']),
            array_keys($batches),
        ), concurrency: max(1, $this->concurrency));

        $scores = [];
        $failed = [];

        foreach ($batches as $index => $batch) {
            $response = $responses[(string) $index] ?? null;

            try {
                $batchScores = $this->scores($batch, $response);
            } catch (DriverException $exception) {
                $failed[] = [$batch, $exception, $this->isRetryable($this->asThrowable($response))];

                continue;
            }

            if ($onScored) {
                $onScored($batchScores);
            }

            $scores += $batchScores;
        }

        return [$scores, $failed];
    }

    /**
     * What one response cost in US dollars. TypeSafe's own API doesn't say,
     * so this is 0 here; drivers for services that do report it override it.
     */
    protected function costOf(ResponseInterface $response): float
    {
        return 0.0;
    }

    protected function asThrowable(mixed $response): ?Throwable
    {
        return match (true) {
            $response instanceof Throwable => $response,
            $response instanceof Response && $response->failed() => $response->toException(),
            default => null,
        };
    }

    /**
     * @param  array{payload: array<string, mixed>, keys: array<string, array-key>}  $batch
     * @return array<array-key, float>
     */
    protected function scores(array $batch, mixed $response): array
    {
        $answers = $this->answers($response);
        $scores = [];

        foreach ($batch['keys'] as $tag => $key) {
            $probability = $answers[$tag]['noul'] ?? null;

            if (! is_numeric($probability)) {
                throw new DriverException("{$this->label()} returned no answer for row #{$tag}.");
            }

            $scores[$key] = (float) $probability;
        }

        return $scores;
    }

    /**
     * Rate limits, server errors and dropped connections are worth another try;
     * a bad key or a malformed request is not.
     */
    protected function isRetryable(?Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    /**
     * Waits as long as the API asks for in Retry-After (up to 30 seconds),
     * otherwise the next configured pause.
     */
    protected function retryDelay(int $attempt, Throwable $exception): int
    {
        $retryAfter = $exception instanceof RequestException
            ? $exception->response->header('Retry-After')
            : '';

        if (is_numeric($retryAfter)) {
            return (int) min((float) $retryAfter * 1000, 30_000);
        }

        return $this->retryDelays[$attempt - 1] ?? end($this->retryDelays) ?: 0;
    }

    /**
     * @param  array<array-key, string>  $texts
     * @return array{payload: array<string, mixed>, keys: array<string, array-key>}
     */
    protected function buildBatch(string $statement, array $texts): array
    {
        $keys = [];

        foreach (array_keys($texts) as $key) {
            do {
                $tag = 'k' . bin2hex(random_bytes(3));
            } while (isset($keys[$tag]));

            $keys[$tag] = $key;
        }

        $tags = array_keys($keys);
        shuffle($tags);

        $records = [];
        $questions = [];

        foreach ($tags as $tag) {
            $records[] = ['id' => "#{$tag}", 'text' => $texts[$keys[$tag]]];
            $questions[$tag] = [
                'type' => 'noul',
                'instructions' => "Regarding record #{$tag}: {$statement}",
            ];
        }

        return [
            'payload' => [
                'model' => $this->model,
                'state' => ['records' => $records],
                'questions' => $questions,
            ],
            'keys' => $keys,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function answers(mixed $response): array
    {
        if ($response instanceof Throwable) {
            throw new DriverException($this->label() . ' request failed: ' . $response->getMessage(), previous: $response);
        }

        if (! $response instanceof Response) {
            throw new DriverException($this->label() . ' request returned no response.');
        }

        if ($response->failed()) {
            throw new DriverException("{$this->label()} request failed with HTTP {$response->status()}: " . mb_substr($response->body(), 0, 300));
        }

        return $response->json('answers') ?? [];
    }
}
