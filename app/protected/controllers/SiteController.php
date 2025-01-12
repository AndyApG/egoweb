<?php
namespace app\controllers;

use app\models\ResendVerificationEmailForm;
use app\models\VerifyEmailForm;
use Yii;
use yii\base\InvalidArgumentException;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use app\models\LoginForm;
use app\models\PasswordResetRequestForm;
use app\models\ResetPasswordForm;
use app\models\SignupForm;
use app\models\ContactForm;
use app\helpers\Tools;
use app\models\User;
use yii\helpers\Url;

/**
 * Site controller
 */
class SiteController extends Controller
{
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'actions' => ['index', 'login', 'create', 'request-password-reset', 'reset-password', 'captcha', 'error'],
                        'allow' => true,
                        'roles' => ['?'],
                    ],
                    [
                        'actions' => ['create'],
                        'allow' => false,
                        'roles' => ['@'],
                    ],
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            /*
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'logout' => ['post'],
                ],
            ],*/
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
            'captcha' => [
                'class' => 'yii\captcha\CaptchaAction',
                'fixedVerifyCode' => null,
                //Background color
                'backColor' => 0xFFFFFF,
                //Maximum number of displays
                'maxLength' => 4,
                //Minimum number of displays
                'minLength' => 4,
                //Spacing
                'padding' => 2,
                //Height
                'height' => 30,
                //Width
                'width' => 85,
                //Font color
                'foreColor' => 0x000000,
                //Set character offset
                'offset' => 4,
                //'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null,
            ],
        ];
    }

    /**
     * Default homepage.  Redrects user to login page or admin page based on login status.
     *
     * @return mixed
     */
    public function actionIndex()
    {
        $this->view->title = "EgoWeb 2.0";
        if (!Yii::$app->user->isGuest) {
            return $this->response->redirect(Url::toRoute('/admin'));
        }

        $users = User::find()->all();
        if (count($users) == 0) {
            return $this->response->redirect(Url::toRoute('/site/create'));
        }

        return $this->render('index');
    }

    /**
    * Displays error.
    *
    * @return mixed
    */
    public function actionError()
    {
        return $this->render('error');
    }

    /**
     * Logs in a user.
     * /site/login
     */
    public function actionLogin()
    {
        $users = User::find()->all();
        if (count($users) == 0) {
            return $this->response->redirect(Url::toRoute('/site/create'));
        }

        $table = Yii::$app->db->schema->getTableSchema('user');
        if (isset($table->columns['lastActivity'])) {
            $oldApp = \Yii::$app;
            new \yii\console\Application([
                'id'            => 'Command runner',
                'basePath'      => '@app',
                'components'    => [
                    'db' => $oldApp->db,
                ],
            ]);
            \Yii::$app->runAction('migrate/up', ['migrationPath' => '@console/migrations/', 'interactive' => false]);
            \Yii::$app = $oldApp;
        }
        
        $this->view->title = "EgoWeb 2.0";
        $failedCount = Yii::$app->session->get('loginFailed') ?  Yii::$app->session->get('loginFailed') : 0;

        if (!Yii::$app->user->isGuest) {
            return $this->response->redirect(Url::toRoute('/admin'));
        }

        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            // return $this->goBack();
            if ($failedCount < 3 || $this->createAction('captcha')->validate($model->captcha, false)) {
                Yii::$app->session->set('loginFailed', null);
                return $this->response->redirect(Url::toRoute('/admin'));
            } else {
                $model->password = '';
                return $this->render('login', [
                    'failedCount' => $failedCount,
                    'model' => $model,
                ]);
            }
        } else {
            $model->password = '';
            return $this->render('login', [
                'failedCount' => $failedCount,
                'model' => $model,
            ]);
        }
    }

    /**
     * Logs out the current user.
     * /site/logout
     */
    public function actionLogout()
    {
        Yii::$app->user->logout();
        return $this->redirect(Yii::$app->request->referrer);
    }

    /**
     * Creates new super user
     * /site/create
     */
    public function actionCreate()
    {
        $this->view->title = "EgoWeb 2.0";
        $users = User::find()->all();
        if (count($users) != 0) {
            return $this->goBack();
        }

        $model = new SignupForm();

        $table = Yii::$app->db->schema->getTableSchema('user');
        if (isset($table->columns['lastActivity'])) {
            $oldApp = \Yii::$app;
            new \yii\console\Application([
                'id'            => 'Command runner',
                'basePath'      => '@app',
                'components'    => [
                    'db' => $oldApp->db,
                ],
            ]);
            \Yii::$app->runAction('migrate/up', ['migrationPath' => '@console/migrations/', 'interactive' => false]);
            \Yii::$app = $oldApp;
        }
    
        if (Yii::$app->request->isPost) {
            if ($model->load(Yii::$app->request->post())) {
                $user = new User();
                $user->name = $model->name;
                $user->email = $model->email;
                $user->setPassword($model->password);
                $user->generateAuthKey();
                $user->permissions = 11;
                if ($user->save()) {
                    Yii::$app->session->setFlash('success', 'Thank you for registration. Please check your inbox for verification email.');
                    //return $this->goHome();
                    //     $model = new VerifyEmailForm($token);
                    //if ($user = $model->verifyEmail()) {
                    if (Yii::$app->user->login($user)) {
                        //Yii::$app->session->setFlash('success', 'Your email has been confirmed!');
                        return $this->response->redirect(Url::toRoute('/admin'));
                    }
                    // }
                } else {
                    print_r($user->errors);
                    die();
                }
            } else {
                print_r($model->errors);
                die();
            }
        }
        return $this->render('create', [
            'model' => $model,
        ]);
    }

    /**
     * Requests password reset.
     * /site/request-password-reset
     */
    public function actionRequestPasswordReset()
    {
        $this->view->title = "EgoWeb 2.0";
        $model = new PasswordResetRequestForm();

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($model->sendEmail()) {
                Yii::$app->session->setFlash('success', 'Check your email for further instructions.');
                return $this->goHome();
            } else {
                Yii::$app->session->setFlash('error', 'Sorry, we are unable to reset password for the provided email address.');
            }
        }

        return $this->render('requestPasswordResetToken', [
            'model' => $model,
        ]);
    }

    /**
     * Resets password.
     * /site/reset-password
     * @param string $token
     * @return mixed
     * @throws BadRequestHttpException
     */
    public function actionResetPassword($token)
    {
        $this->view->title = "EgoWeb 2.0";

        try {
            $model = new ResetPasswordForm($token);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            Yii::$app->session->setFlash('success', 'New password saved.');
            $user = $model->resetPassword();
            if ($user && Yii::$app->user->login($user)) {
                return $this->response->redirect(Url::toRoute('/admin'))->send();
            } else {
                print_r($user->errors);
            }
        } else {
            print_r($model->errors);
        }

        return $this->render('resetPassword', [
            'model' => $model,
        ]);
    }

    /**
     * Verify email address
     * 
     * @param string $token
     * @throws BadRequestHttpException
     * @return yii\web\Response
     */
    public function actionVerifyEmail($token)
    {
        try {
            $model = new VerifyEmailForm($token);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
        if ($user = $model->verifyEmail()) {
            if (Yii::$app->user->login($user)) {
                Yii::$app->session->setFlash('success', 'Your email has been confirmed!');
                return $this->goHome();
            }
        }

        Yii::$app->session->setFlash('error', 'Sorry, we are unable to verify your account with provided token.');
        return $this->goHome();
    }

    /**
     * Resend verification email
     *
     * @return mixed
     */
    public function actionResendVerificationEmail()
    {
        $model = new ResendVerificationEmailForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($model->sendEmail()) {
                Yii::$app->session->setFlash('success', 'Check your email for further instructions.');
                return $this->goHome();
            }
            Yii::$app->session->setFlash('error', 'Sorry, we are unable to resend verification email for the provided email address.');
        }

        return $this->render('resendVerificationEmail', [
            'model' => $model
        ]);
    }
}
