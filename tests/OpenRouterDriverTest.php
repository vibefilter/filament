<?php

namespace Vibefilter\Filament\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Vibefilter\Filament\Contracts\DecisionDriver;
use Vibefilter\Filament\Drivers\OpenRouterDriver;
use Vibefilter\Filament\Exceptions\DriverException;

class OpenRouterDriverTest extends TestCase
{
    protected function fakeOpenRouter(): void
    {
        Http::fake(function (Request $request) {
            $texts = collect($request['state']['records'])->pluck('text', 'id');

            return Http::response([
                'answers' => collect($request['questions'])->map(fn ($question, $tag) => [
                    'type' => 'noul',
                    'noul' => str_contains($texts["#{$tag}"], 'angry') ? 0.9 : 0.1,
                ]),
                'usage' => ['input_tokens' => 317, 'output_tokens' => 25, 'cost' => 0.00125],
            ]);
        });
    }

    public function test_it_adds_up_the_cost_openrouter_reports(): void
    {
        $this->fakeOpenRouter();
        $driver = new OpenRouterDriver('key', batchSize: 1);
        $progress = [];

        $driver->decide('The customer is angry.', ['a', 'b', 'c'], onProgress: function (int $done, int $total, int $retries, ?float $cost) use (&$progress) {
            $progress[] = $cost;
        });

        $this->assertEqualsWithDelta(0.00375, $driver->lastCost(), 1e-12);
        $this->assertEqualsWithDelta(0.00375, end($progress), 1e-12);
    }

    public function test_it_sends_jev_requests_to_openrouter(): void
    {
        $this->fakeOpenRouter();

        $scores = (new OpenRouterDriver('sk-or-test'))->decide('The customer is angry.', [7 => 'So angry.', 8 => 'Lovely.']);

        $this->assertSame([7 => 0.9, 8 => 0.1], $scores);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/systemone'
            && $request->hasHeader('Authorization', 'Bearer sk-or-test')
            && $request['model'] === 'typesafe/jev-1.13');
    }

    public function test_its_decisions_are_cached_per_service_and_model(): void
    {
        $this->assertSame('openrouter:typesafe/jev-1.13', (new OpenRouterDriver('key'))->name());
        $this->assertSame('openrouter:other/model', (new OpenRouterDriver('key', model: 'other/model'))->name());
    }

    public function test_a_missing_key_names_the_openrouter_variable(): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('OPENROUTER_API_KEY');

        (new OpenRouterDriver(null))->decide('The customer is angry.', ['text']);
    }

    public function test_it_is_picked_by_the_driver_setting(): void
    {
        config([
            'vibefilter.driver' => 'openrouter',
            'vibefilter.drivers.openrouter.api_key' => 'sk-or-test',
            'vibefilter.drivers.openrouter.model' => 'typesafe/jev-1.14',
        ]);
        $this->fakeOpenRouter();

        app(DecisionDriver::class)->decide('The customer is angry.', ['angry']);

        Http::assertSent(fn (Request $request) => $request['model'] === 'typesafe/jev-1.14');
    }

    public function test_errors_name_openrouter(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'No credits']], 402)]);

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('OpenRouter request failed with HTTP 402');

        (new OpenRouterDriver('key', retryDelays: []))->decide('The customer is angry.', ['text']);
    }

    public function test_requests_carry_vibefilters_app_attribution_by_default(): void
    {
        $this->fakeOpenRouter();
        config(['vibefilter.driver' => 'openrouter', 'vibefilter.drivers.openrouter.api_key' => 'key']);

        app(DecisionDriver::class)->decide('The customer is angry.', ['a']);

        Http::assertSent(fn (Request $request) => $request->hasHeader('HTTP-Referer', 'https://vibefilter.dev')
            && $request->hasHeader('X-OpenRouter-Title', 'Vibefilter')
            && ! $request->hasHeader('X-OpenRouter-App-Visibility'));
    }

    public function test_the_attribution_can_be_changed_hidden_or_switched_off(): void
    {
        $this->fakeOpenRouter();

        (new OpenRouterDriver('key', attribution: ['url' => 'https://example.com', 'title' => 'My app', 'visibility' => 'hidden']))
            ->decide('The customer is angry.', ['a']);

        Http::assertSent(fn (Request $request) => $request->hasHeader('HTTP-Referer', 'https://example.com')
            && $request->hasHeader('X-OpenRouter-Title', 'My app')
            && $request->hasHeader('X-OpenRouter-App-Visibility', 'hidden'));

        $this->fakeOpenRouter();
        (new OpenRouterDriver('key', attribution: ['url' => '', 'title' => null]))->decide('The customer is angry.', ['a']);

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('HTTP-Referer') && ! $request->hasHeader('X-OpenRouter-Title'));
    }

    public function test_typesafe_requests_carry_no_attribution(): void
    {
        Http::fake(['*' => Http::response(['answers' => ['q0' => ['type' => 'noul', 'noul' => 0.5]]])]);
        config(['vibefilter.driver' => 'typesafe', 'vibefilter.drivers.typesafe.api_key' => 'key']);

        rescue(fn () => app(DecisionDriver::class)->decide('The customer is angry.', ['a']), report: false);

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('HTTP-Referer') && ! $request->hasHeader('X-OpenRouter-Title'));
    }
}
