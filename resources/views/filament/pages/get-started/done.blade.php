<ul class="grid gap-1 text-sm" aria-label="Imported packages">
    @foreach ($packages as $status)
        <li><a href="{{ \App\Filament\Pages\GetStarted::packageUrl($status['package']) }}" class="underline">{{ $status['package']->name }}</a> → {{ $status['state'] }}</li>
    @endforeach
</ul>
