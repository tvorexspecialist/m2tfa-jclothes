<?php

/**
 * adminhtml controller to enforce Two Factor Authentication
 *
 * @category    MageHackDay
 * @package     MageHackDay_TwoFactorAuth
 * @author      Jonathan Day <jonathan@aligent.com.au>
 */
class MageHackDay_TwoFactorAuth_Adminhtml_TwofactorauthController extends Mage_Adminhtml_Controller_Action
{
    protected function _construct()
    {
        parent::_construct();
        // Define module dependent translate
        $this->setUsedModuleName('MageHackDay_TwoFactorAuth');
    }

    public function logoutAction()
    {
      Mage::getSingleton('adminhtml/session')->getCookie()->delete(
          Mage::getSingleton('adminhtml/session')->getSessionName()
      );
      Mage::getSingleton('adminhtml/session')->addSuccess( $this->__("You have been logged out.") );
      $this->_redirect('adminhtml/index');
    }

    public function resetcustomertokenAction()
    {
      $loggedIn = Mage::getSingleton('admin/session')->isLoggedIn();
      $customerId = Mage::app()->getRequest()->getParam("customer_id");

      if ( !$loggedIn || empty($customerId) )
      {
        $this->_redirect('*');
        return;
      }

      $customer = Mage::getModel('customer/customer')->load($customerId);
      if ( !$customer->getId() )
      {
        Mage::getSingleton('adminhtml/session')->addError( $this->__("Customer not found") );
        $this->_redirect('adminhtml/customer/index');
        return;
      }

      try
      {
        $customer->setTwofactorauthToken(null);
        $customer->save();
        Mage::getSingleton('adminhtml/session')->addSuccess( $this->__("Token resetted") );
      }
      catch (Mage_Exception $e)
      {
        Mage::getSingleton('adminhtml/session')->addError( $this->__("Error while saving Customer: %s"), $e->getMessage());
      }

      $this->_redirect('adminhtml/customer/edit', array('id' => $customerId));
    }

    public function interstitialAction()
    {
        if (Mage::helper('twofactorauth/auth')->isAuthorized($this->_getUser())) {
            $this->_getSession()->unsTfaNotEntered(TRUE);
            $this->_redirect('*');
            return;
        }

        $this->loadLayout();
        $this->renderLayout();
    }

