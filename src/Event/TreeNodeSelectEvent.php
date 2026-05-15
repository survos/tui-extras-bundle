<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Event;

use Survos\TuiExtrasBundle\Model\TreeNode;
use Survos\TuiExtrasBundle\Widget\TreeWidget;
use Symfony\Component\Tui\Event\AbstractEvent;

/**
 * Dispatched when the user activates a leaf node (Enter/Space on a leaf).
 */
class TreeNodeSelectEvent extends AbstractEvent
{
    public function __construct(
        TreeWidget $target,
        public readonly TreeNode $node,
    ) {
        parent::__construct($target);
    }
}
