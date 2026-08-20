<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Event;

use Survos\TuiExtrasBundle\Enum\MouseAction;
use Survos\TuiExtrasBundle\Enum\MouseButton;
use Symfony\Component\Tui\Event\AbstractEvent;
use Symfony\Component\Tui\Widget\AbstractWidget;

final class MouseEvent extends AbstractEvent
{
    public function __construct(
        AbstractWidget $target,
        public readonly int $column,
        public readonly int $row,
        public readonly MouseButton $button,
        public readonly MouseAction $action,
        public readonly bool $shift = false,
        public readonly bool $alt = false,
        public readonly bool $ctrl = false,
    ) {
        parent::__construct($target);
    }

    public function isWheel(): bool
    {
        return MouseAction::Wheel === $this->action;
    }
}
