<?php

namespace RREST\APISpec;

interface APISpecInterface
{
    /**
     * Return the route path matched in the APISpec.
     *
     * @example: /v1/item/id
     *
     * @return string
     */
    public function getRoutePath();

    /**
     * Return the HTTP Method matched in the APISpec.
     *
     * @return string
     */
    public function getRouteMethod();

    /**
     * Return the ressource matched in the APISpec.
     *
     * @example: /item/id
     *
     * @return string
     */
    public function getRessourcePath();

    /**
     * @return integer[]
     */
    public function getStatusCodes()

    /**
     * @return string[]
     */
    public function getSupportedHTTPProtocols();

    /**
     * @return RREST\Parameter[]
     */
    public function getParameters();

    /**
     * @return string[]|boolean
     */
    public function getContentTypes();

    /**
     * @param  string $contentType
     * @return string|boolean
     */
    public function getPayloadBodySchema($contentType);
}
