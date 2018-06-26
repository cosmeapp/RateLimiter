<?php

/**
 * Created by PhpStorm.
 * User: xiewence
 * Date: 2018/6/22
 * Time: 下午3:16
 */

namespace RateLimiter\Middleware;

use Closure;
use Illuminate\Http\Response;
use RateLimiter\Basic\RateLimiter;

class ThrottleRequests
{
    /**
     * The rate limiter instance.
     *
     * @var RateLimiter
     */
    protected $limiter;

    /**
     * Create a new request throttler.
     * @param RateLimiter $limiter
     */
    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure $next
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        list($key, $decayUnits, $maxAttempts) = $this->resolveRequestLimitRate($request);

        if ($this->limiter->tooManyAttempts($key, $maxAttempts, $decayUnits)) {
            return $this->buildResponse($request, $key, $maxAttempts);
        }

        $this->limiter->hit($key, $decayUnits);
        $response = $next($request);

        if ($response instanceof Response) {
            return $this->addHeaders(
                $response,
                $maxAttempts,
                $this->calculateRemainingAttempts($key, $maxAttempts)
            );
        }

        return $response;
    }

    /**
     * Resolve request limit rate.
     *
     * @param  \Illuminate\Http\Request $request
     *
     * @return string
     */
    protected function resolveRequestLimitRate($request)
    {
        $method = strtolower($request->method());
        if (config('rate_limiter.api_gateway')) {
            list(,,,, $apiName) = explode('/', $request->getPathInfo());
            $apiName = str_replace('.', '_', $apiName);
        } else {
            $apiName = $request->getPathInfo();
        }

        $defaultLimit = config("rate_limiter.default.get", [1, 10]);
        if (strtolower($method) === 'post') {
            $defaultLimit = config("rate_limiter.default.post", [2, 1]);
        }

        $limitInfo = config("rate_limiter.api_limit.{$apiName}", $defaultLimit);
        $limitLevel = config("rate_limiter.default.limit_level", 'user');
        if (isset($limitInfo['limit_level'])) {
            $limitLevel = $limitInfo['limit_level'];
            $limitRate = $limitInfo['rate'];
        } else {
            $limitRate = $limitInfo;
        }

        list($decayUnits, $maxAttempts) = $limitRate;
        $signature = $this->getSignatureByLevel($request, $limitLevel);

        return [$signature, $decayUnits, $maxAttempts];
    }

    /**
     * Resolve request signature.
     *
     * @param  \Illuminate\Http\Request $request
     * @param $level
     *
     * @return string
     */
    protected function getSignatureByLevel($request, $level)
    {
        $path = $request->getPathInfo();
        $method = $request->method();

        if ($level == 'api') {
            // 只针对接口限制
            $signature = sha1($method . '|' .  $path);
        } elseif ($level == 'device') {
            // 针对接口 + 登录用户 + ip + 设备
            $udidName = config('rate_limiter.default.udid_name');
            $udid = $request->get($udidName, '');
            $signature = sha1($method . '|' . auth()->id() . '|' . $request->ip() . '|' . $udid . '|' .  $path);
        } elseif ($level == 'user') {
            // 针对接口 + 登录用户 + ip
            $signature = sha1($method . '|' . auth()->id() . '|' . $request->ip() . '|' . $path);
        } else {
            // 针对接口 + ip
            $signature = sha1($method . '|' . $request->ip() . '|' .  $path);
        }

        return $signature;
    }

    /**
     * Create a 'too many attempts' response.
     *
     * @param $request
     * @param  string $key
     * @param  int $maxAttempts
     * @return Response
     */
    protected function buildResponse($request, $key, $maxAttempts)
    {
        $content = [
            'status' => 0,
            'code' => config('rate_limiter.error_code', 90429),
            'msg' => '请求过于频繁，请稍后再试.',
        ];

        $header = [];
        if ($request->headers->has('authorization')) {
            $token = $request->header('authorization');
            $header = ['Authorization' => $token];
        }

        $response = new Response($content, 200, $header);

        return $this->addHeaders(
            $response,
            $maxAttempts,
            $this->calculateRemainingAttempts($key, $maxAttempts),
            $this->limiter->availableIn($key)
        );
    }

    /**
     * Add the limit header information to the given response.
     *
     * @param Response $response
     * @param $maxAttempts
     * @param $remainingAttempts
     * @param null $retryAfter
     *
     * @return Response
     */
    protected function addHeaders(Response $response, $maxAttempts, $remainingAttempts, $retryAfter = null)
    {
        $headers = [
            'X-RateLimit-Limit'     => $maxAttempts,
            'X-RateLimit-Remaining' => $remainingAttempts,
        ];

        if (!is_null($retryAfter)) {
            $headers['Retry-After'] = $retryAfter;
        }

        $response->headers->add($headers);

        return $response;
    }

    /**
     * Calculate the number of remaining attempts.
     *
     * @param  string $key
     * @param  int $maxAttempts
     *
     * @return int
     */
    protected function calculateRemainingAttempts($key, $maxAttempts)
    {
        return $maxAttempts - $this->limiter->attempts($key);
    }
}
