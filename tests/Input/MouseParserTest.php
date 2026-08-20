<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Tests\Input;

use PHPUnit\Framework\TestCase;
use Survos\TuiExtrasBundle\Enum\MouseAction;
use Survos\TuiExtrasBundle\Enum\MouseButton;
use Survos\TuiExtrasBundle\Input\MouseParser;
use Symfony\Component\Tui\Widget\TextWidget;

final class MouseParserTest extends TestCase
{
    private MouseParser $parser;
    private TextWidget $target;

    protected function setUp(): void
    {
        $this->parser = new MouseParser();
        $this->target = new TextWidget('target');
    }

    public function testParsesSgrWheelCoordinatesAsZeroBased(): void
    {
        $event = $this->parser->parse("\x1b[<64;12;7M", $this->target);

        self::assertNotNull($event);
        self::assertSame(MouseButton::WheelUp, $event->button);
        self::assertSame(MouseAction::Wheel, $event->action);
        self::assertSame(11, $event->column);
        self::assertSame(6, $event->row);
        self::assertSame($this->target, $event->getTarget());
    }

    public function testParsesSgrWheelDownWithModifiers(): void
    {
        $event = $this->parser->parse("\x1b[<93;1;1M", $this->target);

        self::assertNotNull($event);
        self::assertSame(MouseButton::WheelDown, $event->button);
        self::assertTrue($event->shift);
        self::assertTrue($event->alt);
        self::assertTrue($event->ctrl);
    }

    public function testParsesSgrRelease(): void
    {
        $event = $this->parser->parse("\x1b[<0;4;5m", $this->target);

        self::assertNotNull($event);
        self::assertSame(MouseButton::Left, $event->button);
        self::assertSame(MouseAction::Release, $event->action);
    }

    public function testParsesLegacyX10Wheel(): void
    {
        $event = $this->parser->parse("\x1b[M".\chr(96).\chr(38).\chr(40), $this->target);

        self::assertNotNull($event);
        self::assertSame(MouseButton::WheelUp, $event->button);
        self::assertSame(5, $event->column);
        self::assertSame(7, $event->row);
    }

    public function testIgnoresKeyboardInput(): void
    {
        self::assertNull($this->parser->parse('j', $this->target));
    }
}
