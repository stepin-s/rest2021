<?php

//require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

require_once dirname(__FILE__) . '/gootaxLib/GootaxApi.php';
require_once dirname(__FILE__) . '/GootaxBaseLayer.php';
// require_once dirname(__FILE__) . '/_adapters.php';

use Bitrix\Main\Diag;

class GootaxAdapter extends GootaxBaseLayer
{
    public $orderTimeShiftInMinutes = 10;
    public $callTarifsFromAPI;
    public $defaultTariffs;

    public $dbHost;
    public $dbLogin;
    public $dbPass;
    public $database;
    public $dbTableName;

    public $key;
    public $tenantid;

    public $cityData;

    public $cacheTime = 5;

	protected $_dbCache;
	
	// $citynewid = (string)$gootax_test['options']['cityId'];

    public function __construct()
    {
        parent::__construct();
        $this->_dbCache = new TaxiFileCache('/dbCache.dat');
    }

    private function setParams()
    {
        $this->setKey($this->key);
        $this->setTenantid($this->tenantid);
    }


    private function getCityId()
    {
        if (isset($_COOKIE['CITY_GOOTAX_ID']) && !empty($_COOKIE['CITY_GOOTAX_ID'])) {
            return $_COOKIE['CITY_GOOTAX_ID'];
        }
        if (isset($_COOKIE['CITY_USER']) && $_COOKIE['CITY_USER']) {
            $bxCityId = $_COOKIE['CITY_USER'];
        } else {
            $bxCityId = '1';
        }

        if ($this->hasConfigurator()) {
            $configurator = $this->getConfigurator();
            if (method_exists($configurator, 'getCityId')) {
                $city = $configurator->getCityId($bxCityId);
            }
        }

        //return $city ?: $bxCityId;
		
        // return  $citynewid;
		
        return '26068';
    }

    public function callCost(
        $fromCity,
        $fromStreet,
        $fromHouse,
        $fromHousing,
        $fromBuilding,
        $fromPorch,
        $fromLat,
        $fromLon,
        $toCity,
        $toStreet,
        $toHouse,
        $toHousing,
        $toBuilding,
        $toPorch,
        $toLat,
        $toLon,
        $clientName,
        $phone,
        $priorTime,
        $customCarId,
        $customCar,
        $carType,
        $carGroupId,
        $tariffGroupId,
        $comment,
        $isMobile,
        $additional,
        $promocode
    ) {
        $this->setParams();

        if ($json = $this->_dbCache->getValue('cost' . $fromCity . $fromStreet . $fromHouse . $fromHousing . $fromBuilding . $toCity . $toStreet . $toHouse . $toHousing . $toBuilding . $phone . $priorTime . $carGroupId . $tariffGroupId . $additional)) {
            $cost = json_decode($json);
        } else {
            $address = [
                [
                    'city_id' => $this->getOrderCityId($fromCity),
                    'city' => $fromCity,
                    'street' => $fromStreet,
                    'house' => $fromHouse,
                    'housing' => $fromHousing,
                    'porch' => $fromPorch,
                    'lat' => $fromLat,
                    'lon' => $fromLon,
                ],
                [
                    'city_id' => $this->getOrderCityId($toCity),
                    'city' => $toCity,
                    'street' => $toStreet,
                    'house' => $toHouse,
                    'housing' => $toHousing,
                    'porch' => $toPorch,
                    'lat' => $toLat,
                    'lon' => $toLon,
                ],
            ];

            $tariffs = $this->findTariffs();
            $serviceValues = [];
            foreach ($tariffs as $tariff) {
                if ($tariff->id == $tariffGroupId) {
                    foreach ($tariff->additional as $dop) {
                        $serviceValues[$dop['id']] = $dop['name'];
                    }
                }
            }

            //$this->writeLog('additional ', $additional);
            $services = [];
            if ($additional) {
                $extras = $additional;
            } else {
                $extras = $comment;
            }
            //$this->writeLog('extras ', $extras);
            foreach ($serviceValues as $value => $service) {
                $this->writeLog($value, $service);
                if (mb_strpos($extras, trim($service/*$value*/)) !== false) {
                    $services[] = $value;//$service;
                }
            }

            $sendPhone = preg_replace('/\D/', '', $phone);
            $options = [
                'address' => json_encode(['address' => $address], JSON_UNESCAPED_UNICODE),
                'city_id' => $this->getCityId(),
                'client_name' => $clientName,
                'client_phone' => $sendPhone,
                'comment' => $comment,
                'current_time' => time(),
                'order_time' => $priorTime,
                'pay_type' => 'CASH',
                'tariff_id' => $tariffGroupId,
                'type_request' => 2,
            ];

            if (!empty($services)) {
                $options['additional_options'] = implode(', ', $services);
            }

            //$this->writeLog('callCost ', $options);

            if ($this->_api->sendPostTo($data, 'api-site/create_order_site', $options)) {
                $cost = $data->result->cost_result;
            } else {
                $cost = false;
            }
            $res = json_encode($cost);
            $this->_dbCache->setValue('cost' . $fromCity . $fromStreet . $fromHouse . $fromHousing . $fromBuilding . $toCity . $toStreet . $toHouse . $toHousing . $toBuilding . $phone . $priorTime . $carGroupId . $tariffGroupId,
                $res, $this->cacheTime);
        }

        return $cost;
    }

