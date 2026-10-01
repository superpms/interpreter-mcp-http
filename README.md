# interpreter-mcp-http

框架 MCP HTTP 解释器。普通 HTTP 和 Swoole HTTP 都在业务前缀解析之前接收 /mcp 请求，宿主负责认证和工具映射。

## 安装

宿主 Composer 根项目的 post-autoload-dump 添加：

```json
"scripts": {
    "post-autoload-dump": ["@php pms vendor:install:hook"]
}
```

包通过 extra.pms.config.mcp 指向 resource/config.php，安装钩子生成宿主配置目录的 mcp.php，并保留已有配置。Composer 执行根项目 scripts，宿主需保留该脚本。

## 宿主配置

```php
return [
    'public_origin' => 'https://example.com',
    'allowed_origins' => ['https://console.example.com'],
    'route' => [
        'pattern' => '{terminal}/{endpoint_id}',
        'constraints' => [
            'terminal' => '[A-Za-z0-9:_-]+',
            'endpoint_id' => '[0-9]{1,20}',
        ],
    ],
    'server_info' => ['name' => 'host-mcp', 'version' => '1.0.0'],
    'instructions' => '宿主工具说明',
    'auth' => ['handler' => \core\mcp\Authenticator::class],
    'tools' => ['handler' => \core\mcp\Tools::class],
];
```

有效地址为 /mcp/customer/123。OAuth 元数据为 /.well-known/oauth-protected-resource/mcp/customer/123。模板描述 /mcp 后的相对路径；支持固定段及完整占位符段，例如 v1/{terminal}/{endpoint_id}。空模板匹配 /mcp。严格匹配段数；缺失段、空段、多余段、点路径及编码后的斜线返回 404。

匹配完成先注入路径参数，再调用认证器：

```php
$terminal = $request->params('terminal');
$endpointId = $request->params('endpoint_id');
$resource = $request->getAttach('mcp_resource_uri');
```

同名查询串和请求体以路径参数为准，原始 JSON-RPC 请求体保留。转发替换参数后，通过 getAttach('mcp_route_params') 获取原端点参数。

## 认证器

实现 pms\contract\McpAuthenticatorInterface，提供两个静态方法：

- authenticate(HttpRequestInject $request): array 返回 ['scheme' => 'oauth2', 'context' => $hostContext]。scheme 支持 oauth2、apiKey、noauth，context 必须为数组。
- oauthMetadata(HttpRequestInject $request): ?array：OAuth 返回 authorization_servers、scopes_supported 等资源元数据；API Key 返回 null。此方法在公开发现和认证失败时调用，可根据路径参数定位端点。

宿主查询端点配置并调用现有 OAuth token 校验器、Open API Key 校验器。OAuth 需验证签名、有效期、scope、audience/resource 与 mcp_resource_uri 的绑定。宿主建立用户、租户、权限上下文，并设置业务需要的 Ctx 和 attach。

拒绝访问：

```php
throw new \pms\exception\McpAuthenticationException('Authentication required', httpStatus: 401);
```

权限不足传入 httpStatus: 403, error: 'insufficient_scope'。API Key 可以通过异常 headers 定义挑战头。OAuth 401/403 根据宿主元数据生成 WWW-Authenticate: Bearer resource_metadata="..."；resource 由框架按实际端点生成。成功认证写入 authenticated_context 和 mcp_authentication attach。

未配置认证器时返回内部配置错误。公开服务需要宿主显式实现返回 noauth 的认证器。

## 工具处理器

实现 pms\contract\McpToolProviderInterface，静态 listTools() 返回 ToolsListResult，callTool() 返回 CallToolResult。cursor 等参数读取 getAttach('mcp_params')。工具权限、业务映射、inputSchema 及参数校验由宿主管理；声明自定义路由头的参数时需同步验证头值。

callTool() 使用现有 HTTP 业务执行链：

```php
$result = $route->forward(
    \app\demo\http\Query::class,
    $arguments,
    'POST',
    '/demo/index/Query',
)->getResult(\app\demo\http\Query::class);
return ['content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE)]]];
```

forward 复用 HTTP Sandbox 的中间件、校验、prepare/teardown。业务路径按 http.app.route.prefix 添加前缀；认证 attach 保留。执行异常转为 isError 工具结果并写入 PHP 错误日志，McpProtocolException 保留协议错误。

## 协议

- 2026-07-28：server/discover、tools/list、tools/call；校验版本元数据、clientCapabilities、MCP-Protocol-Version、Mcp-Method、Mcp-Name。
- 2025-11-25：initialize、ping、tools/list、tools/call、通知确认；initialize 协商响应为此版本。
- 同步 JSON Streamable HTTP，每次 POST 一条 JSON-RPC；响应 application/json。支持 tools 能力；GET/DELETE 返回 405，元数据 GET 独立处理。
- OPTIONS 返回 204，Origin 必须命中 allowed_origins，响应禁用缓存。
- 自动注册 mcp-http 解释器与两处根入口。转发仍使用原 HTTP 执行路径。MCP_PROTOCOL_CONTEXT 和 mcp_protocol attach 提供协议上下文。
