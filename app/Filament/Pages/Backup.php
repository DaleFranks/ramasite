<?php

namespace App\Filament\Pages;

use App\Backup as SiteBackup;
use App\Models\Image;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Throwable;

/**
 * Downloads a backup of the whole site as one zip, to keep somewhere safe.
 */
class Backup extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxArrowDown;

    protected static ?string $navigationLabel = 'Backup';

    protected static ?string $title = 'Backup';

    protected static ?int $navigationSort = 100;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Download a backup')
                ->description('One zip file with everything that makes this site its own. Download one before big changes, and from time to time, and keep it somewhere safe.')
                ->schema([
                    Text::make('It holds the database (every page, post, card, module, slider, form and form submission, set of social links, redirect, Theme and Email setting, and the admin accounts), the '.Number::format(count(Storage::disk(Image::DISK)->allFiles())).' uploaded files (images, files, logo and tab icon), and the site\'s settings file. A RESTORING.txt file inside says how to put the site back.'),
                    Text::make('Keep the zip private: it contains the admin accounts and the key to the saved passwords.')
                        ->color('warning'),
                    $this->downloadAction(),
                ]),
        ]);
    }

    public function downloadAction(): Action
    {
        return Action::make('download')
            ->label('Download backup')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->disabled(! SiteBackup::isAvailable())
            ->tooltip(SiteBackup::isAvailable() ? null : 'This server can\'t make zip files. Ask your host to turn on PHP\'s zip extension.')
            ->action(function (): void {
                try {
                    $path = SiteBackup::create();
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->title('The backup couldn\'t be made')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $this->redirect(route('filament.admin.backup.download', ['file' => basename($path)]));
            });
    }
}
