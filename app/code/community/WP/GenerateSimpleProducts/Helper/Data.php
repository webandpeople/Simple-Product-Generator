<?php

class ExceptionGenerateSP extends Exception { }

class WP_GenerateSimpleProducts_Helper_Data extends Mage_Core_Helper_Abstract
{
    public function getAttributeValues($data)
    {
        $values = array();
        foreach ($data as $info) {
            if (!$info['value']) continue;
            $values[$info['value']] = $info['label'];
        }
        return $values;
    }
}
