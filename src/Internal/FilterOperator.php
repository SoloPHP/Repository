<?php

declare(strict_types=1);

namespace Solo\Repository\Internal;

/** @internal */
enum FilterOperator
{
    case All;
    case Any;
    case Not;
    case Related;
}
