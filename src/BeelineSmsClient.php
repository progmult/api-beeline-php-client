<?php

namespace Beeline;

use Http\Client\Exception;
use Http\Client\HttpClient;
use Http\Message\RequestFactory;
use Http\Discovery\HttpClientDiscovery;
use Http\Discovery\MessageFactoryDiscovery;
use SimpleXMLElement;

class BeelineSmsClient
{
    /**
     * Клиент HTTP
     * @var HttpClient
     */
    private $httpClient;

    /**
     * Данные, передаваемые на сервер
     * @var array
     */
    public $postData = [];

    /**
     * Множественный запрос
     * по умолчанию false
     * @var bool
     */
    public $isMultiPost = false;

    /**
     * Фабрика запросов HTTP
     * @var RequestFactory
     */
    private $requestFactory;

    public function __construct(HttpClient $httpClient = null, RequestFactory $requestFactory = null)
    {
        $this->httpClient = $httpClient ?: HttpClientDiscovery::find();
        $this->requestFactory = $requestFactory ?: MessageFactoryDiscovery::find();
    }

    /**
     * Команда на начало мультизапроса
     */
    public function initMultiPost()
    {
        $this->isMultiPost = true;
    }

    /**
     * Команда на конец мультизапроса
     */
    public function deInitMultiPost()
    {
        $this->isMultiPost = false;
        $this->postData = [];
    }

    /**
     * Добавить данные к мультизапросу
     * @param $params
     */
    public function addToMultiPost($params)
    {
        $this->postData['data'][] = $params;
    }

    /**
     * Команда на процессинг мультизапроса и получение результата
     */
    public function processMultiPost()
    {
        $result = $this->getPostRequest($this->postData);
        $this->postData = [];
        return $result;
    }