    public function verifyAction()
    {
        $oRequest = Mage::app()->getRequest();
        $vInputCode = $oRequest->getPost('input_code', NULL);
        $rememberMe = (bool) $oRequest->getPost('remember_me', FALSE);
        $authHelper = Mage::helper('twofactorauth/auth');
        $vSecret = $this->_getUser()->getTwofactorToken();
        if ( ! $vSecret) {
            // User is accessing protected route without configured TFA
            $this->_getSession()->addError($this->__('Your 2FA token has not been created.'));
            $this->_redirect('*/*/qr');
            return;
        }
        $bValid = $authHelper->verifyCode($vInputCode, $vSecret);
        if ($bValid === FALSE) {
            $this->_getSession()->addError($this->__('Invalid security code.'));
            $this->_redirect('*/*/interstitial');
            return;
        }
        if ($rememberMe) {
            try {
                $cookie = $authHelper->generateCookie();
                Mage::getResourceModel('twofactorauth/user_cookie')->saveCookie($this->_getUser()->getId(), $cookie);
                $authHelper->setCookie($cookie, $this->_getCookieExpiry());
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }

        $this->_getSession()->unsTfaNotEntered();
        $this->_redirect('*');
    }

    /**
     * Clear cookies for the current user
     */
    public function clearCookiesAction()
    {
        if ( ! Mage::helper('twofactorauth/auth')->isReAuthenticated()) {
            $this->_getSession()->addError($this->__('Access Denied.'));
            $this->_redirect('*/*/edit');
            return;
        }

        try {
            Mage::getResourceModel('twofactorauth/user_cookie')->deleteCookies($this->_getUser());
            $this->_getSession()->addSuccess($this->__('Security code will be required on next login.'));
            Mage::getSingleton('adminhtml/session')->setData('reauthenticated_2fa', FALSE);
        } catch (Exception $e) {
            $this->_getSession()->addException($e, $this->__('An error occurred while forcing security code on next login.'));
        }

        $this->_redirect('*/*/edit');
    }

    /**
     * Display one time secret question
     */
    public function questionAction()
    {
        $collection = Mage::getResourceModel('twofactorauth/user_question_collection')
            ->addUserFilter($this->_getUser())
            ->setRandomOrder();
        $collection->setCurPage(1)->setPageSize(1);
        $question = $collection->getFirstItem();
        if ( ! $question->getId()) {
            $this->_getSession()->addError($this->__('Cannot load the secret question.'));
            $this->_redirect('*/*/interstitial');
            return;
        }

        $this->loadLayout();
        $this->renderLayout();
    }

    /**
     * Check answer to the one time secret question
     */
    public function answerAction()
    {
        $questionId = (int) Mage::app()->getRequest()->getPost('question_id');
        if ( ! $questionId) {
            $this->_redirect('*/*/interstitial');
            $this->_getSession()->addError($this->__('Unknown question.'));
            return;
        }
        $answer = (string) Mage::app()->getRequest()->getPost('answer');
        if (empty($answer)) {
            $this->_redirect('*/*/interstitial');
            $this->_getSession()->addError($this->__('Please enter your answer to the secret question.'));
            return;
        }
        $question = Mage::getModel('twofactorauth/user_question')->load($questionId);
        if ( ! $question->getId() || $question->getUserId() != $this->_getUser()->getId()) {
            $this->_redirect('*/*/interstitial');
            $this->_getSession()->addError($this->__('Cannot load the secret question.'));
            return;
        }
        if ( ! Mage::helper('core')->validateHash($answer, $question->getAnswer())) {
            $this->_redirect('*/*/interstitial');
            $this->_getSession()->addError($this->__('Answer to the secret question is invalid.'));
            return;
        }

        $this->_getSession()->setTfaNotEntered(FALSE);
        $question->delete();
        $hasQuestions = Mage::getResourceModel('twofactorauth/user_question')->hasQuestions($this->_getUser());
        if ( ! $hasQuestions) {
            $this->_getSession()->addWarning($this->__('The last one-time question was used. Please generate new secret questions.'));
            $this->_redirect('*/*/qr');
            return;
        }

        $this->_redirect('*');
        return;
    }

    /**
     * QR code action
     */
    public function qrAction()
    {
        if ($this->_hasToken() && $this->_isAuthenticated()) {
            $this->_redirect('*/*/edit');
            return;
        }

        $this->loadLayout();
        $this->renderLayout();
    }

    /**
     * Submit QR secret code
     */
    public function qrSubmitAction()
    {
        if ( ! $this->getRequest()->isPost()) {
            return;
        }

        // Force user to re-authenticate to change secret questions
        if ($this->_getUser()->getTwofactorToken()
            && Mage::app()->getRequest()->getPost('questions')
            && ! Mage::helper('twofactorauth/auth')->isReAuthenticated()) {
            $this->_getSession()->addError($this->__('Access Denied.'));
            $this->_redirect('*/*/edit');
            return;
        }

        // Process secret token if not yet configured
        if ( ! $this->_getUser()->getTwofactorToken()) {
            $secret = (string) $this->getRequest()->getPost('qr_secret');
            $securityCode = (string) $this->getRequest()->getPost('security_code');
            if ( ! $secret || ! $securityCode) {
                $this->_redirect('*/*/qr');
                return;
            }

            // Verify 2FA security code
            if (Mage::helper('twofactorauth/auth')->verifyCode($securityCode, $secret)) {
                try {
                    $this->_getUser()->setTwofactorToken($secret)->save();
                    $this->_getSession()->unsTfaNotAssociated();
                }
                catch (Exception $e) {
                    $this->_getSession()->addException($e, $this->__('An error occurred while saving the security code.'));
                    $this->_redirect('*/*/qr');
                    return;
                }

                // Do not require 2-Factor-Authentication on this computer in the future
                $rememberMe = (bool) $this->getRequest()->getPost('remember_me', FALSE);
                if ($rememberMe) {
                    try {
                        $cookie = Mage::helper('twofactorauth/auth')->generateCookie();
                        Mage::getResourceModel('twofactorauth/user_cookie')->saveCookie($this->_getUser()->getId(), $cookie);
                        Mage::helper('twofactorauth/auth')->setCookie($cookie, $this->_getCookieExpiry());
                    } catch (Exception $e) {
                        Mage::logException($e);
                    }
                }

            } else {
                $this->_getSession()->addError($this->__('Invalid security code.'));
                $this->_redirect('*/*/qr');
                return;
            }
        }

        // Process secret questions
        $rows = (array) Mage::app()->getRequest()->getPost('questions');
        if ($rows) {
            try {
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
                    throw new Mage_Core_Exception($this->__('Secret questions with empty question or answer cannot be saved.'));
                }
                if (count($questions) == 0) {
                    throw new Mage_Core_Exception($this->__('At least one secret question is required.'));
                }
                $resource = Mage::getResourceModel('twofactorauth/user_question');
                try {
                    $resource->beginTransaction();
                    // Update existing questions
                    $existingQuestions = Mage::getResourceModel('twofactorauth/user_question_collection')->addUserFilter($this->_getUser());
                    foreach ($existingQuestions as $questionObject) { /** @var $questionObject MageHackDay_TwoFactorAuth_Model_User_Question */
                        if (isset($questions[$questionObject->getQuestion()])) {
                            $answer = (string) $questions[$questionObject->getQuestion()];
                            if ( ! preg_match('/^\*{6}$/', $answer)) {
                                $questionObject->setAnswer(Mage::helper('core')->getHash($answer, 10))->save();
                            }
                            unset($questions[$questionObject->getQuestion()]);
                        } else {
                            $questionObject->delete();
                        }
                    }
                    // Add new questions
                    foreach ($questions as $question => $answer) {
                        $questionObject = Mage::getModel('twofactorauth/user_question'); /** @var $questionObject MageHackDay_TwoFactorAuth_Model_User_Question */
                        $questionObject->setUserId($this->_getUser()->getId());
                        $questionObject->setQuestion($question);
                        $questionObject->setAnswer(Mage::helper('core')->getHash($answer, 10));
                        $questionObject->save();
                    }
                    $resource->commit();
                    $this->_getSession()->addSuccess($this->__('The secret questions have been saved.'));
                    Mage::getSingleton('adminhtml/session')->setData('reauthenticated_2fa', FALSE);
                } catch (Exception $e) {
                    $resource->rollBack();
                    Mage::logException($e);
                    throw new Mage_Core_Exception($this->__('An error occurred while saving the secret questions.'));
                }
            } catch (Mage_Core_Exception $e) {
                $this->_getSession()->addError($e->getMessage());
                $this->_redirect('*/*/qr');
                return;
            } catch (Exception $e) {
                $this->_getSession()->addException($e, $this->__('An error occurred while saving the secret questions.'));
                $this->_redirect('*/*/qr');
                return;
            }
        }

        $this->_redirect('*/*/qr');
        return;
    }

