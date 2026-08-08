<?php

namespace App\Filament\Pages;

use App\Enums\TokenAbility;
use App\Models\Token;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Self-service personal access tokens, reached from the user menu.
 *
 * Every panel user manages their own tokens here — issuing one is how their
 * Composer clients authenticate — and only their own: the page's queries are
 * pinned to the signed-in user, so it needs no Shield permission.
 */
class ApiTokens extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    // Reached from the user menu; it is nobody's workspace, so it stays out
    // of the sidebar.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'API tokens';

    protected string $view = 'filament.pages.api-tokens';

    /**
     * The plain text of a token created this request — the only time it ever
     * exists outside the creator's clipboard.
     */
    public ?string $plainTextToken = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->user()->tokens()->getQuery())
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('token_prefix')
                    ->label('Token')
                    ->formatStateUsing(fn (string $state): string => "{$state}…")
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('abilities')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => TokenAbility::tryFrom($state)?->getLabel() ?? $state),
                TextColumn::make('last_used_at')
                    ->label('Last used')
                    ->since()
                    ->placeholder('Never'),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->date()
                    ->placeholder('Never'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->date(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Revoke')
                    ->modalHeading('Revoke token')
                    ->modalDescription('Composer clients using this token stop authenticating immediately.')
                    ->successNotificationTitle('Token revoked'),
            ])
            ->emptyStateHeading('No API tokens')
            ->emptyStateDescription('Create one to let a Composer client authenticate against this registry.');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Create token')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Create an API token')
                ->modalDescription('The token is shown once, right after it is created.')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('ci-deploy')
                        ->helperText('What this token is for — shown in listings, and how you will recognise it later.'),
                    CheckboxList::make('abilities')
                        ->options(TokenAbility::class)
                        ->default([TokenAbility::RepositoryRead->value])
                        ->required(),
                    DatePicker::make('expires_at')
                        ->label('Expires')
                        ->minDate(now()->addDay())
                        ->helperText('Leave empty for a token that never expires.'),
                ])
                ->action(function (array $data): void {
                    $new = Token::issue(
                        $this->user(),
                        $data['name'],
                        $data['abilities'],
                        // The whole chosen day is valid; expiring at its
                        // midnight would cut the last day off.
                        filled($data['expires_at'] ?? null) ? CarbonImmutable::parse($data['expires_at'])->endOfDay() : null,
                    );

                    $this->plainTextToken = $new->plainText;

                    Notification::make()
                        ->success()
                        ->title('Token created')
                        ->body('Copy it now — it will not be shown again.')
                        ->send();
                }),
        ];
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
