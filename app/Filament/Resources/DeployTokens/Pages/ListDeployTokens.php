<?php

namespace App\Filament\Resources\DeployTokens\Pages;

use App\Enums\TokenAbility;
use App\Filament\Concerns\HidesBreadcrumbs;
use App\Filament\Resources\DeployTokens\DeployTokenResource;
use App\Models\DeployToken;
use App\Models\Token;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDeployTokens extends ListRecords
{
    use HidesBreadcrumbs;

    protected static string $resource = DeployTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Created in a modal, not on a page: a create page redirects, and
            // the one copy of the plain text then has to survive the session
            // between two requests — the hand-off that is lost in production
            // (#27). Staying on the list, the toast rides in the same response
            // that issued it, exactly as rolling does.
            //
            // Deploy tokens read; publishing artifacts is a deliberate extra
            // grant an admin makes by editing the token, not a default a CI
            // box gets for free.
            CreateAction::make()
                ->after(function (DeployToken $record): void {
                    $new = Token::issue($record, $record->name, [TokenAbility::RepositoryRead]);

                    DeployTokenResource::plainTextTokenNotification('Token created — copy it now', $new)->send();
                }),
        ];
    }
}
