<?php

namespace App\Filament\Resources\Entries\Tables;

use App\EntryStatus;
use App\EntryType;
use App\Filament\ViewOnSiteAction;
use App\Models\Entry;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['author', 'blog']))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Entry $record): string => '/'.$record->path),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('blog.title')
                    ->label('Blog')
                    ->toggleable(),
                TextColumn::make('author.name')
                    ->label('Author')
                    ->toggleable(),
                TextColumn::make('published_at')
                    ->label('Publish date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Last edited')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(EntryType::class),
                SelectFilter::make('blog')
                    ->relationship(
                        'blog',
                        'title',
                        fn (Builder $query): Builder => $query->where('type', EntryType::Blog),
                    ),
                SelectFilter::make('status')
                    ->options(EntryStatus::class),
            ])
            ->checkIfRecordIsSelectableUsing(fn (Entry $record): bool => ! $record->isHomePage())
            ->recordActions([
                ViewOnSiteAction::make()
                    ->iconButton()
                    ->tooltip(fn (Entry $record): string => $record->isVisibleToVisitors() ? 'View on the site' : 'Preview'),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
