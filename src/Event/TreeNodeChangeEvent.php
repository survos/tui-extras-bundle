<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Event;

use Survos\TuiExtrasBundle\Model\TreeNode;
use Survos\TuiExtrasBundle\Widget\TreeWidget;
use Symfony\Component\Tui\Event\AbstractEvent;

/**
 * Dispatched whenever the cursor moves to a different node (files AND directories).
 */
class TreeNodeChangeEvent extends AbstractEvent
{
    public function __construct(
        TreeWidget $target,
        public readonly TreeNode $node,
    ) {
        parent::__construct($target);
    }
}