    private function getOrderCityId($city = 'Симферополь')
    {
        $this->setParams();

        if (isset($_COOKIE['CITY_GOOTAX_ID']) && !empty($_COOKIE['CITY_GOOTAX_ID'])) {
            return $_COOKIE['CITY_GOOTAX_ID'];
        }

        if (!$cityId = $this->_dbCache->getValue($city)) {
            if ($this->_api->sendGetTo($data, 'get_city_list', ['city_part' => $city, 'current_time' => time()])) {
                //var_dump($data); die();
                if (isset($data->result->city_list[0]->city_id)) {
                    $cityId = (int)$data->result->city_list[0]->city_id;
                }
            }
            $this->_dbCache->setValue($city, $cityId, $this->cacheTime);
        }

        return $cityId;
    }

    public function findCars($filterParam = [])
    {
        $this->setParams();
        $cars = [];
        $cityId = $this->getCityId();

        if ($json = $this->_dbCache->getValue('cars' . $cityId)) {
            $cars = json_decode($json);
        } else {
            if (!empty($filterParam)) {
                $params = $filterParam;
            }
            $params['city_id'] = $cityId;
            $params['current_time'] = time();

            if ($this->_api->sendGetTo($data, 'get_cars', $params)) {
                if (!empty($data->result->cars)) {
                    foreach ($data->result->cars as $driver) {
                        $car = new TaxiCarInfo();
                        $car->id = $driver->car_id;
                        //$car->description = $driver->car_info;
                        //$car->number = $driver->car_number;
                        $car->lat = $driver->car_lat;
                        $car->lon = $driver->car_lon;
                        $car->isFree = $driver->car_is_free;
                        //$car->course = $driver->degree;
                        $cars[] = $car;
                    }
                }
            }
            $res = json_encode($cars);
            $this->_dbCache->setValue('cars' . $cityId, $res, 60);
        }

        return $cars;
    }

    public function findStreets($streetPart, $maxLimit = 50, $city = null)
    {
        $this->setParams();
        $cityId = $this->getCityId();
        $streets = [];
        if ($json = $this->_dbCache->getValue('str' . $streetPart . $cityId)) {
            $streets = json_decode($json);
        } else {
            if ($this->_api->sendGetTo($data, 'get_geoobjects_list',
                ['city_id' => $cityId, 'current_time' => time(), 'street_part' => $streetPart])) {
                if ($data->result) {
                    foreach ($data->result->geo_objects as $object) {
                        if ($object->type == 'street') {
                            $streets[] = $object->street;
                        }
                    }
                }
            }
            $res = json_encode($streets);
            $this->_dbCache->setValue('str' . $streetPart . $cityId, $res, $this->cacheTime);
        }

        return $streets;
    }

