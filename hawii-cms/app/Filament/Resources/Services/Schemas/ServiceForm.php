<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Filament\Support\Bilingual;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Details')->schema([
                TextInput::make('title.en')
                    ->label('Title (English)')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(160),
                Select::make('pillar')->required()->default('it')->options([
                    'it' => 'IT Solutions',
                    'ai' => 'AI Solutions',
                ]),
                Select::make('icon')->searchable()->options(self::iconOptions())->default('bolt'),
            ])->columns(2),

            Section::make('Content')->schema([
                Bilingual::text('title', 'Title')->columnSpanFull(),
                Bilingual::textarea('excerpt', 'Short description (card)')->columnSpanFull(),
                Bilingual::rich('body', 'Full body (detail page)')->columnSpanFull(),
            ]),

            Section::make('Publishing')->schema([
                TextInput::make('sort')->numeric()->default(0),
                Toggle::make('is_published')->default(true),
            ])->columns(2),
        ]);
    }

    public static function iconOptions(): array
    {
        return collect(['cloud', 'shield', 'code', 'workflow', 'bot', 'chart', 'eye', 'bolt'])
            ->mapWithKeys(fn ($i) => [$i => ucfirst($i)])->all();
    }
}
