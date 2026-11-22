<?php

namespace App\Domains\Numbering\Enums;

enum DocumentNumberAllocationMode: string
{
    case Auto = 'auto';
    case Manual = 'manual';
}
