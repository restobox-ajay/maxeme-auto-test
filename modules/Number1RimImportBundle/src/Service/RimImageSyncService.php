<?php

declare(strict_types=1);

namespace Number1RimImportBundle\Service;

use App\Entity\ProductCore;
use App\Entity\ProductImage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads and reconciles a product's images from the rim API's Image URL array into
 * public/uploads/products/ — App\Entity\ProductImage::filename expects a local file, unlike the
 * reference app's own Image model which just stores the remote URL string as-is (see
 * RIM_API_IMPORT_PLAN.md §3/§4).
 *
 * The local filename is a deterministic hash of the source URL (rim-api-<sha1>.<ext>), not a new
 * ProductImage column — that keeps a re-sync idempotent (recompute the same expected filename set,
 * skip anything already on disk) without any schema change. Reconciliation is scoped to filenames
 * with the rim-api- prefix only, so an image an admin uploaded by hand on the same product is
 * never touched.
 */
final class RimImageSyncService
{
    private const PREFIX = 'rim-api-';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    /** @param list<string> $urls */
    public function sync(ProductCore $product, array $urls, EntityManagerInterface $entityManager): void
    {
        $dir = $this->imagesDirectory();
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return;
        }

        /** @var array<string, string> filename => source url */
        $expected = [];
        foreach ($urls as $url) {
            $filename = $this->filenameForUrl($url);
            if ($filename !== null) {
                $expected[$filename] = $url;
            }
        }

        foreach ($expected as $filename => $url) {
            $path = $dir . '/' . $filename;
            if (!is_file($path)) {
                $this->download($url, $path);
            }
        }

        $this->reconcile($product, $entityManager, array_keys($expected));
    }

    /** @param list<string> $expectedFilenames */
    private function reconcile(ProductCore $product, EntityManagerInterface $entityManager, array $expectedFilenames): void
    {
        $existingBundleManaged = [];
        $hasPrimary = false;
        foreach ($product->getImages() as $image) {
            if ($image->isPrimaryImage()) {
                $hasPrimary = true;
            }
            if (str_starts_with($image->getFilename(), self::PREFIX)) {
                $existingBundleManaged[$image->getFilename()] = $image;
            }
        }

        $sortOrder = 0;
        foreach ($expectedFilenames as $filename) {
            if (isset($existingBundleManaged[$filename])) {
                unset($existingBundleManaged[$filename]); // still wanted — leave it alone
                $sortOrder++;
                continue;
            }

            if (!is_file($this->imagesDirectory() . '/' . $filename)) {
                // Download failed (bad URL, network error) — don't create a row pointing at a
                // file that doesn't exist.
                continue;
            }

            $image = (new ProductImage())
                ->setProduct($product)
                ->setFilename($filename)
                ->setSortOrder($sortOrder)
                ->setPrimaryImage(!$hasPrimary);
            if (!$hasPrimary) {
                $hasPrimary = true;
            }
            $entityManager->persist($image);
            $product->addImage($image);
            $sortOrder++;
        }

        // Whatever's left in $existingBundleManaged was bundle-managed but no longer appears in
        // this pull — remove it (row + file).
        foreach ($existingBundleManaged as $filename => $staleImage) {
            $product->removeImage($staleImage);
            $entityManager->remove($staleImage);
            @unlink($this->imagesDirectory() . '/' . $filename);
        }
    }

    private function download(string $url, string $path): void
    {
        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpClientExceptionInterface) {
            // Best-effort: one broken/unreachable image URL shouldn't fail the whole product sync.
            return;
        }

        if ($status < 200 || $status >= 300 || $content === '') {
            return;
        }

        @file_put_contents($path, $content);
    }

    private function filenameForUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $ext = is_string($path) ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
        $ext = preg_match('/^[a-z0-9]{1,5}$/', $ext) === 1 ? $ext : 'jpg';

        return self::PREFIX . sha1($url) . '.' . $ext;
    }

    private function imagesDirectory(): string
    {
        return $this->projectDir . '/public/uploads/products';
    }
}
