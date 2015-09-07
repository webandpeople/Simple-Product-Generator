<?php

class WP_GenerateSimpleProducts_Block_Config extends Mage_Adminhtml_Block_Catalog_Product_Edit_Tab_Super_Config
{
    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('generatesimpleproducts/config.phtml');
    }

    protected function _prepareLayout()
    {
        if ($this->_getProduct()->getId()) {
            $this->setChild('auto_generate_from_configurable',
                $this->getLayout()->createBlock('adminhtml/widget_button')
                    ->setData(array(
                        'label' => Mage::helper('catalog')->__('Generate combinations of Simple Products'),
                        'class' => 'add',
                        'onclick' => 'wpGenerateSimpleProducts();'
                    ))
            );
        }
        return parent::_prepareLayout();
    }

    public function getAttr()
    {
        $attr = array();
        $helper = Mage::helper('generatesimpleproducts');
        $productId = $this->getRequest()->getParam('id');
        $confProduct = Mage::getModel('catalog/product')->load($productId);
        foreach ($confProduct->getTypeInstance()->getConfigurableAttributes() as $attribute) {
            $attrCode = $attribute->getProductAttribute()->getAttributeCode();
            $values = $helper->getAttributeValues($attribute->getProductAttribute()->getSource()->getAllOptions());
            $attr[$attrCode] = array(
                'label'          => $attribute->getLabel(),
                'values'         => $values,
            );
        }
        #Mage::log($attr);
        return $attr;
    }

    public function getProductId()
    {
        return $this->_getProduct()->getId();
    }

    public function getReloadUrl()
    {
        return $this->getUrl(
            '*/*/edit',
            array(
                'id'       => $this->getProductId(),
                'back'     => 'edit',
                'tab'      => 'product_info_tabs_configurable',
            )
        );
    }
}
