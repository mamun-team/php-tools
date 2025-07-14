<?php

namespace App\Library;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class Caching
{
    private $lock;

    public $place;
    public $ttl;

    public function __construct($place, $ttl)
    {
        $this->place = $place;
        $this->ttl = $ttl;
    }

    /**
     * Get cache value, or remember via callback
     */
    public function get($key, $func = null, $ttl = null, $default = null, $place = null)
    {
        $prefix = $place ?? $this->place;
        $fullKey = "{$prefix}:{$key}";

        if ($func) {
            return Cache::remember($fullKey, $ttl ?? $this->ttl, $func);
        }

        return Cache::get($fullKey, $default);
    }

    public function remember($key, $data, $ttl = null)
    {
        if (!isset($key) || !isset($data)) {
            return null;
        }

        $fullKey = is_array($key) ? implode(':', $key) : "{$this->place}:{$key}";

        Cache::forget($fullKey);

        if ($ttl === 0) {
            return Cache::rememberForever($fullKey, function () use ($data) {
                return is_callable($data) ? $data() : $data;
            });
        } else {
            return Cache::remember($fullKey, $ttl ?? $this->ttl, function () use ($data) {
                return is_callable($data) ? $data() : $data;
            });
        }
    }

    /**
     * Set cache value with TTL or forever
     */
    public function set($key, $value, $ttl = null)
    {
        $fullKey = "{$this->place}:{$key}";
        $ttl = $ttl ?? $this->ttl;

        if ($ttl) {
            Cache::put($fullKey, $value, now()->addMinutes($ttl));
        } else {
            Cache::forever($fullKey, $value);
        }
    }

    /**
     * Delete a specific key
     */
    public function delete($key)
    {
        Cache::forget("{$this->place}:{$key}");
    }

    /**
     * Check if key exists
     */
    public function has($key)
    {
        return Cache::has("{$this->place}:{$key}");
    }

    /**
     * Clear all keys with this prefix (safe with SCAN)
     */
    public function clear()
    {
        $cursor = null;
        do {
            [$cursor, $keys] = Redis::scan($cursor, 'MATCH', "{$this->place}:*", 'COUNT', 100);
            foreach ($keys as $key) {
                Cache::forget($key);
            }
        } while ($cursor != 0);
    }

    /**
     * Acquire a lock
     */
    public function lock($key, $ttl = null)
    {
        $this->lock = Cache::lock("{$this->place}:{$key}", $ttl ?? $this->ttl);
        return $this->lock->get();
    }

    /**
     * Release the lock
     */
    public function unlock()
    {
        if ($this->lock) {
            $this->lock->release();
        }
    }
}