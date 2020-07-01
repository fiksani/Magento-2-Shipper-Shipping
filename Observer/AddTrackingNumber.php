<?php

namespace Fandi\Shipper\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class AddTrackingNumber implements ObserverInterface
{
    protected $orderFactory;
    protected $shipmentFactory;
    protected $orderModel;
    protected $trackFactory;
    protected $logger;
    protected $apiData;

    public function __construct(
        \Magento\Sales\Api\Data\OrderInterface $orderFactory,
        \Magento\Sales\Model\Convert\Order $orderModel,
        \Magento\Sales\Model\Order\Shipment\TrackFactory $trackFactory,
        \Magento\Shipping\Model\ShipmentNotifier $shipmentFactory,
        \Psr\Log\LoggerInterface $logger,
        \Fandi\Shipper\Model\Query\Api $apiData
    ) {
        $this->orderFactory = $orderFactory;
        $this->orderModel = $orderModel;
        $this->trackFactory = $trackFactory;
        $this->shipmentFactory = $shipmentFactory;
        $this->logger = $logger;
        $this->apiData = $apiData;
    }

    public function execute(Observer $observer)
    {
        $invoice = $observer->getEvent()->getInvoice();
        $order = $invoice->getOrder();
        $orderNumber = $order->getIncrementId();

        if ($order->hasInvoices()) {
            if ($order->canShip()) {
                try {
                    $shipment = $this->orderModel->toShipment($order);

                    $sellerOrders = [];
                    $itemsQty = [];

                    foreach ($order->getAllItems() as $orderItem) {
                        if (!$orderItem->getQtyToShip() || $orderItem->getIsVirtual()) {
                            continue;
                        }

                        $qtyShipped = $orderItem->getQtyToShip();
                        $shipmentItem = $this->orderModel->itemToShipmentItem($orderItem)->setQty($qtyShipped);
                        $shipment->addItem($shipmentItem);

                        $product = $orderItem->getProduct();
                        $seller_id = $this->getSellerIdByProductId($product->getId());
                        $sellerOrders[$seller_id][] = $orderItem;
                        $itemsQty[$orderItem->getName()] = $qtyShipped;
                    }

                    $destPostcode = $order->getShippingAddress()->getPostcode();
                    $destStreet = $order->getShippingAddress()->getStreet()[0];
                    $destCity = $order->getShippingAddress()->getCity();
                    $destAddress = sprintf("%s, %s %s", $destStreet, $destCity, $destPostcode);

                    $destAreaId = $this->getAreaId($destPostcode);

                    $carrierTitle = $order->getShippingDescription();
                    $carrierCode = explode('_', $order->getShippingMethod());
                    list($code, $rateID) = $carrierCode;

                    $shipment->register();
                    $shipment->getOrder()->setIsInProcess(true);

                    $trackingIds = [];

                    foreach ($sellerOrders as $sellerId => $items) {
                        $storeData = $this->getStoreDetails($sellerId);
                        $storePostcode = $storeData['store_zipcode'];
                        $storePhone = $storeData['store_phone'];
                        $storeName = $storeData['store_name'];
                        $storeAddress = sprintf("%s, %s, %s %s", $storeData['store_address'], $storeData['store_city'], $storeData['store_region'], $storePostcode);

                        $origAreaId = $this->getAreaId($storePostcode);

                        $totalPrice = 0;
                        $totalWeight = 0;
                        $itemName = [];
                        $contents = [];
                        foreach ($items as $item) {
                            $totalPrice += $item->getPriceInclTax();
                            $totalWeight += $item->getWeight();

                            $contents[] = $item->getName();

                            $itemName[] = [
                                'name' => $item->getName(),
                                'qty' => $itemsQty[$item->getName()],
                                'value' => (int)$item->getPriceInclTax()
                            ];
                        }

                        $params = [
                            'o'     => (string) $origAreaId,
                            'd'     => (string) $destAreaId,
                            'v'     => $totalPrice, // item price
                            'l'     => $request->getPackageWidth(), // length
                            'w'     => $request->getPackageWidth(), // width
                            'wt'    => $totalWeight, // weight
                            'h'     => $request->getPackageHeight(), // height
                            'packageType'  => 2, // small document
                            'rateID' => $rateID,
                            'externalID' => '',
                            'itemName' => $itemName,
                            'contents' => implode(', ', $contents),
                            'consigneeName' => $order->getShippingAddress()->getName(),
                            'consigneePhoneNumber' =>  $order->getShippingAddress()->getTelephone(),
                            'consignerName' =>  $storeName,
                            'consignerPhoneNumber' =>  $storePhone,
                            'originAddress' =>  $storeAddress,
                            'originDirection' =>  '',
                            'destinationAddress' =>  $destAddress,
                            'destinationDirection' =>  '',
                        ];

                        $carrierNumber = $this->apiData->postOrder($params);

                        $trackingIds[] = [
                            'carrier_code' => $order->getShippingMethod(),
                            'title' => $carrierTitle,
                            'number' => $carrierNumber,
                        ];
                    }

                    foreach ($trackingIds as $trackingId) {
                        $data = [
                              'carrier_code' => $trackingId['carrier_code'],
                              'title' => $trackingId['title'],
                              'number' => $trackingId['number'],
                        ];
                        $track = $this->trackFactory->create()->addData($data);
                        $shipment->addTrack($track)->save();
                    }

                    $shipment->save();
                    $shipment->getOrder()->save();

                    // Send email
                    $this->shipmentFactory->notify($shipment);
                    $shipment->save();
                } catch (\Exception $e) {
                    $this->logger->info($e->getMessage());
                }
            } else {
                $this->logger->info('You can not create an shipment:' . $orderNumber);
            }
        } else {
            $this->logger->info('Invoice is not created for order:' . $orderNumber);
        }
        exit;
    }

    private function getSellerIdByProductId($id)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $product = $objectManager->create('Magento\Catalog\Model\Product')->load($id);
        return $product->getData('seller_id');
    }

    private function getStoreDetails($sellerId)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $seller = $objectManager->create('\Purpletree\Marketplace\Model\ResourceModel\Seller');
        return $seller->getStoreDetails($sellerId);
    }

    private function getAreaId($postcode)
    {
        return $this->apiData->getAreaId($postcode);
    }
}
