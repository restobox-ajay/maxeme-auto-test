<?php

declare(strict_types=1);

namespace TechnicalDocsBundle\Twig;

use App\Repository\BundleStatusRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TechnicalDocsBundle\Docs\DocsRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class TechnicalDocsNavExtension extends AbstractExtension
{
    private const SOURCE = 'TechnicalDocsBundle';

    public function __construct(
        private readonly DocsRepository $docsRepository,
        private readonly BundleStatusRepository $bundleStatusRepo,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('technical_docs_items', [$this, 'getItems']),
        ];
    }

    /** @return array<int, array{label: string, path: string, url: string}> */
    public function getItems(): array
    {
        if (!$this->bundleStatusRepo->isActive(self::SOURCE)) {
            return [];
        }

        $items = [];
        foreach ($this->docsRepository->list() as $relativePath) {
            $items[] = [
                'label' => substr($relativePath, 0, -3), // strip ".md"
                'path'  => $relativePath,
                'url'   => $this->urlGenerator->generate('admin_technical_docs_view', ['path' => $relativePath]),
            ];
        }

        return $items;
    }
}
