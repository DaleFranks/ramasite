<?php

namespace App\Filament;

use App\Models\Card;
use App\Models\Form;
use App\Models\Module;
use App\Models\Slider;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;

/**
 * Stops a module, card, slider or form being deleted while content still
 * places it, and says where, as the image and file libraries do.
 */
class InUseCheck
{
    /**
     * For a Delete button's before(): cancel the delete if the record is in use.
     */
    public static function beforeDeleting(): Closure
    {
        return function (DeleteAction $action, Module|Card|Slider|Form $record): void {
            if ($record->usages()->isEmpty()) {
                return;
            }

            Notification::make()
                ->danger()
                ->title($record->name.' is in use and can\'t be deleted')
                ->body($record->inUseMessage().' Remove it from those first.')
                ->persistent()
                ->send();

            $action->cancel();
        };
    }
}
