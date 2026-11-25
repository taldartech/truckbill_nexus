<?php

namespace App\Domains\Accounting\Enums;

enum AccountNature: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Income = 'income';
    case Expense = 'expense';
    case Equity = 'equity';
}
