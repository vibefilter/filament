<?php

namespace Vibefilter\Filament\Tables\Columns;

use Closure;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Vibefilter\Filament\Support\Numbers;
use Vibefilter\Filament\Tables\Filters\VibeFilter;

/**
 * Shows each row's probability for the statement in the table's Vibefilter.
 * The scores come from the run the filter already did, so the column asks
 * the model nothing and adds no database column. It shows only while a
 * statement is active, and isn't sortable: the scores aren't in your table.
 */
class VibeScoreColumn extends TextColumn
{
    protected string | Closure $filterName = 'vibe';

    public static function getDefaultName(): ?string
    {
        return 'vibe_score';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('vibefilter::vibefilter.column.label'));

        $this->state(fn (Model $record): ?float => $this->getScores()[$record->getKey()] ?? null);

        $this->formatStateUsing(fn (float $state): string => Numbers::format($state, 2));

        $this->fontFamily(FontFamily::Mono);

        $this->alignEnd();

        $this->hidden(fn (): bool => $this->getScores() === null);
    }

    /**
     * The name of the Vibefilter to read the scores from, if it isn't the default "vibe".
     */
    public function filter(string | Closure $name): static
    {
        $this->filterName = $name;

        return $this;
    }

    /**
     * @return array<array-key, float>|null
     */
    protected function getScores(): ?array
    {
        $filter = $this->getTable()->getFilter($this->evaluate($this->filterName));

        return $filter instanceof VibeFilter ? $filter->getActiveScores() : null;
    }
}
