<?php

namespace Vibefilter\Filament\Tests;

use Closure;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Livewire\Livewire;
use Vibefilter\Filament\Contracts\DecisionDriver;
use Vibefilter\Filament\Drivers\FakeDriver;
use Vibefilter\Filament\Exceptions\DriverException;
use Vibefilter\Filament\Scorer;
use Vibefilter\Filament\Tests\Fixtures\ListReviews;
use Vibefilter\Filament\Tests\Fixtures\Review;

class VibeFilterTest extends TestCase
{
    protected FakeDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new FakeDriver(fn (string $statement, string $text) => str_contains($text, 'furious') ? 0.95 : 0.05);
        $this->app->instance(DecisionDriver::class, $this->driver);
    }

    protected function review(string $body, string $source = 'claude'): Review
    {
        return Review::create(['body' => $body, 'source' => $source]);
    }

    /**
     * @return array<string>
     */
    protected function sentTexts(): array
    {
        return collect($this->driver->calls)->flatMap(fn ($call) => array_values($call['texts']))->sort()->values()->all();
    }

    /**
     * A driver whose API answers for the texts mentioning "ok" and fails for the rest.
     */
    protected function halfBrokenDriver(): FakeDriver
    {
        return new class extends FakeDriver
        {
            public function decide(string $statement, array $texts, ?Closure $onScored = null, ?Closure $onProgress = null): array
            {
                $answered = array_map(fn () => 0.95, array_filter($texts, fn (string $text) => str_contains($text, 'ok')));
                $onScored?->__invoke($answered);

                if (count($answered) < count($texts)) {
                    throw new DriverException('TypeSafe request failed with HTTP 503.');
                }

                return $answered;
            }
        };
    }

    public function test_it_keeps_only_matching_rows(): void
    {
        $angry = $this->review('I am furious about this.');
        $calm = $this->review('Nice and quick.');

        Livewire::test(ListReviews::class)
            ->filterTable('vibe', ['statement' => 'The customer is angry.'])
            ->assertCanSeeTableRecords([$angry])
            ->assertCanNotSeeTableRecords([$calm])
            ->assertCountTableRecords(1);
    }

    public function test_an_empty_statement_leaves_the_table_alone(): void
    {
        $this->review('Anything.');

        Livewire::test(ListReviews::class)
            ->filterTable('vibe', ['statement' => ''])
            ->assertCountTableRecords(1);

        $this->assertSame([], $this->driver->calls);
    }

    public function test_only_rows_left_by_the_other_filters_are_scored(): void
    {
        $grokAngry = $this->review('Grok says furious.', 'grok');
        $this->review('Grok says calm.', 'grok');
        $this->review('Claude says furious.', 'claude');

        Livewire::test(ListReviews::class)
            ->filterTable('source', 'grok')
            ->filterTable('vibe', ['statement' => 'The customer is angry.'])
            ->assertCanSeeTableRecords([$grokAngry])
            ->assertCountTableRecords(1);

        $this->assertSame(['Grok says calm.', 'Grok says furious.'], $this->sentTexts());
    }

    public function test_only_rows_left_by_the_search_are_scored(): void
    {
        $this->review('Blender: furious.');
        $this->review('Speaker: furious.');

        Livewire::test(ListReviews::class)
            ->searchTable('Blender')
            ->filterTable('vibe', ['statement' => 'The customer is angry.'])
            ->assertCountTableRecords(1);

        $this->assertSame(['Blender: furious.'], $this->sentTexts());
    }

    public function test_above_the_limit_it_asks_instead_of_running(): void
    {
        config(['vibefilter.max_unscored_rows' => 2]);
        $this->review('One furious.');
        $this->review('Two calm.');
        $this->review('Three calm.');

        Livewire::test(ListReviews::class)
            ->filterTable('vibe', ['statement' => 'The customer is angry.'])
            ->assertNotified('3 rows need a fresh score')
            ->assertCountTableRecords(3);

        $this->assertSame([], $this->driver->calls);
    }

    public function test_cached_rows_do_not_count_toward_the_limit(): void
    {
        $this->review('One furious.');
        $this->review('Two calm.');
        app(Scorer::class)->score('The customer is angry.', ['One furious.']);
        config(['vibefilter.max_unscored_rows' => 1]);

        Livewire::test(ListReviews::class)
            ->filterTable('vibe', ['statement' => 'The customer is angry.'])
            ->assertCountTableRecords(1);

        $this->assertSame(['One furious.', 'Two calm.'], $this->sentTexts());
    }

    public function test_run_anyway_scores_every_row_for_that_statement(): void
    {
        config(['vibefilter.max_unscored_rows' => 1]);
        $angry = $this->review('One furious.');
        $this->review('Two calm.');

        Livewire::test(ListReviews::class)
            ->filterTable('vibe', ['statement' => 'The customer is angry.'])
            ->assertCountTableRecords(2)
            ->set('tableFilters.vibe.run_anyway', hash('sha256', 'The customer is angry.'))
            ->assertCanSeeTableRecords([$angry])
            ->assertCountTableRecords(1);
    }

    public function test_run_anyway_does_not_carry_over_to_a_new_statement(): void
    {
        config(['vibefilter.max_unscored_rows' => 1]);
        $this->review('One furious.');
        $this->review('Two calm.');

        Livewire::test(ListReviews::class)
            ->filterTable('vibe', ['statement' => 'The customer is happy.', 'run_anyway' => hash('sha256', 'The customer is angry.')])
            ->assertNotified('2 rows need a fresh score');

        $this->assertSame([], $this->driver->calls);
    }

    public function test_the_run_anyway_button_closes_the_pop_up_and_shows_the_progress_bar(): void
    {
        config(['vibefilter.max_unscored_rows' => 1]);
        $this->review('One furious.');
        $this->review('Two calm.');

        Livewire::test(ListReviews::class)
            ->filterTable('vibe', ['statement' => 'The customer is angry.']);

        $notifications = new Notifications;
        $notifications->mount();
        $popUp = $notifications->notifications->first(fn (Notification $notification) => $notification->getTitle() === '2 rows need a fresh score');
        $click = $popUp->getActions()[0]->getAlpineClickHandler();

        $this->assertStringStartsWith('close();', $click);
        $this->assertStringContainsString("document.querySelectorAll('[data-vibefilter-progress]')", $click);
        $this->assertStringContainsString('Scoring 2 rows', $click);
        $this->assertStringNotContainsString('The customer is angry', $click);
    }

    public function test_a_run_reports_how_many_rows_match(): void
    {
        $this->review('One furious.');
        $this->review('Two calm.');

        Livewire::test(ListReviews::class)
            ->filterTable('vibe', ['statement' => 'The customer is angry.'])
            ->assertNotified('1 of 2 rows match the vibe');
    }

    public function test_a_run_answered_from_the_cache_stays_quiet(): void
    {
        $this->review('One furious.');
        app(Scorer::class)->score('The customer is angry.', ['One furious.']);

        Livewire::test(ListReviews::class)
            ->filterTable('vibe', ['statement' => 'The customer is angry.'])
            ->assertNotNotified('1 of 1 rows match the vibe')
            ->assertCountTableRecords(1);
    }

    public function test_when_some_batches_fail_it_filters_on_the_scored_rows(): void
    {
        $this->app->instance(DecisionDriver::class, $this->halfBrokenDriver());
        $scored = $this->review('ok, and furious.');
        $this->review('This one failed.');

        Livewire::test(ListReviews::class)
            ->filterTable('vibe', ['statement' => 'The customer is angry.'])
            ->assertNotified('1 of 2 rows scored')
            ->assertCanSeeTableRecords([$scored])
            ->assertCountTableRecords(1);
    }

    public function test_when_every_batch_fails_the_table_stays_unfiltered(): void
    {
        $this->app->instance(DecisionDriver::class, $this->halfBrokenDriver());
        $this->review('This failed.');
        $this->review('This failed too.');

        Livewire::test(ListReviews::class)
            ->filterTable('vibe', ['statement' => 'The customer is angry.'])
            ->assertNotified('Vibefilter could not run')
            ->assertCountTableRecords(2);
    }

    public function test_the_table_has_a_slot_for_the_progress_bar(): void
    {
        Livewire::test(ListReviews::class)
            ->assertSeeHtml('<div data-vibefilter-progress></div>');
    }

    public function test_the_score_column_shows_each_rows_probability(): void
    {
        $angry = $this->review('I am furious about this.');

        Livewire::test(ListReviews::class)
            ->assertTableColumnHidden('vibe_score')
            ->filterTable('vibe', ['statement' => 'The customer is angry.'])
            ->assertTableColumnVisible('vibe_score')
            ->assertTableColumnStateSet('vibe_score', 0.95, $angry)
            ->assertSee('0.95');

        // Shown from the run the filter did: the model was asked once.
        $this->assertCount(1, $this->driver->calls);
    }

    public function test_the_score_column_stays_hidden_while_the_filter_waits_for_run_anyway(): void
    {
        config(['vibefilter.max_unscored_rows' => 1]);
        $this->review('One.');
        $this->review('Two.');

        Livewire::test(ListReviews::class)
            ->filterTable('vibe', ['statement' => 'The customer is angry.'])
            ->assertTableColumnHidden('vibe_score');
    }

    public function test_enter_in_the_statement_field_applies_the_filters_and_closes_the_panel(): void
    {
        Livewire::test(ListReviews::class)
            ->assertSeeHtml('x-on:keydown.enter.prevent=')
            ->assertSeeHtml('close(); $wire.applyTableFilters()');
    }
}
