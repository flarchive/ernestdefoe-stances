<?php

namespace ErnestDefoe\Stances\Http;

use Flarum\Frontend\Document;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The /stances page as the server sends it: a real title, canonical URL and description. No queries. */
class PageContent
{
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $document->title = (string) $this->translator->trans('ernestdefoe-stances.forum.page.title');
        $document->canonicalUrl = $this->url->to('forum')->route('stances');
        $document->meta['description'] = (string) $this->translator->trans('ernestdefoe-stances.forum.page.description');

        return $document;
    }
}
