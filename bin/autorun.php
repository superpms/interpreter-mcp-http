<?php

use pms\hook\HttpEntrypointHook;
use pms\hook\InterpreterHook;
use pms\interpreter\mcpHttp\Interpreter;

InterpreterHook::mount('mcp-http', Interpreter::class);
HttpEntrypointHook::mount('/mcp', Interpreter::class);
HttpEntrypointHook::mount('/.well-known/oauth-protected-resource/mcp', Interpreter::class);
