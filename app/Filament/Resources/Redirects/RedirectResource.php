<?php

namespace App\Filament\Resources\Redirects;

use App\Filament\Resources\Redirects\Pages\ManageRedirects;
use App\Models\Entry;
use App\Models\Redirect;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class RedirectResource extends Resource
{
    protected static ?string $model = Redirect::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnRight;

    protected static ?string $recordTitleAttribute = 'from_path';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('from_path')
                    ->label('From')
                    ->prefix(fn (): string => Str::after(url('/'), '://').'/')
                    ->required()
                    ->maxLength(255)
                    ->formatStateUsing(fn (?string $state): ?string => $state)
                    ->dehydrateStateUsing(fn (?string $state): string => Redirect::normalizePath($state))
                    ->regex('#^/?[A-Za-z0-9._~%+\-/]*\*?$#')
                    ->validationMessages([
                        'regex' => 'Use an address such as "old-services" or "blog/old-post". A "*" is allowed only at the end.',
                    ])
                    ->rule(fn (?Redirect $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $path = Redirect::normalizePath((string) $value);

                        if ($path === '' || $path === '*') {
                            $fail('The home page can\'t be redirected.');

                            return;
                        }

                        if (in_array(Str::before($path, '/'), Entry::RESERVED_PATHS, true)) {
                            $fail('Addresses starting with "'.Str::before($path, '/').'" are used by the system.');

                            return;
                        }

                        $taken = Redirect::query()
                            ->where('from_path', $path)
                            ->when($record, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))
                            ->exists();

                        if ($taken) {
                            $fail("There's already a redirect from /{$path}.");

                            return;
                        }

                        if (! str_ends_with($path, '*') && Entry::query()->published()->whereRaw('lower(path) = ?', [$path])->exists()) {
                            $fail("A published page is at /{$path}, so a redirect from there would never be used. Redirects only apply to addresses with no page.");
                        }
                    })
                    ->helperText('The address that no longer has a page, e.g. "old-services". To send a whole section, end it with *, e.g. "blog/*".'),
                TextInput::make('to')
                    ->label('To')
                    ->required()
                    ->maxLength(2048)
                    ->regex('#^(/|https?://)\S*$#i')
                    ->validationMessages([
                        'regex' => 'Start with "/" for an address on this site, e.g. "/services", or give a full address starting with "https://".',
                    ])
                    ->rule(fn (Get $get, ?Redirect $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                        if (! preg_match('#^https?://#i', (string) $value) && Redirect::normalizePath((string) $value) === Redirect::normalizePath((string) $get('from_path'))) {
                            $fail('A redirect can\'t point to the address it comes from.');

                            return;
                        }

                        if (Redirect::wouldLoop((string) $get('from_path'), (string) $value, $record?->getKey())) {
                            $fail('Other redirects lead from there back to /'.Redirect::normalizePath((string) $get('from_path')).', so visitors would go round in a loop.');
                        }
                    })
                    ->helperText('Where visitors go instead, e.g. "/services", or a full web address.'),
                Select::make('status_code')
                    ->label('Type')
                    ->options([
                        301 => 'Permanent (301): the old address has moved for good',
                        302 => 'Temporary (302): it may come back',
                    ])
                    ->default(301)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->helperText('Permanent tells search engines to move the old address\'s ranking to the new one.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('No redirects yet')
            ->emptyStateDescription('Add one to send an old address somewhere new. They\'re also added automatically when a published page\'s address changes.')
            ->columns([
                TextColumn::make('from_path')
                    ->label('From')
                    ->formatStateUsing(fn (string $state): string => '/'.$state)
                    ->searchable()
                    ->sortable()
                    ->description(fn (Redirect $record): ?string => $record->automatic ? 'Added automatically when the page moved' : null),
                TextColumn::make('to')
                    ->label('To')
                    ->searchable()
                    ->limit(60),
                TextColumn::make('status_code')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state === 301 ? 'Permanent' : 'Temporary')
                    ->color(fn (int $state): string => $state === 301 ? 'primary' : 'gray'),
                TextColumn::make('hits')
                    ->label('Uses')
                    ->numeric()
                    ->sortable()
                    ->description(fn (Redirect $record): ?string => $record->last_hit_at ? 'Last '.$record->last_hit_at->diffForHumans() : null),
            ])
            ->recordActions([
                Action::make('try')
                    ->label('Try it')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->url(fn (Redirect $record): string => url('/'.$record->from_path))
                    ->openUrlInNewTab()
                    ->hidden(fn (Redirect $record): bool => $record->isWildcard()),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRedirects::route('/'),
        ];
    }
}
