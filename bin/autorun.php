<?php

use pms\hook\HttpEntrypointHook;
use pms\hook\InterpreterHook;
use pms\interpreter\mcpHttp\Interpreter;

InterpreterHook::mount('mcp-http', Interpreter::class);
HttpEntrypointHook::mount(Interpreter::class);
