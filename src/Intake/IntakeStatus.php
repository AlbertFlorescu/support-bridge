<?php

declare(strict_types=1);

namespace Bridge\Intake;

enum IntakeStatus: string
{
    case Prepared = 'prepared';
    case Creating = 'creating';
    case Uncertain = 'uncertain';
    case Linked = 'linked';
    case Blocked = 'blocked';
    case Withdrawn = 'withdrawn';
}
