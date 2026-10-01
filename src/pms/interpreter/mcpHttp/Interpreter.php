<?php

namespace pms\interpreter\mcpHttp;

use pms\app\InterpreterApp;
use pms\facade\Ctx;
use pms\contract\HttpEntrypointInterface;
use pms\inject\HttpRequestInject;
use pms\inject\HttpResponseInject;

class Interpreter extends InterpreterApp implements HttpEntrypointInterface
{
    protected static string $name = 'mcp-http';

    public static function entry(): bool
    {
        return \pms\interpreter\http\Interpreter::entry();
    }

    /** 挂载路径在宿主配置加载后解析，包含公开资源元数据入口。 */
    public static function prefixes(): array
    {
        $route = config('mcp.route', []);
        return [McpRoute::prefix($route), McpRoute::metadataPrefix($route)];
    }

    public static function handle(HttpRequestInject $request, HttpResponseInject $response): void
    {
        $request->init();
        Ctx::set(HttpRequestInject::class, $request);
        Ctx::set(HttpResponseInject::class, $response);
        (new Sandbox($request, $response))->run();
    }
}
