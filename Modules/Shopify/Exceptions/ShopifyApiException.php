<?php

namespace Modules\Shopify\Exceptions;

use Exception;

class ShopifyApiException extends Exception
{
    protected $statusCode;
    protected $response;

    public function __construct($message = "", $statusCode = 0, $response = null, Exception $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
        $this->response = $response;
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function toArray()
    {
        return [
            'message' => $this->getMessage(),
            'status_code' => $this->statusCode,
            'response' => $this->response,
        ];
    }
}

