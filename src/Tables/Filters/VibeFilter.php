<?php

namespace Vibefilter\Filament\Tables\Filters;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Js;
use Livewire\Component;
use LogicException;
use Vibefilter\Filament\Exceptions\DriverException;
use Vibefilter\Filament\Scorer;
use Vibefilter\Filament\ScoringReport;
use Vibefilter\Filament\Support\Numbers;

/**
 * A table filter that takes a plain-English statement and keeps the rows
 * it is true for. It only looks at the rows the table shows with every other
 * filter and the search applied, scores them (from the cache where possible),
 * and gives the table query a whereIn on the passing keys, so pagination,
 * counts and sorting keep working as usual.
 *
 * If more rows need a fresh score than the limit allows, nothing runs until
 * the user either narrows the table down or presses "Run anyway".
 */
class VibeFilter extends BaseFilter
{
    /** @var array<string>|Closure */
    protected array | Closure $textColumns = [];

    protected float | Closure | null $threshold = null;

    protected int | Closure | null $maxUnscoredRows = null;

    /** @var array<string, array<array-key>|null> Passing keys per statement, so one request scores only once. */
    protected array $resolved = [];

    /** @var array<string, array<array-key, float>> The probabilities behind them, for the score column. */
    protected array $scores = [];

    /** True while this filter asks the table for its candidate rows, so it leaves itself out. */
    protected bool $collectingCandidates = false;

    public static function getDefaultName(): ?string
    {
        return 'vibe';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('vibefilter::vibefilter.filter.label'));

        $this->schema([
            TextInput::make('statement')
                ->label(__('vibefilter::vibefilter.filter.statement'))
                ->placeholder(__('vibefilter::vibefilter.filter.placeholder'))
                ->maxLength(500)
                // Enter applies the filters, like the Apply button, and closes the panel so the
                // progress bar and the result are in view. Only with deferred filters: otherwise
                // the table already follows what is typed, and there is nothing to apply.
                ->extraInputAttributes(fn (): array => $this->getTable()->hasDeferredFilters()
                    ? ['x-on:keydown.enter.prevent' => "if (typeof close === 'function') close(); \$wire.applyTableFilters()"]
                    : []),
        ]);

        $this->query(fn (Builder $query, array $data) => $this->applyStatement(
            $query,
            $data['statement'] ?? null,
            $data['run_anyway'] ?? null,
        ));

        $this->indicateUsing(fn (array $data): array => filled($data['statement'] ?? null)
            ? [Indicator::make(__('vibefilter::vibefilter.filter.indicator', ['statement' => $data['statement']]))]
            : []);