    public function findGeoObjects_old($streetPart, $maxLimit = 10, $city = null, $type = null)
    {
        $this->setParams();

        if (is_array($city)) {
            $cityId = $city['id'];
        } else {
            $cityId = $this->getCityId();
        }

        if (!isset($lang)) {
            $lang = 'ru';
        }

        $objects = [];
        if ($json = $this->_dbCache->getValue('obj' . $streetPart . $cityId)) {
            $objects = json_decode($json);
        } else {
            if ($this->_api->sendGetTo($data, 'get_geoobjects_list',
                ['city_id' => $cityId, 'current_time' => time(), 'street_part' => $streetPart])) {
                if (isset($data->result->geo_objects) && !empty($data->result->geo_objects)) {
                    foreach ($data->result->geo_objects as $objectInfo) {
                        $object = new TaxiGeoObjectInfo();
                        if ($objectInfo->type == 'street') {
                            $object->type = TaxiGeoObjectInfo::TYPE_STREET;
                            $object->typeLabel = 'улица';

                            $object->rawLabel = $object->label = $objectInfo->street;

                            $object->address = new TaxiAddressInfo();
                            $object->address->city = $objectInfo->city;
                            $object->address->street = $objectInfo->street;
                            $object->address->house = null;
                            $object->address->location = null;
                            $object->address->rawData = $object->rawLabel;
                        } elseif ($objectInfo->type == 'public_place') {
                            $object->type = TaxiGeoObjectInfo::TYPE_PUBLIC_PLACE;
                            $object->typeLabel = 'публичное место';

                            $object->rawLabel = $object->label = $objectInfo->name;

                            $object->address = new TaxiAddressInfo();
                            $object->address->city = $objectInfo->city;
                            $object->address->street = $objectInfo->street;
                            $object->address->house = $objectInfo->house;
                            $object->address->location = [$objectInfo->lat, $objectInfo->lon];
                            $object->address->rawData = $object->rawLabel;
                        }
                        $objects[] = $object;
                    }
                }
            }
            $res = json_encode($objects);
            $this->_dbCache->setValue('obj' . $streetPart . $cityId, $res, $this->cacheTime);
        }

        return $objects;
    }

    public function findGeoObjects($streetPart, $maxLimit = 10, $city = null, $type = null)
    {
        $this->setParams();

        if (is_array($city)) {
            $cityId = $city['id'];
        } else {
            $cityId = $this->getCityId();
        }

        if (!isset($lang)) {
            $lang = 'ru';
        }

        $cityCoords = ['lat' => (float)$_COOKIE['CITY_LAT'], 'lon' => (float)$_COOKIE['CITY_LON']];
        $objects = [];
        if (0 && $json = $this->_dbCache->getValue('obj' . $streetPart . $cityId)) {
            $objects = json_decode($json);
        } else {
            if ($this->_api->sendAutocomplete($data, 'autocomplete', [
                'text' => $streetPart,
                'focus.point.lat' => $cityCoords['lat'],
                'focus.point.lon' => $cityCoords['lon'],
                'city_id' => $cityId,
                'type_app' => 'frontend',
                'tenant_id' => $this->tenantid,
                'api_key' => 'search-MKZrG6M',
                'format' => 'gootax',
                'lang' => $lang,
                'radius' => 50,
            ])) {
                //$this->writeLog('autocomplete $data', $data);
                if (isset($data->results) && !empty($data->results)) {
                    foreach ($data->results as $objectInfo) {
                        $object = new TaxiGeoObjectInfo();
                        if ($objectInfo->type == 'house') {
                            $object->type = TaxiGeoObjectInfo::TYPE_STREET;
                            $object->typeLabel = 'улица, дом';
                        } elseif ($objectInfo->type == 'public_place') {
                            $object->type = TaxiGeoObjectInfo::TYPE_PUBLIC_PLACE;
                            $object->typeLabel = 'публичное место';
                        }
                        $object->rawLabel = $object->label = $objectInfo->address->city . ', ' . $objectInfo->address->label;
                        $object->address = new TaxiAddressInfo();
                        $object->address->city = $objectInfo->address->city;
                        $object->address->street = $objectInfo->address->street;
                        $object->address->house = $objectInfo->address->house;
                        $object->address->location = [$objectInfo->address->lat, $objectInfo->address->lon];
                        $object->address->rawData = $object->rawLabel;
                        $objects[] = $object;
                    }
                }
            }
            $res = json_encode($objects);
            $this->_dbCache->setValue('obj' . $streetPart . $cityId, $res, $this->cacheTime);
        }

        return $objects;
    }

