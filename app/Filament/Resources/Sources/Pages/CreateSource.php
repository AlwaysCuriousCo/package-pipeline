<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Filament\Resources\Sources\Actions\ConnectGitHubAction;
use App\Filament\Resources\Sources\SourceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSource extends CreateRecord
{
    protected static string $resource = SourceResource::class;

    protected static ?string $title = 'Add a source manually';

    /**
     * This form is the fallback, so it says what the normal route is — or, on
     * an instance with no app registered, why it is the only one.
     */
    public function getSubheading(): ?string
    {
        return ConnectGitHubAction::isAvailable()
            ? 'Most accounts are added with "Connect GitHub account", which fills all of this in from GitHub. Use this form for a GitHub Enterprise instance, or an account you authenticate with an access token.'
            : 'No GitHub App is registered for this instance, so sources authenticate with an access token. Register one (see docs/github-app.md) to connect accounts in a click instead.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ConnectGitHubAction::make(),
        ];
    }

    /**
     * A new source has no credentials yet, so land on its page where the
     * "Connect" action is rather than back in the list.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
