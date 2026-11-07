<?php

declare(strict_types=1);

namespace Intervention\ImageHash;

use Intervention\Image\Exceptions\InvalidArgumentException;
use Intervention\ImageHash\Analyzers\ImageHashAnalyzer;
use Intervention\ImageHash\Interfaces\StrategyInterface;
use Intervention\ImageHash\Strategies\DifferenceStrategy;
use Intervention\Image\Interfaces\DriverInterface;
use Intervention\Image\Traits\CanResolveDriver;

class ImageHasher
{
    use CanResolveDriver;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public string|DriverInterface $driver,
        public StrategyInterface $strategy = new DifferenceStrategy(),
    ) {
        $this->driver = $this->resolveDriver($driver);
    }

    /**
     * Create image hasher statically.
     *
     * @throws InvalidArgumentException
     */
    public static function create(
        string|DriverInterface $driver,
        StrategyInterface $strategy = new DifferenceStrategy(),
    ): self {
        return new self($driver, $strategy);
    }

    /**
     * Create image hasher with given image manipulation driver.
     *
     * @throws InvalidArgumentException
     */
    public static function usingDriver(string|DriverInterface $driver): self
    {
        return new self($driver);
    }

    /**
     * Create a hasher instance with the given driver from the current one.
     *
     * @throws InvalidArgumentException
     */
    public function withDriver(string|DriverInterface $driver): self
    {
        return new self($driver, $this->strategy);
    }

    /**
     * Create a hasher instance with the given strategy from the current one.
     *
     * @throws InvalidArgumentException
     */
    public function withStrategy(StrategyInterface $strategy): self
    {
        return new self($this->driver, $strategy);
    }

    /**
     * Build image hash from given image source which can be one of the following:
     *
     * - Path in filesystem
     * - Raw binary image data
     * - SplFileInfo object
     * - Base64 encoded image data
     * - Data URI string or instance of DataUriInterface
     * - Stream resource
     * - Instance of ImageInterface
     * - Instance of EncodedImageInterface
     */
    public function hash(mixed $image): Hash
    {
        return $this->driver->decodeImage($image)->analyze(new ImageHashAnalyzer($this->strategy));
    }
}
