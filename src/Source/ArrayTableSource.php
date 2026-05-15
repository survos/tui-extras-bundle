<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Source;

use Survos\TuiExtrasBundle\Contract\TuiTableSourceInterface;
use Survos\TuiExtrasBundle\Model\TuiColumn;

/**
 * In-memory table source backed by an array of associative arrays.
 *
 * When no columns are provided, they are derived from the first row's keys.
 * Search scans all searchable columns (falls back to all columns if none marked searchable).
 */
final class ArrayTableSource implements TuiTableSourceInterface
{
    /** @var TuiColumn[] */
    private readonly array $columns;

    /**
     * @param array<array<string,mixed>> $data
     * @param TuiColumn[]                $columns leave empty to auto-derive from first row
     */
    public function __construct(
        private readonly array $data,
        array $columns = [],
    ) {
        if ([] === $columns && [] !== $data) {
            $columns = array_map(
                static fn (string $k) => TuiColumn::make($k),
                array_keys($data[0]),
            );
        }
        usort($columns, static fn (TuiColumn $a, TuiColumn $b) => $a->order <=> $b->order);
        $this->columns = $columns;
    }

    public function columns(): array
    {
        return $this->columns;
    }

    public function count(?string $search = null): int
    {
        return \count($this->filter($search));
    }

    public function rows(
        int $offset = 0,
        int $limit = 25,
        ?string $search = null,
        ?string $sortBy = null,
        string $sortDir = 'ASC',
    ): iterable {
        $rows = $this->filter($search);

        if (null !== $sortBy) {
            usort($rows, static fn (array $a, array $b) => 'DESC' === $sortDir
                ? ($b[$sortBy] ?? '') <=> ($a[$sortBy] ?? '')
                : ($a[$sortBy] ?? '') <=> ($b[$sortBy] ?? ''));
        }

        return \array_slice($rows, $offset, $limit);
    }

    /** @return array<array<string,mixed>> */
    private function filter(?string $search): array
    {
        if (null === $search || '' === $search) {
            return $this->data;
        }

        $needle = strtolower($search);
        $searchable = array_map(
            static fn (TuiColumn $c) => $c->key,
            array_filter($this->columns, static fn (TuiColumn $c) => $c->searchable),
        );
        if ([] === $searchable) {
            $searchable = array_map(static fn (TuiColumn $c) => $c->key, $this->columns);
        }

        return array_values(array_filter(
            $this->data,
            static function (array $row) use ($needle, $searchable): bool {
                foreach ($searchable as $key) {
                    if (str_contains(strtolower((string) ($row[$key] ?? '')), $needle)) {
                        return true;
                    }
                }
                return false;
            },
        ));
    }
}
