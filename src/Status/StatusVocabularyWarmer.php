<?php

declare(strict_types=1);

namespace App\Status;

use App\Contract\Status\StatusVocabularyLoaderInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Makes a broken status vocabulary a failed DEPLOY rather than a failed request (handoff section 5).
 *
 * {@see StatusVocabularyLoader} does all its checking in its constructor — a key claimed by two
 * providers, an empty label, a malformed definition. Being built from a
 * `!tagged_iterator`, though, the loader is lazy: without this, the first thing to notice a
 * collision would be whichever live request happened to touch a status first.
 *
 * Warming the cache instantiates it, so the throw lands at `cache:warmup` — a step somebody is
 * watching, with the output in front of them — instead of on a customer's checkout.
 *
 * The handoff names this layer explicitly and says why it may be a hard failure here when
 * hydration (section 7) must stay soft: *"Failing here stops a deploy while somebody is watching,
 * rather than killing a live request."*
 *
 * ## What it does NOT check yet
 *
 * Section 5's *"every enum case must exist in its vocabulary"*. That check needs a link from an enum
 * to a vocabulary key, and until a document implements `HasStatus::statusVocabulary()` there is no
 * link to derive it from — only a hand-kept list, which section 5 rules out. It arrives with the
 * documents, derived from them, in the stage that wires them.
 */
final class StatusVocabularyWarmer implements CacheWarmerInterface
{
    public function __construct(private readonly StatusVocabularyLoaderInterface $loader)
    {
    }

    /**
     * Always run, including when the cache is warmed as part of a build with no runtime behind it:
     * the whole value of this class is that it fails before anything serves a request.
     */
    public function isOptional(): bool
    {
        return false;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        // Constructing the loader is the check. Touching every vocabulary makes a provider that
        // builds its arrays lazily fail here too, rather than on first use.
        foreach ($this->loader->getVocabularies() as $key => $vocabulary) {
            // Labels and slugs are the whole of a vocabulary now. The loop that used to walk
            // `transitionsFrom()` for every slug went with the transitions table itself — ruling R4.
            $vocabulary->labels();
            $vocabulary->slugs();

            unset($key);
        }

        return [];
    }
}
