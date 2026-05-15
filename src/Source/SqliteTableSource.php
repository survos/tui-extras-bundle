<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Source;

use Survos\TuiExtrasBundle\Contract\TuiTableSourceInterface;
use Survos\TuiExtrasBundle\Model\TuiColumn;

/**
 * Table source backed by a SQLite PDO connection with optional FTS5 full-text search.
 *
 * FTS5 usage:
 *   CREATE VIRTUAL TABLE fts_items USING fts5(col1, col2, content=items, content_rowid=id);
 *   INSERT INTO fts_items(fts_items) VALUES('rebuild');
 *
 * When an ftsTable is provided, search queries use FTS5 MATCH with prefix matching (query*)
 * and results are ranked by relevance. Falls back to LIKE when FTS5 throws.
 *
 * FolioRowTableSource in survos/folio-bundle is the folio-specific implementation of this pattern.
 */
final class SqliteTableSource implements TuiTableSourceInterface
{
    /** @var TuiColumn[] */
    private readonly array $columns;

    /**
     * @param string      $table     Main data table name
     * @param string|null $ftsTable  FTS5 virtual table name (null = LIKE fallback)
     * @param TuiColumn[] $columns   Leave empty to auto-derive from PRAGMA table_info
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $table,
        private readonly ?string $ftsTable = null,
        array $columns = [],
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->columns = [] === $columns ? $this->deriveColumns() : $columns;
    }

    public function columns(): array
    {
        return $this->columns;
    }

    public function count(?string $search = null): int
    {
        if (null !== $search && '' !== $search) {
            if (null !== $this->ftsTable) {
                try {
                    $stmt = $this->pdo->prepare(
                        "SELECT COUNT(*) FROM {$this->table} WHERE rowid IN (SELECT rowid FROM {$this->ftsTable} WHERE {$this->ftsTable} MATCH ?)",
                    );
                    $stmt->execute([$this->ftsQuery($search)]);

                    return (int) $stmt->fetchColumn();
                } catch (\Exception) {
                    // fall through to LIKE
                }
            }

            [$where, $params] = $this->likeWhere($search);
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->table} WHERE {$where}");
            $stmt->execute($params);

            return (int) $stmt->fetchColumn();
        }

        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->table}")->fetchColumn();
    }

    public function rows(
        int $offset = 0,
        int $limit = 25,
        ?string $search = null,
        ?string $sortBy = null,
        string $sortDir = 'ASC',
    ): iterable {
        $safeDir = 'DESC' === $sortDir ? 'DESC' : 'ASC';

        if (null !== $search && '' !== $search) {
            if (null !== $this->ftsTable) {
                try {
                    // FTS5: join back to content table, rank by relevance, then sort
                    $orderClause = null !== $sortBy
                        ? "t.".self::quoteId($sortBy)." {$safeDir}"
                        : 'fts.rank';
                    $sql = "SELECT t.* FROM {$this->ftsTable} AS fts
                            JOIN {$this->table} AS t ON t.rowid = fts.rowid
                            WHERE fts.{$this->ftsTable} MATCH ?
                            ORDER BY {$orderClause}
                            LIMIT ? OFFSET ?";
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute([$this->ftsQuery($search), $limit, $offset]);

                    return $stmt->fetchAll();
                } catch (\Exception) {
                    // fall through to LIKE
                }
            }

            [$where, $params] = $this->likeWhere($search);
            $orderClause = null !== $sortBy ? 'ORDER BY '.self::quoteId($sortBy)." {$safeDir}" : '';
            $sql = "SELECT * FROM {$this->table} WHERE {$where} {$orderClause} LIMIT ? OFFSET ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([...$params, $limit, $offset]);

            return $stmt->fetchAll();
        }

        $orderClause = null !== $sortBy ? 'ORDER BY '.self::quoteId($sortBy)." {$safeDir}" : '';
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} {$orderClause} LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);

        return $stmt->fetchAll();
    }

    /** @return TuiColumn[] */
    private function deriveColumns(): array
    {
        $stmt = $this->pdo->query("PRAGMA table_info({$this->table})");
        $cols = [];
        foreach ($stmt->fetchAll() as $row) {
            $cols[] = new TuiColumn(
                key: $row['name'],
                label: ucwords(str_replace(['_', '-'], ' ', $row['name'])),
                sortable: true,
                searchable: \in_array(strtolower($row['type']), ['text', 'varchar', ''], true),
                order: (int) $row['cid'] * 10,
            );
        }

        return $cols;
    }

    /** Wrap query for FTS5 prefix match. Last token gets * suffix. */
    private function ftsQuery(string $search): string
    {
        $search = trim($search);
        // Escape FTS5 special chars except space
        $search = preg_replace('/["\(\)\:\^]/', ' ', $search);
        $tokens = array_filter(explode(' ', $search));
        if ([] === $tokens) {
            return '""';
        }
        // prefix-match every token
        return implode(' ', array_map(static fn (string $t) => $t.'*', $tokens));
    }

    /** @return array{string, array<mixed>} [WHERE clause, params] */
    private function likeWhere(string $search): array
    {
        $searchable = array_filter($this->columns, static fn (TuiColumn $c) => $c->searchable);
        if ([] === $searchable) {
            $searchable = $this->columns;
        }
        $clauses = array_map(
            static fn (TuiColumn $c) => self::quoteId($c->key).' LIKE ?',
            array_values($searchable),
        );
        $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
        $params = array_fill(0, \count($clauses), $needle);

        return [implode(' OR ', $clauses), $params];
    }

    private static function quoteId(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
