<?php

namespace App\Filament\Resources\Testimonials\Schemas;

use App\Filament\Support\Bilingual;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TestimonialForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('initials')->maxLength(8)->helperText('Shown in the avatar circle, e.g. SA'),
                TextInput::make('sort')->numeric()->default(0),
                Toggle::make('is_published')->default(true),
            ])->columns(3),
            Bilingual::textarea('quote', 'Quote'),
            Bilingual::text('author', 'Author'),
            Bilingual::text('role', 'Role / Company'),
        ]);
    }
}
