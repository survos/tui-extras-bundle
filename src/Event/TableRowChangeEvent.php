<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Event;

use Survos\TuiExtrasBundle\Widget\DataTableWidget;
use Symfony\Component\Tui\Event\AbstractEvent;

/**
 * Dispatched when the cursor moves to a different row.
 */
class TableRowChangeEvent extends AbstractEvent
{
    /**
     * @param array<string,mixed> $row
     */
    public function __construct(
        DataTableWidget $target,
        public readonly array $row,
    ) {
        parent::__construct($target);
    }
}
