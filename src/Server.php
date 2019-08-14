<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace Hyperf\HttpServer;

use FastRoute\Dispatcher;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\MiddlewareInitializerInterface;
use Hyperf\Contract\OnRequestInterface;
use Hyperf\Dispatcher\HttpDispatcher;
use Hyperf\ExceptionHandler\ExceptionHandlerDispatcher;
use Hyperf\HttpMessage\Server\Request as Psr7Request;
use Hyperf\HttpMessage\Server\Response as Psr7Response;
use Hyperf\HttpServer\Exception\Handler\HttpExceptionHandler;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\HttpServer\Router\DispatcherFactory;
use Hyperf\HttpServer\Router\Handler;
use Hyperf\Utils\Context;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Throwable;

class Server implements OnRequestInterface, MiddlewareInitializerInterface
{
    /**
     * @var array
     */
    protected $middlewares;

    /**
     * @var MiddlewareInterface
     */
    protected $coreMiddleware;

    /**
     * @var array
     */
    protected $exceptionHandlers;

    /**
     * @var \Psr\Container\ContainerInterface
     */
    protected $container;

    /**
     * @var HttpDispatcher
     */
    protected $dispatcher;

    /**
     * @var Dispatcher
     */
    protected $routerDispatcher;

    /**
     * @var string
     */
    protected $serverName;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->dispatcher = $container->get(HttpDispatcher::class);
    }

    public function initCoreMiddleware(string $serverName): void
    {
        $this->serverName = $serverName;
        $this->coreMiddleware = $this->createCoreMiddleware();
        $this->routerDispatcher = $this->createDispatcher($serverName);

        $config = $this->container->get(ConfigInterface::class);
        $this->middlewares = $config->get('middlewares.' . $serverName, []);
        $this->exceptionHandlers = $config->get('exceptions.handler.' . $serverName, $this->getDefaultExceptionHandler());
    }

    public function onRequest(SwooleRequest $request, SwooleResponse $response): void
    {
        try {
            [$psr7Request, $psr7Response] = $this->initRequestAndResponse($request, $response);

            /**
             * @var array
             * @var ServerRequestInterface $psr7Request
             * @var Dispatched $dispatched
             */
            [$psr7Request, $dispatched] = $this->coreMiddleware->dispatch($psr7Request);
            $middlewares = $this->middlewares;
            if ($dispatched->isFind()) {
                $registedMiddlewares = MiddlewareManager::get($this->serverName, $dispatched->handler->route, $psr7Request->getMethod());
                $middlewares = array_merge($middlewares, $registedMiddlewares);
            }

            $psr7Response = $this->dispatcher->dispatch($psr7Request, $middlewares, $this->coreMiddleware);
        } catch (Throwable $throwable) {
            // Delegate the exception to exception handler.
            $exceptionHandlerDispatcher = $this->container->get(ExceptionHandlerDispatcher::class);
            $psr7Response = $exceptionHandlerDispatcher->dispatch($throwable, $this->exceptionHandlers);
        } finally {
            // Send the Response to client.
            if (! isset($psr7Response) || ! $psr7Response instanceof Psr7Response) {
                return;
            }
            $psr7Response->send();
        }
    }

    public function getServerName(): string
    {
        return $this->serverName;
    }

    /**
     * @return $this
     */
    public function setServerName(string $serverName)
    {
        $this->serverName = $serverName;
        return $this;
    }

    protected function createDispatcher(string $serverName): Dispatcher
    {
        $factory = $this->container->get(DispatcherFactory::class);
        return $factory->getDispatcher($serverName);
    }

    protected function getDefaultExceptionHandler(): array
    {
        return [
            HttpExceptionHandler::class,
        ];
    }

    protected function createCoreMiddleware(): MiddlewareInterface
    {
        return new CoreMiddleware($this->container, $this->serverName);
    }

    protected function initRequestAndResponse(SwooleRequest $request, SwooleResponse $response): array
    {
        // Initialize PSR-7 Request and Response objects.
        Context::set(ServerRequestInterface::class, $psr7Request = Psr7Request::loadFromSwooleRequest($request));
        Context::set(ResponseInterface::class, $psr7Response = new Psr7Response($response));
        return [$psr7Request, $psr7Response];
    }
}
