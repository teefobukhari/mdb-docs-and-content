<?php

namespace App\Filament\Resources\CaseStudies\Schemas;

use App\Filament\Support\Bilingual;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CaseStudyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('title.en')->label('Title (English)')->required()->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')->required()->unique(ignoreRecord: true),
                FileUpload::make('cover_image')->image()->directory('cases')->columnSpanFull(),
                TextInput::make('sort')->numeric()->default(0),
                Toggle::make('is_published')->default(true),
            ])->columns(2),
            Bilingual::text('client', 'Client'),
            Bilingual::text('industry', 'Industry'),
            Bilingual::text('title', 'Title'),
            Bilingual::textarea('summary', 'Summary'),
            Bilingual::rich('body', 'Body'),
        ]);
    }
}
