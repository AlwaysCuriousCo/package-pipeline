<?php

namespace App\Support;

/**
 * The stream wrapper behind BoundedSink, registered as `bounded-sink://`.
 *
 * Everything here delegates to the file the sink was opened onto; the one
 * method that does anything is stream_write, which writes what the ceiling
 * leaves room for and returns that count. A short return is how a stream says
 * "no further" to whatever is filling it — libcurl treats it as a write error
 * and abandons the transfer, and PHP's own `fwrite` reports it to the caller.
 *
 * Instantiated by PHP rather than by this app, which is why it takes no
 * constructor arguments and finds its sink through the URL it is opened with.
 *
 * @see BoundedSink
 */
final class BoundedSinkWrapper
{
    /**
     * Set by PHP on every wrapper instance. Unused, and removing it makes
     * `stream_context_create` throw at the wrapper.
     *
     * @var resource
     */
    public $context;

    private BoundedSink $sink;

    /** @var resource */
    private $target;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $sink = BoundedSink::claim((string) parse_url($path, PHP_URL_HOST));

        if (! $sink instanceof BoundedSink) {
            return false;
        }

        $this->sink = $sink;
        $this->target = $sink->target();

        return true;
    }

    public function stream_write(string $data): int
    {
        $accepted = $this->sink->accept($data);

        if ($accepted === '') {
            return 0;
        }

        return (int) fwrite($this->target, $accepted);
    }

    public function stream_read(int $count): string
    {
        return (string) fread($this->target, $count);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return fseek($this->target, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        return (int) ftell($this->target);
    }

    public function stream_eof(): bool
    {
        return feof($this->target);
    }

    public function stream_flush(): bool
    {
        return fflush($this->target);
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return fstat($this->target) ?: [];
    }

    public function stream_close(): void
    {
        // Deliberately not closing the file: the sink owns it, hands it to
        // callers that read what was written, and closes both handles itself.
    }
}
