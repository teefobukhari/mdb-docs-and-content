<?php

namespace App\Filament\Resources\Industries\Schemas;

use App\Filament\Support\Bilingual;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class IndustryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('name.en')->label('Name (English)')->required()->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')->required()->unique(ignoreRecord: true),
                Select::make('icon')->searchable()->default('bolt')->options(collect([
                    'building-columns', 'heart-pulse', 'shopping-bag', 'landmark', 'truck', 'graduation-cap', 'bolt', 'shield', 'cloud',
                ])->mapWithKeys(fn ($i) => [$i => ucfirst(str_replace('-', ' ', $i))])->all()),
                TextInput::make('sort')->numeric()->default(0),
                Toggle::make('is_published')->default(true),
            ])->columns(2),
            Bilingual::text('name', 'Name'),
            Bilingual::textarea('description', 'Description'),
        ]);
    }
}
