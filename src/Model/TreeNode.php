<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Model;

/**
 * A node in a TreeWidget tree.
 *
 * Leaf status is explicit — tracked by the $leaf flag, NOT by whether children
 * have been added. This means an empty directory is still a branch node.
 *
 * Use TreeNode::branch() for expandable nodes (directories, groups).
 * Use TreeNode::leaf()   for terminal nodes (files, commands, rows).
 */
final class TreeNode
{
    /** @var TreeNode[] */
    private array $children = [];
    private bool $expanded;
    private bool $leaf;

    public function __construct(
        public readonly string $label,
        public readonly mixed $data = null,
        bool $expanded = false,
        bool $leaf = false,
    ) {
        $this->expanded = $expanded;
        $this->leaf = $leaf;
    }

    public static function branch(string $label, mixed $data = null, bool $expanded = false): self
    {
        return new self($label, $data, expanded: $expanded, leaf: false);
    }

    public static function leaf(string $label, mixed $data = null): self
    {
        return new self($label, $data, expanded: false, leaf: true);
    }

    public function addChild(self $child): self
    {
        $this->children[] = $child;

        return $this;
    }

    /** @return TreeNode[] */
    public function getChildren(): array
    {
        return $this->children;
    }

    /** True only for nodes explicitly created with leaf() — never for branches, even empty ones. */
    public function isLeaf(): bool
    {
        return $this->leaf;
    }

    public function isExpanded(): bool
    {
        return $this->expanded;
    }

    public function toggle(): void
    {
        $this->expanded = !$this->expanded;
    }

    public function expand(): void
    {
        $this->expanded = true;
    }

    public function collapse(): void
    {
        $this->expanded = false;
    }
}