    public function findTariffs()
    {
        $this->setParams();
        $cityId = $this->getCityId();
        if (!$tariffs = $this->_dbCache->getValue('tariffs' . $cityId)) {
            $tariffs = [];
            if ($this->callTarifsFromAPI) {

                $this->writeLog('$city_id', $cityId);
                
                if ($this->_api->sendGetTo($data, 'get_tariffs_list',
                    ['city_id' => $cityId, 'current_time' => time()])) {

                    if ($data->result) {
                        foreach ($data->result as $tariffData) {
                            $tariff = new TaxiTariffInfo();
                            $tariff->id = isset($tariffData->tariff_id) ? $tariffData->tariff_id : '';
                            $tariff->label = isset($tariffData->tariff_name) ? $tariffData->tariff_name : '';
                            $additionals = [];
                            foreach ($tariffData->additional_options as $dopik) {
                                $additional = [
                                    'id' => $dopik->additional_option_id,
                                    'name' => $dopik->option->name,
                                    'price' => $dopik->price,
                                ];
                                $additionals[] = $additional;
                            }
                            $tariff->additional = $additionals;
                            $tariffs[] = $tariff;
                        }
                    }
                }
            } elseif ($this->defaultTariffs) {
                $tariffs = $this->defaultTariffs[$cityId];
            }
            $this->_dbCache->setValue('tariffs' . $cityId, $tariffs, $this->cacheTime);
        }

        return $tariffs;
    }

    public function createOrder(
        $fromCity,
        $fromStreet,
        $fromHouse,
        $fromHousing,
        $fromBuilding,
        $fromPorch,
        $fromLat,
        $fromLon,
        $toCity,
        $toStreet,
        $toHouse,
        $toHousing,
        $toBuilding,
        $toPorch,
        $toLat,
        $toLon,
        $clientName,
        $phone,
        $priorTime,
        $customCarId,
        $customCar,
        $carType,
        $carGroupId,
        $tariffGroupId,
        $comment,
        $isMobile,
        $additional,
        $promocode
    ) {
        $this->setParams();
        if (empty($toLat)) {
            $address = [
                [
                    'city_id' => $this->getOrderCityId($fromCity),
                    'city' => $fromCity,
                    'street' => $fromStreet,
                    'house' => $fromHouse,
                    'housing' => $fromHousing,
                    'porch' => $fromPorch,
                    'lat' => $fromLat,
                    'lon' => $fromLon,
                ],
            ];
        } else {
            $address = [
                [
                    'city_id' => $this->getOrderCityId($fromCity),
                    'city' => $fromCity,
                    'street' => $fromStreet,
                    'house' => $fromHouse,
                    'housing' => $fromHousing,
                    'porch' => $fromPorch,
                    'lat' => $fromLat,
                    'lon' => $fromLon,
                ],
                [
                    'city_id' => $this->getOrderCityId($toCity),
                    'city' => $toCity,
                    'street' => $toStreet,
                    'house' => $toHouse,
                    'housing' => $toHousing,
                    'porch' => $toPorch,
                    'lat' => $toLat,
                    'lon' => $toLon,
                ],
            ];
        }


        $tariffs = $this->findTariffs();
        $serviceValues = [];
        foreach ($tariffs as $tariff) {
            if ($tariff->id == $tariffGroupId) {
                foreach ($tariff->additional as $dop) {
                    $serviceValues[$dop['id']] = $dop['name'];
                }
            }
        }

        //$this->writeLog('$additional', $additional);
        $services = [];
        if ($additional) {
            $extras = $additional;
        } else {
            $extras = $comment;
        }
        //$this->writeLog('$extras', $extras);
        //$this->writeLog('$serviceValues', $serviceValues);
        foreach ($serviceValues as $value => $service) {
            if (mb_strpos($extras, trim($service/*$value*/)) !== false) {
                $services[] = $value;//$service;
            }
        }
        //$this->writeLog('$services', $services);

        $options = [];

        if (empty($priorTime)) {
            $priorTime = '';
        }

        //$this->writeLog('$priorTime', $priorTime);

        $sendPhone = preg_replace('/\D/', '', $phone);
        $options = [
            'address' => json_encode(['address' => $address], JSON_UNESCAPED_UNICODE),
            'city_id' => $this->getCityId($fromCity),
            'client_name' => $clientName,
            'client_phone' => $sendPhone,
            'comment' => $comment,
            'current_time' => time(),
            'order_time' => $priorTime,
            'pay_type' => 'CASH',
            'tariff_id' => $tariffGroupId,
            'type_request' => 1,
        ];

        if (!empty($services)) {
            $options['additional_options'] = implode(', ', $services);
        }

        $this->writeLog('createOrder', $options);
        if ($this->_api->sendPostTo($data, 'api-site/create_order_site', $options)) {
            $this->_cache->setValue('order' . $data->result->order_number, $data->result->order_id);
            $orderId = $data->result->order_number;
        }

        return $orderId;
    }

