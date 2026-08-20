<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Input;

use Survos\TuiExtrasBundle\Contract\MouseAwareInterface;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Tui;

final class MouseInput
{
    private const ENABLE = "\x1b[?1000h\x1b[?1002h\x1b[?1006h";
    private const DISABLE = "\x1b[?1006l\x1b[?1002l\x1b[?1000l";

    private bool $attached = false;

    public function __construct(
        private readonly MouseParser $parser = new MouseParser(),
    ) {
    }

    public function attach(Tui $tui): void
    {
        if ($this->attached) {
            return;
        }
        $this->attached = true;

        $tui->addListener(function (InputEvent $input) use ($tui): void {
            $target = $tui->getFocus();
            if (null === $target) {
                return;
            }

            $mouse = $this->parser->parse($input->getData(), $target);
            if (null === $mouse) {
                return;
            }

            $input->stopPropagation();
            $tui->getEventDispatcher()->dispatch($mouse);

            if (!$mouse->isPropagationStopped() && $target instanceof MouseAwareInterface) {
                $target->handleMouse($mouse);
                $tui->requestRender();
            }
        }, priority: 100);
    }

    public function run(Tui $tui): void
    {
        $this->attach($tui);
        $tui->getTerminal()->write(self::ENABLE);

        try {
            $tui->run();
        } finally {
            $tui->getTerminal()->write(self::DISABLE);
        }
    }
}
