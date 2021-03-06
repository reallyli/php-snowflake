<?php

/*
 * This file is part of the godruoyi/php-snowflake.
 *
 * (c) Godruoyi <g@godruoyi.com>
 *
 * This source file is subject to the MIT license that is bundled.
 */

namespace Godruoyi\Snowflake;

class Snowflake
{
    const MAX_TIMESTAMP_LENGTH = 41;

    const MAX_WORKID_LENGTH = 5;

    const MAX_SEQUENCE_LENGTH = 12;

    const MAX_FIRST_LENGTH = 1;

    /**
     * The worker id.
     *
     * @var int
     */
    protected $workerid;

    /**
     * The Sequence Resolver instance.
     *
     * @var \Godruoyi\Snowflake\SequenceResolver|null
     */
    protected $sequence;

    /**
     * The start timestamp.
     *
     * @var int
     */
    protected $startTime;

    /**
     * Default sequence resolver.
     *
     * @var \Godruoyi\Snowflake\SequenceResolver|null
     */
    protected $defaultSequenceResolver;

    /**
     * Build Snowflake Instance.
     *
     * @param int $datacenter
     * @param int $workerid
     */
    public function __construct(int $workerid = null)
    {
        $maxWorkId = -1 ^ (-1 << self::MAX_WORKID_LENGTH);

        $this->workerid = $workerid > $maxWorkId || $workerid < 0 ? mt_rand(0, 31) : $workerid;
    }

    /**
     * Get snowflake id.
     *
     * @return string
     */
    public function id()
    {
        $currentTime = $this->getCurrentMicrotime();
        while (($sequence = $this->callResolver($currentTime)) > (-1 ^ (-1 << self::MAX_SEQUENCE_LENGTH))) {
            usleep(1);
            $currentTime = $this->getCurrentMicrotime();
        }

        $workerLeftMoveLength = self::MAX_SEQUENCE_LENGTH;
        $timestampLeftMoveLength = $workerLeftMoveLength + self::MAX_WORKID_LENGTH;

        return (string) ((($currentTime - $this->getStartTimeStamp()) << $timestampLeftMoveLength)
            | ($this->workerid << $workerLeftMoveLength)
            | ($sequence));
    }

    /**
     * Parse snowflake id.
     */
    public function parseId(string $id, $transform = false): array
    {
        $id = decbin($id);

        $data = [
            'timestamp' => substr($id, 0, -22),
            'sequence' => substr($id, -12),
            'workerid' => substr($id, -17, 5),
        ];

        return $transform ? array_map(function ($value) {
            return bindec($value);
        }, $data) : $data;
    }

    /**
     * Get current microtime timestamp.
     *
     * @return int
     */
    public function getCurrentMicrotime()
    {
        return floor(microtime(true) * 1000) | 0;
    }

    /**
     * Set start time (millisecond).
     */
    public function setStartTimeStamp(int $startTime)
    {
        $missTime = $this->getCurrentMicrotime() - $startTime;

        if ($missTime < 0) {
            throw new \Exception('The start time cannot be greater than the current time');
        }

        $maxTimeDiff = -1 ^ (-1 << self::MAX_TIMESTAMP_LENGTH);

        if ($missTime > $maxTimeDiff) {
            throw new \Exception(sprintf('The current microtime - starttime is not allowed to exceed -1 ^ (-1 << %d), You can reset the start time to fix this', self::MAX_TIMESTAMP_LENGTH));
        }

        $this->startTime = $startTime;

        return $this;
    }

    /**
     * Get start timestamp (millisecond), If not set default to 2019-08-08 08:08:08.
     *
     * @return int
     */
    public function getStartTimeStamp()
    {
        if ($this->startTime > 0) {
            return $this->startTime;
        }

        // We set a default start time if you not set.
        $defaultTime = '2019-08-08 08:08:08';

        return strtotime($defaultTime) * 1000;
    }

    /**
     * Set Sequence Resolver.
     *
     * @param SequenceResolver|callable $sequence
     */
    public function setSequenceResolver($sequence)
    {
        $this->sequence = $sequence;

        return $this;
    }

    /**
     * Get Sequence Resolver.
     *
     * @return \Godruoyi\Snowflake\SequenceResolver|callable|null
     */
    public function getSequenceResolver()
    {
        return $this->sequence;
    }

    /**
     * Get Default Sequence Resolver.
     *
     * @return \Godruoyi\Snowflake\SequenceResolver
     */
    public function getDefaultSequenceResolver(): SequenceResolver
    {
        return $this->defaultSequenceResolver ?: $this->defaultSequenceResolver = new RandomSequenceResolver();
    }

    /**
     * Call resolver.
     *
     * @param callable|\Godruoyi\Snowflake\SequenceResolver $resolver
     * @param int                                           $maxSequence
     *
     * @return int
     */
    protected function callResolver($currentTime)
    {
        $resolver = $this->getSequenceResolver();

        try {
            if (is_callable($resolver)) {
                return $resolver($currentTime);
            }
        } catch (\Exception $exception) {
            return $this->getDefaultSequenceResolver()->sequence($currentTime);
        }

        return is_null($resolver) || !($resolver instanceof SequenceResolver)
            ? $this->getDefaultSequenceResolver()->sequence($currentTime)
            : $resolver->sequence($currentTime);
    }
}
