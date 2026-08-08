<?php

namespace App\Filament\Resources\AuthenticationSources\Schemas;

use App\Enums\AuthProvider;
use App\Models\AuthenticationSource;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class AuthenticationSourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->placeholder('Acme SSO')
                    ->helperText('The login button reads "Continue with {name}".'),
                Select::make('provider')
                    ->options(AuthProvider::class)
                    ->required()
                    ->live()
                    ->selectablePlaceholder(false),
                TextInput::make('discovery_url')
                    ->label('OIDC discovery URL')
                    ->url()
                    ->maxLength(255)
                    ->placeholder('https://id.example.com/.well-known/openid-configuration')
                    ->visible(fn (Get $get): bool => AuthProvider::tryFrom((string) $get('provider')) === AuthProvider::Oidc)
                    ->required(fn (Get $get): bool => AuthProvider::tryFrom((string) $get('provider')) === AuthProvider::Oidc)
                    ->helperText('The issuer\'s openid-configuration document; endpoints are read from it.'),
                TextInput::make('client_id')
                    ->label('Client ID')
                    ->required()
                    ->maxLength(255)
                    ->helperText(fn (?AuthenticationSource $record): string => $record
                        ? "Register this callback URL on the OAuth client: {$record->callbackUrl()}"
                        : 'The callback URL to register on the OAuth client is shown here after saving.'),
                TextInput::make('client_secret')
                    ->label('Client secret')
                    ->password()
                    ->revealable()
                    ->maxLength(255)
                    // The stored secret is never echoed back to the browser;
                    // a blank input keeps it, a new value replaces it.
                    ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (?AuthenticationSource $record): bool => $record === null)
                    ->placeholder(fn (?AuthenticationSource $record): string => $record
                        ? 'Secret saved — enter a new one to replace it'
                        : ''),
                Toggle::make('active')
                    ->default(true)
                    ->helperText('Only active providers appear on the login page.'),
                Toggle::make('allow_registration')
                    ->label('Allow registration')
                    ->default(true)
                    ->helperText('Whether an identity with no matching account may create one.'),
                TagsInput::make('allowed_domains')
                    ->label('Allowed email domains')
                    ->placeholder('example.com')
                    ->helperText('Registration is limited to these domains. Leave empty to allow any.'),
                Select::make('default_role')
                    ->label('Role for new accounts')
                    ->options(fn (): array => Role::query()
                        ->where('guard_name', 'web')
                        ->orderBy('name')
                        ->pluck('name', 'name')
                        ->all())
                    ->helperText('Assigned to accounts this provider creates. Without a role they cannot sign in to the panel at all.'),
            ]);
    }
}
