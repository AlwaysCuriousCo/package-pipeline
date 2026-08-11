{{--
    Shown where a role's permissions are ticked, because that is where the
    decision is made.

    The audit log is the one resource whose permission is not narrowed by
    anything else a role holds, and nothing on this screen would say so. The
    full account of what that exposes, and why it is not scoped, lives in
    ActivityResource — this says only as much as the person ticking the box
    has to weigh.
--}}
<x-filament::section
    icon="heroicon-o-clipboard-document-list"
    icon-color="warning"
    heading="The audit log is registry-wide"
    class="mb-6"
>
    <p class="text-sm">
        A role holding any <strong>audit log</strong> permission reads every change recorded
        anywhere in the registry, and the before-and-after values of each one — including
        records this role cannot reach elsewhere. Package and repository scoping does not
        narrow it. Grant it to the people you would let read the whole registry.
    </p>
</x-filament::section>
