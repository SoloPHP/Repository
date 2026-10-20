<?php

declare(strict_types=1);

namespace Solo\Repository;

enum Visibility
{
    case Active;
    case All;
    case Deleted;
}
