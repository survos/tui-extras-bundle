<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Contract;

use Survos\TuiExtrasBundle\Model\TuiColumn;

/**
 * Data source contract for DataTableWidget.
 *
 * Deliberately isomorphic to Doctrine\Common\Collections\Selectable so it can be
 * replaced or aliased once doctrine/collections 3.x reaches Symfony 8.1 compatibility.
 *
 * Implementations:
 *   - ArrayTableSource    — in-memory array, ships in this bundle
 *   - DoctrineTableSource — QueryBuilder-backed, ships in this bundle (requires doctrine/dbal)
 *   - FolioRowTableSource — folio SQLite + FTS5 search, ships in survos/folio-bundle
 *   - JsonlTableSource    — streaming JSONL with memory filter, ships in survos/jsonl-bundle
 */
interface TuiTableSourceInterface
{
    /** @return TuiColumn[] ordered by TuiColumn::$order */
    public function columns(): array;

    public function count(?string $search = null): int;

    /**
     * @return iterable<array<string,mixed>> rows as associative arrays keyed by column key
     */
    public function rows(
        int $offset = 0,
        int $limit = 25,
        ?string $search = null,
        ?string $sortBy = null,
        string $sortDir = 'ASC',
    ): iterable;
}
