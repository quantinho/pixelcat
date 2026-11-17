<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    case Grant = 'grant';
    case Purchase = 'purchase';
    case Hold = 'hold';
    case HoldRelease = 'hold_release';
    case Charge = 'charge';
}
