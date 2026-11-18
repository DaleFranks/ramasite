<?php

namespace App\Filament\Resources\SocialLinkSets\Tables;

use App\Models\SocialLinkSet;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SocialLinkSetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (SocialLinkSet $record): string => $record->shortcode()),
                TextColumn::make('links')
                    ->label('Links')
                    ->state(fn (SocialLinkSet $record): string => $record->serviceNames()->implode(', '))
                    ->limit(60),
                TextColumn::make('logo_style')
                    ->label('Logos')
                    ->badge(),
                TextColumn::make('updated_at')
                    ->label('Last edited')
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
