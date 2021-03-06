<?php namespace Phprest\Middleware;

use Phprest\Application;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Request as BaseRequest;
use Phprest\HttpFoundation\Request;
use Phprest\Service;
use Negotiation\FormatNegotiator;

class ApiVersion implements HttpKernelInterface
{
    use Service\Hateoas\Util;

    /**
     * @var Application
     */
    protected $app;

    /**
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * @param BaseRequest $request
     * @param int $type
     * @param bool $catch
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(BaseRequest $request, $type = self::MASTER_REQUEST, $catch = true)
    {
        $request = new Request($request);
        $mimeProcResult = $this->processMime(
            (new FormatNegotiator())->getBest($request->headers->get('Accept', '*/*'))->getValue()
        );

        $request->setApiVersion(
            str_pad($mimeProcResult->apiVersion, 3, '.0')
        );

        return $this->app->handle($request, $type, $catch);
    }

    /**
     * Returns the DI container
     *
     * @return \Orno\Di\Container
     */
    protected function getContainer()
    {
        return $this->app->getConfig()->getContainer();
    }

    /**
     * @return \Hateoas\Hateoas
     */
    protected function serviceHateoas()
    {
        return $this->app->getConfig()->getHateoasService();
    }
}