    public function getOrderInfo($orderId)
    {
        $this->setParams();

        $order = $this->_cache->getValue('order' . $orderId);

        if ($this->_api->sendGetTo($data, 'get_order_info', [
            'order_id' => $order,
            'need_car_photo' => false,
            'need_driver_photo' => false,
            'current_time' => time(),
        ])) {
            $orderInfo = $data->result->order_info;
            $info = new TaxiOrderInfo();

            $info->id = $orderInfo->order_number;
            $info->carId = $orderInfo->order_number;
            $info->statusCode = $orderInfo->status_id;

            if (empty($orderInfo->detail_cost_info)) {
                $info->cost = $orderInfo->predv_price;
            } else {
                $info->cost = $orderInfo->detail_cost_info->summary_cost;
            }
            $info->costCurrency = $this->costCurrency;

            if ($orderInfo->status_group == 'pre_order') {
                $info->status = 'new';
                $info->statusLabel = 'Идет поиск авто ...';
            } elseif ($orderInfo->status_group == 'call') {
                $info->status = 'car_at_place';
                $info->statusLabel = 'Водитель подъехал';
            } else {
                $info->statusLabel = $orderInfo->status_name;
                $info->status = $orderInfo->status_group;
            }

            if (!empty($orderInfo->car_data)) {
                $info->carDescription = $orderInfo->car_data->car_description;
                $info->driverFio = $orderInfo->car_data->driver_fio;
                $info->carLat = $orderInfo->car_data->car_lat;
                $info->carLon = $orderInfo->car_data->car_lon;
            }
        }

        return $info;
    }

    public function getCarInfo($carId)//В $carId передается orderId
    {
        if ($carInfo = $this->getOrderInfo($carId)) {
            if (isset($carInfo->carDescription)) {
                $car = new TaxiCarInfo();
                $car->id = $carId;
                $car->description = $carInfo->carDescription;
                $car->driverName = $carInfo->driverFio;

                $car->lat = $carInfo->carLat;
                $car->lon = $carInfo->carLon;

                return $car;
            }
        }
    }

    public function rejectOrder($orderId)
    {
        $this->setParams();
        $reject = 0;
        $order = $this->_cache->getValue('order' . $orderId);
        if (!empty($order)) {
            if ($this->_api->sendPostTo($data, 'reject_order', ['order_id' => $order, 'current_time' => time()])) {
                if ($data->result->reject_result == 1) {
                    $reject = 1;
                } else {
                    $reject = 0;
                }
            }
        }

        return $reject;
    }

    public function needSendSms($phone = null)
    {
        $need = 0;
        if ($this->useSmsAuthorization) {
            if (!$this->clientAuthorization->checkTokenByPhone($phone) || !$this->_cache->getValue('user' . $phone)) {
                $need = 1;
            }
        }

        return intval($need);
    }

