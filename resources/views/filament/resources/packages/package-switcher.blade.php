{{--
    Scoped <style> rather than utility classes: app-level Tailwind never
    reaches the panel's pre-built stylesheet (see the sync progress widget).
--}}
@php
    use App\Filament\Resources\Packages\PackageResource;
@endphp

<style>
    .pp-package-switcher-trigger {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        white-space: nowrap;
        font-size: 1.25rem;
        font-weight: 400;
        line-height: 1.5;
        color: inherit;
        cursor: pointer;
        border-radius: 0.375rem;
        padding: 0.125rem 0.375rem;
        margin-inline-start: -0.375rem;
    }

    .pp-package-switcher-trigger:hover {
        background: var(--gray-100);
    }

    .dark .pp-package-switcher-trigger:hover {
        background: var(--gray-800);
    }

    .pp-package-switcher-trigger .pp-vendor,
    .pp-package-switcher-trigger .pp-slash {
        color: var(--gray-500);
    }

    .pp-package-switcher-trigger .pp-slash {
        margin-inline: 0.375rem;
    }

    .pp-package-switcher-trigger .pp-name {
        font-weight: 600;
    }

    .pp-package-switcher-trigger .fi-icon {
        width: 1rem;
        height: 1rem;
        margin-inline-start: 0.125rem;
        color: var(--gray-400);
    }

    .pp-package-switcher-search {
        padding: 0.5rem;
    }
</style>

<x-filament::dropdown placement="bottom-start" width="sm" max-height="20rem">
    <x-slot name="trigger">
        <button type="button" class="pp-package-switcher-trigger" aria-label="Switch package">
            @php [$vendor, $name] = array_pad(explode('/', $current->name, 2), 2, null); @endphp
            @if ($name !== null)
                <span class="pp-vendor">{{ $vendor }}</span><span class="pp-slash">/</span><span class="pp-name">{{ $name }}</span>
            @else
                <span class="pp-name">{{ $vendor }}</span>
            @endif
            <x-filament::icon icon="heroicon-m-chevron-down" />
        </button>
    </x-slot>

    <div x-data="{ q: '' }">
        <div class="pp-package-switcher-search">
            <x-filament::input.wrapper>
                <x-filament::input
                    type="search"
                    placeholder="Find a package…"
                    x-model="q"
                />
            </x-filament::input.wrapper>
        </div>

        <x-filament::dropdown.list>
            @foreach ($packages as $id => $name)
                <x-filament::dropdown.list.item
                    tag="a"
                    :href="PackageResource::getUrl('view', ['record' => $id])"
                    :color="$id === $current->getKey() ? 'primary' : 'gray'"
                    x-show="@js($name).includes(q.toLowerCase())"
                >
                    {{ $name }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </div>
</x-filament::dropdown>
