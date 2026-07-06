<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

enum Variant: string
{
    case Floor = 'floor';
    case Control = 'control';
    case Treatment = 'treatment';
}
