<?php

namespace elementaree\components\services;

use AHCOrders;
use AR_Orders;
use AHCProducts;
use AR_Products;
use AR_TableLog;
use AR_Users;
use AR_Packs;
use AR_TasksQueue;
use AR_Deliveries;
use CEvent;
use DateTime;
use elementaree\components\exceptions\NotFoundUserException;
use elementaree\components\helpers\EEDateHelper;
use elementaree\modules\api\models\products\Product;
use elementaree\modules\api\models\Subscription;
use Maknz\Slack\Attachment;
use Maknz\Slack\Client;
use Exception;
use CConsoleApplication;
use Yii;

/**
 * Class SlackService
 * @package elementaree\components\services
 */
class SlackService extends Service
{
    /**
     * time alert to slack on change Order
     * int
     */
    const START_TIME_ORDER_ALERT = 29 * 3600;

    /**
     * @var string
     */
    const CHANNEL_UNIT_PRODUCTION = '#unit-production';

    /**
     * @var string
     */
    const CHANNEL_OPERATIONS_ASSEMBLY = '#operations_assembly';

    /**
     * @var string
     */
    const CHANNEL_URGENT_BASEE = '#urgent_basee'; //wow

    /**
     * @var string
     */
    const CHANNEL_URGENT_BASEE_X1 = '#basee-custom-х-1';

    /**
     * @var string
     */
    const CHANNEL_URGENT_SMARTEE = '#urgent_smartee'; //diet

    /**
     * new line in message
     * string
     */
    const NEW_LINE = "\n";

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var bool
     */
    protected $send;

    /**
     * @param string $hook
     * @param array $settings
     */
    public function __construct($hook, array $settings)
    {
        $this->client = new Client($hook, $settings);
        $this->send = Yii::app()->params['send_slack'];
    }

    /**
     * @param AR_Packs $pack
     */
    public function createTaskAlertNotReadyProductsX1(AR_Packs $pack)
    {
        if (!$pack->delivered_on_assembly_day) {
            return;
        }

        $data = ['pack_id' => $pack->getPrimaryKey()];

        $this->getFactory()->getTasksQueueService()->createTask(
            AR_TasksQueue::TYPE_TRIGGERED_ALERT_NOT_READY_PRODUCTS_X1,
            EEDateHelper::getInstance()->getNow()->modify('+1 min'),
            $data,
            1
        );
    }

