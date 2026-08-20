<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Input;

use Survos\TuiExtrasBundle\Enum\MouseAction;
use Survos\TuiExtrasBundle\Enum\MouseButton;
use Survos\TuiExtrasBundle\Event\MouseEvent;
use Symfony\Component\Tui\Widget\AbstractWidget;

final class MouseParser
{
    public function parse(string $data, AbstractWidget $target): ?MouseEvent
    {
        if (preg_match('/^\x1b\[<(\d+);(\d+);(\d+)([Mm])$/', $data, $matches)) {
            return $this->createEvent($target, (int) $matches[1], (int) $matches[2] - 1, (int) $matches[3] - 1, 'm' === $matches[4]);
        }

        if (6 === \strlen($data) && str_starts_with($data, "\x1b[M")) {
            $code = \ord($data[3]) - 32;

            return $this->createEvent($target, $code, \ord($data[4]) - 33, \ord($data[5]) - 33, 3 === ($code & 3));
        }

        return null;
    }

    private function createEvent(AbstractWidget $target, int $code, int $column, int $row, bool $released): MouseEvent
    {
        $baseButton = $code & 3;
        $wheel = 0 !== ($code & 64);
        $motion = 0 !== ($code & 32);

        if ($wheel) {
            $button = match ($baseButton) {
                0 => MouseButton::WheelUp,
                1 => MouseButton::WheelDown,
                2 => MouseButton::WheelLeft,
                default => MouseButton::WheelRight,
            };
            $action = MouseAction::Wheel;
        } else {
            $button = match ($baseButton) {
                0 => MouseButton::Left,
                1 => MouseButton::Middle,
                2 => MouseButton::Right,
                default => MouseButton::None,
            };
            $action = match (true) {
                $released => MouseAction::Release,
                $motion && MouseButton::None === $button => MouseAction::Move,
                $motion => MouseAction::Drag,
                default => MouseAction::Press,
            };
        }

        return new MouseEvent(
            $target,
            max(0, $column),
            max(0, $row),
            $button,
            $action,
            shift: 0 !== ($code & 4),
            alt: 0 !== ($code & 8),
            ctrl: 0 !== ($code & 16),
        );
    }
}
