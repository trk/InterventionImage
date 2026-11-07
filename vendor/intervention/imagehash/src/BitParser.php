<?php

declare(strict_types=1);

namespace Intervention\ImageHash;

use Intervention\Image\Exceptions\InvalidArgumentException;
use Stringable;

class BitParser implements Stringable
{
    public function __construct(protected mixed $bit)
    {
        //
    }

    /**
     * Parse boolean bit representation to "0" or "1" string.
     */
    private function mapBooleanBit(bool $bit): string
    {
        return $bit === true ? '1' : '0';
    }

    /**
     * Parse integer bit representation to "0" or "1" string.
     *
     * @throws InvalidArgumentException
     */
    private function mapIntegerBit(int $bit): string
    {
        if (!in_array($bit, [0, 1])) {
            throw new InvalidArgumentException('Invalid integer bit representation "' . $bit . '"');
        }

        return strval($bit);
    }

    /**
     * Parse string bit representation to "0" or "1" string.
     *
     * @throws InvalidArgumentException
     */
    private function mapStringBit(string $bit): string
    {
        if (!in_array($bit, ['0', '1'])) {
            throw new InvalidArgumentException('Invalid string bit representation "' . $bit . '"');
        }

        return $bit;
    }

    /**
     * {@inheritdoc}
     *
     * @see Stringable::__toString()
     *
     * @throws InvalidArgumentException
     */
    public function __toString(): string
    {
        return match (gettype($this->bit)) {
            'integer' => $this->mapIntegerBit($this->bit),
            'string' => $this->mapStringBit($this->bit),
            'boolean' => $this->mapBooleanBit($this->bit),
            default => throw new InvalidArgumentException(
                'Invalid bit representation type "' . gettype($this->bit) . '".',
            ),
        };
    }
}
