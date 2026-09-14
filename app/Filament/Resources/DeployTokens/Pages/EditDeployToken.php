<?php

namespace App\Filament\Resources\DeployTokens\Pages;

use App\Enums\TokenAbility;
use App\Filament\Resources\DeployTokens\DeployTokenResource;
use App\Models\DeployToken;
use App\Models\Token;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
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
                ->modalIcon(Heroicon::OutlinedArrowPath)
                ->modalSubmitActionLabel('Regenerate token')
                ->schema([
                    TextInput::make('username')
                        ->maxLength(255)
                        ->default(fn (DeployToken $record): ?string => $record->token->username)
                        ->placeholder(fn (DeployToken $record): string => $record->name)
                        ->helperText('The username written into the machine\'s auth.json. Only the token authenticates, so this is yours to choose; empty means the deploy token name.'),
                    CheckboxList::make('abilities')
                        ->options(TokenAbility::class)
                        ->default(fn (DeployToken $record): array => $record->token->abilities
                            ?? [TokenAbility::RepositoryRead->value])
                        ->required(),
                ])
                ->action(function (DeployToken $record, array $data): void {
                    // One at a time, so each revocation fires the model
                    // events the audit log is written from.
                    $record->tokens->each->delete();

                    $new = Token::issue(
                        $record,
                        $record->name,
                        $data['abilities'],
                        username: filled($data['username'] ?? null) ? $data['username'] : null,
                    );

                    $new->notification('Token regenerated — copy it now')->send();
                }),
            DeleteAction::make()
                ->modalDescription('Its access token stops authenticating immediately.'),
        ];
    }
}
