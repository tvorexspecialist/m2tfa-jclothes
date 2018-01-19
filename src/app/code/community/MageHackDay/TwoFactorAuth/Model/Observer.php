<?php

class MageHackDay_TwoFactorAuth_Model_Observer {

    /**
     * Listens to the admin_user_authenticate_after Event and checks whether the user has access to areas that are configured
     * to be protected by Two Factor Auth. If so, send the user to either add a Two Factor Auth to their Account, or enter a
     * code from their connected Auth provider
     *
     */
    public function adminUserAuthenticateAfter($observer) {
        if(!Mage::helper('twofactorauth')->isActive()) {
            return $this;
        }

        $event 		= $observer->getEvent();
        $username 	= $event->getUsername();
        /** @var $user Mage_Admin_Model_User */
        $user 		= $event->getUser();
        if (Mage::helper('twofactorauth/auth')->isAuthorized($user)) {
            return $this;
        }
        $oRole = $user->getRole();
        $aResources = $oRole->getResourcesList2D();
        $vSerializedProtectedResources = Mage::getStoreConfig('admin/security/twofactorauth_protected_resources');
        $aProtectedResources = unserialize($vSerializedProtectedResources);
        $bTfaRequired = false;
        foreach($aProtectedResources as $vResourceId => $aProtectedResource){
            if(Mage::getSingleton('admin/session')->isAllowed($aProtectedResource['resource_id'])){
                $bTfaRequired = true;
                break;
            }
        }
        if($bTfaRequired){
            Mage::log('this user has ACLs for resources that we need to protect via TFA');
            $oResponse = Mage::app()->getResponse();
            if(!$user->getTwofactorToken()){
                Mage::log('User is missing required TFA secret');
                $vMessage = Mage::helper('twofactorauth')->__('Please connect your Two Factor Authentication before accessing restricted admin functionality');
                Mage::getSingleton('adminhtml/session')->addError($vMessage);
                Mage::getSingleton('adminhtml/session')->setTfaNotAssociated(true);
                $vRedirectUrl = Mage::helper("adminhtml")->getUrl("adminhtml/system_account/index");
            }
            else{
                Mage::getSingleton('adminhtml/session')->setTfaNotEntered(true);
                $vRedirectUrl = Mage::helper("adminhtml")->getUrl("adminhtml/twofactorauth/interstitial");
            }
            $oResponse->setRedirect($vRedirectUrl);
            $oResponse->sendResponse();
            exit();
        }
        return $this;
    }

    public function verifySecret($observer)
    {
        $authHelper = Mage::helper('twofactorauth/auth');

        $code = Mage::app()->getRequest()->getParam('twofactorauth_code');
        $secret = Mage::app()->getRequest()->getParam('twofactorauth_secret');
        $user = Mage::getSingleton('admin/session')->getUser(); /** @var $user Mage_Admin_Model_User */

        // Try to configure 2fa if the users entered a code
        if ($code) {
            // Success
            if ($authHelper->verifyCode($code, $secret)) {
                try {
                    $user->setTwofactorToken($secret)->save();
                    Mage::getSingleton('adminhtml/session')->unsTfaNotAssociated(true);
                }
                catch (Exception $e) {
                    Mage::logException($e);
                }
            }
            // Failure
            else {
                $message = Mage::helper('twofactorauth')->__('The code you entered was invalid.  Please try again.');
                Mage::getSingleton('adminhtml/session')->addError($message);
            }
        }

        // Process secret questions
        $rows = (array) Mage::app()->getRequest()->getPost('questions');
        if ($rows) {
            $questions = array();
            $invalidQuestion = FALSE;
            foreach ($rows as $index => $row) {
                if ( ! empty($row['question']) && ! empty($row['answer'])) {
                    $questions[(string)$row['question']] = (string)$row['answer'];
                } else if ( ! empty($row['question']) || ! empty($row['answer'])) {
                    $invalidQuestion = TRUE;
                }
            }
            if ($invalidQuestion) {
                $message = Mage::helper('twofactorauth')->__('Questions with empty question or answer were not saved.');
                Mage::getSingleton('adminhtml/session')->addWarning($message);
            }

            $resource = Mage::getResourceModel('twofactorauth/user_question');
            try {
                $resource->beginTransaction();
                $resource->deleteQuestions($user->getId());
                foreach ($questions as $question => $answer) {
                    $questionObject = Mage::getModel('twofactorauth/user_question'); /** @var $questionObject MageHackDay_TwoFactorAuth_Model_User_Question */
                    $questionObject->setUserId($user->getId());
                    $questionObject->setQuestion($question);
                    $questionObject->setAnswer($answer);
                    $questionObject->save();
                }
                $resource->commit();
            } catch (Exception $e) {
                $resource->rollBack();
                Mage::logException($e);
                $message = Mage::helper('twofactorauth')->__('An error occurred while saving the secret questions.');
                Mage::getSingleton('adminhtml/session')->addWarning($message);
            }
        }
    }

    /**
     * Listens for the controller_action_postdispatch_adminhtml Event to
     * check if an Admin that was sent to either:
     *   (a) My Account to associate a Two Factor Auth, or
     *   (b) interstitial page to enter their TFA value
     * is attempting to navigate away without performing the necessary TFA action
     *
     * @param $oObserver
     */
    public function checkTfaSubmitted($oObserver){
        if(Mage::app()->getRequest()->getActionName() == 'logout' || !Mage::helper('twofactorauth')->isActive()){
            return $this;
        }

        $request = $oObserver->getControllerAction()->getRequest();
        if($request->getControllerName() == 'twofactorauth' || $request->getControllerName() == 'system_account') {
            return $this;
        }

        $vRedirectUrl = '';
        if(Mage::getSingleton('adminhtml/session')->getTfaNotAssociated()){
            $vMessage = Mage::helper('twofactorauth')->__('Please connect your Two Factor Authentication before accessing restricted admin functionality');
            Mage::getSingleton('adminhtml/session')->addError($vMessage);
            $vRedirectUrl = Mage::helper("adminhtml")->getUrl("adminhtml/system_account/index");
        }
        else if (Mage::getSingleton('adminhtml/session')->getTfaNotEntered()){
            $vRedirectUrl = Mage::helper("adminhtml")->getUrl("adminhtml/twofactorauth/interstitial");
        }
        if($vRedirectUrl){
            $oRequest = Mage::app()->getRequest();
            $vAction = $oRequest->getActionName();
            Mage::app()->getFrontController()->getAction()->setFlag($vAction, Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH, true);
            $oResponse = Mage::app()->getResponse();
            $oResponse->setRedirect($vRedirectUrl);
            $oResponse->sendResponse();
        }
    }

    /**
     * @todo Store the original after auth url so we can redirect the user after entering their 2fa code
     *
     * @param $observer
     */
    public function customerAuthenticateAfter($observer)
    {
        if(!Mage::helper('twofactorauth')->isActive() || !Mage::helper('twofactorauth')->isFrontendActive()) {
            return $this;
        }
        $customer = $observer->getEvent()->getModel();

        if($customer->getTwofactorauthToken()) {
            $redirectUrl = Mage::getModel("core/url")->getUrl("twofactorauth/interstitial");
            $session = $this->_getSession();

            $session->setOriginalAfterAuthUrl($session->getAfterAuthUrl());

            $session->setAfterAuthUrl($redirectUrl);
        }
    }

    protected function _getSession()
    {
        return Mage::getSingleton('customer/session');
    }
}
