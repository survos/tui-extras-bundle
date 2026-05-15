<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Widget;

use Survos\TuiExtrasBundle\Highlighter\SyntaxHighlighter;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * Read-only text/code/markdown pane with title and separator.
 *
 * Styled sub-elements:
 *   ::title     — top header line
 *   ::separator — line below title
 *   ::content   — body text lines (not applied to pre-colored ANSI content)
 *
 * For plain text or syntax-highlighted content:  setContent(string $text, string $title)
 * For Markdown files:                            setMarkdown(string $markdown, string $title)
 *
 * setMarkdown() delegates body rendering to MarkdownWidget (league/commonmark +
 * tempest/highlight) but keeps the bundle's title/separator chrome. MarkdownWidget
 * is used as an internal renderer — it does not need to be attached to the widget tree
 * since its rendering uses DarkTerminalTheme ANSI codes, not the stylesheet system.
 */
class DetailPanelWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    private bool $expanded = true;
    private string $content = '';
    private string $title = '';
    private bool $isMarkdown = false;
    private ?MarkdownWidget $markdownWidget = null;

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
        if ($this->content !== $content || $this->title !== $title || $this->isMarkdown) {
            $this->content = $content;
            $this->title = $title;
            $this->isMarkdown = false;
            $this->invalidate();
        }

        return $this;
    }

    /**
     * Render body as formatted Markdown using MarkdownWidget (league/commonmark).
     * Requires league/commonmark and tempest/highlight — both required by this bundle.
     */
    public function setMarkdown(string $markdown, string $title = ''): static
    {
        $changed = $this->content !== $markdown || $this->title !== $title || !$this->isMarkdown;

        $this->content = $markdown;
        $this->title = $title;
        $this->isMarkdown = true;

        if (null === $this->markdownWidget) {
            $this->markdownWidget = new MarkdownWidget($markdown);
        } else {
            $this->markdownWidget->setText($markdown);
        }

        if ($changed) {
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
        $cols    = $context->getColumns();
        $maxRows = $context->getRows();
        $lines   = [];

        if ('' !== $this->title) {
            $lines[] = $this->applyElement('title', AnsiUtils::truncateToWidth(
                ' '.$this->title,
                $cols,
                '',
            ));
            $lines[] = $this->applyElement('separator', str_repeat('─', $cols));
        }

        $bodyRows = max(1, $maxRows - \count($lines));

        if ($this->isMarkdown && null !== $this->markdownWidget) {
            // Delegate body to MarkdownWidget using a sub-context.
            // MarkdownWidget uses DarkTerminalTheme ANSI codes, not the stylesheet,
            // so calling render() without attachment is safe.
            $bodyContext = $context->withRows($bodyRows);
            $bodyLines   = SyntaxHighlighter::fixHeadings($this->markdownWidget->render($bodyContext));

            foreach ($bodyLines as $line) {
                if (\count($lines) >= $maxRows) {
                    break;
                }
                $lines[] = $line;
            }
        } else {
            foreach (explode("\n", $this->content) as $raw) {
                if (\count($lines) >= $maxRows) {
                    break;
                }
                while (AnsiUtils::visibleWidth($raw) > $cols - 1) {
                    $lines[] = $this->applyElement('content', AnsiUtils::truncateToWidth(' '.$raw, $cols, ''));
                    $raw = mb_substr($raw, $cols - 1);
                    if (\count($lines) >= $maxRows) {
                        break 2;
                    }
                }
                $lines[] = $this->applyElement('content', AnsiUtils::truncateToWidth(' '.$raw, $cols, ''));
            }
        }

        while (\count($lines) < $maxRows) {
            $lines[] = '';
        }

        return array_slice($lines, 0, $maxRows);
    }
}
