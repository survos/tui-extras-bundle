<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Tests\Input;

use PHPUnit\Framework\TestCase;
use Survos\TuiExtrasBundle\Contract\MouseAwareInterface;
use Survos\TuiExtrasBundle\Event\MouseEvent;
use Survos\TuiExtrasBundle\Input\MouseInput;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

final class MouseInputTest extends TestCase
{
    public function testRoutesWheelInputToFocusedMouseAwareWidget(): void
    {
        $tui = new Tui(terminal: new VirtualTerminal());
        $widget = new MouseAwareTestWidget();
        $tui->add($widget);
        $tui->setFocus($widget);

        (new MouseInput())->attach($tui);
        $tui->handleInput("\x1b[<65;4;5M");

        self::assertNotNull($widget->lastMouseEvent);
        self::assertSame(3, $widget->lastMouseEvent->column);
        self::assertSame(4, $widget->lastMouseEvent->row);
        self::assertSame(0, $widget->keyboardInputCount);
    }

    public function testLeavesKeyboardInputOnTheNormalTuiPath(): void
    {
        $tui = new Tui(terminal: new VirtualTerminal());
        $widget = new MouseAwareTestWidget();
        $tui->add($widget);
        $tui->setFocus($widget);

        (new MouseInput())->attach($tui);
        $tui->handleInput('j');

        self::assertNull($widget->lastMouseEvent);
        self::assertSame(1, $widget->keyboardInputCount);
    }

    public function testRunRestoresMouseMode(): void
    {
        $terminal = new VirtualTerminal();
        $tui = new NonRunningTui(terminal: $terminal);

        (new MouseInput())->run($tui);

        self::assertSame(
            "\x1b[?1000h\x1b[?1002h\x1b[?1006h\x1b[?1006l\x1b[?1002l\x1b[?1000l",
            $terminal->getOutput(),
        );
    }

    public function testRunRestoresMouseModeWhenTuiThrows(): void
    {
        $terminal = new VirtualTerminal();
        $tui = new ThrowingTui(terminal: $terminal);

        try {
            (new MouseInput())->run($tui);
            self::fail('The test TUI should throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('run failed', $exception->getMessage());
        }

        self::assertStringEndsWith("\x1b[?1006l\x1b[?1002l\x1b[?1000l", $terminal->getOutput());
    }
}

final class NonRunningTui extends Tui
{
    public function run(): void
    {
    }
}

final class ThrowingTui extends Tui
{
    public function run(): void
    {
        throw new \RuntimeException('run failed');
    }
}

final class MouseAwareTestWidget extends AbstractWidget implements FocusableInterface, MouseAwareInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    public ?MouseEvent $lastMouseEvent = null;
    public int $keyboardInputCount = 0;

    public function handleMouse(MouseEvent $event): void
    {
        $this->lastMouseEvent = $event;
    }

    public function handleInput(string $data): void
    {
        ++$this->keyboardInputCount;
    }

    public function render(RenderContext $context): array
    {
        return ['test'];
    }
}
