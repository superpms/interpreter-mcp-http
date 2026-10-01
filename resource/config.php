<?php

/** MCP HTTP 宿主配置，安装钩子保留已存在的配置文件。 */
return [
    // 公网域名；null 使用当前请求域名。反向代理部署建议显式填写。
    'public_origin' => null,
    'allowed_origins' => [],
    'route' => [
        'pattern' => '{terminal}/{endpoint_id}',
        'constraints' => [
            'terminal' => '[A-Za-z0-9:_-]+',
            'endpoint_id' => '[0-9]{1,20}',
        ],
    ],
    'server_info' => ['name' => 'superpms-mcp', 'version' => '1.0.0'],
    'instructions' => '',
    'auth' => [
        // 宿主 McpAuthenticatorInterface 实现类，未配置时拒绝提供服务。
        'handler' => null,
    ],
    'tools' => [
        // 宿主 McpToolProviderInterface 实现类，null 时不声明 tools 能力。
        'handler' => null,
    ],
];
