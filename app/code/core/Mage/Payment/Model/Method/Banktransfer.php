<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magento.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magento.com for more information.
 *
 * @category    Mage
 * @package     Mage_Payment
 * @copyright  Copyright (c) 2006-2020 Magento, Inc. (http://www.magento.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Bank Transfer payment method model
 */
class Mage_Payment_Model_Method_Banktransfer extends Mage_Payment_Model_Method_Abstract
    implements Mage_Payment_Model_Recurring_Profile_MethodInterface
{
    const PAYMENT_METHOD_BANKTRANSFER_CODE = 'banktransfer';

    /**
     * Payment method code
     *
     * @var string
     */
    protected $_code = self::PAYMENT_METHOD_BANKTRANSFER_CODE;

    /**
     * Bank Transfer payment block paths
     *
     * @var string
     */
    protected $_formBlockType = 'payment/form_banktransfer';
    protected $_infoBlockType = 'payment/info_banktransfer';

    /**
     * Get instructions text from config
     *
     * @return string
     */
    public function getInstructions()
    {
        return trim($this->getConfigData('instructions'));
    }





    public function test()
    {
    
        Mage::log('recurring ');

        $db = Mage::getSingleton('core/resource')->getConnection('core_read');
        $rp_table = Mage::getSingleton('core/resource')->getTableName('sales/recurring_profile');
        $sql = $db->select()
            ->from( $rp_table, array('internal_reference_id') )
            ->where( 'method_code="banktransfer" AND ( state="active" OR (state="pending" and start_datetime < NOW()) ) AND updated_at <= ' .
                     'CASE period_unit WHEN "day" THEN NOW() - interval period_frequency DAY ' .
                     'WHEN "week" THEN NOW() - INTERVAL period_frequency WEEK ' .
                     'WHEN "month" THEN NOW() - INTERVAL period_frequency MONTH ' .
                     'WHEN "year" THEN NOW() - INTERVAL period_frequency YEAR ' .
                     'END' );
        $data = $db->fetchAll($sql);

        $rowcount = count($data);
        Mage::log('recurring rowcount:'.$rowcount);

        foreach( $data as $pid ) {
            $profile = Mage::getModel('sales/recurring_profile')->loadByInternalReferenceId( $pid['internal_reference_id'] );
            $refId = $profile->getReferenceId();
            $adtl = $profile->getAdditionalInfo();
            $cid = $profile->getCustomerId();


            $period = $profile['period_unit'];
            $periodfq = $profile['period_frequency'];

            //check timezones, now converted to UTC?
            $updated = date_create($profile['updated_at']);
            $now = date_create('now');

            $intervalstr = (int)$periodfq.' '.$period;

            Mage::log('recurring intervalstr:'.$intervalstr);
            Mage::log('recurring #'.$profile->getId().' updated:'.var_export($updated,true));
            Mage::log('recurring #'.$profile->getId().' now:'.var_export($now,true));

            $this->setStore( $profile->getStoreId() );
            if ($this->getConfigData('active') == 0 || ( !is_null($cid) && Mage::getModel('customer/customer')->load($cid)->getId() != $cid ) ) {
                continue;
            }


           /**
             * For each active profile...
             * if it is a billing cycle beyond starting date...
             * if it is due to be paid OR if there's a balance outstanding...
             * create an order/invoice and log the results.
             */
            $testdate = date_sub($now,date_interval_create_from_date_string($intervalstr));
            if ($updated <= $testdate) {

                Mage::log('recurring processing profile #'.$profile->getId().' updated:'.date_format($updated,"Y-m-d H:i:s"));
                Mage::log('recurring processing profile #'.$profile->getId().' testdate:'.date_format($testdate,"Y-m-d H:i:s"));

                //do nothing
                //continue;

                $result = $this->chargeRecurringProfile($profile);
                

                /**
                  * Is the profile complete?
                  */
                $max_cycles = intval($profile->getPeriodMaxCycles());
                if ($max_cycles > 0 && $adtl['billed_count'] == $max_cycles + intval($profile->getTrialPeriodMaxCycles())) {
                    $profile->setState( Mage_Sales_Model_Recurring_Profile::STATE_EXPIRED );
                }





            }

        }



        return true;
    }

    /**
     * Validate RP data
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     */
    public function validateRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile)
    {
        Mage::log('recurring validate ');
        return $this;
    }

    /**
     * Submit RP to the gateway
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     * @param Mage_Payment_Model_Info $paymentInfo
     */
    public function submitRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile, Mage_Payment_Model_Info $paymentInfo)
    {

    Mage::log('recurring submitRecurringProfile '.$profile->getId());
   
    // add order assigned to the recurring profile with initial fee
    if ((float)$profile->getInitAmount()){

        Mage::log('recurring creating order initial fee for profile:'.$profile->getId());
        //$this->setStore( $profile->getStoreId() );

        $productItemInfo = new Varien_Object;
        $productItemInfo->setPaymentType(Mage_Sales_Model_Recurring_Profile::PAYMENT_TYPE_INITIAL);
        $productItemInfo->setPrice($profile->getInitAmount());
 
        $order = $profile->createOrder($productItemInfo);

        // get this from config
        // do not know what state to set 
        $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
        $order->setStatus(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);

        $order->save();

        //send the email with the update
        $order->sendNewOrderEmail(true, null);

        //link this order to the profile
        $profile->addOrderRelation($order->getId());
        $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_ACTIVE);

    }
 
    // charge first order
    // try to combine initial fee with order
    $result = $this->chargeRecurringProfile($profile);
 
    return $this;
    }

    /**
     * Fetch RP details
     *
     * @param string $referenceId
     * @param Varien_Object $result
     */
    public function getRecurringProfileDetails($referenceId, Varien_Object $result)
    {
        Mage::log('recurring get Profile Reference '.$referenceId);
        return $this;
    }

    /**
     * Whether can get recurring profile details
     */
    public function canGetRecurringProfileDetails()
    {
        Mage::log('recurring canGet called');
        return true;
    }

    /**
     * Update RP data
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     */
    public function updateRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile)
    {
        return $this;
    }

    /**
     * Manage status
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     */
    public function updateRecurringProfileStatus(Mage_Payment_Model_Recurring_Profile $profile)
    {
        
    switch ($profile->getNewState()) {
        case Mage_Sales_Model_Recurring_Profile::STATE_ACTIVE:      $action = 'start'; break;
        case Mage_Sales_Model_Recurring_Profile::STATE_CANCELED:    $action = 'cancel'; break;
        case Mage_Sales_Model_Recurring_Profile::STATE_EXPIRED:     $action = 'cancel'; break;
        case Mage_Sales_Model_Recurring_Profile::STATE_SUSPENDED:   $action = 'stop'; break;
        default: return $this;
    }

    $additionalInfo = $profile->getAdditionalInfo() ? $profile->getAdditionalInfo() : array();
        
    Mage::log('recurring updateRecurring Profile #'.$profile->getId()." action:".$action);

    if ($action == 'start'){
        Mage::log('recurring profile #'.$profile->getId());
        $profile->setUpdatedAt(date('Y-m-d H:i:s'));
        $profile->save();
    }
    return $this;
    }

    /**
     * create order for this profile
     * 
     * @param Mage_Payment_Model_Recurring_Profile $profile
     */
    public function chargeRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile)
    {

    Mage::log('recurring charge profile #'.$profile->getId());

    $now = date_create('now');
    $productItemInfo = new Varien_Object;

    /**
     * Are we in a trial period?
     */
    $tperiod = $profile['trial_period_unit'];
    $tperiodfq = $profile['trial_period_frequency'];

    $intervalstr = (int)$tperiodfq.' '.$tperiod;
    $testdate = date_sub($now,date_interval_create_from_date_string($intervalstr));
    $started = date_create($profile['start_datetime']);
    
    if ($started <= $testdate) {
        $price = $profile->getTrialBillingAmount();
        $productItemInfo->setPaymentType(Mage_Sales_Model_Recurring_Profile::PAYMENT_TYPE_TRIAL);
    } else {
        $price = $profile->getBillingAmount();
        $productItemInfo->setPaymentType(Mage_Sales_Model_Recurring_Profile::PAYMENT_TYPE_REGULAR);
    }

    //$productItemInfo->setPaymentType(Mage_Sales_Model_Recurring_Profile::PAYMENT_TYPE_REGULAR);
    $productItemInfo->setTaxAmount( $profile->getTaxAmount() );
    $productItemInfo->setShippingAmount( $profile->getShippingAmount() );
    $productItemInfo->setPrice( $price );

    $order = $profile->createOrder($productItemInfo);

    // get this state from config
    // do not know what state to set 
    $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
    $order->setStatus(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);

    $order->save();

    //send the email with the update
    if ($order->getCanSendNewEmailFlag()) {
        try {
            $order->sendNewOrderEmail(true, null);
        }
        catch(Exception $e) {
            Mage::logException($e->getMessage());
        }
    }

    //link this order to the profile
    $profile->addOrderRelation($order->getId());
    $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_ACTIVE);
    
    // change updated_at to one cycle ahead
    $result = $this->_setUpdateDateToNextPeriod($profile->getId());
        
    return true;
    }


    protected function _setUpdateDateToNextPeriod($profile_id)
    {
    Mage::log('recurring update tonext period Profile #'.$profile_id);
        
    $_resource = Mage::getSingleton('core/resource');
    $sql = '
            UPDATE '.$_resource->getTableName('sales_recurring_profile').'
            SET updated_at = CASE period_unit
                WHEN "day"        THEN DATE_ADD(updated_at, INTERVAL period_frequency DAY)
                WHEN "week"       THEN DATE_ADD(updated_at, INTERVAL (period_frequency*7) DAY)
                WHEN "semi_month" THEN DATE_ADD(updated_at, INTERVAL (period_frequency*14) DAY)
                WHEN "month"      THEN DATE_ADD(updated_at, INTERVAL period_frequency MONTH)
                WHEN "year"       THEN DATE_ADD(updated_at, INTERVAL period_frequency YEAR)
            END
            WHERE profile_id = :pid';
        
    $connection = $_resource->getConnection('core_write');
    $pdoStatement = $connection->prepare($sql);
    $pdoStatement->bindValue(':pid', $profile_id);

    return $pdoStatement->execute();
    }

}
