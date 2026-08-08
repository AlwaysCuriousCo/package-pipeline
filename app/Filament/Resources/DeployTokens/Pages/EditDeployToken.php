<?php

namespace App\Filament\Resources\DeployTokens\Pages;

use App\Enums\TokenAbility;
use App\Filament\Resources\DeployTokens\DeployTokenResource;
use App\Models\DeployToken;
use App\Models\Token;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditDeployToken extends EditRecord
{
    protected static string $resource = DeployTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Rotation without ceremony: the old credential dies the moment
            // the new one exists, and this is also where write ability is
            // deliberately granted.
            Action::make('regenerate')
                ->label('Regenerate token')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->modalHeading('Regenerate the access token')
                ->modalDescription('The current token stops authenticating immediately; whatever machine uses it needs the new one.')
                ->schema([
                    CheckboxList::make('abilities')
                        ->options(TokenAbility::class)
                        ->default(fn (DeployToken $record): array => $record->token?->abilities
                            ?? [TokenAbility::RepositoryRead->value])
                        ->required(),
                ])
                ->action(function (DeployToken $record, array $data): void {
                    $record->tokens()->delete();

                    $new = Token::issue($record, $record->name, $data['abilities']);

                    DeployTokenResource::plainTextTokenNotification('Token regenerated — copy it now', $new)->send();
                }),
            DeleteAction::make()
                ->modalDescription('Its access token stops authenticating immediately.'),
        ];
    }
}
