<?php

namespace RateLimiter\Basic;

use Illuminate\Redis\RedisManager;
use Illuminate\Support\InteractsWithTime;

class RateLimiter
{
    use InteractsWithTime;

    /**
     * The cache store implementation.
     *
     * @var RedisManager
     */
    protected $cache;

    protected $rateUnit;

    /**
     * Create a new rate limiter instance.
     * @param $rateUnit
     */
    public function __construct($rateUnit = TimeUnit::SECOND)
    {
        $this->cache = new RedisStore($rateUnit);
        $this->rateUnit = $rateUnit;
    }

    protected function getUnitSeconds($units)
    {
        if ($this->rateUnit == TimeUnit::SECOND) {
            $seconds = $units * 1;
        } elseif ($this->rateUnit == TimeUnit::MINUTE) {
            $seconds = $units * 60;
        }

        return $seconds;
    }

    /**
     * Determine if the given key has been "accessed" too many times.
     *
     * @param  string  $key
     * @param  int  $maxAttempts
     * @return bool
     */
    public function tooManyAttempts($key, $maxAttempts)
    {
        if ($this->attempts($key) >= $maxAttempts) {
            if (! is_null($this->cache->get($key.':timer'))) {
                return true;
            }

            $this->resetAttempts($key);
        }

        return false;
    }

    /**
     * Increment the counter for a given key for a given decay time.
     *
     * @param  string  $key
     * @param  float|int  $decayUnits
     * @return int
     */
    public function hit($key, $decayUnits = 1)
    {
        $seconds = $this->getUnitSeconds($decayUnits);

        $this->cache->add(
            $key.':timer',
            $this->availableAt($seconds),
            $decayUnits
        );

        $added = $this->cache->add($key, 0, $decayUnits);

        $hits = (int) $this->cache->increment($key);

        if (! $added && $hits == 1) {
            $this->cache->put($key, 1, $decayUnits);
        }

        return $hits;
    }

    /**
     * Get the number of attempts for the given key.
     *
     * @param  string  $key
     * @return mixed
     */
    public function attempts($key)
    {
        return $this->cache->get($key, 0);
    }

    /**
     * Reset the number of attempts for the given key.
     *
     * @param  string  $key
     * @return mixed
     */
    public function resetAttempts($key)
    {
        return $this->cache->forget($key);
    }

    /**
     * Get the number of retries left for the given key.
     *
     * @param  string  $key
     * @param  int  $maxAttempts
     * @return int
     */
    public function retriesLeft($key, $maxAttempts)
    {
        $attempts = $this->attempts($key);

        return $maxAttempts - $attempts;
    }

    /**
     * Clear the hits and lockout timer for the given key.
     *
     * @param  string  $key
     * @return void
     */
    public function clear($key)
    {
        $this->resetAttempts($key);

        $this->cache->forget($key.':timer');
    }

    /**
     * Get the number of seconds until the "key" is accessible again.
     *
     * @param  string  $key
     * @return int
     */
    public function availableIn($key)
    {
        return $this->cache->get($key.':timer') - $this->currentTime();
    }
}
