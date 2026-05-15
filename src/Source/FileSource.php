<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Source;

use Survos\TuiExtrasBundle\Contract\TuiTableSourceInterface;
use Survos\TuiExtrasBundle\Model\TuiColumn;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Read-only file browser backed by Symfony Finder.
 *
 * Loads all matching files into memory on first access, then applies
 * search/sort/pagination in-memory. Suitable for typical project directories
 * (tens of thousands of files); for filesystem roots or enormous trees, set
 * $maxDepth or pass name patterns to keep the result set manageable.
 *
 * .gitignore is respected by default via Finder::ignoreVCSIgnored(true).
 * .git/, .svn/, and friends are always excluded via ignoreVCS(true).
 *
 * Search matches against the full relative pathname (directory + filename),
 * so typing "Command/Browse" narrows to that path prefix.
 */
final class FileSource implements TuiTableSourceInterface
{
    /** @var TuiColumn[] */
    private readonly array $columns;

    /** @var array<array<string,mixed>>|null lazily populated */
    private ?array $allRows = null;

    /**
     * @param string   $directory   Root directory to scan
     * @param bool     $gitignore   Respect .gitignore files (default: true)
     * @param string[] $patterns    Filename glob patterns, e.g. ['*.php', '*.yaml']
     * @param string[] $excludeDirs Directory names to skip, e.g. ['var', 'node_modules']
     * @param int|null $maxDepth    Max recursion depth (null = unlimited)
     */
    public function __construct(
        private readonly string $directory,
        private readonly bool $gitignore = true,
        private readonly array $patterns = [],
        private readonly array $excludeDirs = [],
        private readonly ?int $maxDepth = null,
    ) {
        $this->columns = [
            new TuiColumn(key: 'name', label: 'Name', width: 32, sortable: true, searchable: true, order: 10),
            new TuiColumn(key: 'extension', label: 'Ext', width: 6, sortable: true, searchable: false, order: 20),
            new TuiColumn(key: 'size', label: 'Size', width: 9, sortable: true, format: 'bytes', order: 30),
            new TuiColumn(key: 'modified', label: 'Modified', width: 17, sortable: true, format: 'datetime', order: 40),
            new TuiColumn(key: 'path', label: 'Path', width: 0, sortable: true, searchable: true, order: 50),
        ];
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
        $all = $this->load();

        if (null === $search || '' === $search) {
            return $all;
        }

        $needle = strtolower($search);

        return array_values(array_filter(
            $all,
            static function (array $row) use ($needle): bool {
                // search matches name OR full relative path
                return str_contains(strtolower($row['name']), $needle)
                    || str_contains(strtolower((string) $row['path']), $needle);
            },
        ));
    }

    /** @return array<array<string,mixed>> */
    private function load(): array
    {
        if (null !== $this->allRows) {
            return $this->allRows;
        }

        $finder = new Finder();
        $finder
            ->files()
            ->in($this->directory)
            ->ignoreVCS(true)
            ->ignoreDotFiles(false)
            ->ignoreUnreadableDirs()
            ->sortByName();

        if ($this->gitignore) {
            $finder->ignoreVCSIgnored(true);
        }

        if ([] !== $this->patterns) {
            $finder->name($this->patterns);
        }

        if ([] !== $this->excludeDirs) {
            $finder->exclude($this->excludeDirs);
        }

        if (null !== $this->maxDepth) {
            $finder->depth('<= '.$this->maxDepth);
        }

        $rows = [];
        /** @var SplFileInfo $file */
        foreach ($finder as $file) {
            $rows[] = [
                'name' => $file->getFilename(),
                'extension' => $file->getExtension(),
                'size' => $file->getSize(),
                'modified' => (new \DateTimeImmutable())->setTimestamp($file->getMTime()),
                'path' => $file->getRelativePath() ?: '.',
            ];
        }

        return $this->allRows = $rows;
    }
}