    /**
     * @param AR_TasksQueue $task
     * @return bool
     * @throws Exception
     */
    public function sendAlertNotReadyProductsX1(AR_TasksQueue $task)
    {
        if (empty($task->data['pack_id'])) {
            throw new Exception('Not found pack_id');
        }

        $pack = AR_Packs::model()->findByPk($task->data['pack_id']);

        if (empty($pack) || !$pack->delivered_on_assembly_day) {
            return false;
        }

        $products = $pack->products;

        if (empty($products)) {
            throw new Exception('Not found products in pack ID = ' . $pack->getPrimaryKey());
        }

//  все что ниже закоменчено, костыль из https://elementaree.atlassian.net/browse/SUP-1528

        try {
            $delivery = $pack->delivery;
            $subscription = $delivery->subscription;

            $dateHelper = EEDateHelper::getInstance();
            $tomorrow = $dateHelper->getToday()->setTime(0,0)->modify('+2 day');
            $firstDeliveryDate = $dateHelper->getDate($delivery->delivery_date);
            $time = $firstDeliveryDate->getTimestamp() - $tomorrow->getTimestamp();

            foreach ($products as $product) {

//                if ($product->isDiet() || $product->isReady()) {
//                    continue;
//                }

            /** @var Product $subscriptionProduct */
                if ($time <= 0
                    && $subscription instanceof Subscription
//                    && Product::TYPE_DIET != $subscription->getProduct()->type
                    && in_array($subscription->getProduct()->type, [Product::TYPE_DIET, Product::TYPE_WOW])
                ) {
                    var_dump($time);
//                    if (!$product->isReady()) {
                        /** @var Subscription $subscription */
                        $subscription = $delivery->subscription;
                        $message = 'Подписка *#* <' . $this->generateSubscriptionLink($subscription) . '|' . $subscription->id . '>' . self::NEW_LINE;
                        $message .= '<' . $this->generateProductLink($product) . '|' . $product->name . '>';

                        if ($product->isWow()) {
                            $message .= ' - *WOW Сценарий ' . $product->scenario_id . '*';
                        }

                        if ($product->isDiet()) {
                            $message .= ' - *DIET Сценарий ' . AHCProducts::getDietCaloriesText($product->diet_product_cal) . '*';
                        }

                        $message .= self::NEW_LINE;
                        $tags = $subscription->getTagsName();

                        if (!empty($tags) && count($tags)) {
                            $message .= 'Теги подписки:' . self::NEW_LINE;
                            $message .= '*' . implode(self::NEW_LINE, $tags);
                            $message .= '*' . self::NEW_LINE;
                        }

                        if (!empty($subscription->customization_comments)) {
                            $message .= 'Комментарии кастомизации: *' . $subscription->customization_comments . '*' . self::NEW_LINE;
                        }

                        $tableLog = AR_TableLog::model()->findByAttributes([
                            'table_name' => $delivery->tableName(),
                            'table_id' => $delivery->getPrimaryKey(),
                            'action' => 'CREATE'
                        ]);

                        $user = null;

                        if ($tableLog) {
                            $user = AR_Users::model()->findByPk($tableLog->user_id);
                        } else {
                            $user = Yii::user() ? Yii::user() : null;
                        }

                        if ($user) {
                            $message .= 'Подписка был создана пользователем: *' . $user->username . '*' . self::NEW_LINE;
                            $message .= 'Дата создания: *' . EEDateHelper::getInstance()->getDate($delivery->created_at)->format(EEDateHelper::FORMAT_DATE_DEFAULT_FULL) . '*' . self::NEW_LINE;
                        }

                        $message .= 'Дата доставки: *(День в день) ' . $delivery->delivery_date . '*' . self::NEW_LINE;
                        $attachment = [
                            'color' => '#36a64f',
                            'fallback' => $message,
                            'text' => $message,
                            'mrkdwn_in' => ["text", "fallback"],
                        ];
//                    $text = 'Обратите внимание! Создана новая подписка.';
                        $text = 'Обратите внимание! "Создана новая"/Изменена подписка.';

                        $attachment = new Attachment($attachment);
                        $this->sendAttachmentMessage($text, $attachment, self::CHANNEL_URGENT_BASEE_X1);

                        return true;
//                    }
                }
            }
        } catch (Exception $exp) {
            $this->getFactory()->getSentryService()->sendLog($exp);
        }

        return false;
    }

    /**
     * @param AR_Packs $pack
     * @param AR_Products $product
     */
    public function sendAlertNotReadyCustomProduct(AR_Packs $pack, AR_Products $product)
    {
        try {
            $delivery = $pack->delivery;
            $startX2 = new DateTime($delivery->delivery_date . ' 00:00:00');
            $endX2 = new DateTime($delivery->delivery_date . ' 14:00:00');
            $startX2->modify('-2 day');
            $endX2->modify('-2 day');
            $startX3 = new DateTime($delivery->delivery_date . ' 19:00:00');
            $endX3 = new DateTime($delivery->delivery_date . ' 23:59:59');
            $startX3->modify('-3 day');
            $endX3->modify('-3 day');
            $urgentSmartee = (time() >= $startX2->getTimestamp() && time() <= $endX2->getTimestamp());
            $urgentBasee = (time() >= $startX3->getTimestamp() && time() <= $endX3->getTimestamp());

            if ($urgentSmartee || $urgentBasee) {
                if ($product->isCustom()
                    && !$product->isReady()
                    && (($urgentSmartee && $product->isDiet()) || (($urgentBasee || $urgentSmartee)&& $product->isWow()))
                ) {
                    /** @var Subscription $subscription */
                    $subscription = $delivery->subscription;
                    $message = 'Подписка # <' . $this->generateSubscriptionLink($subscription) . '|' . $subscription->id . '>' . self::NEW_LINE;
                    $message .= '<' . $this->generateProductLink($product) . '|' . $product->name . '>';

                    if ($product->isWow()) {
                        $message .= ' - Сценарий ' . $product->scenario_id;
                    }

                    $message .= self::NEW_LINE;
                    $tags = $subscription->getTagsName();

                    if (!empty($tags) && count($tags)) {
                        $message .= '*Теги подписки:*' . self::NEW_LINE;
                        foreach ($tags as $tag) {
                            $message .= $tag . self::NEW_LINE;
                        }
                    }

                    if (!empty($subscription->customization_comments)) {
                        $message .= '*Комментарии к кастомизаци:*' . $subscription->customization_comments . self::NEW_LINE;
                    }

                    $tableLog = AR_TableLog::model()->findByAttributes([
                        'table_name' => $delivery->tableName(),
                        'table_id' => $delivery->getPrimaryKey(),
                        'action' => 'CREATE'
                    ]);

                    if ($tableLog) {
                        $user = AR_Users::model()->findByPk($tableLog->user_id);

                        if ($user) {
                            $message .= '*Подписка был создана пользователем:*' . $user->username . self::NEW_LINE;
                            $message .= '*Дата создания:*' . EEDateHelper::getInstance()->getDate($delivery->created_at)->format(EEDateHelper::FORMAT_DATE_DEFAULT_FULL) . self::NEW_LINE;
                        }
                    }

                    $message .= '*Дата доставки:*' . $delivery->delivery_date . self::NEW_LINE;
                    $attachment = [
                        'color' => '#ffff00',
                        'fallback' => $message,
                        'text' => $message,
                        'mrkdwn_in' => ['text', 'fallback']
                    ];
                    $text = 'Обратите внимание создана новая подписка';

                    if ($this->getToChannelCustomProduct($product)) {
                        $this->addTaskQueue($text, $attachment, $this->getToChannelCustomProduct($product));
                    }

                }
            }
        } catch (Exception $exp) {
            $this->getFactory()->getSentryService()->sendLog($exp);
        }
    }

