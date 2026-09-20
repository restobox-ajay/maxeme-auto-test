<?php

declare(strict_types=1);

namespace NewsBundle\Hook;

use NewsBundle\Repository\NewsPostRepository;
use Twig\Environment;

final class NewsScrollerRenderer
{
    public function __construct(
        private readonly NewsPostRepository $newsPostRepo,
        private readonly Environment $twig,
    ) {}

    public function render(int $limit = 3): string
    {
        $posts = $this->newsPostRepo->findLatestPublished($limit);
        if ($posts === []) {
            return '';
        }

        return $this->twig->render('@News/_scroller.html.twig', ['posts' => $posts]);
    }
}
