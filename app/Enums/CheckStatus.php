<?php

declare(strict_types=1);

namespace App\Enums;

enum CheckStatus: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Timeout = 'timeout';
}
