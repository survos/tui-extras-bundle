<?php

declare(strict_types=1);

namespace Survos\TuiExtrasBundle\Enum;

enum MouseAction
{
    case Press;
    case Release;
    case Drag;
    case Move;
    case Wheel;
}
