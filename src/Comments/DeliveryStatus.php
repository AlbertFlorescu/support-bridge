<?php

declare(strict_types=1);

namespace Bridge\Comments;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Uncertain = 'uncertain';
    case Delivered = 'delivered';
    case Blocked = 'blocked';
}