    public function ping()
    {
        $this->setParams();
        if ($this->_api->sendGetTo($data, 'ping', [
            'current_time' => time(),
        ])) {
            return $data;
        } else {
            return false;
        }
    }

    public function getAccountInfo()
    {
        return false;
    }

    public function sendRealSms($toPhone, $message)
    {
        $this->setParams();
        if ($json = $this->_dbCache->getValue('send' . $toPhone)) {
            $send = json_decode($json);
        } else {
            $sendPhone = preg_replace('/\D/', '', $toPhone);
            if ($this->_api->sendPostTo($data, 'send_password', ['phone' => $sendPhone, 'current_time' => time()])) {
                
                $this->writeLog('sms data', $data);
                
                if (isset($data->result->password_result) && $data->result->password_result == 1) {
                    $send = 1;
                } else {
                    $send = 0;
                }
            }
            $res = json_encode($send);
            $this->_dbCache->setValue('send' . $toPhone, $res, $this->cacheTime);
        }

        return $send;
    }

    private function findBxUser($phone, $clientId) {
        //Diag\Debug::writeToFile('CLIENTID:'. $clientId, $varName = "", $fileName = "");
        $rsUser = CUser::GetList($by, $order,
            array(
                "UF_CLIENT_ID" => $clientId,
                "LOGIN" => $phone

            ),
            array(
                "SELECT" => array(
                    "ID",
                    "UF_CLIENT_ID",
                ),
            )
        );
        $userId = false;

        if($arUser = $rsUser->Fetch()) {
            $userId = $arUser['ID'];
        }

        return $userId>0 ? $userId : false;
    }

    public function checkSms($phone, $smsCode)
    {
        $this->writeLog($phone, $smsCode);
        $this->setParams();
        //Diag\Debug::writeToFile($this->tenantid, $varName = "", $fileName = "");
        if ($json = $this->_dbCache->getValue('check' . $phone . $smsCode)) {
            $check = json_decode($json);
        } else {
            $sendPhone = preg_replace('/\D/', '', $phone);

            if ($this->_api->sendPostTo($data, 'accept_password',
                ['phone' => $sendPhone, 'password' => $smsCode, 'current_time' => time()])) {

                $this->writeLog('accept_password', $data);
                
                if (isset($data->result->accept_result) && $data->result->accept_result == 1) {
                    $check = 1;
                } else {
                    $check = 0;
                }
            }

            if ($data->result->accept_result == 1) {

                $this->writeLog('1 зашли в accept_result=1');

                $userId = $this->findBxUser($sendPhone, $data->result->client_profile->client_id);

                $this->writeLog('2 userId',$userId);
                
                if($userId != false) {

                    $this->writeLog('3 '); 

                    global $USER;

                    $user = new CUser;
                    $fields = Array(
                        "NAME" => $data->result->client_profile->name,
                        "LAST_NAME" => $data->result->client_profile->surname,
                        // "NAME" => "your",
                        // "LAST_NAME" => "name",
                    );

                    $user->Update($userId, $fields);

                    $USER->Authorize($userId);
                    $this->writeLog('4 '); 
                }
                else {
                    $this->writeLog('5 '); 
                    $newlogin = $sendPhone;
                    $newemail = $sendPhone . '@myorg.ru';
                    $newpassword = $sendPhone;
                    // $_name = "name";
                    // $_surname = "surname";
                    $group = [3];
                    $user = new CUser;
                    $arFields = [
                        "NAME" => $data->result->client_profile->name,
                        // "NAME" => $_name,
                        "LAST_NAME" => $data->result->client_profile->surname,
                        // "LAST_NAME" => $_surname,
                        "EMAIL" => strlen($data->result->client_profile->email) > 0 ? $data->result->client_profile->email : $newemail,
                        "LOGIN" => $newlogin,
                        "LID" => "ru",
                        "ACTIVE" => "Y",
                        "GROUP_ID" => $group,
                        "PASSWORD" => $newpassword,
                        "CONFIRM_PASSWORD" => $newpassword,
                        "UF_CLIENT_ID" => $data->result->client_profile->client_id,
                    ];

                    $this->writeLog('5.5 $arFields ', $arFields); 
                    $ID = $user->Add($arFields);

                    $this->writeLog('6 $ID ', $ID); 

                    global $USER;
                    $USER->Authorize($ID);

                    $this->writeLog('7 USER ', $USER); 

                }
                //if(intval($ID) > 0) echo 'New admin user is created';
            }

            $res = json_encode($check);
            $this->_dbCache->setValue('check' . $phone . $smsCode, $res, $this->cacheTime);

            $this->writeLog('8 ', $res); 
            //  $this->_dbCache->setValue('client_id' . $phone . $smsCode, $data->result->client_profile->client_id, $this->cacheTime);
        }

        return $check;
        $this->writeLog('9 check ', $check); 
    }

