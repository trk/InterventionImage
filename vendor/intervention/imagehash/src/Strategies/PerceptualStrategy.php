<?php

declare(strict_types=1);

namespace Intervention\ImageHash\Strategies;

use Intervention\Image\Exceptions\InvalidArgumentException;
use Intervention\Image\Interfaces\AnalyzerInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\ImageHash\Hash;
use Intervention\ImageHash\Interfaces\StrategyInterface;
use Intervention\ImageHash\Analyzers\RgbArrayAnalyzer;
use Intervention\ImageHash\Exceptions\ImageHashException;

class PerceptualStrategy implements StrategyInterface, AnalyzerInterface
{
    public const string AVERAGE = 'average';
    public const string MEDIAN = 'median';

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        readonly protected int $size = 32,
        readonly protected string $comparisonMethod = self::AVERAGE,
    ) {
        if ($this->size < 8) {
            throw new InvalidArgumentException('Size must be at least 8');
        }

        if (!in_array($this->comparisonMethod, [self::AVERAGE, self::MEDIAN])) {
            throw new InvalidArgumentException('Unknown comparison mode ' . $comparisonMethod);
        }
    }

    /**
     * Build hash from given image.
     *
     * @throws ImageHashException
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
     * @throws ImageHashException
     * @throws InvalidArgumentException
     */
    public function hash(ImageInterface $image): Hash
    {
        $resized = $image->resize($this->size, $this->size);

        $matrix = [];
        $rows = [];

        for ($y = 0; $y < $this->size; $y++) {
            $row = [];
            for ($x = 0; $x < $this->size; $x++) {
                $rgb = $resized->analyze(new RgbArrayAnalyzer($x, $y));
                $row[$x] = (int) floor(($rgb[0] * 0.299) + ($rgb[1] * 0.587) + ($rgb[2] * 0.114));
            }
            $rows[$y] = $this->dct($row);
        }

        for ($x = 0; $x < $this->size; $x++) {
            $col = [];
            for ($y = 0; $y < $this->size; $y++) {
                $col[$y] = $rows[$y][$x];
            }
            $colTransformed = $this->dct($col);
            for ($y = 0; $y < $this->size; $y++) {
                $matrix[$y][$x] = $colTransformed[$y];
            }
        }

        // Extract the top 8x8 pixels.
        $pixels = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $pixels[] = $matrix[$y][$x];
            }
        }

        $comparisonPixels = array_slice($pixels, 1);
        $compare = match ($this->comparisonMethod) {
            self::MEDIAN => $this->median($comparisonPixels),
            default => $this->average($comparisonPixels),
        };

        // Calculate hash.
        $bits = [];
        foreach ($pixels as $pixel) {
            $bits[] = (int) ($pixel > $compare);
        }

        return Hash::fromBits($bits);
    }

    /**
     * Perform a 1 dimension Discrete Cosine Transformation.
     *
     * @param array<int|float> $matrix
     * @return array<float>
     */
    protected function dct(array $matrix): array
    {
        $transformed = [];
        $size = count($matrix);

        for ($i = 0; $i < $size; $i++) {
            $sum = 0;
            for ($j = 0; $j < $size; $j++) {
                $sum += $matrix[$j] * cos($i * pi() * ($j + 0.5) / $size);
            }
            $sum *= sqrt(2 / $size);
            if ($i === 0) {
                $sum *= 1 / sqrt(2);
            }
            $transformed[$i] = $sum;
        }

        return $transformed;
    }

    /**
     * Get the median of the pixel values.
     *
     * @param array<float> $pixels
     */
    protected function median(array $pixels): float
    {
        sort($pixels, SORT_NUMERIC);

        if (count($pixels) % 2 === 0) {
            return ($pixels[count($pixels) / 2 - 1] + $pixels[count($pixels) / 2]) / 2;
        }

        return $pixels[(int) floor(count($pixels) / 2)];
    }

    /**
     * Get the average of the pixel values.
     *
     * @param array<float> $pixels
     * @throws ImageHashException
     */
    protected function average(array $pixels): float
    {
        $pixelCount = count($pixels);

        if ($pixelCount === 0) {
            throw new ImageHashException('Unable to calculate average values from zero pixels');
        }

        return array_sum($pixels) / $pixelCount;
    }
}
