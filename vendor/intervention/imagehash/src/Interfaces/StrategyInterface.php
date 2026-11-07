<?php

declare(strict_types=1);

namespace Intervention\ImageHash\Interfaces;

use Intervention\Image\Interfaces\ImageInterface;
use Intervention\ImageHash\Hash;

interface StrategyInterface
{
    /**
     * Build hash from given image.
     */
    public function hash(ImageInterface $image): Hash;
}
