<?php

namespace ErnestDefoe\Stances\Tests\integration\api;

/**
 * Requests as the forum makes them. StanceResource's own endpoints (create and update) take a
 * JSON:API document; the actions take plain fields. Answers that are `stances` documents come back
 * as plain rows (the id plus the attributes), so assertions read the same whichever route answered.
 */
trait JsonApiCalls
{
    /** @return array{int, array<string, mixed>|null} */
    private function call(string $method, string $path, ?int $as, array $body = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        if ($method !== 'GET') {
            if (in_array($method, ['POST', 'PATCH'], true) && preg_match('#^/api/stances(?:/(\d+))?$#', $route, $m)) {
                if (array_key_exists('type', $body)) {
                    $body['blockType'] = $body['type'];
                    unset($body['type']);
                }
                $body = ['data' => ['type' => 'stances'] + (isset($m[1]) ? ['id' => $m[1]] : []) + ['attributes' => $body]];
            }
            $options['json'] = $body;
        }
        parse_str($query, $params);
        $response = $this->send($this->request($method, $route, $options)->withQueryParams($params));

        return [$response->getStatusCode(), self::flatten(json_decode((string) $response->getBody(), true))];
    }

    private static function flatten(?array $json): ?array
    {
        $row = function ($r) {
            if (! is_array($r) || ($r['type'] ?? null) !== 'stances') {
                return $r;
            }
            $attributes = $r['attributes'] ?? [];
            if (array_key_exists('blockType', $attributes)) {
                $attributes['type'] = $attributes['blockType'];
                unset($attributes['blockType']);
            }

            return ['id' => (int) $r['id']] + $attributes;
        };
        $data = $json['data'] ?? null;
        if (is_array($data)) {
            $json['data'] = array_is_list($data) ? array_map($row, $data) : $row($data);
        }

        return $json;
    }
}
