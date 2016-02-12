<?php

namespace RREST\Provider;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;
use RREST\Response;
use Silex\Application;

/**
 * Silex provider.
 */
class Silex implements ProviderInterface
{
    /**
     * @var Request
     */
    protected $request;

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
        $this->request = Request::createFromGlobals();
    }

    /**
     * {@inheritdoc}
     */
    public function addRoute($routePath, $method, $controllerClassName, $actionMethodName, Response $response, \Closure $init)
    {
        $controller = $this->app->match(
            $routePath,
            $controllerClassName.'::'.$actionMethodName
        )
        ->method(strtoupper($method))
        //define a response configured
        ->value('response', $response)
        ->before(function(Request $request) use ($init) {
            $this->request = $request;
            $init();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function applyCORS($origin = '*', $methods = 'GET,POST,PUT,DELETE,OPTIONS', $headers = '')
    {
        $this->app->before(function (Request $request) use ($origin, $methods, $headers) {
            if ($request->getMethod() === 'OPTIONS') {
                $response = new HttpFoundationResponse();
                $response->headers->set("Access-Control-Allow-Origin",$origin);
                $response->headers->set("Access-Control-Allow-Methods",$methods);
                $response->headers->set("Access-Control-Allow-Headers",$headers);
                $response->setStatusCode(200);
                return $response;
            }
        }, Application::EARLY_EVENT);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getHTTPProtocol()
    {
        return $this->request->getScheme();
    }

    /**
     * {@inheritdoc}
     */
    public function getHTTPParameterValue($key, $type)
    {
        $parameterBags = ['query', 'request', 'attributes'];
        // Search in all Silex Request parameters
        foreach ($parameterBags as $parameterBag) {
            $requestParam = $this->request->{$parameterBag};
            if ($requestParam->has($key)) {
                return $requestParam->get($key);
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function setHTTPParameterValue($key, $value)
    {
        $parameterBags = ['query', 'request', 'attributes'];
        // Search in Silex Request parameters
        foreach ($parameterBags as $parameterBag) {
            $requestParam = $this->request->{$parameterBag};
            if ($requestParam->has($key)) {
                $requestParam->set($key, $value);
                //stop searching parameter when finding one
                break;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getHTTPPayloadBodyValue()
    {
        return $this->request->getContent();
    }

    /**
     * {@inheritdoc}
     */
    public function setHTTPPayloadBodyValue($payloadBodyJSON)
    {
        //no better way to setContent of the Request
        $this->request->initialize(
            $this->request->query->all(),
            $this->request->request->all(),
            $this->request->attributes->all(),
            $this->request->cookies->all(),
            $this->request->files->all(),
            $this->request->server->all(),
            $payloadBodyJSON
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getHTTPHeaderContentType()
    {
        return $this->request->headers->get('Content-Type');
    }

    /**
     * {@inheritdoc}
     */
    public function getHTTPHeaderAccept()
    {
        //Accept is empty in header, a bug?
        //return $this->request->headers->get('Accept');
        return $this->request->server->get('HTTP_ACCEPT');
    }

    /**
     * {@inheritdoc}
     */
    public function getResponse($content = '', $statusCode = 200, $headers = array())
    {
        return new HttpFoundationResponse($content, $statusCode, $headers);
    }
}
