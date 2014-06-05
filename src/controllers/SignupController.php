<?php
/**
 * SignupController class file.
 * @author Christoffer Niska <christoffer.niska@nordsoftware.com>
 * @copyright Copyright &copy; Nord Software Ltd 2014-
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @package nordsoftware.yii_account.controllers
 */

namespace nordsoftware\yii_account\controllers;

use nordsoftware\yii_account\models\ar\Account;
use nordsoftware\yii_account\models\ar\AccountToken;
use nordsoftware\yii_account\Module;
use nordsoftware\yii_account\helpers\Helper;

class SignupController extends Controller
{
    /**
     * @var string
     */
    public $emailSubject;

    /**
     * @var string
     */
    public $formId = 'signupForm';

    /**
     * @inheritDoc
     */
    public function init()
    {
        parent::init();

        if ($this->emailSubject === null) {
            $this->emailSubject = Helper::t('email', 'Thank you for signing up');
        }
    }

    /**
     * @inheritDoc
     */
    public function filters()
    {
        return array(
            'guestOnly + index',
            'ensureToken + activate',
        );
    }

    /**
     * Displays the 'sign up' page.
     */
    public function actionIndex()
    {
        $modelClass = $this->module->getClassName(Module::CLASS_SIGNUP_FORM);

        /** @var \nordsoftware\yii_account\models\form\SignupForm $model */
        $model = new $modelClass();

        $request = \Yii::app()->request;

        if ($request->isAjaxRequest && $request->getPost('ajax') === $this->formId) {
            echo \CActiveForm::validate($model);
            \Yii::app()->end();
        }

        if ($request->isPostRequest) {
            $model->attributes = $request->getPost(Helper::classNameToKey($modelClass));

            if ($model->validate()) {
                $accountClass = $this->module->getClassName(Module::CLASS_MODEL);

                /** @var \nordsoftware\yii_account\models\ar\Account $account */
                $account = new $accountClass();
                $account->attributes = $model->attributes;

                if (!$account->save(true, array_keys($model->attributes))) {
                    $this->fatalError();
                }

                if (!$this->module->enableActivation) {
                    $account->saveAttributes(array('status' => Account::STATUS_ACTIVATE));
                    $this->redirect(array('/account/authenticate/login'));
                }

                $this->sendActivationMail($account);
                $this->redirect('done');
            }
        }

        $this->render('index', array('model' => $model));
    }

    /**
     * Sends the activation email to the given account.
     *
     * @param Account $account account model.
     * @throws \nordsoftware\yii_account\exceptions\Exception
     */
    protected function sendActivationMail(Account $account)
    {
        if (!$account->save(false)) {
            $this->fatalError();
        }

        $token = $this->generateToken(
            Module::TOKEN_ACTIVATE,
            $account->id,
            Helper::sqlDateTime(time() + $this->module->activateExpireTime)
        );

        $activateUrl = $this->createAbsoluteUrl('/account/signup/activate', array('token' => $token));

        $this->module->sendMail(
            $account->email,
            $this->emailSubject,
            $this->renderPartial('/email/activate', array('activateUrl' => $activateUrl))
        );
    }

    /**
     * Displays the 'done' page.
     */
    public function actionDone()
    {
        $this->render('done');
    }

    /**
     * Actions to take when activating an account.
     *
     * @param string $token authentication token.
     */
    public function actionActivate($token)
    {
        $tokenModel = $this->loadToken(Module::TOKEN_ACTIVATE, $token);

        $modelClass = $this->module->getClassName(Module::CLASS_MODEL);

        /** @var \nordsoftware\yii_account\models\ar\Account $model */
        $model = \CActiveRecord::model($modelClass)->findByPk($tokenModel->accountId);

        if ($model === null) {
            $this->pageNotFound();
        }

        $model->status = Account::STATUS_ACTIVATE;

        if (!$model->save(true, array('status'))) {
            $this->fatalError();
        }

        if (!$tokenModel->saveAttributes(array('status' => AccountToken::STATUS_USED))) {
            $this->fatalError();
        }

        $this->redirect(array('/account/authenticate/login'));
    }
}