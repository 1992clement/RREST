<?php

namespace RREST;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use JsonSchema\Validator;
use RREST\APISpec\APISpecInterface;
use RREST\Provider\ProviderInterface;
use RREST\Exception\InvalidParameterException;
use RREST\Exception\InvalidBodyException;

/**
 * ApiSpec + Provider = RREST.
 */
class RREST
{
    /**
     * @var APISpecInterface
     */
    protected $apiSpec;

    /**
     * @var ProviderInterface
     */
    protected $provider;

    /**
     * @var string
     */
    protected $controllerNamespace;

    /**
     * @var array
     */
    protected $hintedHTTPParameters;

    /**
     * @var array|stdClass
     */
    protected $hintedPayloadBody;

    /**
     * @param APISpecInterface  $apiSpec
     * @param ProviderInterface $provider
     * @param string            $controllerNamespace
     */
    public function __construct(APISpecInterface $apiSpec, ProviderInterface $provider, $controllerNamespace = 'Controllers')
    {
        $this->apiSpec = $apiSpec;
        $this->provider = $provider;
        $this->controllerNamespace = $controllerNamespace;
        $this->hintedHTTPParameters = [];
    }

    public function addRoute()
    {
        $routePath = $this->apiSpec->getRoutePath();
        $method = $this->apiSpec->getRouteMethod();
        $controllerClassName = $this->getRouteControllerClassName(
            $this->apiSpec->getRessourcePath()
        );

        $this->assertControllerClassName($controllerClassName);
        $this->assertActionMethodName($controllerClassName, $method);

        $this->provider->addRoute(
            $routePath,
            $method,
            $this->getControllerNamespaceClass($controllerClassName),
            $this->getActionMethodName($method),
            function () {
                $this->assertHTTPProtocol();
                $this->assertHTTPContentType();
                $this->assertHTTPParameters();
                $this->assertHTTPPayloadBody();
                $this->hintHTTPParameterValue($this->hintedHTTPParameters);
                $this->hintHTTPPayloadBody($this->hintedPayloadBody);
            }
        );
    }

    /**
     * @param string $origin
     * @param string $methods
     * @param string $headers
     *
     * @return bool
     */
    public function applyCORS($origin = '*', $methods = 'GET,POST,PUT,DELETE,OPTIONS', $headers = '')
    {
        return $this->provider->applyCORS($origin, $methods, $headers);
    }

    /**
     * @throw AccessDeniedHttpException
     */
    protected function assertHTTPProtocol()
    {
        $supportedHTTPProtocols = array_map('strtoupper', $this->apiSpec->getSupportedHTTPProtocols());
        $httpProtocol = strtoupper($this->provider->getHTTPProtocol());
        if(in_array($httpProtocol, $supportedHTTPProtocols) === false) {
            throw new AccessDeniedHttpException();
        }
    }

    /**
     * @throw UnsupportedMediaTypeHttpException
     */
    protected function assertHTTPContentType()
    {
        $contentTypes = $this->apiSpec->getContentTypes();
        if(
            empty($contentTypes) === false &&
            in_array($this->provider->getContentType(),$contentTypes) === false
        ) {
            throw new UnsupportedMediaTypeHttpException();
        }
    }

    /**
     * @throw InvalidParameterException
     */
    protected function assertHTTPParameters()
    {
        $invalidParametersError = [];
        $parameters = $this->apiSpec->getParameters();
        foreach ($parameters as $parameter) {
            $value = $this->provider->getHTTPParameterValue(
                $parameter->getName(),
                $parameter->getType()
            );
            try {
                $castValue = $this->cast($value, $parameter->getType());
            } catch (\Exception $e) {
                throw new InvalidParameterException([
                    new Error(
                        $e->getMessage(),
                        $e->getCode()
                    ),
                ]);
            }
            try {
                $parameter->assertValue($castValue, $value);
                $this->hintedHTTPParameters[$parameter->getName()] = $castValue;
            } catch (InvalidParameterException $e) {
                $invalidParametersError = array_merge(
                    $e->getErrors(),
                    $invalidParametersError
                );
            }
        }

        if (empty($invalidParametersError) == false) {
            throw new InvalidParameterException($invalidParametersError);
        }
    }

    protected function hintHTTPParameterValue($hintedHTTPParameters)
    {
        foreach ($hintedHTTPParameters as $key => $value) {
            $this->provider->setHTTPParameterValue($key, $value);
        }
    }

    /**
     * @throw RREST\Exception\InvalidBodyException
     */
    protected function assertHTTPPayloadBody()
    {
        $httpContentType = $this->provider->getContentType();
        $payloadBodySchema = $this->apiSpec->getPayloadBodySchema($httpContentType);

        //No payload body here, no need to assert
        if($payloadBodySchema === false) {
            return;
        }

        $payloadBodyValue = $this->provider->getHTTPPayloadBodyValue();

        switch (true) {
            case strpos($httpContentType, 'json') !== false:
                $this->assertHTTPPayloadBodyJSON($payloadBodyValue, $payloadBodySchema);
                break;
            case strpos($httpContentType, 'xml') !== false:
                $this->assertHTTPPayloadBodyXML($payloadBodyValue, $payloadBodySchema);
                break;
            default:
                throw new UnsupportedMediaTypeHttpException();
                break;
        }
    }

