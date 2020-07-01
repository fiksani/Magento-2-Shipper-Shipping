<?php


namespace Fandi\Shipper\ViewModel;


use Magento\Framework\View\Element\Block\ArgumentInterface;

class Tracking implements ArgumentInterface
{
    private $apiData;

    public function __construct(\Fandi\Shipper\Model\Query\Api $apiData)
    {
        $this->apiData = $apiData;
    }

    public function getTrackingInfo($orderId)
    {
        return $this->apiData->getOrder($orderId);
    }

}
