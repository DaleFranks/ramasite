<?php

namespace App\Filament\Resources\Sliders\Pages;

use App\Filament\Concerns\WarnsAboutEdgeToEdgeImages;
use App\Filament\Resources\Sliders\SliderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSlider extends CreateRecord
{
    use WarnsAboutEdgeToEdgeImages;

    protected static string $resource = SliderResource::class;

    protected function afterCreate(): void
    {
        $this->warnAboutEdgeToEdgeImages();
    }
}
