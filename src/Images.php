<?php

namespace ErnestDefoe\Stances;

use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Psr\Http\Message\UploadedFileInterface;

/**
 * This-or-That pictures, kept on the forum's assets disk under stances/: scaled down to 640px and
 * re-encoded as WebP, so whatever was uploaded (a 12 MB phone photo, an image with junk appended)
 * is never what's served. Deleted with their block.
 */
class Images
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_PIXELS = 25_000_000;
    private const DIR = 'stances/';

    private Filesystem $disk;

    public function __construct(Factory $files, private readonly ImageManager $images)
    {
        $this->disk = $files->disk('flarum-assets');
    }

    public function store(UploadedFileInterface $file): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK || $file->getSize() > self::MAX_BYTES
            || ! in_array($file->getClientMediaType(), ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new Refusal('stances_bad_image');
        }

        $bytes = $file->getStream()->getContents();
        // Checked from the header before decoding: a small file can declare a huge canvas.
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw new Refusal('stances_image_too_big');
        }
        // About 5 bytes a pixel while decoding and scaling; refuse rather than hit the memory limit.
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit > 0 && memory_get_usage(true) + $size[0] * $size[1] * 5 > $limit) {
            throw new Refusal('stances_image_too_big');
        }

        try {
            // First frame only: decoding every frame of a long animation can exhaust memory.
            $image = (new ImageManager($this->images->driver()::class, decodeAnimation: false))->read($bytes);
        } catch (\Throwable) {
            throw new Refusal('stances_bad_image');
        }

        $image->scaleDown(640, 640);
        $path = self::DIR.Str::lower(Str::random(20)).'.webp';
        $this->disk->put($path, (string) $image->toWebp(82));

        return $path;
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $n = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    public static function ownerKey(string $path): string
    {
        return 'stances-image-'.basename($path, '.webp');
    }

    /** Only our own folder: a path from anywhere else is never touched. */
    public function owns(?string $path): bool
    {
        return is_string($path) && preg_match('#^stances/[a-z0-9]{20}\.webp$#', $path) === 1;
    }

    /** @param iterable<Block> $blocks */
    public function forget(iterable $blocks): void
    {
        foreach ($blocks as $block) {
            foreach ($block->images ?? [] as $path) {
                if ($this->owns($path) && $this->disk->exists($path)) {
                    $this->disk->delete($path);
                }
            }
        }
    }
}
