{{-- The setup lines for each repository a plan's packages are installed
     from — usually one; a plan spanning two products' hosts gets a pair per
     host, since Composer keys credentials by host. --}}
@foreach ($repositories as $setup)
<pre class="overflow-x-auto rounded-lg bg-zinc-900 px-4 py-3 text-sm text-zinc-100"><code>{{ \App\Support\NewToken::composerCommandFor('token', $token, global: true, host: parse_url($setup->url(), PHP_URL_HOST)) }}
{{ $setup->configureCommand() }}</code></pre>
@endforeach
