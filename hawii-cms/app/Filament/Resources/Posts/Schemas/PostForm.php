<?php

namespace App\Filament\Resources\Posts\Schemas;

use App\Filament\Support\Bilingual;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('title.en')->label('Title (English)')->required()->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug((string) $state))),
                TextInput::make('slug')->required()->unique(ignoreRecord: true),
                Select::make('user_id')->label('Author')->relationship('author', 'name')->searchable()
                    ->default(fn () => auth()->id()),
                FileUpload::make('cover_image')->image()->directory('posts'),
                DateTimePicker::make('published_at')->default(now()),
                Toggle::make('is_published')->default(false),
            ])->columns(2),
            Bilingual::text('title', 'Title'),
            Bilingual::textarea('excerpt', 'Excerpt'),
            Bilingual::rich('body', 'Body'),
        ]);
    }
}
