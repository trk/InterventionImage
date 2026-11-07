<?php

declare(strict_types=1);

namespace Intervention\ImageHash\Analyzers;

use Intervention\Image\Interfaces\AnalyzerInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\ImageHash\Hash;
use Intervention\ImageHash\Interfaces\StrategyInterface;
use Intervention\ImageHash\Strategies\DifferenceStrategy;

class ImageHashAnalyzer implements AnalyzerInterface
{
    public function __construct(protected StrategyInterface $strategy = new DifferenceStrategy())
    {
        //
    }

    public function analyze(ImageInterface $image): Hash
    {
        return $this->strategy->hash(clone $image);
    }
}
