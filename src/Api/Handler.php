<?php

namespace ErnestDefoe\Stances\Api;

use ErnestDefoe\Stances\Block;
use Flarum\Api\JsonApi;
use Flarum\User\Exception\PermissionDeniedException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The actions with no JSON:API equivalent. A Refusal goes to Flarum's error handling (RefusalHandler). */
abstract class Handler implements RequestHandlerInterface
{
    /** @return array<string, mixed> */
    protected static function body(ServerRequestInterface $request): array
    {
        return (array) $request->getParsedBody();
    }

    /** The block as StanceResource's Show endpoint serializes it, so every answer looks the same to the store. */
    protected static function show(ServerRequestInterface $request, Block $block): ResponseInterface
    {
        return resolve(JsonApi::class)
            ->forResource(StanceResource::class)
            ->forEndpoint('show')
            ->handle($request->withMethod('GET')->withQueryParams(['id' => (string) $block->id])->withParsedBody(null));
    }

    protected static function deny(): never
    {
        throw new PermissionDeniedException();
    }
}
