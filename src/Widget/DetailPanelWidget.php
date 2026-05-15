<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Widget;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * Read-only text panel for displaying detail content alongside a DataTableWidget.
 *
 * Styled sub-elements:
 *   ::title   — top header line
 *   ::content — body text lines
 *
 * Usage in a split layout:
 *   $split = (new ContainerWidget())->setStyle(new Style(direction: Direction::Horizontal, gap: 1));
 *   $split->add($table->setStyle(new Style(maxColumns: 60)));
 *   $split->add($detail);
 *   $table->onRowChange(fn($e) => $detail->setContent($e->row['body'], $e->row['title']));
 */
class DetailPanelWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    private bool $expanded = true;
    private string $content = '';
    private string $title = '';

    public function expandVertically(bool $expand): static
    {
        $this->expanded = $expand;

        return $this;
    }

    public function isVerticallyExpanded(): bool
    {
        return $this->expanded;
    }

    public function setContent(string $content, string $title = ''): static
    {
        if ($this->content !== $content || $this->title !== $title) {
            $this->content = $content;
            $this->title = $title;
            $this->invalidate();
        }

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function render(RenderContext $context): array
    {
        $cols = $context->getColumns();
        $maxRows = $context->getRows();
        $lines = [];

        if ('' !== $this->title) {
            $lines[] = $this->applyElement('title', AnsiUtils::truncateToWidth(
                ' '.$this->title,
                $cols,
                '',
            ));
            $lines[] = $this->applyElement('separator', str_repeat('─', $cols));
        }

        foreach (explode("\n", $this->content) as $raw) {
            if (\count($lines) >= $maxRows) {
                break;
            }
            // soft-wrap lines that are too wide
            while (AnsiUtils::visibleWidth($raw) > $cols - 1) {
                $lines[] = $this->applyElement('content', AnsiUtils::truncateToWidth(' '.$raw, $cols, ''));
                $raw = mb_substr($raw, $cols - 1);
                if (\count($lines) >= $maxRows) {
                    break 2;
                }
            }
            $lines[] = $this->applyElement('content', AnsiUtils::truncateToWidth(' '.$raw, $cols, ''));
        }

        // Pad to fill available rows
        while (\count($lines) < $maxRows) {
            $lines[] = '';
        }

        return array_slice($lines, 0, $maxRows);
    }
}
