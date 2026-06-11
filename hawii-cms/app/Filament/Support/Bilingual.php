<?php

namespace App\Filament\Support;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;

/**
 * Helpers that build an English + Arabic pair of inputs bound to a JSON
 * {en, ar} attribute via dot notation (e.g. title.en / title.ar).
 */
class Bilingual
{
    public static function text(string $field, string $label, bool $required = false): Fieldset
    {
        return Fieldset::make($label)->schema([
            TextInput::make("{$field}.en")->label('English')->required($required),
            TextInput::make("{$field}.ar")->label('العربية')->extraInputAttributes(['dir' => 'rtl']),
        ])->columns(2);
    }

    public static function textarea(string $field, string $label, int $rows = 3): Fieldset
    {
        return Fieldset::make($label)->schema([
            Textarea::make("{$field}.en")->label('English')->rows($rows),
            Textarea::make("{$field}.ar")->label('العربية')->rows($rows)->extraInputAttributes(['dir' => 'rtl']),
        ])->columns(2);
    }

    public static function rich(string $field, string $label): Fieldset
    {
        return Fieldset::make($label)->schema([
            RichEditor::make("{$field}.en")->label('English')->columnSpanFull(),
            RichEditor::make("{$field}.ar")->label('العربية')->columnSpanFull(),
        ])->columns(1);
    }
}
