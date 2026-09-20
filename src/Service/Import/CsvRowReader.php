<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Service\TextInput;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Reads an uploaded CSV into a header row + iterable of header-keyed rows. Same
 * SplFileObject::READ_CSV approach every existing CSV importer in this codebase already uses
 * (e.g. Number1ProductImportBundle\Service\ProductCsvTransformer::parseRows()) — kept here once so
 * ImportRunner doesn't hand-roll it per import.
 */
final class CsvRowReader
{
    /**
     * trim() plus TextInput::stripControlCharacters() (#303/#395) rather than a bare trim() — every
     * cell this reads ends up stored verbatim in ImportRunRow::$inputData/$mappedData and rendered
     * later on the admin detail page, the exact "audit-trail snapshot" scenario that helper exists
     * for. Kept as `string`, not TextInput::nullableString()'s `?string`: a blank cell has to stay
     * '' here, since these values become array keys/values other code reads as plain strings.
     */
    private function clean(mixed $cell): string
    {
        return trim(TextInput::stripControlCharacters((string) $cell));
    }

    /** @return list<string> headers in file order, exactly as they appear (not normalized) */
    public function headers(UploadedFile $file): array
    {
        $splFile = new \SplFileObject($file->getPathname());
        $splFile->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        $splFile->setCsvControl(',');

        foreach ($splFile as $row) {
            if (\is_array($row) && $row !== [null]) {
                return array_map($this->clean(...), $row);
            }
        }

        return [];
    }

    /**
     * @return iterable<array<string, string>> header => cell value, one per data row (header row
     *                                          itself excluded)
     */
    public function rows(UploadedFile $file): iterable
    {
        $splFile = new \SplFileObject($file->getPathname());
        $splFile->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        $splFile->setCsvControl(',');

        $headers = null;
        foreach ($splFile as $row) {
            if (!\is_array($row) || $row === [null]) {
                continue;
            }

            if ($headers === null) {
                $headers = array_map($this->clean(...), $row);
                continue;
            }

            $mapped = [];
            foreach ($headers as $i => $header) {
                $mapped[$header] = $this->clean($row[$i] ?? '');
            }

            if ($mapped === [] || array_filter($mapped, static fn (string $v): bool => $v !== '') === []) {
                continue;
            }

            yield $mapped;
        }
    }
}
