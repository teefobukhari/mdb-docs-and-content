<?php

namespace App\Filament\Resources\ContactSubmissions\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContactSubmissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('name')->disabled(),
                TextInput::make('email')->disabled(),
                TextInput::make('interest')->disabled(),
                TextInput::make('locale')->disabled(),
                Textarea::make('message')->disabled()->columnSpanFull()->rows(5),
                Toggle::make('is_read')->label('Mark as read'),
            ])->columns(2),
        ]);
    }
}
