<?php

declare(strict_types=1);

namespace Datacircle\Bedrock;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;

/**
 * Calls Datacircle's open API, https://api.datacircle.dev/v1 (its spec: https://api.datacircle.dev/v1/openapi.json).
 * It needs no key. More at https://datacircle.dev
 *
 * It calls through a PSR-18 client: the one given, or Guzzle, which the AWS SDK for PHP installs.
 */
class Client
{
    public const BASE_URL = 'https://api.datacircle.dev/v1';

    /** This package's version, sent in each call's User-Agent. */
    public const VERSION = '0.1.0';

    /** A plan holds about 90 keys (its terms of use, its aggregators...): these choose a plan, the rest is on its page. */
    public const PLAN_KEYS = ['api', 'rank', 'provider', 'plan', 'score', 'perThousand', 'notFoundPerThousand', 'billing', 'pay',
        'currency', 'units', 'unit', 'strengths', 'latencyMs', 'page', 'pricingPage', 'docs'];

    public readonly string $baseUrl;

    private ?ClientInterface $http;

    /**
     * @param string|null $baseUrl Where the tools call. BASE_URL when left out.
     * @param ClientInterface|null $http The PSR-18 client to call through (a proxy, a timeout, a test): Guzzle when left out.
     */
    public function __construct(?string $baseUrl = null, ?ClientInterface $http = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? self::BASE_URL, '/');
        $this->http = $http;
    }

    /**
     * Calls one path and gives its JSON. A 429 is waited for once, a minute at most.
     *
     * @param array<string, string|int|null> $query
     *
     * @return array<mixed>
     *
     * @throws ApiError an answer of 4xx, with the API's own words
     * @throws \RuntimeException any other failure
     */
    public function get(string $path, array $query = []): array
    {
        $query = array_filter($query, static fn (mixed $value): bool => null !== $value && '' !== $value);
        $url = $this->baseUrl.$path.([] === $query ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $this->http ??= new \GuzzleHttp\Client(['timeout' => 60, 'connect_timeout' => 15]);
        $request = new Request('GET', $url, ['Accept' => 'application/json', 'User-Agent' => 'datacircle-bedrock-php/'.self::VERSION]);

        for ($try = 0;; ++$try) {
            try {
                $response = $this->http->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                throw new \RuntimeException('datacircle: '.$e->getMessage(), 0, $e);
            }

            $status = $response->getStatusCode();
            if (429 === $status && 0 === $try) {
                $wait = (int) $response->getHeaderLine('Retry-After');
                sleep($wait >= 1 && $wait <= 60 ? $wait : 60);

                continue;
            }
            $json = json_decode((string) $response->getBody(), true);
            if ($status >= 400 && $status < 500) {
                $error = is_array($json) ? ($json['error'] ?? null) : null;

                throw new ApiError($status, is_string($error) && '' !== $error ? $error : trim($status.' '.$response->getReasonPhrase()));
            }
            if (200 !== $status) {
                throw new \RuntimeException(trim('datacircle: '.$status.' '.$response->getReasonPhrase()));
            }
            if (!is_array($json)) {
                throw new \RuntimeException('datacircle: the answer is not JSON');
            }

            return $json;
        }
    }

    /**
     * The API's rule for a slug: the name in lowercase letters and digits, the rest a hyphen.
     */
    public static function slugify(string $name): string
    {
        $name = trim($name);
        if (class_exists(\Normalizer::class)) {
            $name = (string) preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($name, \Normalizer::FORM_KD));
        } elseif (function_exists('iconv')) {
            // Without intl, each letter alone: iconv writes é as 'e or ?e in some locales, and the mark is dropped.
            $name = (string) preg_replace_callback('/[^\x00-\x7F]/u', static fn (array $letter): string => str_replace(
                ["'", '"', '`', '^', '~', '?'], '', (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $letter[0])), $name);
        }

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    public static function brief(array $item, array $keys): array
    {
        $kept = [];
        foreach ($keys as $key) {
            if (isset($item[$key])) {
                $kept[$key] = $item[$key];
            }
        }

        return $kept;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function objects(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], 'is_array'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function briefPlans(mixed $value): array
    {
        return array_map(static fn (array $plan): array => self::brief($plan, self::PLAN_KEYS), self::objects($value));
    }
}
