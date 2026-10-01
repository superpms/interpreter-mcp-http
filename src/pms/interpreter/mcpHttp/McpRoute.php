<?php

namespace pms\interpreter\mcpHttp;

use InvalidArgumentException;
use pms\hook\HttpEntrypointHook;

class McpRoute
{
    /** 根据宿主配置生成 MCP 挂载路径。 */
    public static function prefix(array $config): string
    {
        $prefix = HttpEntrypointHook::normalizePrefix($config['prefix'] ?? 'mcp');
        if ($prefix === '/') {
            throw new InvalidArgumentException('mcp.route.prefix 需要非空挂载路径');
        }
        return $prefix;
    }

    /** 资源元数据入口跟随 MCP 挂载路径。 */
    public static function metadataPrefix(array $config): string
    {
        return '/.well-known/oauth-protected-resource' . self::prefix($config);
    }


    /**
     * 按当前模板生成端点路径，并使用同一匹配规则验证参数。
     *
     * @param array<string, string> $params 路径参数
     * @param array $config 宿主路由配置
     * @return string 完整端点路径
     */
    public static function path(array $params, array $config): string
    {
        $pattern = $config['pattern'] ?? '{terminal}/{endpoint_id}';
        if (!is_string($pattern)) {
            throw new InvalidArgumentException('mcp.route.pattern 必须是相对路径模板');
        }
        $suffix = preg_replace_callback('/\\{([A-Za-z_][A-Za-z0-9_]*)\\}/', static function (array $match) use ($params): string {
            $value = $params[$match[1]] ?? null;
            if (!is_string($value)) {
                throw new InvalidArgumentException('MCP 路径参数缺失：' . $match[1]);
            }
            return rawurlencode($value);
        }, $pattern);
        $path = self::prefix($config) . ($suffix === '' ? '' : '/' . $suffix);
        $match = self::match($path, $config);
        if ($match === null) {
            throw new InvalidArgumentException('MCP 路径参数不符合模板约束');
        }
        return $match['resource_path'];
    }

    /** 完整匹配宿主模板，每个占位符占用一个路径段。 */
    public static function match(string $path, array $config): ?array
    {
        $resourcePrefix = self::prefix($config);
        $metadataPrefix = self::metadataPrefix($config);
        $metadata = HttpEntrypointHook::relativePath($path, $metadataPrefix) !== null;
        $prefix = $metadata ? $metadataPrefix : $resourcePrefix;
        if (HttpEntrypointHook::relativePath($path, $prefix) === null) {
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
            'resource_path' => $resourcePrefix . ($canonical === [] ? '' : '/' . implode('/', $canonical)),
        ];
    }
}
