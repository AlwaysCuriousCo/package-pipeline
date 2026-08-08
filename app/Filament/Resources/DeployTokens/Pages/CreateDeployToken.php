<?php

namespace App\Filament\Resources\DeployTokens\Pages;

use App\Enums\TokenAbility;
use App\Filament\Resources\DeployTokens\DeployTokenResource;
use App\Models\DeployToken;
use App\Models\Token;
use Filament\Notifications\Notification;
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

        // The one time the plain text exists: persistent so it survives the
        // redirect to the index, dismissed only by the admin who copied it.
        Notification::make()
            ->success()
            ->title('Deploy token created — copy it now')
            ->body(
                'It will not be shown again.<br><br><code>composer config http-basic.'.request()->getHost()
                ." token {$new->plainText}</code>"
            )
            ->persistent()
            ->send();
    }
}
