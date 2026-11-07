# Intervention ImageHash
## Perceptual image hashing for PHP

[![Latest Version](https://img.shields.io/packagist/v/intervention/imagehash.svg)](https://packagist.org/packages/intervention/imagehash)
[![Build Status](https://github.com/Intervention/image/actions/workflows/run-tests.yml/badge.svg)](https://github.com/Intervention/imagehash/actions)
[![Monthly Downloads](https://img.shields.io/packagist/dm/intervention/imagehash.svg)](https://packagist.org/packages/intervention/imagehash/stats)
[![Support me on Ko-fi](https://raw.githubusercontent.com/Intervention/imagehash/develop/.github/images/support.svg)](https://ko-fi.com/interventionphp)

Intervention ImageHash is an extension library to [Intervention Image](https://github.com/Intervention/image) and provides perceptual image hashing with different strategies. 

A perceptual hash is a fingerprint of a multimedia file derived from various features from its content. Unlike cryptographic hash functions which rely on the avalanche effect of small changes in input leading to drastic changes in the output, perceptual hashes are "close" to one another if the features are similar.

## Installation

Install this library using [Composer](https://getcomposer.org). Add the package with the following command:

```bash
composer require intervention/imagehash
```

## Usage

### Building Hashes

#### Using ImageHasher

The `ImageHasher` serves as the central starting point for all hashing operations. Depending on the PHP environment, a driver must be passed to it that matches the image extension (GD, Imagick or libvips) being used. Here is also the hashing strategy defined.

The library comes with four built-in hashing strategies:

 - `Intervention\ImageHash\Strategies\AverageStrategy` - Hash based the average image color
 - `Intervention\ImageHash\Strategies\DifferenceStrategy` - Hash based on the previous pixel
 - `Intervention\ImageHash\Strategies\BlockStrategy` - Hash based on blockhash.io
 - `Intervention\ImageHash\Strategies\PerceptualStrategy` - The original pHash

Choose one of these strategies. If you don't know which one to use, try the `DifferenceStrategy`. Some strategies allow configuration, be sure to check the constructors.

To generate hashes, the `hash()` method is used, which can read from [various image sources](https://image.intervention.io/v4/basics/instantiation#supported-image-sources) like paths, raw image data and more.

```php
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\ImageHash\ImageHasher;
use Intervention\ImageHash\Strategies\DifferenceStrategy;

$hasher = new ImageHasher(new GdDriver(), new DifferenceStrategy());
$hash = $hasher->hash('path/to/image.jpg');
```

#### Using AnalyzerInterface

Alternatively, you can choose to use `Image::analyze()` method instead of the `ImageHasher`. This integrates more seamlessly into an existing Intervention Image processing pipeline, if you already have instances of `Intervention\Image\Interfaces\ImageInterface` - the results remain the same.

```php
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\ImageHash\Strategies\DifferenceStrategy;

$image = ImageManager::usingDriver(GdDriver::class)
    ->decodePath('path/to/image.jpg')
    ->scale(width: 300);

$hash = $image->analyze(new DifferenceStrategy()); // all strategies are possible here
```

### Comparing Hashes

The resulting `Hash` object, is a hexadecimal image fingerprint that can be stored once calculated. Two fingerprints can be compared by the hamming distance for similarities. Low distance values will indicate that the images are similar or the same, high distance values indicate that the images are different. Use the following methods for comparisons:

```php
$distance = $hash1->distance($hash2); // 12
$equals = $hash1->equals($hash2); // false
```

Perceptual hashes are a different concept compared to cryptographic hash functions like MD5 and SHA1. With cryptographic hashes, the hash values are random. Comparing two SHA1 hash values really only tells you two things. If the hashes are different, then the data is different. In contrast, perceptual hashes can be compared - giving you a sense of similarity between the two data sets.

A perceptual hash is a compact summary of visual features. Because of that, the hashes are influenced by the input images, the processing strategy and the processing pipeline and may vary with its distance to other hashes depending on these factors.

### Converting Hashes

The `Hash` object can be converted to a couple of different formats:

```php
echo $hash->toHex(); // "74657374"
echo $hash->toBits(); // "01110100011001010111001101110100"
echo $hash->toBytes(); // "test"
```

### Parsing Hashes

If you want to reconstruct a `Hash` object from a previous calculated value, use:

```php
$hash = Hash::fromHex('74657374');
$hash = Hash::fromBits('01110100011001010111001101110100');
$hash = Hash::fromBytes('test');
```

## Requirements

 - PHP 8.3 or higher
 - The [GD](http://php.net/manual/en/book.image.php), [Imagick](http://php.net/manual/en/book.imagick.php) or [libvips](https://www.libvips.org) extension
 - Optionally, install the [GMP](http://php.net/manual/en/book.gmp.php) extension for faster fingerprint comparisons

## Demo

These images are similar:

![Equals1](https://raw.githubusercontent.com/Intervention/imagehash/develop/tests/images/tropical_high.jpg)
![Equals2](https://raw.githubusercontent.com/Intervention/imagehash/develop/tests/images/tropical_watermark.jpg)

	Image 1 hash: 8f9e9d8b0f0f1f07 (1000111110011110100111011000101100001111000011110001111100000111)
	Image 2 hash: 8e9e958b0f2f1f07 (1000111010011110100101011000101100001111001011110001111100000111)
	Hamming distance: 3

These images are different:

![Equals1](https://raw.githubusercontent.com/Intervention/imagehash/develop/tests/images/mountain_day.jpg)
![Equals2](https://raw.githubusercontent.com/Intervention/imagehash/develop/tests/images/tropical_high.jpg)

	Image 1 hash: 6c2b58432011e38e (0110110000101011010110000100001100100000000100011110001110001110)
	Image 2 hash: 8f9e9d8b0f0f1f07 (1000111110011110100111011000101100001111000011110001111100000111)
	Hamming distance: 35

## Security

If you discover any security related issues, please email oliver@intervention.io directly.

## Authors

This project is based on work originally developed by [Jens Segers](https://github.com/jenssegers) and released under the MIT License. Many thanks to him for sharing his code with the community, which made this fork possible.

This version is maintained by [Oliver Vogel](https://intervention.io) including modifications and improvements, but it builds directly on the solid foundation Jens created. 

## License

Intervention ImageHash is licensed under the [MIT License](LICENSE).
