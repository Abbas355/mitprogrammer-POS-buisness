<?php

namespace Modules\FbrIntegration\Exceptions;

use Exception;

class FbrApiException extends Exception
{
    protected $statusCode;
    protected $responseData;

    public function __construct($message = "", $statusCode = 0, $responseData = null, $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->statusCode = $statusCode;
        $this->responseData = $responseData;
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }

    public function getResponseData()
    {
        return $this->responseData;
    }
}
