<?php

declare(strict_types=1);

namespace Intervention\ImageHash\Strategies;

use Intervention\Image\Exceptions\InvalidArgumentException;
use Intervention\Image\Interfaces\AnalyzerInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\ImageHash\Hash;
use Intervention\ImageHash\Interfaces\StrategyInterface;
use Intervention\ImageHash\Analyzers\RgbArrayAnalyzer;

class DifferenceStrategy implements StrategyInterface, AnalyzerInterface
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
     * @throws InvalidArgumentException
     */
    public function hash(ImageInterface $image): Hash
    {
        // For this implementation we create a 8x9 image.
        $width = $this->size + 1;
        $height = $this->size;

        $resized = $image->resize($width, $height);

        $bits = [];
        for ($y = 0; $y < $height; $y++) {
            // Get the pixel value for the leftmost pixel.
            $rgb = $resized->analyze(new RgbArrayAnalyzer(0, $y));
            $left = (int) floor(($rgb[0] * 0.299) + ($rgb[1] * 0.587) + ($rgb[2] * 0.114));

            for ($x = 1; $x < $width; $x++) {
                // Get the pixel value for each pixel starting from position 1.
                $rgb = $resized->analyze(new RgbArrayAnalyzer($x, $y));
                $right = (int) floor(($rgb[0] * 0.299) + ($rgb[1] * 0.587) + ($rgb[2] * 0.114));

                // Each hash bit is set based on whether the left pixel is brighter than the right pixel.
                // http://www.hackerfactor.com/blog/index.php?/archives/529-Kind-of-Like-That.html
                $bits[] = (int) ($left > $right);

                // Prepare the next loop.
                $left = $right;
            }
        }

        return Hash::fromBits($bits);
    }
}
