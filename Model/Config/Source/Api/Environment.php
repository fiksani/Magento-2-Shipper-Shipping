<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Fandi\Shipper\Model\Config\Source\Api;

use Magento\Framework\Option\ArrayInterface;

class Environment implements ArrayInterface
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => 'sandbox',
                'label' => __('Sandbox'),
            ],
            [
                'value' => 'production',
                'label' => __('Production'),
            ],
        ];
    }
}
