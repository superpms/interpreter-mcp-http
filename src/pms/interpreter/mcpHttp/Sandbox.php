<?php

namespace pms\interpreter\mcpHttp;

use JsonException;
use LogicException;
use pms\contract\McpAuthenticatorInterface;
use pms\contract\McpToolProviderInterface;
use pms\exception\McpAuthenticationException;
use pms\exception\McpProtocolException;
use pms\inject\HttpRequestInject;
use pms\inject\HttpResponseInject;
use pms\interpreter\http\sandbox\HttpRoute;
use stdClass;
use Throwable;

/** 同步 JSON Streamable HTTP：协议处理在框架内，认证与工具由宿主提供。 */
class Sandbox
{
    public const CURRENT_VERSION = '2026-07-28';
    public const INITIALIZE_VERSION = '2025-11-25';
    protected int|string|null $id = null;
    protected bool $modern = false;
    protected string $version = self::INITIALIZE_VERSION;
    protected array $authMechanisms = [];
    protected ?string $authenticationScheme = null;
    protected string $resource = '';
    protected string $metadataUri = '';

    public function __construct(
        protected HttpRequestInject $request,
        protected HttpResponseInject $response,
    ) {}

    public function run(): void
    {
        $this->response->header('Content-Type', 'application/json; charset=utf-8');
        $this->response->header('Cache-Control', 'no-store');
        try {
            $match = McpRoute::match($this->request->pathinfo(), config('mcp.route', []));
            if ($match === null) {
                $this->json(['error' => 'MCP endpoint not found'], 404);
                return;
            }
            $this->request->mergeRouteParams($match['params']);
            $this->request->setAttach('mcp_route_params', $match['params']);
            $this->request->setAttach('mcp_resource_path', $match['resource_path']);
            $origin = rtrim(config('mcp.public_origin', null) ?? $this->request->domain(), '/');
            $uri = parse_url($origin);
            if (!is_array($uri) || !in_array($uri['scheme'] ?? '', ['http', 'https'], true)
                || empty($uri['host']) || (isset($uri['user']) || isset($uri['pass']))
                || isset($uri['query']) || isset($uri['fragment']) || !empty($uri['path'])) {
                throw new LogicException('mcp.public_origin 需要有效的公开 HTTP 域名');
            }
            $this->resource = $origin . $match['resource_path'];
            $this->metadataUri = $origin . '/.well-known/oauth-protected-resource' . $match['resource_path'];
            $this->request->setAttach('mcp_resource_uri', $this->resource);
            $this->origin();
            if ($this->request->isOptions()) {
                $this->response->status(204);
                $this->response->end();
                return;
            }
            $this->authMechanisms = $this->authenticationMechanisms();
            if ($match['metadata']) {
                if (!$this->request->isGet()) {
                    $this->response->header('Allow', 'GET, OPTIONS');
                    $this->json(['error' => 'Method not allowed'], 405);
                    return;
                }
                $metadata = $this->oauthMetadata();
                $this->json($metadata ?? ['error' => 'OAuth metadata unavailable'], $metadata === null ? 404 : 200);
                return;
            }
            $authentication = $this->authenticate();
            $this->request->setAttach('mcp_authentication', $authentication);
            $this->request->setAttach('authenticated_context', $authentication['context']);
            if (!$this->request->isPost()) {
                $this->response->header('Allow', 'POST, OPTIONS');
                $this->json(['error' => 'Method not allowed'], 405);
                return;
            }
            if (strtolower(trim(explode(';', $this->request->contentType(), 2)[0])) !== 'application/json') {
                throw new McpProtocolException('Content-Type must be application/json', -32600, 415);
            }
            if (!str_contains(strtolower((string)$this->request->header('accept', '')), 'application/json')) {
                throw new McpProtocolException('Accept must include application/json', -32600, 406);
            }
            try {
                $message = json_decode((string)$this->request->rawContent(), false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new McpProtocolException('Parse error', -32700);
            }
            if (!$message instanceof stdClass || ($message->jsonrpc ?? null) !== '2.0'
                || !is_string($message->method ?? null) || $message->method === '') {
                throw new McpProtocolException('Invalid Request', -32600);
            }
            if (property_exists($message, 'id')) {
                if (!is_int($message->id) && !is_string($message->id)) {
                    throw new McpProtocolException('Invalid request id', -32600);
                }
                $this->id = $message->id;
            }
            $paramsObject = property_exists($message, 'params') ? $message->params : new stdClass();
            if (!$paramsObject instanceof stdClass) {
                throw new McpProtocolException('params must be an object');
            }
            $params = json_decode(json_encode($paramsObject, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $this->protocol($message->method, $paramsObject);
            // notifications/initialized、notifications/cancelled 属于初始化版协议。
            if (!property_exists($message, 'id')) {
                if ($this->modern || !str_starts_with($message->method, 'notifications/')) {
                    throw new McpProtocolException('Request id required', -32600);
                }
                $this->response->status(202);
                $this->response->end();
                return;
            }
            $this->request->setAttach('mcp_params', $params);
            $protocol = [
                'modern' => $this->modern,
                'modern_version' => self::CURRENT_VERSION,
                'legacy_version' => self::INITIALIZE_VERSION,
                'server_info' => $this->serverInfo(),
                'oauth' => $authentication['scheme'] === 'oauth2',
            ];
            $this->request->setAttach('mcp_protocol', $protocol);
            $route = new HttpRoute($this->request->pathinfo(), self::class);
            $route->activate($this->request, $this->response);
            $route->getCoroutine()->set('MCP_PROTOCOL_CONTEXT', $protocol);
            $result = match ($message->method) {
                'server/discover' => $this->discover(),
                'initialize' => $this->initialize($paramsObject, $params),
                'ping' => $this->ping(),
                'tools/list' => ($this->tools())::listTools($this->request, $route),
                'tools/call' => $this->callTool($paramsObject, $params, $route),
                default => throw new McpProtocolException('Method not found', -32601, $this->modern ? 404 : 200),
            };
            if ($this->modern) {
                $result['resultType'] = 'complete';
                $result['_meta'] = array_merge((array)($result['_meta'] ?? []), ['serverInfo' => $this->serverInfo()]);
            }
            $this->json(['jsonrpc' => '2.0', 'id' => $this->id, 'result' => $result === [] ? new stdClass() : $result]);
        } catch (McpAuthenticationException $e) {
            $this->authenticationError($e);
        } catch (McpProtocolException $e) {
            $error = ['code' => $e->rpcCode, 'message' => $e->getMessage()];
            if ($e->data !== null) {
                $error['data'] = $e->data;
            }
            $this->json(['jsonrpc' => '2.0', 'id' => $this->id, 'error' => $error], $e->httpStatus);
        } catch (Throwable $e) {
            error_log('[mcp-http] ' . $e);
            $this->json(['jsonrpc' => '2.0', 'id' => $this->id,
                'error' => ['code' => -32603, 'message' => 'Internal error']], 500);
        }
    }

    protected function origin(): void
    {
        // 覆盖业务 HTTP 的 CORS，MCP 只返回明确允许的 Origin。
        $this->response->header('Access-Control-Allow-Origin', []);
        $this->response->header('Access-Control-Allow-Credentials', []);
        $origin = (string)$this->request->header('origin', '');
        if ($origin !== '') {
            if (!in_array($origin, config('mcp.allowed_origins', []), true)) {
                throw new McpProtocolException('Origin forbidden', -32600, 403);
            }
            $this->response->header('Access-Control-Allow-Origin', $origin);
            $this->response->header('Vary', 'Origin');
        }
        $this->response->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
        $headers = ['Content-Type', 'MCP-Protocol-Version', 'Mcp-Method', 'Mcp-Name'];
        foreach ((array)config('mcp.auth.mechanisms', []) as $mechanism) {
            if (is_array($mechanism) && is_string($mechanism['header'] ?? null)) {
                $headers[] = $mechanism['header'];
            }
        }
        $this->response->header('Access-Control-Allow-Headers', implode(', ', array_unique($headers)));
        $this->response->header('Access-Control-Expose-Headers', 'WWW-Authenticate, MCP-Protocol-Version');
    }


    /**
     * 读取认证机制配置，要求各凭据请求头唯一，公开认证单独登记。
     *
     * @return array<string, array>
     */
    protected function authenticationMechanisms(): array
    {
        $mechanisms = config('mcp.auth.mechanisms', []);
        if (!is_array($mechanisms) || $mechanisms === []) {
            throw new LogicException('mcp.auth.mechanisms 需要登记认证器');
        }
        $headers = [];
        foreach ($mechanisms as $scheme => $mechanism) {
            if (!in_array($scheme, ['oauth2', 'apiKey', 'noauth'], true) || !is_array($mechanism)) {
                throw new LogicException('MCP 认证机制配置无效');
            }
            $handler = $mechanism['handler'] ?? null;
            if (!is_string($handler) || !is_subclass_of($handler, McpAuthenticatorInterface::class)) {
                throw new LogicException('MCP 认证器需要实现 McpAuthenticatorInterface');
            }
            if ($scheme === 'noauth') {
                if (count($mechanisms) !== 1 || isset($mechanism['header'])) {
                    throw new LogicException('noauth 需要单独登记且不设置凭据请求头');
                }
                continue;
            }
            $header = $mechanism['header'] ?? null;
            if (!is_string($header) || preg_match("/^[A-Za-z0-9!#$%&'*+.^_`|~-]+$/D", $header) !== 1) {
                throw new LogicException('MCP 认证机制需要有效的 header');
            }
            $header = strtolower($header);
            if (isset($headers[$header])) {
                throw new LogicException('MCP 认证凭据请求头重复：' . $header);
            }
            $headers[$header] = true;
            $mechanisms[$scheme]['header'] = $header;
        }
        return $mechanisms;
    }

    /**
     * 按凭据请求头选择一次认证，冲突和失败直接结束请求。
     *
     * @return array{scheme: string, context: array}
     */
    protected function authenticate(): array
    {
        $selected = [];
        foreach ($this->authMechanisms as $scheme => $mechanism) {
            if ($scheme === 'noauth' || $this->request->header($mechanism['header'], '') !== '') {
                $selected[] = $scheme;
            }
        }
        if (count($selected) > 1) {
            throw new McpAuthenticationException('MCP 请求只能提交一种认证凭据', 400, 'invalid_request');
        }
        if ($selected === []) {
            throw new McpAuthenticationException('需要 MCP 认证凭据');
        }
        $scheme = $selected[0];
        $this->authenticationScheme = $scheme;
        $mechanism = $this->authMechanisms[$scheme];
        $this->request->setAttach('mcp_auth_scheme', $scheme);
        $this->request->setAttach('mcp_auth_config', $mechanism);
        $authentication = ($mechanism['handler'])::authenticate($this->request);
        if (($authentication['scheme'] ?? null) !== $scheme || !is_array($authentication['context'] ?? null)) {
            throw new LogicException('MCP 认证结果需要匹配配置的 scheme 并返回 context');
        }
        return $authentication;
    }

    /**
     * 读取宿主静态说明及运行时说明。
     */
    protected function instructions(): string
    {
        $instructions = config('mcp.instructions', '');
        if (is_callable($instructions)) {
            $instructions = $instructions();
        }
        if (!is_string($instructions)) {
            throw new LogicException('mcp.instructions 需要字符串及返回字符串的 callable');
        }
        return $instructions;
    }

    protected function protocol(string $method, stdClass $params): void
    {
        $meta = $params->_meta ?? null;
        $bodyVersion = $meta instanceof stdClass ? ($meta->{'io.modelcontextprotocol/protocolVersion'} ?? null) : null;
        $headerVersion = (string)$this->request->header('mcp-protocol-version', '');
        $version = $headerVersion !== '' ? $headerVersion : ($bodyVersion ?? self::INITIALIZE_VERSION);
        if (!in_array($version, [self::CURRENT_VERSION, self::INITIALIZE_VERSION], true)) {
            throw new McpProtocolException('Unsupported protocol version', -32022, 400,
                ['supportedVersions' => [self::CURRENT_VERSION, self::INITIALIZE_VERSION], 'requestedVersion' => $version]);
        }
        $this->modern = $version === self::CURRENT_VERSION || $bodyVersion === self::CURRENT_VERSION;
        $this->version = $version;
        if ($this->modern) {
            if (!$meta instanceof stdClass || $bodyVersion !== self::CURRENT_VERSION
                || !($meta->{'io.modelcontextprotocol/clientCapabilities'} ?? null) instanceof stdClass) {
                throw new McpProtocolException('Protocol metadata required');
            }
            if ($headerVersion !== $bodyVersion || $this->request->header('mcp-method') !== $method) {
                throw new McpProtocolException('Protocol routing header mismatch', -32020);
            }
            if ($method === 'tools/call') {
                $headerName = (string)$this->request->header('mcp-name', '');
                if (preg_match('/^=\\?base64\\?(.+)\\?=$/D', $headerName, $match)) {
                    $decoded = base64_decode($match[1], true);
                    $headerName = $decoded === false ? '' : $decoded;
                }
                if ($headerName === '' || $headerName !== ($params->name ?? null)) {
                    throw new McpProtocolException('Mcp-Name mismatch', -32020);
                }
            }
        }
        $this->response->header('MCP-Protocol-Version', $version);
    }

    protected function serverInfo(): array
    {
        $info = config('mcp.server_info', ['name' => 'superpms-mcp', 'version' => '1.0.0']);
        if (!is_array($info) || !is_string($info['name'] ?? null) || !is_string($info['version'] ?? null)) {
            throw new LogicException('mcp.server_info 需要 name 与 version');
        }
        return $info;
    }

    protected function capabilities(): stdClass
    {
        $capabilities = new stdClass();
        if (config('mcp.tools.handler') !== null) {
            $this->tools();
            $capabilities->tools = new stdClass();
        }
        return $capabilities;
    }

    protected function discover(): array
    {
        if (!$this->modern) {
            throw new McpProtocolException('Method not found', -32601, 200);
        }
        return [
            'supportedVersions' => [self::CURRENT_VERSION],
            'capabilities' => $this->capabilities(),
            'instructions' => $this->instructions(),
            'ttlMs' => 0,
            'cacheScope' => 'private',
        ];
    }

    protected function initialize(stdClass $object, array $params): array
    {
        if ($this->modern) {
            throw new McpProtocolException('Method not found', -32601, 404);
        }
        if (!is_string($params['protocolVersion'] ?? null)
            || !($object->capabilities ?? null) instanceof stdClass
            || !($object->clientInfo ?? null) instanceof stdClass
            || !is_string($params['clientInfo']['name'] ?? null) || !is_string($params['clientInfo']['version'] ?? null)) {
            throw new McpProtocolException('Invalid initialize params');
        }
        return [
            'protocolVersion' => self::INITIALIZE_VERSION,
            'serverInfo' => $this->serverInfo(),
            'capabilities' => $this->capabilities(),
            'instructions' => $this->instructions(),
        ];
    }

    protected function ping(): array
    {
        if ($this->modern) {
            throw new McpProtocolException('Method not found', -32601, 404);
        }
        return [];
    }

    protected function tools(): string
    {
        $handler = config('mcp.tools.handler');
        if (!is_string($handler) || !is_subclass_of($handler, McpToolProviderInterface::class)) {
            throw new McpProtocolException('Tools unavailable', -32601, $this->modern ? 404 : 200);
        }
        return $handler;
    }

    protected function callTool(stdClass $object, array $params, HttpRoute $route): array
    {
        if (!is_string($params['name'] ?? null) || $params['name'] === ''
            || (property_exists($object, 'arguments') && !$object->arguments instanceof stdClass)) {
            throw new McpProtocolException('Invalid tools/call params');
        }
        $provider = $this->tools();
        try {
            return $provider::callTool($this->request, $route, $params['name'], $params['arguments'] ?? []);
        } catch (McpProtocolException $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log('[mcp-http tool] ' . $e);
            return ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Tool execution failed']]];
        }
    }

    protected function oauthMetadata(): ?array
    {
        $handler = $this->authMechanisms['oauth2']['handler'] ?? null;
        if ($handler === null) {
            return null;
        }
        $this->request->setAttach('mcp_auth_scheme', 'oauth2');
        $this->request->setAttach('mcp_auth_config', $this->authMechanisms['oauth2']);
        $metadata = $handler::oauthMetadata($this->request);
        if ($metadata === null) {
            return null;
        }
        if (!is_array($metadata['authorization_servers'] ?? null) || $metadata['authorization_servers'] === []) {
            throw new LogicException('OAuth metadata 需要 authorization_servers');
        }
        $metadata['resource'] = $this->resource;
        return $metadata;
    }

    protected function authenticationError(McpAuthenticationException $e): void
    {
        $headers = $e->headers;
        if ($this->authenticationScheme !== 'apiKey' && in_array($e->httpStatus, [401, 403], true)
            && !array_key_exists('www-authenticate', array_change_key_case($headers))) {
            try {
                $metadata = $this->oauthMetadata();
                if ($metadata !== null) {
                    $quote = static fn(string $value): string => '"' . addcslashes(str_replace(["\r", "\n"], '', $value), '"\\') . '"';
                    $challenge = 'Bearer resource_metadata=' . $quote($this->metadataUri);
                    if (!empty($metadata['scopes_supported'])) {
                        $challenge .= ', scope=' . $quote(implode(' ', $metadata['scopes_supported']));
                    }
                    if ($e->httpStatus === 403 || $this->request->header('authorization', '') !== '') {
                        $challenge .= ', error=' . $quote($e->error);
                    }
                    $headers['WWW-Authenticate'] = $challenge;
                }
            } catch (McpAuthenticationException $metadataError) {
                error_log('[mcp-http metadata] ' . $metadataError);
            } catch (Throwable $metadataError) {
                error_log('[mcp-http metadata] ' . $metadataError);
                $this->json(['error' => 'server_error'], 500);
                return;
            }
        }
        foreach ($headers as $name => $value) {
            $this->response->header($name, $value);
        }
        $this->json(['error' => $e->error, 'error_description' => $e->getMessage()], $e->httpStatus);
    }

    protected function json(array $body, int $status = 200): void
    {
        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->response->status($status);
        $this->response->end($encoded);
    }
}
