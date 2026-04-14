<?php declare(strict_types=1);

namespace Apntalk\EslReact\Protocol;

use Apntalk\EslCore\Contracts\FrameParserInterface;
use Apntalk\EslCore\Exceptions\ParseException;
use Apntalk\EslCore\Protocol\Frame;

final class FrameReader
{
    public function __construct(private readonly FrameParserInterface $parser) {}

    /**
     * Feed raw bytes into the parser. Returns zero or more complete frames.
     * On ParseException, the parser is reset and the exception re-thrown.
     *
     * @return list<Frame>
     * @throws ParseException
     */
    public function feed(string $bytes): array
    {
        try {
            $this->parser->feed($bytes);
            return $this->parser->drain();
        } catch (ParseException $e) {
            $this->parser->reset();
            throw $e;
        }
    }

    public function reset(): void
    {
        $this->parser->reset();
    }

    public function bufferedByteCount(): int
    {
        return $this->parser->bufferedByteCount();
    }
}
