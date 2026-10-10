<?php

namespace Vibefilter\Filament\Drivers;

use Psr\Http\Message\ResponseInterface;
use Vibefilter\Filament\Contracts\ReportsCost;

/**
 * TypeSafe Jev through OpenRouter's Decisions API, which takes the same
 * requests as TypeSafe's own API. Handy when you already have an OpenRouter
 * key. OpenRouter forwards to TypeSafe, so a Jev outage affects both.
 */
class OpenRouterDriver extends TypeSafeDriver implements ReportsCost
{
    /**
     * @param  array<int>  $retryDelays  Pauses in milliseconds before each retry.
     * @param  array{url?: ?string, title?: ?string, visibility?: ?string}  $attribution  App attribution headers; empty values are left out.
     */
    public function __construct(
        ?string $apiKey,
        string $baseUrl = 'https://openrouter.ai/api/v1',
        string $model = 'typesafe/jev-1.13',
        int $timeout = 60,
        int $batchSize = 100,
        int $concurrency = 10,
        array $retryDelays = [500, 2000, 5000],
        protected array $attribution = [],
    ) {
        parent::__construct($apiKey, $baseUrl, $model, $timeout, $batchSize, $concurrency, $retryDelays);
    }

    public function lastCost(): float
    {
        return $this->cost;
    }

    /**
     * OpenRouter puts the price of each request in usage.cost. The body is read
     * here while the response is on its way, so rewind it for the caller.
     */
    protected function costOf(ResponseInterface $response): float
    {
        $body = $response->getBody();
        $json = json_decode((string) $body, true);

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $cost = is_array($json) ? ($json['usage']['cost'] ?? null) : null;

        return is_numeric($cost) ? (float) $cost : 0.0;
    }

    /**
     * OpenRouter's app attribution: the URL creates the app page, the title names it,
     * and "hidden" keeps a new app out of the public rankings.
     *
     * @return array<string, string>
     */
    protected function extraHeaders(): array
    {
        return array_filter([
            'HTTP-Referer' => (string) ($this->attribution['url'] ?? ''),
            'X-OpenRouter-Title' => (string) ($this->attribution['title'] ?? ''),
            'X-OpenRouter-App-Visibility' => (string) ($this->attribution['visibility'] ?? ''),
        ], fn (string $value) => $value !== '');
    }

    protected function service(): string
    {
        return 'openrouter';
    }

    protected function label(): string
    {
        return 'OpenRouter';
    }

    protected function missingKeyMessage(): string
    {
        return 'OpenRouter API key is missing. Set OPENROUTER_API_KEY in your .env file.';
    }
}
