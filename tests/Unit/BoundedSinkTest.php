<?php

namespace Tests\Unit;

use App\Support\BoundedSink;
use PHPUnit\Framework\TestCase;

/**
 * The ceiling, applied to the writes rather than to the result.
 *
 * What matters here is the short write: it is how a stream tells whatever is
 * filling it to stop, and it is what makes libcurl abandon a transfer instead
 * of spending the rest of the timeout on bytes that are going to be thrown
 * away.
 */
class BoundedSinkTest extends TestCase
{
    public function test_it_accepts_a_transfer_that_fits(): void
    {
        $sink = BoundedSink::to('php://temp', 32);

        $this->assertSame(11, fwrite($sink->stream(), 'hello world'));
        $this->assertFalse($sink->exceeded());
        $this->assertSame(11, $sink->bytes());
        $this->assertSame('hello world', $sink->contents());
    }

    public function test_a_transfer_that_lands_exactly_on_the_ceiling_is_kept(): void
    {
        $sink = BoundedSink::to('php://temp', 5);

        // The limit is what may be written, not what may be approached: an
        // archive whose size is the ceiling to the byte is inside it.
        $this->assertSame(5, fwrite($sink->stream(), 'abcde'));
        $this->assertFalse($sink->exceeded());
    }

    public function test_it_stops_accepting_bytes_at_the_ceiling(): void
    {
        $sink = BoundedSink::to('php://temp', 8);

        $this->assertSame(4, fwrite($sink->stream(), 'aaaa'));

        // Short: four of these six bytes are all there is room for, and
        // reporting that is what aborts the transfer rather than truncating it
        // quietly.
        $this->assertSame(4, fwrite($sink->stream(), 'bbbbbb'));

        // And nothing at all afterwards, however long the upstream keeps
        // sending.
        $this->assertSame(0, fwrite($sink->stream(), 'cccccc'));

        $this->assertTrue($sink->exceeded());
        $this->assertSame(8, $sink->bytes());
        $this->assertSame('aaaabbbb', $sink->contents());
    }

    public function test_it_writes_through_to_the_file_it_was_opened_onto(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'bounded-sink-');

        $sink = BoundedSink::to($path, 1024);

        fwrite($sink->stream(), 'PK-pretend-this-is-a-zip');

        // Closed before the file is read by anything else — the last of what
        // was written is in this process until the handle is gone, which is
        // exactly what a checksum taken over the path would otherwise miss.
        $sink->close();

        $this->assertSame('PK-pretend-this-is-a-zip', (string) file_get_contents($path));

        @unlink($path);
    }
}
