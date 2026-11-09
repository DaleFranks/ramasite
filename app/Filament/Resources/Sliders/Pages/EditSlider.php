<?php

namespace App\Filament\Resources\Sliders\Pages;

use App\Filament\Concerns\WarnsAboutEdgeToEdgeImages;
use App\Filament\Resources\Sliders\SliderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSlider extends EditRecord
{
    use WarnsAboutEdgeToEdgeImages;

    protected static string $resource = SliderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $this->warnAboutEdgeToEdgeImages();
    }
}
