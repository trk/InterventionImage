<?php

declare(strict_types=1);

namespace Intervention\ImageHash;

use Intervention\Image\Exceptions\InvalidArgumentException;
use Intervention\ImageHash\Exceptions\ImageHashException;
use JsonSerializable;
use Stringable;

class Hash implements Stringable, JsonSerializable
{
    /**
     * @throws InvalidArgumentException
     */
    private function __construct(readonly protected string $bytes)
    {
        if (strlen($this->bytes) < 1) {
            throw new InvalidArgumentException("A hash must be created of at least one byte");
        }
    }

    /**
     * Create hash from hexadecimal string.
     *
     * @throws InvalidArgumentException
     * @throws ImageHashException
     */
    public static function fromHex(string $hex): self
    {
        if (strlen($hex) < 2) {
            throw new InvalidArgumentException("Hash must be created of at least one byte hexadecimal string");
        }

        $hex = strtolower($hex);

        if (!ctype_xdigit($hex)) {
            throw new InvalidArgumentException("Hash must be created from valid hexadecimal strings");
        }

        if (strlen($hex) % 2 !== 0) {
            throw new InvalidArgumentException("Hash must be created from an even length hexadecimal string");
        }

        $bytes = hex2bin($hex);

        if ($bytes === false) {
            throw new ImageHashException("Failed to convert hex to binary");
        }

        return new self($bytes);
    }

    /**
     * Create hash from concatinated bit string or array of bits.
     *
     * @param array<int|string|bool> $bits
     * @throws InvalidArgumentException
     */
    public static function fromBits(string|array $bits): self
    {
        $bits = is_string($bits) ? str_split($bits) : $bits;

        if (count($bits) < 8) {
            throw new InvalidArgumentException('Hash must be created from at least 8 bits');
        }

        $bits = array_map(fn(mixed $bit): string => (string) new BitParser($bit), $bits);
        $bytes = array_map(fn(array $bits): string => implode('', $bits), array_chunk($bits, 8));
        $bytes = array_map(fn(string $byte): string => pack('C', bindec($byte)), $bytes);

        return new self(implode('', $bytes));
    }

    /**
     * Create hash from given byte string.
     *
     * @throws InvalidArgumentException
     */
    public static function fromBytes(string $bytes): self
    {
        return new self($bytes);
    }

    /**
     * Create hash from a base64-encoded string.
     *
     * @throws InvalidArgumentException
     */
    public static function fromBase64(string $base64): self
    {
        $base64 = base64_decode($base64, strict: true);

        if ($base64 === false) {
            throw new InvalidArgumentException('Unable to base64-decode string');
        }

        return new self($base64);
    }

    /**
     * Transform hash to hexadecimal string.
     */
    public function toHex(): string
    {
        $bytes = str_split($this->bytes);
        $bytes = array_map(fn(string $byte): string => dechex(ord($byte)), $bytes);
        $bytes = array_map(fn(string $byte): string => str_pad($byte, 2, '0', STR_PAD_LEFT), $bytes);

        return implode('', $bytes);
    }

    /**
     * Convert hash into a concatinated string of bits.
     */
    public function toBits(): string
    {
        $bytes = str_split($this->bytes);
        $bytes = array_map(fn(string $byte): string => decbin(ord($byte)), $bytes);
        $bytes = array_map(fn(string $byte): string => str_pad($byte, 8, '0', STR_PAD_LEFT), $bytes);
        $bytes = array_map(fn(string $byte): array => str_split($byte), $bytes);

        return implode('', array_merge(...$bytes));
    }

    /**
     * Return bytes of hash.
     */
    public function toBytes(): string
    {
        return $this->bytes;
    }

    /**
     * Return base64-encoded bytes of hash.
     */
    public function toBase64(): string
    {
        return base64_encode($this->bytes);
    }

    /**
     * Calculate distance to given hash.
     *
     * @throws InvalidArgumentException
     */
    public function distance(self $hash): int
    {
        if ($this->bitLength() !== $hash->bitLength()) {
            throw new InvalidArgumentException("Hashes must have the same bit length for comparison");
        }

        $bits1 = $this->toBits();
        $bits2 = $hash->toBits();

        if (extension_loaded('gmp') && function_exists('gmp_hamdist')) {
            return gmp_hamdist('0b1' . $bits1, '0b1' . $bits2);
        }

        return count(array_diff_assoc(str_split($bits1), str_split($bits2)));
    }

    /**
     * Determine if given hash is equal to hash.
     *
     * @throws InvalidArgumentException
     */
    public function equals(self $hash, int $leeway = 0): bool
    {
        return $this->distance($hash) <= $leeway;
    }

    /**
     * Return bit length of hash.
     */
    public function bitLength(): int
    {
        return strlen($this->bytes) * 8;
    }

    /**
     * {@inheritdoc}
     *
     * @see JsonSerializable::jsonSerialize()
     */
    public function jsonSerialize(): mixed
    {
        return $this->toHex();
    }

    /**
     * {@inheritdoc}
     *
     * @see Stringable::__toString()
     */
    public function __toString(): string
    {
        return $this->toHex();
    }

    /**
     * Display debug info of current hash.
     *
     * @return array<mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'hex' => $this->toHex(),
            'bitLength' => $this->bitLength(),
        ];
    }
}
