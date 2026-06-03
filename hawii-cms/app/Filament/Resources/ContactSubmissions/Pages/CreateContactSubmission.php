<?php

namespace App\Filament\Resources\ContactSubmissions\Pages;

use App\Filament\Resources\ContactSubmissions\ContactSubmissionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContactSubmission extends CreateRecord
{
    protected static string $resource = ContactSubmissionResource::class;
}
