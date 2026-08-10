<?php

namespace App\Support;

use RuntimeException;

/**
 * Somewhere to stream a download into that stops accepting bytes at a ceiling.
 *
 * A size limit checked after the transfer is not a size limit. The bytes have
 * already been written by then — to whichever volume the temporary directory
 * is on, which is rarely the disk the ceiling was set to protect — and the
 * only thing bounding how many of them arrived was the timeout: four minutes
 * of an upstream's bandwidth, times however many requests are in flight. An
 * upstream that answers a 20 MB archive with 200 GB gets to spend all of it
 * before anything asks how big it was.
 *
 * So the ceiling is applied to the writes themselves. Past it the sink accepts
 * nothing further and says so by writing short, which is the documented way to
 * make libcurl abandon a transfer — no exception thrown from inside a callback,
 * no reliance on the progress meter being called often enough to matter.
 *
 * Implemented as a stream wrapper rather than as a decorator around Guzzle's,
 * because the cap then applies wherever the bytes come from: curl's write
 * function in production, and the plain `fwrite` Laravel's HTTP fake performs
 * in the tests. One mechanism, exercised by both.
 *
 * @see BoundedSinkWrapper
 */
final class BoundedSink
{
    private const PROTOCOL = 'bounded-sink';

    /**
     * Sinks that have been opened but not yet claimed by their wrapper.
     *
     * A stream wrapper is instantiated by PHP, not by us, and is handed
     * nothing but the URL it was opened with — so the URL carries an id, and
     * this is where the wrapper collects what the id stands for. Entries live
     * for the duration of one `fopen`.
     *
     * @var array<string, self>
     */
    private static array $waiting = [];

    /** @var resource */
    private $target;

    /** @var resource */
    private $stream;

    private int $bytes = 0;

    private bool $exceeded = false;

    private function __construct(private readonly int $limit) {}

    /**
     * A sink writing into `$target`, accepting at most `$limit` bytes.
     *
     * `php://temp` is a legitimate target and is what the metadata fetch uses:
     * a document that fits stays in memory, one that does not spills to disk,
     * and neither can grow past the ceiling in the first place.
     */
    public static function to(string $target, int $limit): self
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, BoundedSinkWrapper::class);
        }

        $sink = new self($limit);

        $handle = fopen($target, 'w+b');

        if ($handle === false) {
            throw new RuntimeException("Could not open {$target} to download into.");
        }

        $sink->target = $handle;

        $id = bin2hex(random_bytes(16));

        self::$waiting[$id] = $sink;

        $stream = fopen(self::PROTOCOL.'://'.$id, 'w+b');

        unset(self::$waiting[$id]);

        if ($stream === false) {
            fclose($handle);

            throw new RuntimeException('Could not open a bounded sink to download into.');
        }

        $sink->stream = $stream;

        return $sink;
    }

    public static function claim(string $id): ?self
    {
        return self::$waiting[$id] ?? null;
    }

    /**
     * What to hand the HTTP client as its `sink`.
     *
     * @return resource
     */
    public function stream()
    {
        return $this->stream;
    }

    /**
     * @return resource
     */
    public function target()
    {
        return $this->target;
    }

    /**
     * Whether the transfer tried to write past the ceiling.
     *
     * The one thing worth asking afterwards, and the reason a truncated
     * download is never mistaken for a complete one: the caller refuses on
     * this, not on the size of what landed.
     */
    public function exceeded(): bool
    {
        return $this->exceeded;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * Refuse the rest of a transfer that has not begun.
     *
     * For the caller that learns from the response headers that what is coming
     * is too big to want. It is the same refusal by a cheaper route, so it is
     * recorded as the same refusal.
     */
    public function refuse(): void
    {
        $this->exceeded = true;
    }

    /**
     * How much of this write the ceiling leaves room for.
     *
     * Truncating rather than refusing outright, so a transfer that ends
     * exactly on the limit is not failed by the chunking of the last packet.
     */
    public function accept(string $data): string
    {
        $room = max(0, $this->limit - $this->bytes);

        if (strlen($data) > $room) {
            $this->exceeded = true;
        }

        $accepted = substr($data, 0, $room);

        $this->bytes += strlen($accepted);

        return $accepted;
    }

    /**
     * Everything written, for a caller small enough to want it in a string.
     */
    public function contents(): string
    {
        rewind($this->target);

        return (string) stream_get_contents($this->target);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        if (is_resource($this->target)) {
            fclose($this->target);
        }
    }
}
