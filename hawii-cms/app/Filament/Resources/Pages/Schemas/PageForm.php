<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Filament\Support\Bilingual;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('title.en')->label('Title (English)')->required()->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')->required()->unique(ignoreRecord: true)
                    ->helperText('Reachable at /p/{slug} — e.g. about, privacy, terms'),
                Toggle::make('is_published')->default(true),
            ])->columns(2),
            Bilingual::text('title', 'Title'),
            Bilingual::textarea('meta_description', 'Meta description (SEO)'),
            Bilingual::rich('body', 'Body'),
        ]);
    }
}