    /**
     * Выполняет произвольный запрос к API
     *
     * @param $uri
     * @param array $params
     * @param string $method
     * @return \Beeline\SimpleXMLElement|SimpleXMLElement
     * @throws Exception
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function apiCall($uri, $params = [], $method = 'GET')
    {
        // удалить пустые значения
        $params = array_filter($params);
        if ($method == 'GET') {
            $uri .= http_build_query($params);
            $request = $this->requestFactory->createRequest($method, $uri);
        } else {
            $request = $this->requestFactory->createRequest($method, $uri, [], http_build_query($params));
            $request = $request->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        }

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() == 200) {
            // parse XML
            //var_dump($response->getBody()->__toString());
            return BeelineResponseParser::parseXML($response->getBody()->__toString());
        }

        return new SimpleXMLElement('<output/>');
    }

    /**
     * Выполняет POST-запрос к API
     * @param $params
     * @return \Beeline\SimpleXMLElement|SimpleXMLElement
     * @throws Exception
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function getPostRequest($params)
    {
        return $this->apiCall('/?', $params, $method = 'POST');
    }

    /**
     * Отправляет СМС
     *
     * @param string $message текст сообщения
     * @param array|string|null $target номер телефона абонента или список номеров в виде массива (вариант рассылки по адресатам)
     * @param string|null $phl_codename кодовое имя контакт-листа в системе (вариант рассылки по адресатам)
     * @param string|null $sender имя отправителя зарегистрированного для вас в системе
     * @param array $time_period период отправки сообщения в формате [0=>"HH:mm"][1=>"HH:mm"],
     *        в течение которого сообщение должно быть отправлено получателям.
     *        Используйте данный параметр, если хотите разрешить отправку сообщения только в определенное время (например 10:00-21:00).
     * @param boolean $show_description отображать текстовую расшифровку финального статуса.
     *        Если в GET или POST-запросе указать show_description=true, то в ответном XML платформа будет передавать поле с расшифровкой статуса
     *
     * @return SimpleXMLElement
     * @throws Exception
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function post_sms(
        $message,
        $target = null,
        $phl_codename = null,
        $sender = null,
        $time_period = [],
        $show_description = null
    )
    {
        // http://<ip_address>:<port>/sms_send/?action=post_sms&user=<пользователь>&pass=<пароль>&target=<телефоны>&message=<сообщение>

        // Пример:
        //
        // POST= (
        //  [user] => userX
        //  [pass] => ***
        //  [action] => post_sms
        //  [message] => Привет
        //  [target] => +79999999991, +79999999992, +7999999999999
        //  [CLIENTADR] => 127.0.0.1
        //  [HTTP_ACCEPT_LANGUAGE] => ru-ru,ru;q=0.8,en-us;q=0.5,en;q=0.3
        // )
        //
        // Ответ сервера:
        //
        // Если все параметры запроса правильные и сообщение добавлено в очередь на отправку, то сервер
        // возвращает в http-заголовке ответа:
        // HTTP/1.1 200 OK
        //
        //  <output>
        //      <result sms_group_id="996">
        //          <sms id="99991" smstype="SENDSMS" phone="+79999999991"><![CDATA[Привет]]></sms>
        //          <sms id="99992" smstype="SENDSMS" phone="+79999999992"><![CDATA[Привет]]></sms>
        //      </result>
        //      <errors>
        //          <error>Неправильный номер телефона: +7999999999999</error>
        //      </errors>
        //  </output>

        if (is_array($target)) {
            $target = implode(",", $target);
        }

        if (!empty($time_period) && count($time_period) == 2) {
            $time_period = implode("-", $time_period);
        }

        $params = [
            'action' => 'post_sms',
            'message' => $message,
            'target' => $target,
            'phl_codename' => $phl_codename,
            'sender' => $sender,
            'time_period' => $time_period,
            'show_description' => $show_description,
        ];

        if ($this->isMultiPost) {
            $this->addToMultiPost($params);
        } else {
            return $this->getPostRequest($params);
        }
    }


    /**
     * Проверяет статус доставки
     *
     * Три варианта получения статусов для сообщений:
     *   sms_id = данные по одному сообщению.
     *   sms_group_id = данные по всем сообщениям за одну отсылку.
     *   date_from, date_to, smstype = данные по всем сообщениям за период времени от date_from до date_to по типу сообщений smstype.
     *
     * @param integer|null $sms_id данные по одному сообщению
     * @param integer|null $sms_group_id данные по всем сообщениям за одну отсылку
     * @param null $date_from dd.mm.yyyy hh:ii:ss (дд.мм.гггг чч:ми:сс)
     * @param null $date_to dd.mm.yyyy hh:ii:ss (дд.мм.гггг чч:ми:сс
     * @param string|null $smstype типу сообщений SENDSMS текстовые СМС сообщения
     * @return SimpleXMLElement
     * @throws Exception
     * @throws \Psr\Http\Client\ClientExceptionInterface
     */
    public function status(
        $sms_id = null,
        $sms_group_id = null,
        $date_from = null,
        $date_to = null,
        $smstype = null
    )
    {
        // http://<ip_address>:<port>/sms_send/?action=status&user=<пользователь>&pass=<пароль>&sms_id=<id_смс>

        // Пример:
        //
        // POST= (
        //  [action] => status
        //  [sms_id] => 6666
        //  [user] => userX
        //  [pass] => ***
        //  [CLIENTADR] => 127.0.0.1
        //  [HTTP_ACCEPT_LANGUAGE] => ru-ru,ru;q=0.8,en-us;q=0.5,en;q=0.3
        // )
        //
        // Ответ сервера:
        //
        // Если все параметры запроса правильные, то сервер возвращает в http-заголовке ответа:
        // HTTP/1.1 200 OK
        // <output>
        //  <MESSAGES>
        //      <MESSAGE SMS_ID="6666" SMSTYPE="SENDSMS">
        //          <CREATED>24.12.2007 15:57:45</CREATED>
        //          <SMS_SUBMITTER_SUBSYSTEM>WEB</SMS_SUBMITTER_SUBSYSTEM>
        //          <AUL_USERNAME>userX.Y</AUL_USERNAME>
        //          <AUL_CLIENT_ADR>127.0.0.1</AUL_CLIENT_ADR>
        //          <SMS_SENDER>SenderName</SMS_SENDER>
        //          <SMS_TARGET>89999991111</SMS_TARGET>
        //          <SMS_RES_COUNT>1</SMS_RES_COUNT>
        //          <SMS_TEXT>
        //              <![CDATA[ Привет ]]>
        //          </SMS_TEXT>
        //          <SMSSTC_CODE>wait</SMSSTC_CODE>
        //          <SMS_STATUS>Сообщение в процессе доставки</SMS_STATUS>
        //          <SMS_CLOSED>0</SMS_CLOSED>
        //          <SMS_SENT>0</SMS_SENT>
        //      </MESSAGE>
        //  </MESSAGES>
        // </output>

        //  Данные по сообщению:
        //
        //  SMS_ID - ID сообщения
        //  SMS_GROUP_ID - ID рассылки сообщений
        //  SMSTYPE - тип сообщения
        //  CREATED - дата и время создания сообщения
        //  SMS_SUBMITTER_SUBSYSTEM - система отправки сообщения
        //  AUL_USERNAME - Имя пользователя создавшего сообщение
        //  AUL_CLIENT_ADR - IP адрес пользователя создавшего сообщение
        //  SMS_SENDER - Имя отправителя сообщения
        //  SMS_TARGET - Телефон адресата
        //  SMS_RES_COUNT - Кол-во единиц ресурсов на данное сообщение
        //  SMS_TEXT - Текст сообщения
        //  SMSSTC_CODE - Код статуса доставки сообщения
        //  SMS_STATUS - Текстовое описание статуса доставки сообщения
        //  SMS_CLOSED - [0,1] 0 - сообщения находится в процессинге. 1 = работа по отправке сообщения завершена
        //  SMS_SENT - [0,1] 0 - сообщение не отослано. 1 = сообщение отослано успешно
        //  SMS_CALL_DURATION - Время, в течение которого было установлено соединение для отправки сообщения.
        //  SMS_CLOSE_TIME - Время завершения работы по сообщению.

        $params = [
            'action' => 'status',
            'sms_id' => $sms_id,
            'sms_group_id' => $sms_group_id,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'smstype' => $smstype,
        ];

        if ($this->isMultiPost) {
            $this->addToMultiPost($params);
        } else {
            return $this->getPostRequest($params);
        }
    }

    /**
     * Отправить СМС на номера телефонов
     * @param $message
     * @param $phones
     * @param null $sender
     * @return SimpleXMLElement
     * @throws Exception
     */
    public function actionSendSmsByPhone($message, $phones, $sender = null)
    {
        return $this->post_sms($message, $phones, [], $sender);
    }

    /**
     * Получить статус СМС по её внутреннему id
     * @param $sms_id
     * @return SimpleXMLElement
     * @throws Exception
     */
    public function actionStatusSmsById($sms_id)
    {
        return $this->status($sms_id);
    }
}