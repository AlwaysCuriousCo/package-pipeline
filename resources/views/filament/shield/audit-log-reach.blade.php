{{--
    Shown on the Roles screen, because that is where the decision is made.

    The audit log is the one resource whose permission is not narrowed by
    anything else a role holds, and nothing on this screen would say so.
--}}
<x-filament::section
    icon="heroicon-o-clipboard-document-list"
    icon-color="warning"
    heading="The audit log is registry-wide"
    class="mb-6"
>
    <p class="text-sm">
        A role holding any <strong>audit log</strong> permission reads every change recorded
        anywhere in the registry, and the before-and-after values of each one. Package and
        repository scoping does not narrow it: <code>Unscoped:Package</code> makes no
        difference here, and neither do grants or team membership.
    </p>
    <p class="mt-2 text-sm">
        That means the names of private packages and their VCS URLs, every account's name and
        email address, and the name, prefix and abilities of every access token — including
        those belonging to people and repositories this role cannot otherwise reach. Grant it
        to the people you would let read the whole registry.
    </p>
</x-filament::section>
