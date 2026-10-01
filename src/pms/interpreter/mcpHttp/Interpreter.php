<?php

namespace pms\interpreter\mcpHttp;

use pms\app\InterpreterApp;
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

    public static function handle(HttpRequestInject $request, HttpResponseInject $response): void
    {
        (new Sandbox($request, $response))->run();
    }
}
