<?php

declare(strict_types=1);

namespace Kryption\MediaHub\Tests\Fixtures;

/**
 * A ZIP READER FOR THE BENCH.
 *
 * ⚠️ IT EXISTS BECAUSE `ext-zip` IS IN NONE OF THE FOUR ENVIRONMENTS — verified. Adding the
 * extension would have been shorter, but would have made these tests depend on an extension the
 * PACKAGE does not require: `zipstream-php` is pure PHP, and that is precisely what lets it run
 * where `ZipArchive` is missing. A bench demanding more than the code it tests does not prove
 * the code installs anywhere.
 *
 * ⚠️ AND IT READS THE CENTRAL DIRECTORY, NOT THE LOCAL HEADERS. With a stream, the local headers
 * announce sizes of ZERO — the compressed size is only known once the file has been written, and
 * it is reported in a descriptor placed AFTER the data. Only the central directory, written at
 * the end, tells the truth. A reader trusting the local header would return empty files without
 * complaining.
 */
final class ZipReader
{
    /** @var array<string, array{method: int, size: int, offset: int, compressed: int}> */
    private array $entries = [];

    private function __construct(private readonly string $bytes)
    {
    }

    public static function from(string $bytes): self
    {
        $reader = new self($bytes);
        $reader->readCentralDirectory();

        return $reader;
    }

    /** @return array<int, string> the entry names, in central-directory order */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /** The compression method: 0 = stored as is, 8 = deflated. */
    public function method(string $name): int
    {
        return $this->entries[$name]['method'];
    }

    public function contents(string $name): string
    {
        $entry = $this->entries[$name];

        /*
         * ⚠️ THE LOCAL HEADER HAS ITS OWN EXTRA-FIELD LENGTH, different from the central
         * directory's. Reusing the latter shifts the read by a few bytes — and the content
         * returned is then noise that looks like data.
         */
        $local = $entry['offset'];

        if (substr($this->bytes, $local, 4) !== "PK\x03\x04") {
            throw new \RuntimeException('local header not found for '.$name);
        }

        $nameLength = $this->word($local + 26);
        $extraLength = $this->word($local + 28);

        $start = $local + 30 + $nameLength + $extraLength;
        $raw = substr($this->bytes, $start, $entry['compressed']);

        if ($entry['method'] === 0) {
            return $raw;
        }

        $inflated = @gzinflate($raw);

        if ($inflated === false) {
            throw new \RuntimeException('cannot inflate '.$name);
        }

        return $inflated;
    }

    private function readCentralDirectory(): void
    {
        $end = strrpos($this->bytes, "PK\x05\x06");

        if ($end === false) {
            throw new \RuntimeException('archive without an end-of-central-directory record');
        }

        $count = $this->word($end + 10);
        $position = $this->dword($end + 16);

        for ($i = 0; $i < $count; $i++) {
            if (substr($this->bytes, $position, 4) !== "PK\x01\x02") {
                throw new \RuntimeException('central-directory entry expected');
            }

            $method = $this->word($position + 10);
            $compressed = $this->dword($position + 20);
            $size = $this->dword($position + 24);
            $nameLength = $this->word($position + 28);
            $extraLength = $this->word($position + 30);
            $commentLength = $this->word($position + 32);
            $local = $this->dword($position + 42);

            $name = substr($this->bytes, $position + 46, $nameLength);

            $this->entries[$name] = [
                'method' => $method,
                'size' => $size,
                'compressed' => $compressed,
                'offset' => $local,
            ];

            $position += 46 + $nameLength + $extraLength + $commentLength;
        }
    }

    private function word(int $position): int
    {
        return (int) unpack('v', substr($this->bytes, $position, 2))[1];
    }

    private function dword(int $position): int
    {
        return (int) unpack('V', substr($this->bytes, $position, 4))[1];
    }
}
