<?php

namespace App\Filament\Resources\Services\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title.en')->label('Title')->searchable()->sortable(),
                TextColumn::make('pillar')->badge()->colors(['info' => 'it', 'warning' => 'ai']),
                TextColumn::make('sort')->sortable(),
                IconColumn::make('is_published')->boolean()->label('Live'),
                TextColumn::make('updated_at')->dateTime()->since()->label('Updated')->toggleable(),
            ])
            ->defaultSort('sort')
            ->filters([
                SelectFilter::make('pillar')->options(['it' => 'IT', 'ai' => 'AI']),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
