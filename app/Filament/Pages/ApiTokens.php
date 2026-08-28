<?php

namespace App\Filament\Pages;

use App\Enums\TokenAbility;
use App\Filament\Resources\AccessTokens\AccessTokenResource;
use App\Models\Token;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
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
 *
 * Which is exactly why the abilities on offer are not the whole enum. The page
 * is reachable by anybody who can sign in, and a token it issues acts as the
 * person who issued it, so an ability that would let a role do what the panel
 * refuses it is not offered — and is refused again on submit.
 *
 * @see TokenAbility::issuableBy()
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
            ->columns(AccessTokenResource::tokenColumns())
            ->recordActions([
                AccessTokenResource::revokeAction(),
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
                        ->options(fn (): array => $this->issuableAbilities())
                        ->default([TokenAbility::RepositoryRead->value])
                        ->required()
                        // The options are what this account's role permits, and
                        // the browser is under no obligation to post them:
                        // Livewire state arrives from the client like any other
                        // form field. So the same question is asked here, on the
                        // way in, where it is the one that decides.
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            $refused = array_diff((array) $value, array_keys($this->issuableAbilities()));

                            if ($refused !== []) {
                                $fail('Your role may not issue a token with: '.implode(', ', $refused).'.');
                            }
                        })
                        ->helperText('A token can never do more than you can: what it may reach, and what it may change, still answer to your own grants and role.'),
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

    /**
     * The abilities this account may put on a token, as checkbox options.
     *
     * @return array<string, string>
     *
     * @see TokenAbility::issuableBy() for what is offered to whom, and why
     */
    private function issuableAbilities(): array
    {
        $options = [];

        foreach (TokenAbility::issuableBy($this->user()) as $ability) {
            $options[$ability->value] = $ability->getLabel();
        }

        return $options;
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
