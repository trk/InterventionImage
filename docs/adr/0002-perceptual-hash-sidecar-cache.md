# Dedicated Sidecar Cache for Perceptual Hashes

Perceptual image hashes (`dhash`, `phash`, `ahash`, `bhash`) are cached in a dedicated `{basename}.hashes.json` sidecar file rather than being merged into `{basename}.colors.json`. Color extraction (`ColorExtractor`) and perceptual hashing (`Intervention\ImageHash`) serve independent use cases and are invoked at different times; separating their sidecar files prevents partial schema writes and allows operators to invalidate hash caches independently via `clearVariations(..., ['hashes' => true])` and `wire image:clear --hashes-only`.
