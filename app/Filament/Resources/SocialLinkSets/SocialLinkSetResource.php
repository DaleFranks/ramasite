<?php

namespace App\Filament\Resources\SocialLinkSets;

use App\Filament\Resources\SocialLinkSets\Pages\CreateSocialLinkSet;
use App\Filament\Resources\SocialLinkSets\Pages\EditSocialLinkSet;
use App\Filament\Resources\SocialLinkSets\Pages\ListSocialLinkSets;
use App\Filament\Resources\SocialLinkSets\Schemas\SocialLinkSetForm;
use App\Filament\Resources\SocialLinkSets\Tables\SocialLinkSetsTable;
use App\Models\SocialLinkSet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SocialLinkSetResource extends Resource
{
    protected static ?string $model = SocialLinkSet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static ?string $navigationLabel = 'Social links';

    protected static ?string $modelLabel = 'set of social links';

    protected static ?string $pluralModelLabel = 'social links';

    protected static ?string $slug = 'social-links';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return SocialLinkSetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SocialLinkSetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSocialLinkSets::route('/'),
            'create' => CreateSocialLinkSet::route('/create'),
            'edit' => EditSocialLinkSet::route('/{record}/edit'),
        ];
    }
}
