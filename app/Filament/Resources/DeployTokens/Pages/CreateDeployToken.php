<?php

namespace App\Filament\Resources\DeployTokens\Pages;

use App\Enums\TokenAbility;
use App\Filament\Resources\DeployTokens\DeployTokenResource;
use App\Models\DeployToken;
use App\Models\Token;
use Filament\Resources\Pages\CreateRecord;

class CreateDeployToken extends CreateRecord
{
    protected static string $resource = DeployTokenResource::class;

    /**
     * Issue the credential the machine will actually present.
     *
     * Deploy tokens read; publishing artifacts is a deliberate extra grant an
     * admin makes by editing the token, not a default a CI box gets for free.
     */
    protected function afterCreate(): void
    {
        /** @var DeployToken $deployToken */
        $deployToken = $this->getRecord();

        $new = Token::issue($deployToken, $deployToken->name, [TokenAbility::RepositoryRead]);

        DeployTokenResource::plainTextTokenNotification('Token created — copy it now', $new)->send();
    }
}
