<?php

declare(strict_types=1);

namespace Plugins\Push\Tests\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientResponse;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\PendingRequestContract;

/**
 * Records what the gateways would have sent, and answers with scripted
 * responses.
 *
 * Every assertion about an APNs or FCM request is made against what THIS
 * recorded, so the request shape is checked rather than assumed — the headers
 * are the part of APNs that fails silently when wrong, and a test that only
 * checked the response would never see them.
 */
final class FakeHttpClient implements HttpClientPort
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $sent = [];

    /** @var list<HttpClientResponse|\Throwable> */
    private array $scripted = [];

    /** @param array<string, string> $headers */
    public function queue(int $status, string $body = '', array $headers = []): self
    {
        $this->scripted[] = new HttpClientResponse($status, $body, $headers);

        return $this;
    }

    public function queueThrow(\Throwable $e): self
    {
        $this->scripted[] = $e;

        return $this;
    }

    public function request(string $method, string $url, array $options = []): HttpClientResponse
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

        $next = array_shift($this->scripted);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        // An unscripted call answers 200 with an empty body rather than
        // failing: a test asserting on the REQUEST should not have to script a
        // response it does not care about.
        return $next ?? new HttpClientResponse(200, '{}');
    }

    /** @return array{method: string, url: string, options: array<string, mixed>}|null */
    public function last(): ?array
    {
        return $this->sent === [] ? null : $this->sent[\count($this->sent) - 1];
    }

    /**
     * The headers of the last request, lower-cased.
     *
     * @return array<string, string>
     */
    public function lastHeaders(): array
    {
        $headers = $this->last()['options']['headers'] ?? [];
        $out     = [];

        foreach (\is_array($headers) ? $headers : [] as $name => $value) {
            $out[strtolower((string) $name)] = (string) $value;
        }

        return $out;
    }

    /**
     * The decoded JSON body of the last request.
     *
     * @return array<string, mixed>
     */
    public function lastJson(): array
    {
        $body = $this->last()['options']['body'] ?? '';
        $decoded = json_decode(\is_string($body) ? $body : '', true);

        return \is_array($decoded) ? $decoded : [];
    }

    public function get(string $url, array $query = []): HttpClientResponse
    {
        return $this->request('GET', $url, ['query' => $query]);
    }

    public function post(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('POST', $url, ['json' => $data]);
    }

    public function put(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('PUT', $url, ['json' => $data]);
    }

    public function patch(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('PATCH', $url, ['json' => $data]);
    }

    public function delete(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('DELETE', $url, ['json' => $data]);
    }

    public function pending(): PendingRequestContract
    {
        throw new \LogicException('The push gateways use request() directly; pending() is not needed.');
    }
}
