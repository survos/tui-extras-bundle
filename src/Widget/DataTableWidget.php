<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Widget;

use Survos\TuiExtrasBundle\Contract\MouseAwareInterface;
use Survos\TuiExtrasBundle\Contract\TuiTableSourceInterface;
use Survos\TuiExtrasBundle\Enum\MouseButton;
use Survos\TuiExtrasBundle\Event\MouseEvent;
use Survos\TuiExtrasBundle\Event\TableRowChangeEvent;
use Survos\TuiExtrasBundle\Event\TableRowEvent;
use Survos\TuiExtrasBundle\Model\TuiColumn;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\QuitableTrait;

/**
 * Paged, searchable, sortable data table widget.
 *
 * Styled sub-elements (customize via StyleSheet):
 *   ::header      — column header row
 *   ::separator   — line between header and data
 *   ::selected    — highlighted cursor row
 *   ::statusbar   — bottom status/hint line
 *   ::search      — active search input line
 *   ::no-match    — empty state message
 *
 * Keybindings (normal mode):
 *   ↑/k  row up       ↓/j  row down
 *   PgUp/←  page up   PgDn/→  page down
 *   /    enter search  Esc  clear search
 *   Enter  dispatch SelectEvent   q  quit
 *
 * Keybindings (search mode):
 *   any printable char  append to query
 *   Backspace           remove last char
 *   Enter               commit search
 *   Esc                 cancel (restore previous query)
 */
class DataTableWidget extends AbstractWidget implements FocusableInterface, MouseAwareInterface
{
    use FocusableTrait;
    use KeybindingsTrait;
    use QuitableTrait;

    private int $offset = 0;
    private int $limit = 25;
    private ?string $search = null;
    private ?string $sortBy = null;
    private string $sortDir = 'ASC';
    private int $selectedRow = 0;

    private bool $searchMode = false;
    private string $searchBuffer = '';
    private ?string $savedSearch = null; // restored on Escape

    /** @var array<array<string,mixed>> */
    private array $currentRows = [];
    private int $totalCount = 0;
    private bool $dataDirty = true;

    public function __construct(
        private readonly TuiTableSourceInterface $source,
    ) {}

    /** @return array<string,mixed>|null */
    public function getSelectedRow(): ?array
    {
        return $this->currentRows[$this->selectedRow] ?? null;
    }

    public function onSelect(callable $callback): static
    {
        return $this->on(TableRowEvent::class, $callback);
    }

    public function onRowChange(callable $callback): static
    {
        return $this->on(TableRowChangeEvent::class, $callback);
    }

    public function render(RenderContext $context): array
    {
        $totalRows = $context->getRows();
        $cols = array_values(array_filter(
            $this->source->columns(),
            static fn (TuiColumn $c) => $c->visible,
        ));

        $footerLines = 1;
        $headerLines = 2; // header + separator
        $searchLines = $this->searchMode ? 1 : 0;
        $dataLines = max(1, $totalRows - $headerLines - $footerLines - $searchLines);

        // Re-fetch when limit changes (terminal resize) or data is dirty
        if ($dataLines !== $this->limit || $this->dataDirty) {
            $this->limit = $dataLines;
            $this->totalCount = $this->source->count($this->search);
            $this->currentRows = iterator_to_array(
                $this->source->rows($this->offset, $this->limit, $this->search, $this->sortBy, $this->sortDir),
                false,
            );
            $this->selectedRow = min($this->selectedRow, max(0, \count($this->currentRows) - 1));
            $this->dataDirty = false;
        }

        $columns = $context->getColumns();
        $widths = $this->computeWidths($cols, $columns);
        $lines = [];

        $lines[] = $this->renderHeader($cols, $widths, $columns);
        $lines[] = $this->renderSeparator($widths, $columns);

        if ([] === $this->currentRows) {
            $msg = null !== $this->search ? "No results for \"{$this->search}\"" : 'No data';
            $lines[] = $this->applyElement('no-match', '  '.AnsiUtils::truncateToWidth($msg, $columns - 4, ''));
            for ($i = 1; $i < $dataLines; ++$i) {
                $lines[] = '';
            }
        } else {
            foreach ($this->currentRows as $i => $row) {
                $lines[] = $this->renderDataRow($row, $cols, $widths, $columns, $i === $this->selectedRow);
            }
            for ($i = \count($this->currentRows); $i < $dataLines; ++$i) {
                $lines[] = '';
            }
        }

        if ($this->searchMode) {
            $searchLine = '/ '.$this->searchBuffer.'▌';
            $lines[] = $this->applyElement('search', AnsiUtils::truncateToWidth($searchLine, $columns - 2, ''));
        }

        $lines[] = $this->renderStatusBar($columns);

        return $lines;
    }

