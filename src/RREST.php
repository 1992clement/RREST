<?php

namespace RREST;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use JsonSchema\Validator;
use RREST\APISpec\APISpecInterface;
use RREST\Provider\ProviderInterface;
use RREST\Exception\InvalidParameterException;
use RREST\Exception\InvalidBodyException;
use RREST\Response;

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
     * @var array[]
     */
    protected $formats = [
        'json' => ['application/json', 'application/x-json'],
        'xml' => ['text/xml', 'application/xml', 'application/x-xml'],
    ];

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
        $method = $this->apiSpec->getRouteMethod();
        $controllerClassName = $this->getRouteControllerClassName(
            $this->apiSpec->getRessourcePath()
        );

        $this->assertControllerClassName($controllerClassName);
        $this->assertActionMethodName($controllerClassName, $method);

        $routPaths = $this->getRoutePaths($this->apiSpec->getRoutePath());

        foreach ($routPaths as $routPath) {
            $this->provider->addRoute(
                $routPath,
                $method,
                $this->getControllerNamespaceClass($controllerClassName),
                $this->getActionMethodName($method),
                $this->getResponse(),
                function () {
                    $contentType = $this->provider->getContentType();
                    $contentTypeSchema = $this->apiSpec->getRequestPayloadBodySchema($contentType);
                    $availableContentTypes = $this->apiSpec->getRequestPayloadBodyContentTypes();
                    $accept = $this->provider->getAccept();
                    $availableAcceptContentTypes = $this->apiSpec->getResponsePayloadBodyContentTypes();
                    $protocol = $this->provider->getProtocol();
                    $availableProtocols = $this->apiSpec->getProtocols();
                    $payloadBodyValue = $this->provider->getPayloadBodyValue();

                    $this->assertHTTPProtocol($availableProtocols,$protocol);
                    $this->assertHTTPHeaderAccept($availableAcceptContentTypes,$accept);
                    $this->assertHTTPHeaderContentType($availableContentTypes,$contentType);
                    $this->assertHTTPParameters();
                    $this->assertHTTPPayloadBody($contentType,$contentTypeSchema,$payloadBodyValue);

                    $this->hintHTTPParameterValue($this->hintedHTTPParameters);
                    $this->hintHTTPPayloadBody($this->hintedPayloadBody);
                }
            );
        }
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
     * Return all routes path for the the APISpec.
     * This help to no worry about calling the API
     * with or without a trailing slash.
     *
     * @param  string $apiSpecRoutePath
     *
     * @return string[]
     */
    protected function getRoutePaths($apiSpecRoutePath)
    {
        $routePaths = [];
        $routePaths[] = $apiSpecRoutePath;
        if( substr($apiSpecRoutePath, -1) === '/' ) {
            $routePaths[] = substr($apiSpecRoutePath, 0, -1);
        } else {
            $routePaths[] = $apiSpecRoutePath.'/';
        }

        return $routePaths;
    }

    /**
     * @return Response
     */
    protected function getResponse()
    {
        $statusCodeSucess = $this->getStatusCodeSuccess();
        $format = $this->getResponseFormat();
        $response = new Response(
            $this->provider,
            $format,
            $statusCodeSucess
        );
        $response->setContentType(
            $this->provider->getAccept()
        );
        return $response;
    }

    /**
     * @return string
     */
    protected function getResponseFormat($format = 'json')
    {
        $contentTypes = $this->apiSpec->getResponsePayloadBodyContentTypes();
        if(empty($contentTypes)) {
            throw new \RuntimeException('No content type defined for this response in your APISpec');
        }
        $contentType = $this->provider->getAccept();
        foreach ($this->formats as $format => $mimeTypes) {
            if (in_array($contentType, $mimeTypes)) {
                break;
            }
        }
        //if no mimeType match the contentType of the request, take the
        //default one json. We don't throw new NotAcceptableHttpException()
        //here because it will invalid request like OPTIONS where you can't
        //easily set headers, especialy with an ajax request in a browser
        //but the assertHTTPHeaderAccept will do the job after that

        return $format;
    }

    /**
     * Find the sucess status code to apply at the end of the request
     *
     * @return int
     */
    protected function getStatusCodeSuccess()
    {
        $statusCodes = $this->apiSpec->getStatusCodes();
        //find a 20x code
        $statusCodes20x = array_filter($statusCodes, function($value) {
            return preg_match('/20\d?/', $value);
        });
        if(count($statusCodes20x) === 1) {
            return (int) array_pop($statusCodes20x);
        }
        else {
            throw new \RuntimeException('You can\'t define multiple 20x for one resource path!');
        }
        //default
        return 200;
    }

    /**
     * @param  string $availableHTTPProtocols
     * @param  string $currentHTTPProtocol
     *
     * @throw AccessDeniedHttpException
     */
    protected function assertHTTPProtocol($availableHTTPProtocols, $currentHTTPProtocol)
    {
        $availableHTTPProtocols = array_map('strtoupper', $availableHTTPProtocols);
        $currentHTTPProtocol = strtoupper($currentHTTPProtocol);
        if(in_array($currentHTTPProtocol, $availableHTTPProtocols) === false) {
            throw new AccessDeniedHttpException();
        }
    }

    /**
     * @param  string $availableContentTypes
     * @param  string $contentType
     *
     * @throw UnsupportedMediaTypeHttpException
     */
    protected function assertHTTPHeaderContentType($availableContentTypes, $contentType)
    {
        $availableContentTypes = array_map('strtolower', $availableContentTypes);
        $contentType = strtolower($contentType);
        if(
            empty($availableContentTypes) === false &&
            in_array($contentType,$availableContentTypes) === false
        ) {
            throw new UnsupportedMediaTypeHttpException();
        }
    }

    /**
     * @param  string $availableContentTypes
     * @param  string $acceptContentType
     *
     * @throw UnsupportedMediaTypeHttpException
     */
    protected function assertHTTPHeaderAccept($availableContentTypes, $acceptContentType)
    {
        $availableContentTypes = array_map('strtolower', $availableContentTypes);
        $acceptContentType = strtolower($acceptContentType);
        if(empty($availableContentTypes)) {
            throw new \RuntimeException('No content type defined for this response');
        }
        if( in_array($acceptContentType,$availableContentTypes) === false ) {
            throw new NotAcceptableHttpException();
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
            $value = $this->provider->getParameterValue(
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
            $this->provider->setParameterValue($key, $value);
        }
    }

    /**
     * @param  string $contentType
     * @param  string $schema
     * @param  string $value
     *
     * @throw RREST\Exception\InvalidBodyException
     */
    protected function assertHTTPPayloadBody($contentType, $schema, $value)
    {
        //No payload body here, no need to assert
        if($schema === false) {
            return;
        }

        $value = $this->provider->getPayloadBodyValue();
        switch (true) {
            case strpos($contentType, 'json') !== false:
                $this->assertHTTPPayloadBodyJSON($value, $schema);
                break;
            case strpos($contentType, 'xml') !== false:
                $this->assertHTTPPayloadBodyXML($value, $schema);
                break;
            default:
                throw new UnsupportedMediaTypeHttpException();
                break;
        }
    }

    /**
     * @param  string $value
     * @param  string $schema
     *
     * @throws RREST\Exception\InvalidBodyException
     *
     */
    protected function assertHTTPPayloadBodyXML($value, $schema)
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
        $valueDOM = new \DOMDocument;
        $valueDOM->loadXML($value);
        $thowInvalidBodyException();

        //validate XMLSchema
        $invalidBodyError = [];
        $valueDOM->schemaValidateSource($schema);
        $thowInvalidBodyException();

        libxml_use_internal_errors($originalErrorLevel);

        //use json to convert the XML to a \stdClass object
        $valueJSON= json_decode(json_encode(simplexml_load_string($value)));

        $this->hintedPayloadBody= $valueJSON;
    }

    /**
     * @param  string $value
     * @param  string $schema
     *
     * @throws RREST\Exception\InvalidBodyException
     *
     */
    protected function assertHTTPPayloadBodyJSON($value, $schema)
    {
        //validate JSON
        $valueJSON = json_decode($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidBodyException([new Error(
                ucfirst(json_last_error_msg()),
                'invalid-payloadbody-json'
            )]);
        }

        //validate JsonSchema
        $jsonValidator = new Validator();
        $jsonValidator->check($valueJSON, json_decode($schema));
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

        $this->hintedPayloadBody= $valueJSON;
    }

    protected function hintHTTPPayloadBody($hintedPayloadBody)
    {
        $this->provider->setPayloadBodyValue( $hintedPayloadBody );
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
