<?php

namespace App\Filament\Concerns;

use App\Models\Slider;
use Filament\Notifications\Notification;

/**
 * After a slider is saved, points out images that won't suit it running edge
 * to edge. It stays on screen until dismissed, as it can name several images.
 */
trait WarnsAboutEdgeToEdgeImages
{
    protected function warnAboutEdgeToEdgeImages(): void
    {
        /** @var Slider $slider */
        $slider = $this->getRecord();
        $warnings = $slider->edgeToEdgeImageWarnings();

        if ($warnings === []) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('Check this slider\'s images')
            ->body(implode(' ', $warnings))
            ->persistent()
            ->send();
    }
}
