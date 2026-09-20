<?php

declare(strict_types=1);

namespace App\Status;

use App\Contract\Status\StatusVocabularyLoaderInterface;
use App\Contract\Status\StatusVocabularyProviderInterface;

/**
 * Collects every provider's vocabularies into one lookup (handoff sections 3 and 4).
 *
 * The config-backed FIRST implementation of {@see StatusVocabularyLoaderInterface}. It reads
 * hardcoded arrays, and that is the decision rather than a placeholder to apologise for — owner:
 * *"the loader class just loads from a bunch of pre-written hard coded arrays for now at least."*
 * No database table, no settings screen and no migration in phase one: the whole point of putting a
 * loader in front of the arrays is that swapping the source later changes one implementation and no
 * call sites, so there is nothing to gain from building the table before anyone can edit it.
 *
 * ## Collisions fail at boot, loudly
 *
 * Two providers claiming `invoice` throw from this constructor. Not a log line, not last-one-wins:
 * a vocabulary quietly replaced by another bundle's changes what transitions are legal on a live
 * document, and the symptom — a move that used to work now being refused — points nowhere near the
 * cause. The message names both providers and the key, because that is the whole of what the person
 * reading the stack trace needs.
 *
 * Being in the CONSTRUCTOR is the load-bearing part. The service is built from a `!tagged_iterator`,
 * so the throw lands wherever the loader is first instantiated; {@see StatusVocabularyWarmer} makes
 * that moment cache warm, which is a deploy step somebody is watching rather than a live request.
 *
 * ## Providers are not gated on their bundle being Active
 *
 * Deliberately, and unlike `DocumentPrefixCatalogue`. See
 * {@see StatusVocabularyProviderInterface} for the ruling and for where the gate does belong.
 */
final class StatusVocabularyLoader implements StatusVocabularyLoaderInterface
{
    /** @var array<string, StatusVocab> */
    private array $vocabularies = [];

    /** @var array<string, string> vocabulary key => the provider class that declared it */
    private array $declaredBy = [];

    /** @param iterable<StatusVocabularyProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            if (!$provider instanceof StatusVocabularyProviderInterface) {
                continue;
            }

            foreach ($provider->statusVocabularies() as $key => $definition) {
                if (isset($this->declaredBy[$key])) {
                    throw new \LogicException(sprintf(
                        'Two providers both declare the status vocabulary "%s": %s and %s. A vocabulary has exactly'
                            . ' one owner — letting the last one registered win would silently change which'
                            . ' transitions are legal, with nothing to point at. Rename one key, or delete one'
                            . ' provider.',
                        $key,
                        $this->declaredBy[$key],
                        $provider::class,
                    ));
                }

                $this->declaredBy[$key] = $provider::class;
                $this->vocabularies[$key] = StatusVocab::fromArray($key, $definition);
            }
        }
    }

    public function getVocabulary(string $key): StatusVocab
    {
        if (!isset($this->vocabularies[$key])) {
            throw new \LogicException(sprintf(
                'No status vocabulary is registered for "%s". Registered: %s. A vocabulary key is written in source,'
                    . ' so this is a typo or a provider that was never tagged "app.status_vocabulary_provider".',
                $key,
                $this->vocabularies === [] ? '(none)' : implode(', ', array_keys($this->vocabularies)),
            ));
        }

        return $this->vocabularies[$key];
    }

    public function hasVocabulary(string $key): bool
    {
        return isset($this->vocabularies[$key]);
    }

    public function getVocabularies(): array
    {
        return $this->vocabularies;
    }

    /** The provider class that declared $key, for a diagnostic that has to name a culprit. */
    public function declaredBy(string $key): ?string
    {
        return $this->declaredBy[$key] ?? null;
    }

    /**
     * Refuses, in this implementation.
     *
     * Customisation is real and is coming; storing it is the database-backed loader's job. Accepting
     * the write here and holding it for the length of one request would look like it worked, and the
     * customer's relabelled status would be gone by the next page — a silent loss of a saved edit,
     * which is worse than a refusal that says which implementation does support it.
     */
    public function setVocabulary(string $key, array $definition): void
    {
        throw new \LogicException(sprintf(
            'Status vocabularies cannot be edited at runtime in this build: "%s" is declared by %s, in code. This is'
                . ' the config-backed first implementation of StatusVocabularyLoaderInterface and it has nowhere to'
                . ' store a change. The database-backed implementation is what makes customer-defined statuses'
                . ' possible.',
            $key,
            $this->declaredBy[$key] ?? 'no provider',
        ));
    }
}
