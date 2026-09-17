<?php

declare(strict_types=1);

namespace Amor\Api;

final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,params:array,handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $paramNames = [];
        $regex = preg_replace_callback('#\{(\w+)\}#', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $regex = '#^' . $regex . '$#';

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => $regex,
            'params' => $paramNames,
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    /**
     * Dispatches $request. Returns void — handlers write directly via Response.
     * 404 for no matching path, 405 for a matching path with the wrong method
     * (so a caller can tell "doesn't exist" from "wrong verb").
     */
    public function dispatch(Request $request): void
    {
        $pathMatchedAnyMethod = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches)) {
                $pathMatchedAnyMethod = true;
                if ($route['method'] !== $request->method) {
                    continue;
                }
                array_shift($matches);
                $request->routeParams = array_combine($route['params'], $matches);
                ($route['handler'])($request);
                return;
            }
        }

        if ($pathMatchedAnyMethod) {
            throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method not allowed for this path');
        }
        throw new ApiException(404, 'NOT_FOUND', 'No such endpoint');
    }
}
