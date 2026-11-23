<?php

namespace App\Domains\Company\Enums;

enum GstRegistrationType: string
{
    case Regular = 'regular';
    case Composition = 'composition';
    case Unregistered = 'unregistered';
}
