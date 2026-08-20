<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Enum;

enum MouseButton
{
    case Left;
    case Middle;
    case Right;
    case None;
    case WheelUp;
    case WheelDown;
    case WheelLeft;
    case WheelRight;
}
