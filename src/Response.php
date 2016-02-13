<?php
namespace RREST;

use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use RREST\Provider\ProviderInterface;

class Response
{
    /**
     * @var mixed
     */
    protected $content;

    /**
     * @var string
     */
    protected $format;

    /**
     * @var string
     */
    protected $statusCode;

    /**
     * @var string[]
     */
    protected $supportedFormat = ['json','xml'];

    /**
     * @var ProviderInterface
     */
    protected $provider;

    /**
     * The URL of a resource, useful when POST a new one
     *
     * @var string
     */
    protected $headerLocation;

    /**
     * @var string
     */
    protected $headerContentType;

    public function __construct(ProviderInterface $provider, $format, $statusCode)
    {
        $this->setFormat($format);
        $this->setProvider($provider);
        $this->setStatusCode($statusCode);
    }

    /**
     * @return string
     */
    public function getFormat()
    {
        return $this->format;
    }

    /**
     * @param string
     */
    public function setFormat($format)
    {
        if(in_array($format, $this->supportedFormat) === false) {
            throw new \RuntimeException(
                'format not supported, only are '.implode(', ', $this->supportedFormat).' availables'
            );
        }
        $this->format = $format;
    }

    /**
     * @return string
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @param string
     */
    public function setStatusCode($statusCode)
    {
        $this->statusCode = $statusCode;
    }

    /**
     * @return mixed
     */
    public function getHeaderLocation()
    {
        return $this->headerLocation;
    }

    /**
     * @param mixed
     */
    public function setHeaderLocation($headerLocation)
    {
        $this->headerLocation = $headerLocation;

    }

    /**
     * @return mixed
     */
    public function getHeaderContentType()
    {
        return $this->headerContentType;
    }

    /**
     * @param mixed
     */
    public function setHeaderContentType($headerContentType)
    {
        $this->headerContentType = $headerContentType;
    }

    /**
     * All headers configured, index by header name
     *
     * @return string[]
     */
    public function getHeaders()
    {
        $headers = [];
        $contentType = $this->getHeaderContentType();
        if(empty($contentType)===false) {
            $headers['Content-Type'] = $contentType;
        }
        $location = $this->getHeaderLocation();
        if(empty($location)===false) {
            $headers['Location'] = $location;
        }
        return $headers;
    }

    /**
     * @param mixed $content
     *
     * @return boolean
     */
    public function setContent($content)
    {
        $this->content = $content;
    }

    /**
     * @return mixed
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * @param ProviderInterface $provider
     */
    public function setProvider(ProviderInterface $provider)
    {
        $this->provider = $provider;
    }

    /**
     * @return ProviderInterface
     */
    public function getProvider()
    {
        return $this->provider;
    }

    /**
     * Get a provider configured response with:
     * - content serialized
     * - success status code
     * - header Content-Type
     * - header Location
     *
     * @param  boolean $autoSerializeContent
     *
     * @return mixed
     */
    public function getProviderResponse($autoSerializeContent=true)
    {
        $content = $this->getContent();
        if($autoSerializeContent) {
            $content = $this->serialized($content, $this->getFormat());
        }
        return $this->provider->getResponse(
            $content, $this->getStatusCode(), $this->getHeaders()
        );
    }

    /**
     * @param  mixed $data
     * @param  string $format
     * @return string
     */
    protected function serialized($data, $format)
    {
        if( $format === 'json' ) {
            return json_encode($data);
        }
        elseif( $format === 'xml' ) {
            $serializer = new Serializer([
                    new ObjectNormalizer()
                ],[
                    'xml' => new XmlEncoder(),
                ]
            );
            //fix stdClass not serialize vy default
            $data = json_decode(json_encode($data), true);
            return $serializer->serialize($data, $format);
        }
    }
}
