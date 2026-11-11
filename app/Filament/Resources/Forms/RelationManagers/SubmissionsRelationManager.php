<?php

namespace App\Filament\Resources\Forms\RelationManagers;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Theme;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What visitors have sent with a form: one column per field, a full view of
 * each submission, and a CSV download of them all.
 *
 * @property Form $ownerRecord
 */
class SubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'submissions';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nothing has been sent with this form yet')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Sent')
                    ->dateTime()
                    ->sortable(),
                ...$this->ownerRecord->formFields()->map(fn (array $field): TextColumn => TextColumn::make('answer_'.$field['key'])
                    ->label($field['label'])
                    ->state(fn (FormSubmission $record): string => $record->answerText($field['key']))
                    ->limit(50)
                    ->placeholder('—'))
                    ->all(),
                IconColumn::make('email_status')
                    ->label('Emailed')
                    ->icon(fn (?string $state): Heroicon => $state === 'failed' ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                    ->color(fn (?string $state): string => $state === 'failed' ? 'danger' : 'success')
                    ->tooltip(fn (?string $state): ?string => $state === 'failed' ? 'The email about this submission couldn\'t be sent. Check the Email screen.' : 'Emailed')
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('download')
                    ->label('Download CSV')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->action(fn (): StreamedResponse => $this->ownerRecord->downloadSubmissionsCsv()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->modalHeading(fn (FormSubmission $record): string => 'Sent '.Theme::localTime($record->created_at)->format('F j, Y \a\t g:i a'))
                    ->schema(fn (FormSubmission $record): array => collect($record->answers)
                        ->map(fn (array $answer, string $key): TextEntry => TextEntry::make('answer_'.$key)
                            ->label($answer['label'] ?? $key)
                            ->state($record->answerText($key))
                            ->placeholder('—')
                            ->extraAttributes(['style' => 'white-space: pre-line']))
                        ->values()
                        ->all()),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
