<?php

namespace ErnestDefoe\Stances\Api;

use ErnestDefoe\Stances\Access;
use ErnestDefoe\Stances\Images;
use ErnestDefoe\Stances\Refusal;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST /api/stances/images   multipart "image": a This-or-That picture. Returns the path the
 * dialog sends with the block, and its URL for the preview. One a block never claims is removed by
 * stances:prune.
 */
class ImageController extends Handler
{
    public const PER_HOUR = 30;

    public function __construct(
        private readonly Images $images,
        private readonly Factory $files,
        private readonly Repository $cache,
    ) {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        if (! Access::canCreateAnywhere($actor)) {
            self::deny();
        }

        $file = $request->getUploadedFiles()['image'] ?? null;
        if (! $file instanceof UploadedFileInterface) {
            throw new Refusal('stances_bad_image');
        }

        // 30 pictures an hour per member is plenty for a dialog that takes two. A fixed window:
        // add() never refreshes the expiry, and increment() is atomic.
        $key = 'stances-uploads-'.$actor->id;
        $this->cache->add($key, 0, 3600);
        if ((int) $this->cache->increment($key) > self::PER_HOUR) {
            throw new Refusal('stances_too_many_uploads', 429);
        }
        $path = $this->images->store($file);
        // Only the member who uploaded a picture can put it on a block.
        $this->cache->put(Images::ownerKey($path), $actor->id, 86400);

        return new JsonResponse(['data' => ['path' => $path, 'url' => $this->files->disk('flarum-assets')->url($path)]], 201);
    }
}
