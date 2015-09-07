<?php

class WP_GenerateSimpleProducts_IndexController extends Mage_Adminhtml_Controller_Action
{
    const COMBINATIONS_MAXIMUM = 200002;
    const PORTION_COUNT = 10;

    private $_configurableAttributes         = null;
    private $_existingCombinations           = null;
    private $_configurableProduct            = null;
    private $_baseData                       = null;
    private $_configurableAttributesIterator = null;

    public function indexAction()
    {
        $json = array();
        $blocked = (int)Mage::getSingleton('admin/session')->getGenerateSPBlocked();
        $startProcess = $this->getRequest()->getParam('startProcess') ? 1 : 0;
        if ($startProcess) $blocked = 0;
        if (!$blocked) {
            Mage::getSingleton('admin/session')->setGenerateSPBlocked(1);
            $data = $this->getRequest()->getParams();
            #Mage::log($data);
            try {
                list($done, $count, $fndCount, $crtCount, $attrSetName) = $this->_generateMissingSimpleProducts($data);
                // --- save statistic ---
                Mage::getSingleton('admin/session')->setGenerateSPDone($done);
                Mage::getSingleton('admin/session')->setGenerateSPCount($count);
                Mage::getSingleton('admin/session')->setGenerateSPFndCount($fndCount);
                Mage::getSingleton('admin/session')->setGenerateSPCrtCount($crtCount);
                Mage::getSingleton('admin/session')->setGenerateSPAttrSetName($attrSetName);
                // --- / save statistic ---
                // --- save data ---
                Mage::getSingleton('admin/session')->setGenerateMainData(array(
                    $this->_configurableAttributes,
                    $this->_existingCombinations,
                    $this->_baseData,
                    $this->_configurableAttributesIterator
                ));
                // --- / save data ---
                $completedText = '';
                if ($done >= $count || $done >= self::COMBINATIONS_MAXIMUM) {
                    $json['finish'] = true;
                    $completedText = $this->__('<br />Process completed');
                }
                $json['text'] = $this->__(
                    'Attribute Set: <b>%s</b><br /><br />Process: %d of %d<br />Existing: %d<br />Created: %d<br />%s',
                    $attrSetName, $done, $count, $fndCount, $crtCount, $completedText);
            } catch (Exception $e) {
                $json['error'] = $e->getMessage();
            }
            Mage::getSingleton('admin/session')->setGenerateSPBlocked(0);
        } else {
            $bcnt = Mage::getSingleton('admin/session')->getGenerateSPBlocked();
            $bcnt++;
            Mage::getSingleton('admin/session')->setGenerateSPBlocked($bcnt);
            if ($bcnt > 5) {
                $json['error'] = $this->__('There was error occurred during the process.');
            }
        }
        $this->getResponse()->setBody(Zend_Json::encode($json));
    }

    private function _generateMissingSimpleProducts($data)
    {
        list($done, $count, $fndCount, $crtCount, $productIds, $attrSetName) = $this->_initData($data);
        $i = 0; $combination = $this->_readCurrentCombination();
        while ($combination) {
            // --- find by combination (of attached products) ---
            $productId = $this->_getProductIdByCombination($combination);
            if ($productId) {
                $productIds[] = $productId;
                $fndCount++;
            } else {
                // --- find by sku (of existing products) ---
                list($skuPrefix, $namePrefix) = $this->_getSimpleProductPrefixes($combination);
                $sku = $this->_baseData['sku'] . $skuPrefix;
                $productId = $this->_getProductIdBySku($sku);
                if ($productId) {
                    $productIds[] = $productId;
                    $fndCount++;
                } else {
                    // --- create a Simple Product ---
                    $productIds[] = $this->_createSimpleProduct($combination);
                    $crtCount++;
                }
            }
            $combination = $this->_getNextCombination();
            $done++; $i++;
            if ($i >= self::PORTION_COUNT || $done >= self::COMBINATIONS_MAXIMUM) break;
        }

        // --- Save product relations ---
        if (count($productIds)) {
            Mage::getResourceModel('catalog/product_type_configurable')
                ->saveProducts($this->_configurableProduct, $productIds);

            Mage::getSingleton('admin/session')->setGenerateSPProductIds($productIds);
        }

        return array($done, $count, $fndCount, $crtCount, $attrSetName);
    }