    /**
     * @param string $date
     */
    public function sendAlertNotReadyStandardProduct($date)
    {
        $date = EEDateHelper::getInstance()->getDate($date);
        $date->modify('+ 2 day');
        $products = $this->getFactory()->getProductService()->getStandardProductsByDate($date);

        if ($products->count() == 0) {
            return;
        }

        $messageWow = '';
        $messageDiet = '';

        /** @var AR_Products $product */
        foreach ($products as $product) {
            if ($product->isWow()) {
                $messageWow .= '<' . $this->generateProductLink($product) . '|' . $product->name . '> - ' .
                    '*' . $product->getStatusText() . '*' . self::NEW_LINE;
            } elseif ($product->isDiet()) {
                $messageDiet .= '<' . $this->generateProductLink($product) . '|' . $product->name . '> - ' .
                    '*' . $product->getStatusText() . '*' . self::NEW_LINE;
            }
        }

        $nowDate = new \DateTime();
        $text = 'На дату ' . $nowDate->format(EEDateHelper::FORMAT_DATE_DEFAULT_FULL) . ', следующие стандартные продукты заказаны. *Доставка (' . $date->format(EEDateHelper::FORMAT_DEFAULT) . ')*. Продукты не переведены в статус "готов":';

        if (!empty($messageWow)) {
            $attachment = [
                'color' => 'danger',
                'fallback' => $messageWow,
                'text' => $messageWow,
                'mrkdwn_in' => ['text', 'fallback']
            ];
            $this->addTaskQueue($text, $attachment, self::CHANNEL_URGENT_BASEE);
        }

        if (!empty($messageDiet)) {
            $attachment = [
                'color' => 'danger',
                'fallback' => $messageDiet,
                'text' => $messageDiet,
                'mrkdwn_in' => ['text', 'fallback']
            ];

            $this->addTaskQueue($text, $attachment, self::CHANNEL_URGENT_SMARTEE);
        }
    }

    /**
     * @param AR_Orders $order
     * @param int $oldStatusOrder
     * @throws NotFoundUserException
     */
    public function sendAlertChangeStatusOrder(AR_Orders $order, $oldStatusOrder)
    {
        if (!$order->isStatus($order::STATUS_DELIVERED)) {
            if ($this->isSendAlertByOrder($order) &&
                $this->isSendAlertByOrderManualPacking($order, $oldStatusOrder)
            ) {
                $tableLog = $this->getTableLog($order);
                if ($tableLog) {
                    /** @var AR_Users $user */
                    $user = AR_Users::model()->findByPk($tableLog->user_id);
                    if ($user) {
                        $message = '';
                        if ($order->isCheckAutoPacking()) {
                            $message .= 'Заказ #' . $order->id . ' был изменен пользователем *' .
                                $user->username . '*' . self::NEW_LINE .
                                'Дата изменения: *' . $tableLog->created_at . '*' . self::NEW_LINE;
                        }
                        $message .= 'Дата доставки: *' . $order->delivery_date . '*' . self::NEW_LINE .
                            'Изменение статуса: _' . AHCOrders::getStatusText($oldStatusOrder) . '_ -> _' .
                            AHCOrders::getStatusText($order->status) . '_ ' . self::NEW_LINE .
                            '<' . $this->generateOrderLink($order) . '| Order #' . $order->id . '>';
                        $attachment = [
                            'color' => 'danger',
                            'fallback' => $message,
                            'text' => $message,
                            'mrkdwn_in' => ['text', 'fallback']
                        ];
                        $text = 'Обратите внимание изменение заказа #' . $order->id . self::NEW_LINE .
                            'Заказ был изменен пользователем *' . $user->username . '*';
                        $this->addTaskQueue($text, $attachment, $this->getToChannel($order));
                    } else {
                        throw new NotFoundUserException($tableLog->user_id);
                    }
                }
            }
        }
    }

