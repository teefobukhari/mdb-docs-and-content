<?php

namespace App\Filament\Pages;

use App\Filament\Support\Bilingual;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ManageSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.manage-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 19;

    protected static ?string $title = 'Site Settings';

    /** Keys that hold translatable {en, ar} values. */
    protected array $translatable = [
        'site_name', 'tagline', 'hero_title', 'hero_subtitle', 'hero_badge',
        'contact_location', 'footer_note',
    ];

    /** Keys that hold plain scalar values. */
    protected array $scalar = [
        'contact_email', 'contact_phone', 'social_linkedin', 'social_x',
        'social_github', 'brand_color', 'accent_color',
    ];

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $state = [];
        foreach (array_merge($this->translatable, $this->scalar) as $key) {
            $state[$key] = Setting::get($key);
        }
        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Brand')->schema([
                    Bilingual::text('site_name', 'Site name'),
                    ColorPicker::make('brand_color'),
                    ColorPicker::make('accent_color'),
                ])->columns(2),

                Section::make('Hero & messaging')->schema([
                    Bilingual::text('tagline', 'Tagline (badge)'),
                    Bilingual::text('hero_title', 'Hero title'),
                    Bilingual::textarea('hero_subtitle', 'Hero subtitle'),
                    Bilingual::text('hero_badge', 'Hero trust line'),
                ]),

                Section::make('Contact & social')->schema([
                    TextInput::make('contact_email')->email(),
                    TextInput::make('contact_phone'),
                    Bilingual::text('contact_location', 'Location label')->columnSpanFull(),
                    TextInput::make('social_linkedin')->label('LinkedIn URL'),
                    TextInput::make('social_x')->label('X (Twitter) URL'),
                    TextInput::make('social_github')->label('GitHub URL'),
                ])->columns(2),

                Section::make('Footer')->schema([
                    Bilingual::text('footer_note', 'Footer note'),
                ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach (array_merge($this->translatable, $this->scalar) as $key) {
            if (array_key_exists($key, $state)) {
                Setting::set($key, $state[$key]);
            }
        }

        Notification::make()->title('Settings saved')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('Save settings')->submit('save'),
        ];
    }
}