    private function _initData($data)
    {
        $startProcess = isset($data['startProcess']) && $data['startProcess'] ? 1 : 0;
        $productId = $data['productId'];
        $this->_configurableProduct = $confProduct = Mage::getModel('catalog/product')->load($productId);

        if ($startProcess) { // --- init data

            $attributeSetId = $confProduct->getAttributeSetId();
            $selectedParams = Mage::helper('core')->jsonDecode($data['spgOptions']);
            #Mage::log($selectedParams);
            // --- get attributes of conf. product ---
            $baseAttributes = $confProduct->getAttributes();
            $baseData = $confProduct->getData();
            foreach ($baseAttributes as $attr) {
                $attrCode = $attr->getAttributeCode();
                if (isset($baseData[$attrCode])) {
                    $attributes[$attrCode] = $baseData[$attrCode];
                }
            }
            // --- exclude attributes ---
            unset($attributes['entity_id']);
            unset($attributes['type_id']);
            unset($attributes['entity_type_id']);
            unset($attributes['url_key']);
            unset($attributes['url_path']);
            unset($attributes['required_options']);
            unset($attributes['has_options']);
            unset($attributes['created_at']);
            unset($attributes['updated_at']);
            unset($attributes['thumbnail']);
            unset($attributes['small_image']);
            unset($attributes['image']);
            unset($attributes['media_gallery']);
            $this->_baseData = $attributes;
            // --- / get attributes by set ---
            $attrCodes = $iterator = array();
            $maximum = 1;
            $helper = Mage::helper('generatesimpleproducts');
            foreach ($confProduct->getTypeInstance()->getConfigurableAttributes() as $attribute) {
                $attrCode = $attribute->getProductAttribute()->getAttributeCode();
                $values = $helper->getAttributeValues($attribute->getProductAttribute()->getSource()->getAllOptions());
                #Mage::log(array($attrCode, $values));
                // --- filter attr and values ---
                $check = array_flip($selectedParams[$attrCode]);
                $values = array_intersect_key($values, $check);
                #Mage::log(array($attrCode, $values));
                if (!count($values)) {
                    throw new ExceptionGenerateSP('You need to specify all required options');
                }
                // --- /filter attr and values ---
                $attrCodes[$attrCode] = array(
                    'id'             => $attribute->getId(),
                    'label'          => $attribute->getLabel(),
                    'position'       => $attribute->getPosition(),
                    'attribute_id'   => $attribute->getProductAttribute()->getId(),
                    'attribute_code' => $attribute->getProductAttribute()->getAttributeCode(),
                    'frontend_label' => $attribute->getProductAttribute()->getFrontend()->getLabel(),
                    'store_label'    => $attribute->getProductAttribute()->getStoreLabel(),
                    'values'         => $values,
                );
                $maximum *= count($values);
                $iterator[$attrCode] = array(
                    'code' => $attrCode,
                    'index' => 0,
                    'count' => count($values),
                    'values' => array_keys($values)
                );
            }
            #Mage::log($iterator);
            $this->_configurableAttributesIterator = $iterator;
            $this->_configurableAttributes = $attrCodes;
            $existingCombinations = array();
            $productIds = array();
            $associatedProducts = $confProduct->getTypeInstance()->getUsedProducts();
            foreach ($associatedProducts as $item) {
                $itemData = $item->getData();
                $productIds[] = $itemData['entity_id'];
                $key = $this->_getCombinationKey($itemData);
                #Mage::log(array($itemData, $key));
                if (!$key) continue;
                $existingCombinations[$key] = $itemData['entity_id'];
            }
            $this->_existingCombinations = $existingCombinations;
            $done = $fndCount = $crtCount = 0; $count = $maximum;
            // --- attr Set Name ---
            $attributeSetModel = Mage::getModel('eav/entity_attribute_set');
            $attributeSetModel->load($attributeSetId);
            $attrSetName = $attributeSetModel->getAttributeSetName();

        } else { // --- read from session

            // --- data ---
            list(
                $this->_configurableAttributes,
                $this->_existingCombinations,
                $this->_baseData,
                $this->_configurableAttributesIterator
            ) = Mage::getSingleton('admin/session')->getGenerateMainData();
            // --- statistic ---
            $done           = Mage::getSingleton('admin/session')->getGenerateSPDone();
            $count          = Mage::getSingleton('admin/session')->getGenerateSPCount();
            $fndCount       = Mage::getSingleton('admin/session')->getGenerateSPFndCount();
            $crtCount       = Mage::getSingleton('admin/session')->getGenerateSPCrtCount();
            $productIds     = Mage::getSingleton('admin/session')->getGenerateSPProductIds();
            $attrSetName    = Mage::getSingleton('admin/session')->getGenerateSPAttrSetName();
        }
        return array($done, $count, $fndCount, $crtCount, $productIds, $attrSetName);
    }

