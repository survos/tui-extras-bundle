<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Widget;

use Survos\TuiExtrasBundle\Event\TreeNodeChangeEvent;
use Survos\TuiExtrasBundle\Event\TreeNodeSelectEvent;
use Survos\TuiExtrasBundle\Model\TreeNode;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\QuitableTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * Collapsible tree widget with keyboard navigation.
 *
 * Visual style (matching squall's Database Structure pane):
 *   ▼ src/                    expanded branch
 *   ── ► Command/             collapsed branch (child)
 *   ▼ Widget/                 expanded branch (child)
 *   ──── TreeWidget.php       leaf (grandchild)
 *
 * Styled sub-elements:
 *   ::branch-expanded   ▼ label for expanded branch
 *   ::branch-collapsed  ► label for collapsed branch
 *   ::leaf              ── label for leaf nodes
 *   ::selected          current cursor line (any node type)
 *   ::connector         tree line characters
 *
 * Keybindings:
 *   ↑/k    move up          ↓/j    move down
 *   →      expand branch    ←      collapse branch
 *   Enter/Space  toggle branch / dispatch TreeNodeSelectEvent on leaf
 *   q/ctrl+c     quit
 */
class TreeWidget extends AbstractWidget implements FocusableInterface, VerticallyExpandableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;
    use QuitableTrait;

    private bool $expanded = true;

    /** @var array<int, array{node: TreeNode, depth: int}> flat visible list, rebuilt on demand */
    private array $visible = [];
    private bool $visibleDirty = true;

    private int $cursor = 0;
    private int $scrollOffset = 0;

    /** @var TreeNode[] root-level nodes */
    private array $roots = [];

    public function expandVertically(bool $expand): static
    {
        $this->expanded = $expand;

        return $this;
    }

    public function isVerticallyExpanded(): bool
    {
        return $this->expanded;
    }

    /** @param TreeNode[] $roots */
    public function setRoots(array $roots): static
    {
        $this->roots = $roots;
        $this->visibleDirty = true;
        $this->cursor = 0;
        $this->scrollOffset = 0;
        $this->invalidate();

        return $this;
    }

    public function addRoot(TreeNode $node): static
    {
        $this->roots[] = $node;
        $this->visibleDirty = true;
        $this->invalidate();

        return $this;
    }

    public function getCursorNode(): ?TreeNode
    {
        return $this->visible[$this->cursor]['node'] ?? null;
    }

    public function onSelect(callable $callback): static
    {
        return $this->on(TreeNodeSelectEvent::class, $callback);
    }

    public function onCursorChange(callable $callback): static
    {
        return $this->on(TreeNodeChangeEvent::class, $callback);
    }

    public function render(RenderContext $context): array
    {
        if ($this->visibleDirty) {
            $this->rebuildVisible();
        }

        $cols = $context->getColumns();
        $rows = $context->getRows();

        // Keep scroll window around cursor
        if ($this->cursor < $this->scrollOffset) {
            $this->scrollOffset = $this->cursor;
        } elseif ($this->cursor >= $this->scrollOffset + $rows) {
            $this->scrollOffset = $this->cursor - $rows + 1;
        }

        $lines = [];
        $end = min(\count($this->visible), $this->scrollOffset + $rows);

        for ($i = $this->scrollOffset; $i < $end; ++$i) {
            ['node' => $node, 'depth' => $depth] = $this->visible[$i];
            $selected = $i === $this->cursor;

            $line = $this->renderNode($node, $depth, $cols, $selected);
            $lines[] = $selected ? $this->applyElement('selected', $line) : $line;
        }

        // Pad to fill remaining rows
        while (\count($lines) < $rows) {
            $lines[] = '';
        }

        return $lines;
    }

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'cursor_up')) {
            if ($this->cursor > 0) {
                --$this->cursor;
                $this->dispatchCursorChange();
                $this->invalidate();
            }

            return;
        }

        if ($kb->matches($data, 'cursor_down')) {
            if ($this->cursor < \count($this->visible) - 1) {
                ++$this->cursor;
                $this->dispatchCursorChange();
                $this->invalidate();
            }

            return;
        }

        if ($kb->matches($data, 'expand')) {
            $node = $this->getCursorNode();
            if (null !== $node && !$node->isLeaf() && !$node->isExpanded()) {
                $node->expand();
                $this->visibleDirty = true;
                $this->invalidate();
            }

            return;
        }

        if ($kb->matches($data, 'collapse')) {
            $node = $this->getCursorNode();
            if (null !== $node && !$node->isLeaf() && $node->isExpanded()) {
                $node->collapse();
                $this->visibleDirty = true;
                $this->invalidate();

                return;
            }
            $this->moveToParent();
            $this->dispatchCursorChange();

            return;
        }

        if ($kb->matches($data, 'activate')) {
            $node = $this->getCursorNode();
            if (null === $node) {
                return;
            }
            if ($node->isLeaf()) {
                $this->dispatch(new TreeNodeSelectEvent($this, $node));
            } else {
                $node->toggle();
                $this->visibleDirty = true;
                $this->invalidate();
            }

            return;
        }

        if ($kb->matches($data, 'quit')) {
            $this->dispatchQuit();
        }
    }

    /** @return array<string,string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'cursor_up' => [Key::UP, 'k'],
            'cursor_down' => [Key::DOWN, 'j'],
            'expand' => [Key::RIGHT],
            'collapse' => [Key::LEFT],
            'activate' => [Key::ENTER, Key::SPACE],
            'quit' => ['q', Key::ctrl('c')],
        ];
    }

    private function rebuildVisible(): void
    {
        $this->visible = [];
        foreach ($this->roots as $root) {
            $this->walkNode($root, 0);
        }
        $this->cursor = min($this->cursor, max(0, \count($this->visible) - 1));
        $this->visibleDirty = false;
    }

    private function walkNode(TreeNode $node, int $depth): void
    {
        $this->visible[] = ['node' => $node, 'depth' => $depth];
        if (!$node->isLeaf() && $node->isExpanded()) {
            foreach ($node->getChildren() as $child) {
                $this->walkNode($child, $depth + 1);
            }
        }
    }

    private function renderNode(TreeNode $node, int $depth, int $cols, bool $selected): string
    {
        // Tree connector lines: each depth level contributes 2 chars ("── " prefix)
        $indent = str_repeat('  ', $depth);

        if ($node->isLeaf()) {
            $prefix = $indent.'── ';
            $label = AnsiUtils::truncateToWidth($prefix.$node->label, $cols, '…');

            return $selected ? $label : $this->applyElement('leaf', $label);
        }

        if ($node->isExpanded()) {
            $prefix = $indent.'▼ ';
            $label = AnsiUtils::truncateToWidth($prefix.$node->label, $cols, '…');

            return $selected ? $label : $this->applyElement('branch-expanded', $label);
        }

        $prefix = $indent.'► ';
        $label = AnsiUtils::truncateToWidth($prefix.$node->label, $cols, '…');

        return $selected ? $label : $this->applyElement('branch-collapsed', $label);
    }

    private function dispatchCursorChange(): void
    {
        $node = $this->getCursorNode();
        if (null !== $node) {
            $this->dispatch(new TreeNodeChangeEvent($this, $node));
        }
    }

    private function moveToParent(): void
    {
        if ($this->cursor === 0) {
            return;
        }
        $currentDepth = $this->visible[$this->cursor]['depth'];
        for ($i = $this->cursor - 1; $i >= 0; --$i) {
            if ($this->visible[$i]['depth'] < $currentDepth) {
                $this->cursor = $i;
                $this->invalidate();

                return;
            }
        }
    }
}
