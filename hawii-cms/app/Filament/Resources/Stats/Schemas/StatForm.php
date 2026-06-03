<?php

namespace App\Filament\Resources\Stats\Schemas;

use App\Filament\Support\Bilingual;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StatForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('value')->required()->helperText('e.g. 150+, 99.9%, 24/7'),
                TextInput::make('sort')->numeric()->default(0),
                Toggle::make('is_published')->default(true),
            ])->columns(2),
            Bilingual::text('label', 'Label'),
        ]);
    }
}