    /**
     * @param AR_Orders $order
     * @param string $oldDateOrder
     */
    public function sendAlertChangeDateDeliveryOrder(AR_Orders $order, $oldDateOrder)
    {
        $order->delivery_date = $oldDateOrder;
        if ($this->isSendAlertByOrder($order)) {
            $order->refresh();
            $tableLog = $this->getTableLog($order);
            if ($tableLog && !empty($oldDateOrder)) {
                /** @var AR_Users $user */
                $user = AR_Users::model()->findByPk($tableLog->user_id);
                if ($user) {
                    $message = '';
                    if ($order->isCheckAutoPacking()) {
                        $message .= 'Заказ #' . $order->id . ' был изменен пользователем *' .
                            $user->username . '*' . self::NEW_LINE .
                            'Дата изменения: *' . $tableLog->created_at . '*' . self::NEW_LINE;
                    }
                    $message .= 'статус: *' . AHCOrders::getStatusText($order->status) . '*' . self::NEW_LINE .
                        'Изменение даты доставки: _' . $oldDateOrder . '_ -> _' .
                        $order->delivery_date . '_ ' . self::NEW_LINE .
                        '<' . $this->generateOrderLink($order) . '| Order #' . $order->id . '>';
                    $attachment = [
                        'color' => 'danger',
                        'fallback' => $message,
                        'text' => $message,
                        'mrkdwn_in' => ['text', 'fallback']
                    ];
                    $text = 'Обратите внимание изменена даты доставки заказа #' . $order->id . self::NEW_LINE .
                        'Заказ был изменен пользователем *' . $user->username . '*';
                    $this->addTaskQueue($text, $attachment, $this->getToChannel($order));
                }
            }
        }
    }

    /**
     * @param AR_Orders $order
     * @return string
     */
    protected function getToChannel(AR_Orders $order)
    {
        return $order->isCheckAutoPacking() ?
            self::CHANNEL_UNIT_PRODUCTION :
            self::CHANNEL_OPERATIONS_ASSEMBLY;
    }

    /**
     * @param AR_Products $product
     * @return string
     */
    protected function getToChannelCustomProduct(AR_Products $product)
    {
        $channel = null;
        if ($product->isCustom() && $product->isWow()) {
            $channel = self::CHANNEL_URGENT_BASEE;
        } elseif ($product->isCustom() && $product->isDiet()) {
            $channel = self::CHANNEL_URGENT_SMARTEE;
        }

        return $channel;
    }

    /**
     * @param string $text
     * @param array $attachment
     * @param string $to
     */
    protected function addTaskQueue($text, $attachment, $to)
    {
        $this->getFactory()
            ->getTasksQueueService()
            ->createTask(
                AR_TasksQueue::TYPE_TRIGGERED_SLACK_SEND,
                EEDateHelper::getInstance()->getNow(),
                [
                    'text' => $text,
                    'attachment' => $attachment,
                    'to' => $to,
                ],
                5
            );
    }

    /**
     * @param AR_TasksQueue $task
     * @return bool
     */
    public function sendMessageQueueToSlack(AR_TasksQueue $task)
    {
        $data = $task->data;
        $attachment = new Attachment($data['attachment']);

        $this->sendAttachmentMessage($data['text'], $attachment, $data['to']);

        return true;
    }

    /**
     * @param AR_Orders $order
     * @return AR_TableLog
     */
    protected function getTableLog(AR_Orders $order)
    {
        return AR_TableLog::model()->findByAttributes([
            'table_name' => $order->tableName(),
            'table_id' => $order->id
        ], ['order' => 'created_at DESC']);
    }