    /**
     * 2FA edit action
     */
    public function editAction()
    {
        $this->loadLayout();
        $this->renderLayout();
    }

    /**
     * Reset Two-Factor Authentication for logged-in user
     */
    public function resetAction()
    {
        if ( ! Mage::helper('twofactorauth/auth')->isReAuthenticated()) {
            $this->_getSession()->addError($this->__('Access Denied.'));
            $this->_redirectReferer();
            return;
        }

        if ( ! $this->_getUser()->getTwofactorToken()) {
            $this->_getSession()->addError($this->__('Two-Factor Authentication is not configured so cannot be reset.'));
            $this->_redirectReferer();
            return;
        }

        $resource = Mage::getResourceModel('twofactorauth/user_question');
        try {
            $resource->beginTransaction();
            $this->_getUser()->setTwofactorToken(NULL)->save();
            Mage::getResourceModel('twofactorauth/user_cookie')->deleteCookies($this->_getUser());
            $resource->deleteQuestions($this->_getUser()->getId());
            $resource->commit();
            $this->_getSession()->addSuccess($this->__('Two-Factor Authentication has been reset.'));
            Mage::getSingleton('adminhtml/session')->setData('reauthenticated_2fa', FALSE);
        } catch (Exception $e) {
            $resource->rollBack();
            $this->_getSession()->addException($e, $this->__('An unexpected error occurred while resetting the Two-Factor Authentication.'));
        }

        // Logout the user
        if ( empty($userId) )
        {
          $adminSession = Mage::getSingleton('admin/session');
          $adminSession->unsetAll();
          $adminSession->getCookie()->delete($adminSession->getSessionName());
        }

        $this->_redirect('*');
        return;
    }