        $this->excludeWhenResolvingRecord();
    }

    /**
     * The columns whose text is sent to the model, e.g. ['subject', 'body'].
     *
     * @param  array<string>|Closure  $columns
     */
    public function textColumns(array | Closure $columns): static
    {
        $this->textColumns = $columns;

        return $this;
    }

    /**
     * Rows scoring at or above this probability pass. Defaults to config('vibefilter.threshold').
     */
    public function threshold(float | Closure | null $threshold): static
    {
        $this->threshold = $threshold;

        return $this;
    }

    /**
     * Above this many rows without a cached score, the filter asks before it runs.
     * Defaults to config('vibefilter.max_unscored_rows').
     */
    public function maxUnscoredRows(int | Closure | null $max): static
    {
        $this->maxUnscoredRows = $max;

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getTextColumns(): array
    {
        return $this->evaluate($this->textColumns);
    }

    public function getThreshold(): float
    {
        return (float) ($this->evaluate($this->threshold) ?? config('vibefilter.threshold', 0.8));
    }

    public function getMaxUnscoredRows(): int
    {
        return (int) ($this->evaluate($this->maxUnscoredRows) ?? config('vibefilter.max_unscored_rows', 1000));
    }

    /**
     * The probabilities for the active statement, by record key, once the filter has run.
     * Null while there is no statement, or it is waiting for "Run anyway". Only reads:
     * the scores come from the run the table query already triggered.
     *
     * @return array<array-key, float>|null
     */
    public function getActiveScores(): ?array
    {
        $statement = trim((string) ($this->getState()['statement'] ?? ''));

        return $statement === '' ? null : ($this->scores[$statement] ?? null);
    }

    /**
     * Scores the rows before the table renders, while the request is handling the
     * "Apply" or "Run anyway" click. Blade buffers output during rendering, so this
     * is the only point where the progress bar can be streamed to the browser.
     * Rendering then finds the result already resolved.
     */
    public function prescore(): void
    {
        $state = $this->getLivewire()->getTableFilterState($this->getName()) ?? [];

        $this->resolve($state['statement'] ?? null, $state['run_anyway'] ?? null);
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function applyStatement(Builder $query, ?string $statement, ?string $runAnyway): void
    {
        if ($this->collectingCandidates) {
            return;
        }

        $keys = $this->resolve($statement, $runAnyway);

        if ($keys !== null) {
            $query->whereKey($keys);
        }
    }

    /**
     * @return array<array-key>|null The passing keys, or null to leave the table unfiltered.
     */
    protected function resolve(?string $statement, ?string $runAnyway): ?array
    {
        if (blank($statement)) {
            return null;
        }

        $statement = trim($statement);

        if (! array_key_exists($statement, $this->resolved)) {
            $this->resolved[$statement] = $this->passingKeys(
                $this->candidateQuery(),
                $statement,
                force: hash_equals($this->confirmationToken($statement), (string) $runAnyway),
            );

            // The table may have decided which columns show while the scores weren't there yet.
            $this->getTable()->flushCachedVisibleColumns();
        }

        return $this->resolved[$statement];
    }

    /**
     * The rows the table shows with every other filter and the search applied.
     * Filament applies filters inside a nested where, which can't see the rest,
     * so this asks the table for its whole filtered query, minus this filter.
     *
     * @return Builder<Model>
     */
    protected function candidateQuery(): Builder
    {
        $this->collectingCandidates = true;

        try {
            return $this->getLivewire()->getFilteredTableQuery();
        } finally {
            $this->collectingCandidates = false;
        }
    }

    /**
     * @param  Builder<Model>  $candidates
     * @return array<array-key>|null Null when the table should stay unfiltered (not run yet, or scoring failed).
     */
    protected function passingKeys(Builder $candidates, string $statement, bool $force): ?array
    {
        $model = $candidates->getModel();
        $columns = $this->getTextColumns();

        $texts = $candidates
            ->get([$model->getQualifiedKeyName(), ...array_map($model->qualifyColumn(...), $columns)])
            ->mapWithKeys(fn (Model $row) => [$row->getKey() => $this->rowText($row, $columns)])
            ->all();

        $scorer = app(Scorer::class);
        $max = $this->getMaxUnscoredRows();

        $unscored = $scorer->unscored($statement, $texts);

        if (! $force && $unscored > $max) {
            $this->askBeforeRunning($statement, $unscored, count($texts), $max);

            return null;
        }

        try {
            $scores = $scorer->score(
                $statement,
                $texts,
                fn (int $done, int $total, int $retries, ?float $cost = null) => $this->streamProgress($unscored, $done, $total, $retries, $cost),
            );
        } catch (DriverException $exception) {
            // Batches that came back before the failure are cached: filter on those.
            $scores = $scorer->cachedScores($statement, $texts);
            $this->scores[$statement] = $scores;
            $this->reportFailure($exception, count($scores), count($texts));

            if ($scores === []) {
                return null;
            }
        }

        $this->scores[$statement] = $scores;

        $threshold = $this->getThreshold();
        $passing = array_keys(array_filter($scores, fn (float $probability) => $probability >= $threshold));

        if (isset($exception)) {
            return $passing;
        }

        if ($scorer->lastReport?->scored) {
            $this->reportRun($scorer->lastReport, count($passing));
        }

        return $passing;
    }

    /**
     * After a run that asked the model anything: how many rows match, and what it took.
     * Runs answered entirely from the cache stay quiet.
     */
    protected function reportRun(ScoringReport $report, int $matching): void
    {
        $details = [$report->requests
            ? trans_choice('vibefilter::vibefilter.report.scored_in', $report->scored, [
                'count' => Numbers::format($report->scored),
                'requests' => trans_choice('vibefilter::vibefilter.report.requests', $report->requests),
            ])
            : trans_choice('vibefilter::vibefilter.report.scored', $report->scored, ['count' => Numbers::format($report->scored)]),
        ];

        if ($report->retries()) {
            $details[] = trans_choice('vibefilter::vibefilter.report.retried', $report->retries(), ['count' => $report->retries()]);
        }

        if ($report->cached) {
            $details[] = __('vibefilter::vibefilter.report.cached', ['count' => Numbers::format($report->cached)]);
        }

        // What it took goes on a line of its own.
        $totals = [];

        if ($report->cost !== null) {
            $totals[] = __('vibefilter::vibefilter.report.cost', ['amount' => Numbers::money($report->cost)]);
        }

        $totals[] = __('vibefilter::vibefilter.report.seconds', ['seconds' => Numbers::format($report->seconds, 1)]);

        Notification::make()
            ->success()
            ->title(__('vibefilter::vibefilter.report.title', [
                'matching' => Numbers::format($matching),
                'total' => Numbers::format($report->rows),
            ]))
            ->body(e(implode(' · ', $details)) . '<br>' . e(implode(' · ', $totals)))
            ->send();
    }

    /**
     * The API failed for some or all batches. What did come back is cached, so
     * trying again only sends the rows that are still missing.
     */
    protected function reportFailure(DriverException $exception, int $scored, int $total): void
    {
        $retry = Action::make('tryAgain')
            ->label(__('vibefilter::vibefilter.failure.try_again'))
            ->button()
            // Only a Livewire id goes into the script.
            ->alpineClickHandler("close(); Livewire.find('{$this->component()->getId()}').\$refresh();");

        if ($scored === 0) {
            Notification::make()
                ->danger()
                ->persistent()
                ->title(__('vibefilter::vibefilter.failure.title'))
                ->body($exception->getMessage() . ' ' . __('vibefilter::vibefilter.failure.unfiltered'))
                ->actions([$retry])
                ->send();

            return;
        }

        $missing = $total - $scored;

        Notification::make()
            ->warning()
            ->persistent()
            ->title(__('vibefilter::vibefilter.failure.partial_title', [
                'scored' => Numbers::format($scored),
                'total' => Numbers::format($total),
            ]))
            ->body(trans_choice('vibefilter::vibefilter.failure.partial_body', $missing, ['count' => Numbers::format($missing)])
                . ' (' . $exception->getMessage() . ')')
            ->actions([$retry])
            ->send();
    }

    /**
     * Pushes the progress bar into the table while the request is still running
     * (Livewire streaming). The bar disappears when the table re-renders.
     */
    protected function streamProgress(int $rows, int $done, int $total, int $retries, ?float $cost = null): void
    {
        $this->component()->stream(
            content: $this->progressHtml($rows, $done, $total, $retries, $cost),
            replace: true,
            el: '[data-vibefilter-progress]',
        );
    }

    protected function progressHtml(int $rows, int $done, int $total, int $retries, ?float $cost = null): string
    {
        return View::make('vibefilter::progress', compact('rows', 'done', 'total', 'retries', 'cost'))->render();
    }

    /**
     * The table's Livewire component. Filament types it as the HasTable contract,
     * which leaves out Livewire's own methods (getId, stream).
     */
    protected function component(): Component
    {
        $livewire = $this->getLivewire();

        if (! $livewire instanceof Component) {
            throw new LogicException('The vibe filter only works on tables that live in a Livewire component.');
        }

        return $livewire;
    }

    /**
     * A pop-up with the numbers, how to get under the limit, and a button to run anyway.
     */
    protected function askBeforeRunning(string $statement, int $unscored, int $total, int $max): void
    {
        $livewireId = $this->component()->getId();
        $statePath = 'tableFilters.' . $this->getName() . '.run_anyway';
        $token = $this->confirmationToken($statement);
        $requests = (int) ceil($unscored / max(1, (int) config('vibefilter.batch_size', 100)));
        $startingBar = Js::from($this->progressHtml($unscored, 0, $requests, 0));

        Notification::make('vibefilter-limit-' . $token)
            ->warning()
            ->persistent()
            ->title(trans_choice('vibefilter::vibefilter.limit.title', $unscored, ['count' => Numbers::format($unscored)]))
            ->body(e(__('vibefilter::vibefilter.limit.body', [
                'total' => Numbers::format($total),
                'unscored' => Numbers::format($unscored),
                'limit' => Numbers::format($max),
            ])) . '<br><br>' . e(__('vibefilter::vibefilter.limit.hint')))
            ->actions([
                Action::make('runAnyway')
                    ->label(__('vibefilter::vibefilter.limit.run_anyway'))
                    ->button()
                    // Only ids, a filter name, a hex token and our own markup go into
                    // the script, never user input. The bar shows at once; the filter
                    // then streams the real progress into it.
                    ->alpineClickHandler(implode(' ', [
                        'close();',
                        "document.querySelectorAll('[data-vibefilter-progress]').forEach((el) => el.innerHTML = {$startingBar});",
                        "Livewire.find('{$livewireId}').set('{$statePath}', '{$token}');",
                    ])),
            ])
            ->send();
    }

    /**
     * Ties a "Run anyway" click to one statement, so a new statement asks again.
     */
    protected function confirmationToken(string $statement): string
    {
        return hash('sha256', trim($statement));
    }

    /**
     * @param  array<string>  $columns
     */
    protected function rowText(Model $row, array $columns): string
    {
        if (count($columns) === 1) {
            return (string) $row->getAttribute($columns[0]);
        }

        return collect($columns)
            ->map(fn (string $column) => $column . ': ' . $row->getAttribute($column))
            ->implode("\n");
    }
}
