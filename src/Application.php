<?php namespace Phprest;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Phprest\Exception\Exception;
use Orno\Di\Exception\ReflectionException;
use Phprest\Service;
use Phprest\Entity;

class Application extends \Proton\Application
{
    const CNTRID_DEBUG = 'debug';
    const CNTRID_VENDOR = 'vendor';
    const CNTRID_API_VERSION = 'api-version';
    const CNTRID_ROUTER = 'router';

    const API_VERSION_REG_EXP = '((?:[0-9](?:\.[0-9])?){1})';

    use Service\Hateoas\Getter, Service\Hateoas\Util;
    use Service\Logger\Getter;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var array
     */
    protected $registeredServiceNames = [];

    /**
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->container = $config->getContainer();
        $this->router = $config->getRouter();
        $this->eventEmitter = $config->getEventEmitter();

        AnnotationRegistry::registerLoader('class_exists');

        $this->setErrorHandlers();

        $this->registerService($config->getHateoasService(), $config->getHateoasConfig());
        if ( ! is_null($config->getLoggerConfig()) and ! is_null($config->getLoggerService())) {
            $this->registerService($config->getLoggerService(), $config->getLoggerConfig());
        }

        $this->container->add(self::CNTRID_VENDOR, $config->getVendor());
        $this->container->add(self::CNTRID_API_VERSION, $config->getApiVersion());
        $this->container->add(self::CNTRID_DEBUG, $config->isDebug());
        $this->container->add(self::CNTRID_ROUTER, function() { return $this->router; } );
    }

    /**
     * @param Service\Serviceable $service
     * @param Service\Configurable $config
     *
     * @return void
     *
     * @throws \Exception
     */
    public function registerService(Service\Serviceable $service, Service\Configurable $config)
    {
        if (in_array($config->getServiceName(), $this->registeredServiceNames)) {
            throw new \Exception('Service <' . $config->getServiceName() . '> has been already registered!');
        }

        $service->register($this->container, $config);

        $this->registeredServiceNames[] = $config->getServiceName();
    }

    /**
     * @param string $class Namespaced class name
     *
     * @return void
     */
    public function registerController($class)
    {
        $controller = new $class($this->container);

        $this->container->add($class, function () use ($controller) {
            return $controller;
        });
    }

    /**
     * Run the application
     *
     * @param Request $request
     *
     * @return string
     */
    public function run(Request $request = null)
    {
        $app = (new \Stack\Builder())
            ->push('Phprest\Middleware\ApiVersion')
            ->resolve($this);

        if (null === $request) {
            $request = Request::createFromGlobals();
        }

        $response = $app->handle($request);
        $response->send();

        $app->terminate($request, $response);
    }

    /**
     * Add a HEAD route
     *
     * @param string $route
     * @param mixed $action
     *
     * @return void
     */
    public function head($route, $action)
    {
        $this->router->addRoute('HEAD', $route, $action);
    }

    /**
     * Add a OPTIONS route
     *
     * @param string $route
     * @param mixed $action
     *
     * @return void
     */
    public function options($route, $action)
    {
        $this->router->addRoute('OPTIONS', $route, $action);
    }

    /**
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @return void
     */
    protected function setErrorHandlers()
    {
        set_error_handler(function($errNo, $errStr, $errFile, $errLine) {
            if (0 === error_reporting()) { # error is caused with @ operator
                return false;
            }
            throw new \ErrorException($errStr, 0, $errNo, $errFile, $errLine);
        });

        $exceptionHandler = function(\Exception $exception) {
            $this->exceptionHandler($exception);
        };

        set_exception_handler($exceptionHandler);

        register_shutdown_function(function() use ($exceptionHandler) {
            if (($error = error_get_last())
                and in_array(
                    $error['type'],
                    [E_ERROR, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING, E_PARSE]
                )
            ) {
                call_user_func(
                    $exceptionHandler,
                    new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line'])
                );
            }
        });

        $this->setExceptionDecorator(function (\Exception $e) {
            throw $e;
        });
    }

    /**
     * @param \Exception $exception
     *
     * @return void
     */
    protected function exceptionHandler(\Exception $exception)
    {
        if ( ! $this->config->isDebug()) {
            try {
                if ($exception instanceof Exception) {
                    $this->serviceLogger()->addError(
                        $exception->getMessage() .
                        '(' . $exception->getCode() . ')' .
                        ' :: Details: ' .
                        implode(';', $exception->getDetails()) .
                        ' :: Stack Trace: ' .
                        $exception->getTraceAsString()
                    );

                    $exception = new Exception( $this->config->getLoggerConfig()->prodErrorMessage,
                        $exception->getCode(),
                        $exception->getStatusCode(),
                        $exception->getDetails(),
                        $exception->getPrevious()
                    );
                } else {
                    $this->serviceLogger()->addError(
                        $exception->getMessage() .
                        '(' . $exception->getCode() . ')' .
                        ' :: Stack Trace: ' .
                        $exception->getTraceAsString()
                    );

                    $exception = new \Exception($this->config->getLoggerConfig()->prodErrorMessage,
                        $exception->getCode(),
                        $exception->getPrevious()
                    );
                }
            } catch (ReflectionException $e) {
            }
        }

        $this->getExceptionResponse($exception)->send();
    }

    /**
     * Returns with a serialized response.
     *
     * @param \Exception $exception
     *
     * @return Response
     *
     * @throws \Exception
     */
    protected function getExceptionResponse(\Exception $exception)
    {
        if (php_sapi_name() === 'cli') {
            throw $exception;
        }

        $response = new Response();

        try {
            $response = $this->serialize(
                ! $this->config->isDebug() ? new Entity\Error($exception) : new Entity\DebugError($exception),
                Request::createFromGlobals(),
                $response
            );
        } catch (\Exception $e) {
            $response->setContent(
                $this->serviceHateoas()->getSerializer()->serialize(
                    ! $this->config->isDebug() ? new Entity\Error($exception) : new Entity\DebugError($exception),
                    'json'
                )
            );

            $vendor = $this->container->get(self::CNTRID_VENDOR);
            $apiVersion = $this->container->get(self::CNTRID_API_VERSION);

            $response->headers->set('Content-Type', 'application/vnd.' . $vendor . '-v' . $apiVersion . '+json');
        }

        $response->setStatusCode(method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500);

        return $response;
    }
}