    public function login($phone, $smsCode)
    {
        $info = new TaxiAutorizationInfo();
        if ($this->checkSms($phone, $smsCode)) {
            $json = $this->_dbCache->getValue('client_id' . $phone . $smsCode);
            $josnClientId = json_decode($json);

            $info->isAuthorizedNow = true;
            $info->phone = $phone;
            if (!$info->browserKey || !$this->clientAuthorization->checkBrowserKey($info->browserKey)) {
                $info->browserKey = $this->clientAuthorization->createCookieBrowserUniqueKey();
            }
            $info->token = $this->clientAuthorization->createCookieToken($phone, $info->browserKey);
            $info->text = "Успешная авторизация через СМС код {$smsCode} на телефон {$phone}";
            $info->resultCode = null;
            $info->success = true;
            $info->clientId = $josnClientId[1];
            $this->_cache->setValue('user' . $phone, true, 366 * 7 * 24 * 3600);
        } else {
            $this->log->info("Неверная попытка авторизации");
            $info->isAuthorizedNow = false;
            $info->phone = $phone;
            $info->token = null;
            $info->browserKey = null;
            $info->resultCode = null;
            $info->success = false;
            $info->text = "Неверный код {$smsCode} для авторизации на телефон {$phone}";
        }

        return $info;
    }

    public function getCoords($address = null)
    {
        return false;
    }

    public function changeOrderStatus($orderId, $statusCode)
    {
        return false;
    }

    public function addReview($reviewData)
    {
        $this->setParams();
        $orderId = $this->_cache->getValue('order' . $reviewData->orderId);

        if ($json = $this->_dbCache->getValue('review' . $orderId)) {
            $review = json_decode($json);
        } else {
            $comment = $reviewData->comment ? urldecode($reviewData->comment) : '--';
            $rating = $reviewData->grade ? $reviewData->grade : '0';

            if ($this->_api->sendPostTo($data, 'send_response',
                ['order_id' => $orderId, 'grade' => $rating, 'text' => $comment, 'current_time' => time()])) {
                if ($data->result->respone_result == 1) {
                    $review = 1;
                } else {
                    $review = 0;
                }
            } else {
                $review = 0;
            }

            $res = json_encode($review);
            $this->_dbCache->setValue('review' . $orderId, $res, $this->cacheTime);
        }

        return $review;
    }

    public function getCity()
    {
        $cityes = [];
        if ($this->cityData) {
            foreach ($this->cityData as $city) {
                $cityes[] = $city;
            }
        }

        return $cityes;//*/
        /*$this->setParams();
        $cityes = array();
        if ($this->_api->sendGetTo($data, 'get_tenant_city_list', array(
            'current_time' => time(),
        ))) {
            foreach ($data->result->city_list as $city) {
                $town = array();
                $town['id'] = $city->city_id;
                $town['cityName'] = $city->city_name;
                $town['lat'] = $city->city_lat;
                $town['lon'] = $city->city_lon;
                $cityes[] = $town;
            }
        }
        return $cityes;//*/
    }

    private function writeLog($label, $message = '')
    {
        if (!$this->_log) {
            $this->_log = new TaxiLog($this);
        }
        $this->_log->info("{$label}: " . CVarDumper::dumpAsString($message));
    }
}
