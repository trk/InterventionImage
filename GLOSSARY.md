# InterventionImage

Responsive image variation, visual analysis, and perceptual fingerprinting engine for ProcessWire `Pageimage` assets.

## Language

### Variations & Delivery

**Variation**:
A derived image file generated from an original `Pageimage` with a deterministic dimension and modifier suffix.
_Avoid_: Thumbnail, resized copy, derivative

**Delayed Queue**:
A pending variation request persisted alongside the target path until the variation is requested over HTTP or processed in batch.
_Avoid_: Background job, pending resize

**LQIP (Low-Quality Image Placeholder)**:
A compact blurred inline preview representing an image while the full variation loads.
_Avoid_: Blurhash, tiny preview, skeleton image

### Visual Analysis

**Color Metadata**:
The extracted dominant color, color palette, and Vibrant/Muted theme swatches associated with an original image.
_Avoid_: Color profile, swatch set

**Perceptual Hash**:
A compact hexadecimal fingerprint of an image's visual features whose Hamming distance to another fingerprint measures visual similarity.
_Avoid_: Checksum, cryptographic hash, file digest

**Hamming Distance**:
The number of differing bits between two perceptual hashes of equal length, where lower values indicate higher visual similarity.
_Avoid_: Similarity score, diff count

**Sidecar Cache**:
A companion metadata file stored alongside an original image that persists computed visual analysis results across requests and is purged when variations are cleared.
_Avoid_: Temp file, metadata table
