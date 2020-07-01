<?php
namespace Fandi\Shipper\Model\Carrier;

use Magento\Quote\Model\Quote\Address\RateRequest;

class ShipperCarrier extends \Magento\Shipping\Model\Carrier\AbstractCarrier implements
    \Magento\Shipping\Model\Carrier\CarrierInterface
{
    /**
     * @var string
     */
    protected $_code = 'shippercarrier';

    protected $_logger;
    /**
     * @var bool
     */
    protected $_isFixed = true;

    /**
     * @var \Magento\Shipping\Model\Rate\ResultFactory
     */
    protected $_rateResultFactory;

    /**
     * @var \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory
     */
    protected $_rateMethodFactory;

    /**
     * @var \Fandi\Shipper\Model\Query\Api
     */
    protected $_apiData;

    protected $_storeInfo;
    protected $_storeManager;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param \Fandi\Shipper\Model\Query\Api $apiData
     * @param \Magento\Store\Model\Information $storeInfo,
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager,
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        \Fandi\Shipper\Model\Query\Api $apiData,
        \Magento\Store\Model\Information $storeInfo,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->_logger = $logger;
        $this->_apiData = $apiData;
        $this->_storeInfo = $storeInfo;
        $this->_storeManager = $storeManager;

        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * @param RateRequest $request
     * @return \Magento\Shipping\Model\Rate\Result|bool
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->_rateResultFactory->create();

        $sellerOrders = [];

        if ($request->getAllItems()) {
            foreach ($request->getAllItems() as $item) {
                $product = $item->getProduct();
                $seller_id = $this->getSellerIdByProductId($product->getId());
                $sellerOrders[$seller_id][] = $item;
            }
        }

        $destPostcode = $request->getDestPostcode();
        $destAreaId = $this->getAreaId($destPostcode);
        if (!$destAreaId) {
            return false;
        }

        $finalLogistics = [];

        if (!empty($sellerOrders)) {
            foreach ($sellerOrders as $sellerId => $items) {
                $storeData = $this->getStoreDetails($sellerId);
                $storePostcode = $storeData['store_zipcode'];

                $origAreaId = $this->getAreaId($storePostcode);
                if (!$origAreaId) {
                    return false;
                }

                $totalPrice = 0;
                $totalWeight = 0;
                foreach ($items as $item) {
                    $totalPrice += $item->getPriceInclTax();
                    $totalWeight += $item->getWeight();
                }

                $params = [
                    'o'     => $origAreaId,
                    'd'     => $destAreaId,
                    'v'     => $totalPrice, // item price
                    'l'     => 15, // $request->getPackageWidth(), // length
                    'w'     => 11, // $request->getPackageWidth(), // width
                    'wt'    => $totalWeight, // weight
                    'h'     => 11, // $request->getPackageHeight(), // height
                    'cod'   => 0, // cod
                    'type'  => 2, // small document
                    'order' => 1
                ];

                $domesticRates = $this->_apiData->getDomesticRate($params);
                if ($domesticRates) {
                    foreach ($domesticRates as $logistic) {
                        foreach ($logistic as $rate) {
                            $finalLogistics[] = [
                                'rate_id' => $rate->rate_id,
                                'rate_name' => $rate->name . ' - ' . $rate->rate_name,
                                'final_rate' => $rate->finalRate,
                                'counter' => 1
                            ];
                        }
                    }
                }
            }
        }

        $finalRates = $this->getFinalRates($finalLogistics);

        if ($finalRates) {
            foreach ($finalRates as $key => $val) {
                list($rateName, $methodName) = explode(' - ', $key);
                $rate = (object)[
                 'name' => $rateName,
                 'rate_name' => $methodName,
                 'finalRate' => $val['final_rate'],
                 'rate_id' => $val['id'],
                ];

                // hanya rates yg disupport untuk seluruh seller
                if (intval($val['counter']) !== count($sellerOrders)) {
                    continue;
                }

                $method = $this->generateMethods($rate);
                $result->append($method);
            }
        }

        return $result;
    }

    public function getFinalRates($data)
    {
        $groups = [];
        foreach ($data as $item) {
            $key = $item['rate_name'];
            if (!array_key_exists($key, $groups)) {
                $groups[$key] = [
                    'counter' => $item['counter'],
                    'id' => $item['rate_id'],
                    'final_rate' => $item['final_rate'],
                ];
            } else {
                $groups[$key]['counter'] = $groups[$key]['counter'] + $item['counter'];
                $groups[$key]['final_rate'] = $groups[$key]['final_rate'] + $item['final_rate'];
            }
        }
        return $groups;
    }

    public function getSellerIdByProductId($id)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $product = $objectManager->create('Magento\Catalog\Model\Product')->load($id);
        return $product->getData('seller_id');
    }

    public function getStoreDetails($sellerId)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $seller = $objectManager->create('\Purpletree\Marketplace\Model\ResourceModel\Seller');
        return $seller->getStoreDetails($sellerId);
    }

    public function getAreaId($postcode)
    {
        return $this->_apiData->getAreaId($postcode);
    }

    /**
     * @return \Magento\Quote\Model\Quote\Address\RateResult\Method
     */
    public function generateMethods($carrier)
    {
        $method = $this->_rateMethodFactory->create();
        $method->setCarrier($this->_code);
        $method->setCarrierTitle($carrier->name);
        $method->setMethod($carrier->rate_id);
        $method->setMethodTitle($carrier->rate_name);
        $method->setPrice($carrier->finalRate);
        $method->setCost($carrier->finalRate);
        return $method;
    }

    /**
     * @return array
     */
    public function getAllowedMethods()
    {
        return [$this->_code=> $this->getConfigData('name')];
    }
}
