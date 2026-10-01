<?php

/** MCP HTTP 宿主配置，安装钩子保留已存在的配置文件。 */
return [
    // 公网域名；null 使用当前请求域名。反向代理部署建议显式填写。
    'public_origin' => null,
    'allowed_origins' => [],
    'route' => [
        // 固定挂载路径，支持 gateway/mcp 等多级路径。
        'prefix' => 'mcp',
        'pattern' => '{terminal}/{endpoint_id}',
        'constraints' => [
            'terminal' => '[A-Za-z0-9:_-]+',
            'endpoint_id' => '[0-9]{1,20}',
        ],
    ],
    'server_info' => ['name' => 'superpms-mcp', 'version' => '1.0.0'],
    'instructions' => '',
    'auth' => [
        // 每项 handler 实现 McpAuthenticatorInterface，header 决定本次认证方式。
        // 同时携带多个凭据时拒绝请求；校验失败直接结束。
        'mechanisms' => [
            // 'oauth2' => ['handler' => HostOauthAuthenticator::class, 'header' => 'Authorization'],
            // 'apiKey' => ['handler' => HostOpenApiAuthenticator::class, 'header' => 'x-token'],
            // 公开服务单独登记 'noauth' => ['handler' => HostPublicAuthenticator::class]。
        ],
    ],
    'tools' => [
        // 宿主 McpToolProviderInterface 实现类，null 时不声明 tools 能力。
        'handler' => null,
    ],
];
