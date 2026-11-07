<?php

declare(strict_types=1);

namespace Intervention\ImageHash\Strategies;

use Intervention\Image\Exceptions\InvalidArgumentException;
use Intervention\Image\Exceptions\RuntimeException;
use Intervention\Image\Interfaces\AnalyzerInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\ImageHash\Hash;
use Intervention\ImageHash\Interfaces\StrategyInterface;
use Intervention\ImageHash\Analyzers\RgbArrayAnalyzer;

class AverageStrategy implements StrategyInterface, AnalyzerInterface
{
    /**
     * @throws InvalidArgumentException
     */
    public function __construct(readonly protected int $size = 8)
    {
        if ($this->size <= 0) {
            throw new InvalidArgumentException('Invalid size. Must be int<1, max>');
        }
    }

    /**
     * Build hash from given image.
     *
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    public function analyze(ImageInterface $image): Hash
    {
        return $this->hash(clone $image);
    }

    /**
     * {@inheritdoc}
     *
     * @see StrategyInterface::hash()
     *
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    public function hash(ImageInterface $image): Hash
    {
        $resized = $image->resize($this->size, $this->size);

        // Create an array of greyscale pixel values.
        $pixels = [];
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $rgb = $resized->analyze(new RgbArrayAnalyzer($x, $y));
                $pixels[] = (int) floor(($rgb[0] * 0.299) + ($rgb[1] * 0.587) + ($rgb[2] * 0.114));
            }
        }

        // Get the average pixel value.
        $average = floor(array_sum($pixels) / count($pixels));

        // Each hash bit is set based on whether the current pixels value is above or below the average.
        $bits = array_map(fn(int $pixel): int => (int) ($pixel > $average), $pixels);

        return Hash::fromBits($bits);
    }
}
