<?php

declare(strict_types=1);

namespace AudD\Internal;

use GuzzleHttp\Psr7\Stream;
use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 view of a caller's PHP stream resource that never closes it.
 *
 * Guzzle closes the body streams it sends. Wrapping the caller's handle in
 * this class makes "close" a detach instead, so the handle stays open for the
 * caller and can be rewound if the request is retried.
 *
 * @internal
 */
final class BorrowedStream implements StreamInterface
{
    private readonly Stream $inner;

    /**
     * @param resource $resource
     */
    public function __construct($resource)
    {
        $this->inner = new Stream($resource);
    }

    public function __destruct()
    {
        $this->inner->detach();
    }

    public function __toString(): string
    {
        return $this->inner->__toString();
    }

    public function close(): void
    {
        $this->inner->detach();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }

    public function write(string $string): int
    {
        return $this->inner->write($string);
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function read(int $length): string
    {
        return $this->inner->read($length);
    }

    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }
}
