<?php

namespace pms\contract;

use pms\inject\HttpRequestInject;

interface McpAuthenticatorInterface
{
    /** 返回 scheme（oauth2、apiKey、noauth）及 context；拒绝访问抛出 McpAuthenticationException。 */
    public static function authenticate(HttpRequestInject $request): array;

    /** OAuth 返回 authorization_servers、scopes_supported 等资源元数据；其他认证返回 null。 */
    public static function oauthMetadata(HttpRequestInject $request): ?array;
}
