<?php

namespace Fandi\Shipper\Model\Query;

use Magento\Framework\HTTP\ZendClientFactory;

class Api
{
    /**
     * @var ZendClientFactory
     */
    protected $httpClient;

    /**
     * Api constructor.
     * @param ZendClientFactory $httpClient
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        ZendClientFactory $httpClient,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->httpClient = $httpClient;
        $this->scopeConfig = $scopeConfig;
    }

    protected function getConfig($field)
    {
        $path = 'carriers/shippercarrier/' . $field;
        return $this->scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    protected function getServerKey()
    {
        return $this->getConfig('server_key');
    }

    protected function getEnvironment()
    {
        return $this->getConfig('environment') == 'production' ? true : false;
    }

    public function apiCall($url, $method, $params = [])
    {
        $is_production = $this->getEnvironment();
        if (!$is_production) {
            $endpoint = 'https://api.sandbox.shipper.id';
        } else {
            $endpoint = 'https://api.shipper.id/prod';
        }

        $apiCall = $this->httpClient->create();
        $apiCall->setUri($endpoint . '/public/v1/' . $url);
        $apiCall->setMethod($method);
        $apiCall->setHeaders([
            'Accept: application/json',
            'User-Agent: Shipper/​'
        ]);

        if ($method === \Zend_Http_Client::GET) {
            $params = array_merge($params, [
                'apiKey'    => $this->getServerKey(),
            ]);
            $apiCall->setParameterGet($params);
        } else {
            $apiCall->setParameterGet([
                'apiKey'    => $this->getServerKey(),
            ]);
            $apiCall->setParameterPost($params);
        }

        return $apiCall->request();
    }

    public function getDomesticRate($params)
    {
        $rates = $this->apiCall('domesticRates', \Zend_Http_Client::GET, $params);
        $ratesBody = json_decode($rates->getBody());

        if ($ratesBody->status == "success" && $ratesBody->data->statusCode == 200) {
            return $ratesBody->data->rates->logistic;
        }

        return false;
    }

    public function postOrder($params)
    {
        $response = $this->apiCall('orders/domestics', \Zend_Http_Client::POST, $params);
        $responseBody = json_decode($response->getBody());

        if ($responseBody->status == 'success' && $responseBody->data->statusCode == 201) {
            return $responseBody->data->id;
        }

        return false;
    }

    public function getOrder($orderId)
    {
        $response = $this->apiCall('orders/' . $orderId, \Zend_Http_Client::GET);
        $responseData = json_decode($response->getBody());

        if ($responseData->status === "success" && $responseData->data && strtolower($responseData->data->title) == 'ok') {
            return $responseData->data->order;
        }

        return false;
    }

    public function getAreaId($postcode)
    {
        $rates = $this->apiCall('details/' . $postcode, \Zend_Http_Client::GET);
        $ratesBody = json_decode($rates->getBody());

        if ($ratesBody->status === "success" && $ratesBody->data && strtolower($ratesBody->data->title) == 'ok' && count($ratesBody->data->rows) > 0) {
            return $ratesBody->data->rows[0]->area_id;
        }

        return false;
    }
}