    public function handleInput(string $data): void
    {
        if ($this->searchMode) {
            $this->handleSearchInput($data);

            return;
        }

        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'row_up')) {
            $this->moveSelection(-1);

            return;
        }

        if ($kb->matches($data, 'row_down')) {
            $this->moveSelection(1);

            return;
        }

        if ($kb->matches($data, 'page_up')) {
            $this->offset = max(0, $this->offset - $this->limit);
            $this->selectedRow = 0;
            $this->dataDirty = true;
            $this->invalidate();

            return;
        }

        if ($kb->matches($data, 'page_down')) {
            if ($this->offset + $this->limit < $this->totalCount) {
                $this->offset += $this->limit;
                $this->selectedRow = 0;
                $this->dataDirty = true;
            }
            $this->invalidate();

            return;
        }

        if ($kb->matches($data, 'select')) {
            $row = $this->getSelectedRow();
            if (null !== $row) {
                $this->dispatch(new TableRowEvent($this, $row));
            }

            return;
        }

        if ($kb->matches($data, 'search')) {
            $this->searchMode = true;
            $this->savedSearch = $this->search;
            $this->searchBuffer = $this->search ?? '';
            $this->invalidate();

            return;
        }

        if ($kb->matches($data, 'cancel')) {
            if (null !== $this->search) {
                $this->search = null;
                $this->offset = 0;
                $this->dataDirty = true;
                $this->invalidate();
            }

            return;
        }

        if ($kb->matches($data, 'sort_toggle') && null !== $this->sortBy) {
            $this->sortDir = 'ASC' === $this->sortDir ? 'DESC' : 'ASC';
            $this->offset = 0;
            $this->dataDirty = true;
            $this->invalidate();

            return;
        }

        if ($kb->matches($data, 'quit')) {
            $this->dispatchQuit();
        }
        // all other keys are silently ignored
    }

    public function handleMouse(MouseEvent $event): void
    {
        match ($event->button) {
            MouseButton::WheelUp => $this->moveSelection(-1),
            MouseButton::WheelDown => $this->moveSelection(1),
            default => null,
        };
    }

    private function moveSelection(int $delta): void
    {
        if ($delta < 0) {
            if ($this->selectedRow > 0) {
                --$this->selectedRow;
            } elseif ($this->offset > 0) {
                $this->offset = max(0, $this->offset - $this->limit);
                $this->selectedRow = max(0, $this->limit - 1);
                $this->dataDirty = true;
            }
        } elseif ($delta > 0) {
            if ($this->selectedRow < \count($this->currentRows) - 1) {
                ++$this->selectedRow;
            } elseif ($this->offset + $this->limit < $this->totalCount) {
                $this->offset += $this->limit;
                $this->selectedRow = 0;
                $this->dataDirty = true;
            }
        }

        $this->dispatchSelectionChange();
        $this->invalidate();
    }

    /** @return array<string,string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'row_up' => [Key::UP, 'k'],
            'row_down' => [Key::DOWN, 'j'],
            'page_up' => [Key::PAGE_UP, Key::LEFT],
            'page_down' => [Key::PAGE_DOWN, Key::RIGHT],
            'select' => [Key::ENTER, Key::SPACE],
            'search' => ['/'],
            'cancel' => [Key::ESCAPE],
            'sort_toggle' => ['s'],
            'quit' => ['q', Key::ctrl('c')],
            'search_backspace' => [Key::BACKSPACE, Key::DELETE],
        ];
    }

    private function handleSearchInput(string $data): void
    {
        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'cancel')) {
            // Escape: abandon, restore search to what it was before entering search mode
            $this->searchMode = false;
            $this->search = $this->savedSearch;
            $this->searchBuffer = '';
            $this->offset = 0;
            $this->dataDirty = true;
            $this->invalidate();

            return;
        }

        if ($kb->matches($data, 'select')) {
            // Enter: commit and close the search bar (filter already applied live)
            $this->searchMode = false;
            $this->invalidate();

            return;
        }

        if ($kb->matches($data, 'search_backspace')) {
            $this->searchBuffer = mb_substr($this->searchBuffer, 0, -1);
            $this->applyLiveSearch();

            return;
        }

        // Append printable character and apply immediately
        if (1 === \strlen($data) && \ord($data) >= 32) {
            $this->searchBuffer .= $data;
            $this->applyLiveSearch();
        }
    }

    private function applyLiveSearch(): void
    {
        $this->search = '' !== $this->searchBuffer ? $this->searchBuffer : null;
        $this->offset = 0;
        $this->dataDirty = true;
        $this->invalidate();
    }

    /** @param TuiColumn[] $cols */
    private function renderHeader(array $cols, array $widths, int $columns): string
    {
        $parts = [];
        foreach ($cols as $i => $col) {
            $label = mb_strtoupper($col->label);
            if ($col->sortable && $col->key === $this->sortBy) {
                $label .= 'ASC' === $this->sortDir ? ' ▲' : ' ▼';
            }
            $parts[] = AnsiUtils::truncateToWidth(
                str_pad($label, $widths[$i]),
                $widths[$i],
                '',
            );
        }

        return $this->applyElement('header', AnsiUtils::truncateToWidth(
            ' '.implode('  ', $parts),
            $columns,
            '',
        ));
    }

    /** @param int[] $widths */
    private function renderSeparator(array $widths, int $columns): string
    {
        $parts = array_map(static fn (int $w) => str_repeat('─', $w), $widths);

        return $this->applyElement('separator', AnsiUtils::truncateToWidth(
            '─'.implode('──', $parts),
            $columns,
            '',
        ));
    }

    /**
     * @param array<string,mixed> $row
     * @param TuiColumn[]         $cols
     * @param int[]               $widths
     */
    private function renderDataRow(array $row, array $cols, array $widths, int $columns, bool $selected): string
    {
        $parts = [];
        foreach ($cols as $i => $col) {
            $cell = $this->formatCell($row[$col->key] ?? null, $col);
            $parts[] = AnsiUtils::truncateToWidth(
                str_pad($cell, $widths[$i]),
                $widths[$i],
                '…',
            );
        }

        $line = AnsiUtils::truncateToWidth(' '.implode('  ', $parts), $columns, '');

        return $selected ? $this->applyElement('selected', $line) : $line;
    }

    private function renderStatusBar(int $columns): string
    {
        $page = $this->limit > 0 ? (int) floor($this->offset / $this->limit) + 1 : 1;
        $totalPages = $this->limit > 0 ? max(1, (int) ceil($this->totalCount / $this->limit)) : 1;
        $showing = \count($this->currentRows);
        $from = $this->totalCount > 0 ? $this->offset + 1 : 0;
        $to = $this->offset + $showing;

        $searchHint = null !== $this->search ? " · search: \"{$this->search}\"" : '';
        $status = \sprintf(
            ' %d–%d / %d  p.%d/%d%s   [/]search [↑↓]nav [PgDn]page [s]sort [q]quit',
            $from, $to, $this->totalCount,
            $page, $totalPages,
            $searchHint,
        );

        return $this->applyElement('statusbar', AnsiUtils::truncateToWidth($status, $columns, ''));
    }

    /**
     * @param TuiColumn[] $cols
     * @return int[]
     */
    private function computeWidths(array $cols, int $totalColumns): array
    {
        // 1 leading space + 2-char gap between each column
        $overhead = 1 + max(0, \count($cols) - 1) * 2;
        $available = $totalColumns - $overhead;

        $widths = [];
        $fixedTotal = 0;
        $autoCount = 0;

        foreach ($cols as $col) {
            if ($col->width > 0) {
                $widths[] = $col->width;
                $fixedTotal += $col->width;
            } else {
                $widths[] = 0;
                ++$autoCount;
            }
        }

        $autoWidth = $autoCount > 0
            ? max(5, (int) floor(($available - $fixedTotal) / $autoCount))
            : 0;

        foreach ($widths as $i => $w) {
            if (0 === $w) {
                $widths[$i] = $autoWidth;
            }
        }

        return $widths;
    }

    private function formatCell(mixed $value, TuiColumn $col): string
    {
        if (null === $value) {
            return '—';
        }

        return match ($col->format) {
            'boolean' => $value ? '✓' : '✗',
            'date' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value,
            'datetime' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i') : (string) $value,
            'bytes' => $this->formatBytes((int) $value),
            'percent' => number_format((float) $value * 100, 1).'%',
            default => (string) $value,
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1_048_576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1_048_576, 1).' MB';
    }

    private function dispatchSelectionChange(): void
    {
        $row = $this->getSelectedRow();
        if (null !== $row) {
            $this->dispatch(new TableRowChangeEvent($this, $row));
        }
    }
}
