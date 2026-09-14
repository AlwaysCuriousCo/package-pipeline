<x-filament-panels::page>
    @if ($plainTextToken)
        {{-- The one time the secret exists. Everything a person does next is
             a copy, so each shape it gets pasted into is its own row. --}}
        <x-filament::section
            icon="heroicon-o-key"
            icon-color="success"
            heading="Copy your new token now"
            description="This is the only time it is shown. Whatever you don't copy, you regenerate."
        >
            <div class="space-y-3">
                <x-copy-line
                    label="Token"
                    accent
                    :value="$plainTextToken"
                    hint="The secret itself — paste it into a CI secret store or a password manager."
                />

                <x-copy-line
                    label="This project"
                    :value="\App\Support\NewToken::composerCommandFor($tokenUsername, $plainTextToken)"
                    hint="Writes auth.json beside the project's composer.json."
                />

                <x-copy-line
                    label="This machine"
                    :value="\App\Support\NewToken::composerCommandFor($tokenUsername, $plainTextToken, global: true)"
                    hint="Writes your home auth.json, for every project on this machine."
                />
            </div>
        </x-filament::section>
    @endif

    {{-- The moment someone is about to make the wrong kind of token. --}}
    <x-filament::section
        icon="heroicon-o-server-stack"
        icon-color="gray"
        heading="Setting up CI or a server?"
    >
        <p class="text-sm text-gray-500 dark:text-gray-400">
            @if (\App\Filament\Resources\DeployTokens\DeployTokenResource::canViewAny())
                Use a <a href="{{ \App\Filament\Resources\DeployTokens\DeployTokenResource::getUrl() }}" class="font-medium text-primary-600 underline dark:text-primary-400">deploy token</a> instead, so it isn't tied to your account.
            @else
                Ask an admin for a deploy token instead, so it isn't tied to your account.
            @endif
            A personal token dies with your account; a deploy token belongs to the machine.
        </p>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
