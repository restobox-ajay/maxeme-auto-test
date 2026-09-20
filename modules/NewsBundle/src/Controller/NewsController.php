<?php

declare(strict_types=1);

namespace NewsBundle\Controller;

use NewsBundle\Entity\NewsPost;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NewsController extends AbstractController
{
    #[Route('/news/{id}', name: 'customer_news_view', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function view(NewsPost $post): Response
    {
        return $this->render('@News/view.html.twig', [
            'post' => $post,
        ]);
    }
}