    /**
     * Reset Two-Factor Authentication for other users
     */
    public function resetUserAction()
    {
        $user = Mage::getModel('admin/user');
        $user->load($this->getRequest()->getParam('user_id'));
        if ( ! $user->getId()) {
            $this->_getSession()->addError($this->__('That user no longer exists.'));
            $this->_redirectReferer($this->getUrl('*/permissions_user'));
        }

        try {
            $user->getResource()->beginTransaction();
            $user->setTwofactorToken(NULL)->save();
            Mage::getResourceModel('twofactorauth/user_cookie')->deleteCookies($user);
            Mage::getResourceModel('twofactorauth/user_question')->deleteQuestions($user);
            $user->getResource()->commit();
        } catch (Exception $e) {
            $user->getResource()->rollBack();
            Mage::logException($e);
            $this->_getSession()->addError($this->__('An unexpected error occurred while resetting 2FA.'));
        }
        $this->_redirectReferer($this->getUrl('*/permissions_user'));
    }

    /**
     * Validate password
     */
    public function passwordAction()
    {
        $password = (string)$this->getRequest()->getParam('password');
        if (empty($password)) {
            $this->_getSession()->addError($this->__('Invalid request.'));
            $this->_redirect('*');
            return;
        }

        if ( ! Mage::helper('core')->validateHash($password, $this->_getUser()->getPassword())) {
            $this->_getSession()->addError($this->__('Invalid password.'));
        } else {
            $this->_getSession()->addSuccess($this->__('The password was successfully verified.'));
            Mage::getSingleton('adminhtml/session')->setData('reauthenticated_2fa', TRUE);
        }

        $this->_redirect('*/*/edit');
        return;
    }

    /**
     * @return Mage_Admin_Model_User
     */
    protected function _getUser()
    {
        return Mage::getSingleton('admin/session')->getUser();
    }

    /**
     * Check whether the action is allowed
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        $action = $this->getRequest()->getActionName();

        if ($action == 'resetUser') {
            return Mage::getSingleton('admin/session')->isAllowed('system/acl/users');
        }

        if ( ! Mage::helper('twofactorauth')->isForceForBackend()) {
            $isAllowed = Mage::getSingleton('admin/session')->isAllowed('admin/system/myaccount');
            if ( ! $isAllowed) {
                return FALSE;
            }
        }

        $hasToken = $this->_hasToken();
        $authenticated = $this->_isAuthenticated();

        if (in_array($action, array('question', 'answer'))) {
            return $hasToken;
        }

        if (in_array($action, array('qr', 'qrSubmit'))) {
            return ( ! $hasToken || ($hasToken && $authenticated));
        }

        if (in_array($action, array('edit', 'save'))) {
            return ($hasToken && $authenticated);
        }

        if ( ! $authenticated && in_array($action, array('clearCookies', 'reset'))) {
            return FALSE;
        }

        return TRUE;
    }

    /**
     * Check whether the user is authenticated with 2FA
     *
     * @return bool
     */
    protected function _isAuthenticated()
    {
        return ! Mage::getSingleton('adminhtml/session')->getTfaNotEntered();
    }

    /**
     * Check whether the user has authentication token
     *
     * @return bool
     */
    protected function _hasToken()
    {
        $user = $this->_getUser();

        if (!$user)
        {
          return false;
        }

        return !! $user->getTwofactorToken();
    }

    protected function _getCookieExpiry() {
        return Mage::helper('twofactorauth')->getRememberMeDuration();
    }
}
