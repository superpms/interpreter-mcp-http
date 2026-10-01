<?php

namespace pms\interpreter\mcpHttp;

use InvalidArgumentException;

class McpRoute
{
    public const PREFIX = '/mcp';
    public const METADATA_PREFIX = '/.well-known/oauth-protected-resource/mcp';

    /** 完整匹配宿主模板，每个占位符占用一个路径段。 */
    public static function match(string $path, array $config): ?array
    {
        $metadata = $path === self::METADATA_PREFIX || str_starts_with($path, self::METADATA_PREFIX . '/');
        $prefix = $metadata ? self::METADATA_PREFIX : self::PREFIX;
        if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
            return null;
        }
        $pattern = $config['pattern'] ?? '{terminal}/{endpoint_id}';
        if (!is_string($pattern) || trim($pattern, '/') !== $pattern || str_contains($pattern, '//')) {
            throw new InvalidArgumentException('mcp.route.pattern 必须是相对路径模板');
        }
        $segments = $pattern === '' ? [] : explode('/', $pattern);
        $suffix = substr($path, strlen($prefix));
        $actual = $suffix === '' ? [] : explode('/', substr($suffix, 1));
        $names = [];
        $rules = [];
        foreach ($segments as $segment) {
            if (preg_match('/^\\{([A-Za-z_][A-Za-z0-9_]*)\\}$/D', $segment, $match)) {
                $name = $match[1];
                if (isset($names[$name])) {
                    throw new InvalidArgumentException('MCP 路径参数重复：' . $name);
                }
                $names[$name] = true;
                $constraint = $config['constraints'][$name] ?? '[^/]+';
                if (!is_string($constraint) || @preg_match('~^(?:' . str_replace('~', '\\~', $constraint) . ')$~Du', '') === false) {
                    throw new InvalidArgumentException('MCP 路径约束无效：' . $name);
                }
                $rules[] = [$name, '~^(?:' . str_replace('~', '\\~', $constraint) . ')$~Du'];
            } elseif ($segment === '' || $segment === '.' || $segment === '..' || strpbrk($segment, '{}?#\\') !== false) {
                throw new InvalidArgumentException('MCP 模板路径段无效');
            } else {
                $rules[] = [null, $segment];
            }
        }
        if (count($actual) !== count($rules)) {
            return null;
        }
        $params = [];
        $canonical = [];
        foreach ($rules as $index => [$name, $rule]) {
            $value = rawurldecode($actual[$index]);
            if ($value === '' || $value === '.' || $value === '..' || strpbrk($value, "/\\\0\r\n") !== false) {
                return null;
            }
            if ($name === null) {
                if ($value !== $rule) {
                    return null;
                }
            } else {
                if (preg_match($rule, $value) !== 1) {
                    return null;
                }
                $params[$name] = $value;
            }
            $canonical[] = rawurlencode($value);
        }
        return [
            'params' => $params,
            'metadata' => $metadata,
            'resource_path' => self::PREFIX . ($canonical === [] ? '' : '/' . implode('/', $canonical)),
        ];
    }
}
