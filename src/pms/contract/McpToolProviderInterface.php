<?php

namespace pms\contract;

use pms\inject\HttpRequestInject;
use pms\inject\HttpRouteInject;

interface McpToolProviderInterface
{
    /** 返回 ToolsListResult；请求参数通过 mcp_params attach 获取。 */
    public static function listTools(HttpRequestInject $request, HttpRouteInject $route): array;

    /** 返回 CallToolResult；业务接口通过 route->forward() 执行。 */
    public static function callTool(HttpRequestInject $request, HttpRouteInject $route, string $name, array $arguments): array;
}