    /**
     * @param  string $payloadBodyValue
     * @param  string $payloadBodySchema
     *
     * @throws RREST\Exception\InvalidBodyException
     *
     */
    protected function assertHTTPPayloadBodyXML($payloadBodyValue, $payloadBodySchema)
    {
        $thowInvalidBodyException = function() {
            $invalidBodyError = [];
            $libXMLErrors = libxml_get_errors();
            libxml_clear_errors();
            if (empty($libXMLErrors) === false) {
                foreach ($libXMLErrors as $libXMLError) {
                    $message = $libXMLError->message.' (line: '.$libXMLError->line.')';
                    $invalidBodyError[] = new Error(
                        $message,
                        'invalid-payloadbody-xml'
                    );
                }
                if (empty($invalidBodyError) == false) {
                    throw new InvalidBodyException($invalidBodyError);
                }
            }
        };

        //validate XML
        $originalErrorLevel = libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadXML($payloadBodyValue);
        $thowInvalidBodyException();

        //validate XMLSchema
        $invalidBodyError = [];
        $dom->schemaValidateSource($payloadBodySchema);
        $thowInvalidBodyException();

        libxml_use_internal_errors($originalErrorLevel);
        $this->hintedPayloadBody= $payloadBodyValueXML;
    }

    /**
     * @param  string $payloadBodyValue
     * @param  string $payloadBodySchema
     *
     * @throws RREST\Exception\InvalidBodyException
     *
     */
    protected function assertHTTPPayloadBodyJSON($payloadBodyValue, $payloadBodySchema)
    {
        //validate JSON
        $payloadBodyValueJSON = json_decode($payloadBodyValue);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidBodyException([new Error(
                ucfirst(json_last_error_msg()),
                'invalid-payloadbody-json'
            )]);
        }

        //validate JsonSchema
        $jsonValidator = new Validator();
        $jsonValidator->check($payloadBodyValueJSON, json_decode($payloadBodySchema));
        if ($jsonValidator->isValid() === false) {
            $invalidBodyError = [];
            foreach ($jsonValidator->getErrors() as $jsonError) {
                $invalidBodyError[] = new Error(
                    ucfirst(trim(strtolower(
                        $jsonError['property'].' property: '.$jsonError['message']
                    ))),
                    'invalid-payloadbody-json'
                );
            }
            if (empty($invalidBodyError) == false) {
                throw new InvalidBodyException($invalidBodyError);
            }
        }

        $this->hintedPayloadBody= $payloadBodyValueJSON;
    }

    protected function hintHTTPPayloadBody($hintedPayloadBody)
    {
        $this->provider->setHTTPPayloadBodyValue( $hintedPayloadBody );
    }

    /**
     * @param string $controllerClassName
     * @throw RuntimeException
     *
     * @return string
     */
    protected function assertControllerClassName($controllerClassName)
    {
        $controllerNamespaceClass = $this->getControllerNamespaceClass($controllerClassName);
        if (class_exists($controllerNamespaceClass) == false) {
            throw new \RuntimeException(
                $controllerNamespaceClass.' not found'
            );
        }
    }

    /**
     * @param string $controllerClassName
     * @throw RuntimeException
     *
     * @return string
     */
    protected function assertActionMethodName($controllerClassName, $action)
    {
        $controllerNamespaceClass = $this->getControllerNamespaceClass($controllerClassName);
        $controllerActionMethodName = $this->getActionMethodName($action);
        if (method_exists($controllerNamespaceClass, $controllerActionMethodName) == false) {
            throw new \RuntimeException(
                $controllerNamespaceClass.'::'.$controllerActionMethodName.' method not found'
            );
        }
    }

    /**
     * @param mixed  $value
     * @param string $type
     *
     * @return mixed
     */
    protected function cast($value, $type)
    {
        $castValue = $value;
        if ($type == 'number') {
            $type = 'num';
        }

        if ($type != 'date') {
            $castValue = \CastToType::cast($value, $type, false, true);
        } else {
            //Specific case for date
            $castValue = new \DateTime($value);
        }

        //The cast not working, parameters is probably not this $type
        if (is_null($castValue)) {
            return $value;
        }

        return $castValue;
    }

    /**
     * Return the Controller class name depending of a route path
     * By convention:
     *  - /item/{itemId}/ -> Item
     *  - /item/{itemId}/comment -> Item\Comment.
     *
     * @param string $routePath
     *
     * @return string
     */
    protected function getRouteControllerClassName($routePath)
    {
        // remove URI parameters like controller/id/subcontroller/50
        $controllerClassName = preg_replace('/\{[^}]+\}/', ' ', $routePath);
        $controllerClassName = trim(str_replace('/ /', ' ', $controllerClassName));
        $controllerClassName = trim(str_replace('/', '', $controllerClassName));
        // uppercase the first character of each word
        $controllerClassName = ucwords($controllerClassName);
        // namespace
        $controllerClassName = str_replace(' ', '\\', $controllerClassName);

        return $controllerClassName;
    }

    /**
     * @param string $action
     *
     * @return string
     */
    protected function getActionMethodName($action)
    {
        return $action.'Action';
    }

    /**
     * @param string $controllerClassName
     *
     * @return string
     */
    private function getControllerNamespaceClass($controllerClassName)
    {
        return $this->controllerNamespace.'\\'.$controllerClassName;
    }
}