    private function _getCombinationKey($data)
    {
        $keyParts = array();
        foreach ($this->_configurableAttributes as $attrCode => $info) {
            if (isset($data[$attrCode])) {
                $values = array_keys($info['values']);
                if (in_array($data[$attrCode], $values)) {
                    $keyParts[] = $data[$attrCode];
                }
            } else {
                return;
            }
        }
        $key = implode('-', $keyParts);
        return $key;
    }

    private function _getProductIdByCombination($combination)
    {
        $key = $this->_getCombinationKey($combination);
        if (isset($this->_existingCombinations[$key])) return $this->_existingCombinations[$key];
        return false;
    }

    private function _getProductIdBySku($sku)
    {
        $product = Mage::getModel('catalog/product')->loadByAttribute('sku', $sku);
        if (is_object($product)) return $product->getId();
        return;
    }

    private function _readCurrentCombination()
    {
        $combination = array(); $iterator = $this->_configurableAttributesIterator;
        foreach ($this->_configurableAttributesIterator as $attrCode => $info) {
            if (isset($info['values'][$info['index']])) {
                $combination[$attrCode] = $info['values'][$info['index']];
            } else {
                return;
            }
        }
        return $combination;
    }

    private function _getNextCombination()
    {
        $iterator = $this->_configurableAttributesIterator;
        $lastItem = end($iterator);
        $item = reset($iterator); $i = 0; $max = self::COMBINATIONS_MAXIMUM * 2;
        while ($item && $i < $max) {
            $attrCode = $item['code'];
            $iterator[$attrCode]['index']++;
            if ($iterator[$attrCode]['index'] > $iterator[$attrCode]['count']-1 && $lastItem['code'] != $attrCode) {
                $iterator[$attrCode]['index'] = 0;
                $item = next($iterator);
            } else {
                $item = false;
            }
            $i++;
        }
        $this->_configurableAttributesIterator = $iterator;
        return $this->_readCurrentCombination();
    }

    private function _createSimpleProduct($combination)
    {
        $product = Mage::getModel('catalog/product');
        // --- set data ---
        $data = $this->_getSimpleProductDataByCombination($combination);
        $product->setData($data);
        $product->setWebsiteIds(self::_getWebsiteIds());
        // --- add stock info ---
        $stockData = $product->getStockData();
        $stockData['qty'] = 1000;
        $stockData['is_in_stock'] = 1;
        $product->setStockData($stockData);
        // --- / add stock info ---
        $product->save();
        return $product->getId();
    }

    private function _getSimpleProductDataByCombination($combination)
    {
        list($skuPrefix, $namePrefix) = $this->_getSimpleProductPrefixes($combination);
        $data = $this->_baseData;
        $data['sku'] = $data['sku'] . $skuPrefix;
        $data['name'] = $data['name'] . $namePrefix;
        $data['type_id'] = Mage_Catalog_Model_Product_Type::DEFAULT_TYPE; // --- Simple
        $data['weight'] = 1.0;
        $data['visibility'] = 1; // --- Not Visible Individually
        $data['status'] = 1; // --- enabled
        foreach ($combination as $attrCode => $value) {
            $data[$attrCode] = $value;
        }
        #Mage::log($data);
        return $data;
    }

    private function _getSimpleProductPrefixes($combination)
    {
        $skuPrefixParts = $namePrefixParts = array();
        $configurableAttributes = $this->_configurableAttributes;
        foreach ($combination as $attrCode => $value) {
            // --- generate Name of the Simple Product ---
            $prefix = $configurableAttributes[$attrCode]['label'] . ' ' . $configurableAttributes[$attrCode]['values'][$value];
            $namePrefixParts[] = $prefix;
            $skuPrefixParts[] = str_replace(' ', '-', $prefix);
        }
        $skuPrefix = '-' . implode('-', $skuPrefixParts);
        $namePrefix = ' - ' . implode(' - ', $namePrefixParts);
        return array($skuPrefix, $namePrefix);
    }

    private static function _getWebsiteIds()
    {
        $websiteIds = Mage::getSingleton('admin/session')->getProductWebsiteIds();
        if (is_null($websiteIds)) {
            $websites = Mage::app()->getWebsites();
            $websiteIds = array();
            foreach ($websites as $website)
                $websiteIds[] = $website->getId();
            Mage::getSingleton('admin/session')->setProductWebsiteIds($websiteIds);
        }
        return $websiteIds;
    }
}