    /**
     * @param AR_Orders $order
     * @return bool
     */
    protected function isSendAlertByOrder(AR_Orders $order)
    {
        if ($order->getIsNewRecord()) {
            return false;
        }
        $dateHelper = EEDateHelper::getInstance();
        $timeDilivery = $dateHelper->getDate($order->delivery_date);
        $now = $dateHelper->getNow();
        $diff = $timeDilivery->getTimestamp() - $now->getTimestamp();

        return ($diff >= 0 && $diff <= self::START_TIME_ORDER_ALERT);
    }

    /**
     * @param AR_Orders $order
     * @param int $oldStatusOrder
     * @return bool
     */
    protected function isSendAlertByOrderManualPacking(AR_Orders $order, $oldStatusOrder)
    {
        return $order->isCheckAutoPacking(); //add else https://elementaree.atlassian.net/browse/SUP-646

        if ($order->status == AR_Orders::STATUS_NEW || $oldStatusOrder == AR_Orders::STATUS_NEW) {
            return false;
        }

        if ($order->status == AR_Orders::STATUS_CONFIRMED || $oldStatusOrder == AR_Orders::STATUS_OPENED) {
            return false;
        }

        $result = false;

        /** @var AR_Products $product */
        foreach ($order->products as $product) {
            if ($product->isCustom()) {
                $result = true;
                break;
            }
        }

        return $result;
    }

    /**
     * @param AR_Products $product
     */
    public function sendErrorMessageEmptyProductCard(AR_Products $product)
    {
        $attachment = [
            'color' => 'danger',
            'fields' => [
                [
                    'title' => 'Отсутствует продуктовая карточка',
                    'value' => '<' . $this->generateProductLink($product) . '|' . $product->name . '>',
                ]
            ]
        ];
        $text = 'Обратите внимание на данную ошибку!';

        $this->addTaskQueue($text, $attachment, self::CHANNEL_UNIT_PRODUCTION);
    }

    /**
     * @param string $textMessage
     * @param Attachment $attachment
     * @param string $to
     */
    public function sendAttachmentMessage($textMessage, Attachment $attachment, $to = null)
    {
        $message = $this->client->createMessage();
        $message->attach($attachment);
        if ($to) {
            $message->to($to);
        }
        if ($this->send) {
            $message->setText($this->replaceText($textMessage))->send();
        }
    }

    /**
     * @param string $textMessage
     * @param string $to
     */
    public function sendMessage($textMessage, $to = null)
    {
        $message = $this->client->createMessage();
        if ($to) {
            $message->to($to);
        }
        if ($this->send) {
            $message->setText($this->replaceText($textMessage))->send();
        }
    }

    /**
     * @param string $text
     * @return string
     */
    protected function replaceText($text)
    {
        $simbol = ['&', '<', '>'];
        $replace = ['&amp;', '&lt;', '&gt;'];
        $text = str_replace($simbol, $replace, $text);

        return $text;
    }

    /**
     * @param Subscription $subscription
     * @return string
     */
    protected function generateSubscriptionLink(Subscription $subscription)
    {
        if (Yii::app() instanceof CConsoleApplication) {
            return Yii::app()->params['site-url'] . 'subscriptions/view?id=' . $subscription->id . '&returnUrl=subscriptions/index';
        } else {
            return Yii::app()->createAbsoluteUrl('/subscriptions/view', ['id' => $subscription->id, 'returnUrl' => 'subscriptions/index']);
        }
    }

    /**
     * @param AR_Products $product
     * @return string
     */
    protected function generateProductLink(AR_Products $product)
    {
        if (Yii::app() instanceof CConsoleApplication) {
            return Yii::app()->params['site-url'] . 'products/view?id=' . $product->id . '&returnUrl=products/index';
        } else {
            return Yii::app()->createAbsoluteUrl('/products/view', ['id' => $product->id, 'returnUrl' => 'products/index']);
        }
    }

    /**
     * @param AR_Orders $order
     * @return string
     */
    protected function generateOrderLink(AR_Orders $order)
    {
        if (Yii::app() instanceof CConsoleApplication) {
            return Yii::app()->params['site-url'] . 'deliveries/view?id=' . $order->id . '&returnUrl=deliveries/index';
        } else {
            return Yii::app()->createAbsoluteUrl('/deliveries/view', ['id' => $order->id, 'returnUrl' => 'deliveries/index']);
        }
    }
}
