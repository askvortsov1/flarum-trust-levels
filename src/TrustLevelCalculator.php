<?php

/*
 * This file is part of askvortsov/flarum-trust-levels
 *
 *  Copyright (c) 2021 Alexander Skvortsov.
 *
 *  For detailed copyright and license information, please view the
 *  LICENSE file that was distributed with this source code.
 */

namespace Askvortsov\TrustLevels;

use Askvortsov\TrustLevels\Metric\MetricManager;
use Flarum\User\User;
use Illuminate\Support\Arr;

class TrustLevelCalculator
{
    /**
     * @var MetricManager
     */
    protected $metrics;

    public function __construct(MetricManager $metrics)
    {
        $this->metrics = $metrics;
    }

    public function recalculate(User $user)
    {
        $stats = $this->getUserStats($user);

        $prevLevels = $this->toAssoc($user->trustLevels->toArray());
        $currLevels = $this->toAssoc($this->getTrustLevelsForStats($stats));

        $user->trustLevels()->sync(Arr::pluck($currLevels, 'id'));

        $this->adjustUserGroups($user, $prevLevels, $currLevels);
    }

    protected function getUserStats(User $user)
    {
        $stats = [];

        foreach ($this->metrics->getDrivers() as $name => $driver) {
            $stats[$name] = $driver->getValue($user);
        }

        return $stats;
    }

    protected function getTrustLevelsForStats($stats)
    {
        return TrustLevel::all()->filter(function (TrustLevel $level) use ($stats) {
            foreach ($stats as $stat => $val) {
                $min = $level->getMetricMin($stat);
                $max = $level->getMetricMax($stat);
                $withinMetric = ($min === -1 || $val >= $min) && ($max === -1 || $val <= $max);

                if (!$withinMetric) {
                    return false;
                }
            }

            return true;
        })->toArray();
    }

    protected function adjustUserGroups($user, $prevLevels, $currLevels)
    {
        $removedLevels = array_diff_key($prevLevels, $currLevels);
        $removedGroupIds = Arr::pluck($removedLevels, 'group_id');

        $newLevels = array_diff_key($currLevels, $prevLevels);
        $newGroupIds = Arr::pluck($newLevels, 'group_id');

        $allGroupIds = Arr::pluck($user->groups()->select('id')->get()->toArray(), 'id');
        $allGroupIds = array_diff($allGroupIds, $removedGroupIds);
        $allGroupIds = array_unique(array_merge($allGroupIds, $newGroupIds));

        $user->groups()->sync($allGroupIds);
    }

    /**
     * Converts model query results into an associative array with the ID as the key.
     */
    protected function toAssoc($arr)
    {
        $newArr = [];

        foreach ($arr as $model) {
            $newArr[$model['id']] = $model;
        }

        return $newArr;
    }
}
