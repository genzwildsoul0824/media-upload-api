<?php

namespace App\Service;

use Predis\ClientInterface;
use Symfony\Component\HttpFoundation\Request;

class RateLimitService
{
    public function __construct(
        private readonly ClientInterface $redis,
        private readonly int $rateLimit
    ) {
    }

    public function checkRateLimit(Request $request): bool
    {
        $clientIp = $request->getClientIp();
        $key = 'rate_limit:' . $clientIp;
        
        $current = (int)$this->redis->get($key);
        
        if ($current >= $this->rateLimit) {
            return false;
        }
        
        $this->redis->incr($key);
        
        if ($current === 0) {
            $this->redis->expire($key, 60); // 1 minute window
        }
        
        return true;
    }

    public function getRemainingRequests(Request $request): int
    {
        $clientIp = $request->getClientIp();
        $key = 'rate_limit:' . $clientIp;
        
        $current = (int)$this->redis->get($key);
        
        return max(0, $this->rateLimit - $current);
    }
}

