<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for curl inside the Stripe SDK.
 *
 * The SDK reaches its HTTP client through a static, which is the only seam it
 * offers - so this is what lets the checkout paths be tested at all without an
 * account, a key or a socket. Install it with
 * {@see \Stripe\ApiRequestor::setHttpClient()} and put the real one back
 * afterwards; it is global, so leaving it in place would follow the suite
 * around.
 *
 * @internal
 */
final class StripeTransportSpy implements ClientInterface
{
    /**
     * The parameters of the most recent request, as the SDK flattened them.
     *
     * @var array<string, mixed>
     */
    public array $lastParams = [];

    /**
     * How many times the SDK has asked for anything at all.
     */
    public int $calls = 0;

    private function __construct(
        private readonly string $body,
        private readonly bool $refuse,
    ) {
    }

    /**
     * Answers every call with one fixed body, as a reachable Stripe would.
     */
    public static function answering(string $body): self
    {
        return new self($body, false);
    }

    /**
     * Refuses to connect, the way the SDK reports a Stripe it cannot reach.
     */
    public static function refusing(): self
    {
        return new self('', true);
    }

    /**
     * @param 'delete'|'get'|'post'    $method
     * @param string                   $absUrl
     * @param array<int|string, mixed> $headers
     * @param array<string, mixed>     $params
     * @param bool                     $hasFile
     * @param 'v1'|'v2'                $apiMode
     * @param int|null                 $maxNetworkRetries
     *
     * @return array{string, int, array<string, string>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        ++$this->calls;
        $this->lastParams = $params;

        if ($this->refuse) {
            throw new ApiConnectionException('Could not connect to Stripe.');
        }

        return [$this->body, 200, []];
    }
}
