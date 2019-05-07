<?php

namespace Grasmash\Evaluator\DrupalOrgData;

/**
 * Class Statuses.
 *
 * @package Grasmash\Evaluator
 */
final class Statuses
{
    const ACTIVE = 1;
    const FIXED = 2;
    const CLOSED_DUPLICATE = 3;
    const POSTPONED = 4;
    const CLOSED_WONT_FIX = 5;
    const CLOSED_WORKS_AS_DESIGNED = 6;
    const CLOSED_FIXED = 7;
    const NEEDS_REVIEW = 8;
    const NEEDS_WORK = 13;
    const RTBC = 14;
    const PATCH_TO_BE_PORTED = 15;
    const POSTPONED_NEED_INFO = 16;
    const CLOSED_OUTDATED = 17;
    const CLOSE_CANNOT_REPRODUCE = 18;

  /**
   *
   */
    public static function getOpenStatuses()
    {
        return [
        self::ACTIVE,
        self::NEEDS_REVIEW,
        self::NEEDS_WORK,
        self::RTBC,
        ];
    }
}
