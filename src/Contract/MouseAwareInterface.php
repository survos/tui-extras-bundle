<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Contract;

use Survos\TuiExtrasBundle\Event\MouseEvent;

interface MouseAwareInterface
{
    public function handleMouse(MouseEvent $event): void;
}
