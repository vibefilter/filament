<?php

namespace Vibefilter\Filament\Tests\Fixtures;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Component;
use Vibefilter\Filament\Tables\Columns\VibeScoreColumn;
use Vibefilter\Filament\Tables\Filters\VibeFilter;

/**
 * A plain Filament table with Vibefilter next to an ordinary filter and a search.
 */
class ListReviews extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        return $table
            ->query(Review::query())
            ->columns([
                TextColumn::make('body')->searchable(),
                TextColumn::make('source'),
                VibeScoreColumn::make(),
            ])
            ->filters([
                VibeFilter::make()->textColumns(['body']),
                SelectFilter::make('source')->options([
                    'claude' => 'Claude',
                    'grok' => 'Grok',
                ]),
            ]);
    }

    public function render(): string
    {
        return '<div>{{ $this->table }}</div>';
    }
}
