<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/sbr.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/classes/sbr_meta.php';
require_once(__DIR__ . '/Exception/ReservesPayoutException.php');
require_once(__DIR__ . '/Exception/ReservesPayoutQueueException.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/classes/reserves/BaseModel.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/classes/reserves/ReservesModel.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/classes/reserves/ReservesModelFactory.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/classes/reserves/ReservesHelper.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/classes/reserves/ReservesPayoutPopup.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/classes/YandexMoney3/YandexMoney3.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/classes/log.php');


use YandexMoney3\Request\DepositionRequest;
//use YandexMoney3\Request\BalanceRequest;
use YandexMoney3\YandexMoney3;



class ReservesPayout extends BaseModel
{
    protected static $instance;

    private $is_test        = false;
    
    const MAX_SUM_TEST      = 14000;
    const MAX_SUM           = 14000;
    private $max_sum;
    
    const AGENT_ID_TEST     = 200385;
    const AGENT_ID          = 200385;
    private $agent_id;
    
    const CURRENCY_TEST     = 10643;
    const CURRENCY          = 643;
    private $currency;
    
    const STATUS_NEW        = -1;
    const STATUS_SUCCESS    = 0;
    const STATUS_INPROGRESS = 1;
    const STATUS_FAIL       = 3;
    
    
    
    private $TABLE              = 'reserves_payout';
    private $TABLE_REQV         = 'reserves_payout_reqv';
    static public $_TABLE_REQV  = 'reserves_payout_reqv';


    private $cert_files         = array();

    private $apiFacade          = null;

    private $log;
    

    private $TABLE_HISTORY      = 'reserves_payout_history';
    private $TABLE_REQV_HISTORY = 'reserves_payout_reqv_history';

    private $TABLE_ERROR_LOG    = 'reserves_payout_error_log';


    
    
    public function __construct() 
    {
        $this->is_test = !is_release();

        if (defined('YM_PAYOUT_ENCRYPT_CERT_FILE')) {
            $this->cert_files['encrypt_cert_path'] = YM_PAYOUT_ENCRYPT_CERT_FILE;
        }
        
        if (defined('YM_PAYOUT_DECRYPT_CERT_FILE')) {
            $this->cert_files['decrypt_cert_path'] = YM_PAYOUT_DECRYPT_CERT_FILE;
        }
        
        if (defined('YM_PAYOUT_PRIVATE_KEY_FILE')) {
            $this->cert_files['private_key_path'] = YM_PAYOUT_PRIVATE_KEY_FILE;
        }
        
        if (defined('YM_PAYOUT_PASSPHRASE')) {
            $this->cert_files['passphrase'] = YM_PAYOUT_PASSPHRASE;
        }

        $this->max_sum = ($this->is_test)?self::MAX_SUM_TEST:self::MAX_SUM;
        $this->agent_id = ($this->is_test)?self::AGENT_ID_TEST:self::AGENT_ID;
        $this->currency = ($this->is_test)?self::CURRENCY_TEST:self::CURRENCY;
    }
    
    
    
    /**
     * ��������� ��������� ������� ����� 
     * �� ������� � ������ ���������� � �������
     * ������������� ������ ����� �����-���� �������� ����������/����������.
     * 
     * @param type $reserve_id
     */
    public function saveToHistory($reserve_id, $only_reqv = false)
    {
        $reqv_history_id = $this->db()->val("
            INSERT INTO {$this->TABLE_REQV_HISTORY} (
                reserve_id, pay_type, fields, date, last)
            SELECT 
                reserve_id,
                pay_type,
                fields,
                date,
                last
            FROM {$this->TABLE_REQV} 
            WHERE reserve_id = ?i 
            RETURNING id;
        ", $reserve_id);
            
        if ($reqv_history_id && !$only_reqv) {
            $this->db()->query("
                INSERT INTO {$this->TABLE_HISTORY} (
                        payout_id, reserve_id, price, status, error,
                        date, last, cnt, techMessage, reqv_history_id)
                (
                    SELECT 
                        id AS payout_id,
                        reserve_id,
                        price,
                        status,
                        error,
                        date,
                        last,
                        cnt,
                        techMessage,
                        {$reqv_history_id} AS reqv_history_id
                    FROM {$this->TABLE} 
                    WHERE reserve_id = ?i
                )
            ", $reserve_id);
        }
    }
    
    
    /**
     * �������� ��� ��� ���������� �������
     * 
     * @param type $reserve_id
     * @return type
     */
    public function getErrorLog($reserve_id)
    {
        return $this->db()->rows("
            SELECT * FROM {$this->TABLE_ERROR_LOG} 
            WHERE reserve_id = ?i 
            ORDER BY id DESC
        ", $reserve_id);
    }
    



    /**
     * ����� � ��� ��������� ������
     * 
     * @param type $reserve_id
     * @param type $message
     */
    public function errorLog($reserve_id, $message)
    {
        $this->db()->insert($this->TABLE_ERROR_LOG, array(
            'reserve_id' => $reserve_id,
            'message' => $message
        ));
        
        if (!$this->log) {
            $this->log = new log('reserves_payout/' . SERVER . '-error-%d%m%Y.log', 'a', '[%d.%m.%Y %H:%M:%S] ');
        }

        $this->log->writeln(sprintf("Reserve ID = %s: %s", $reserve_id, iconv('cp1251', 'utf-8', $message)));
    }    
    
    
    
    /**
     * �������� ������ ��� �������������� � API ������ 
     * 
     * @return YandexMoney3
     */
    protected function getApiFacade()
    {
        if (!$this->apiFacade) {
            $this->apiFacade = YandexMoney3::getApiFacade();
            
            $options = array(
                'crypt' => $this->cert_files,
                'is_test' => $this->is_test
            );
            
            if ($this->is_test) {
                $options['test_url'] = YM_PAYOUT_TEST_URL;
            }
            
            $this->apiFacade->setOptions($options);
        }
        
        return $this->apiFacade;
    }

    

    /**
     * ������� ������ �� ������� ����� ������
     * 
     * @param type $reserve_id
     * @param type $status
     * @return type
     */
    public function getPayouts($reserve_id, $status = null)
    {
        $where_sql = ($status)?$this->db()->parse(' AND status = ?i',$status):'';
        
        return $this->db()->rows("
            SELECT *
            FROM {$this->TABLE}
            WHERE reserve_id = ?i 
            {$where_sql}
        ",$reserve_id);
    }

    

    /**
     * ������� ������ ����������
     * 
     * @param type $reserve_id
     * @return type
     */
    public function getPayoutReqv($reserve_id)
    {
        return $this->db()->row("
            SELECT *
            FROM {$this->TABLE_REQV}
            WHERE reserve_id = ?i
            LIMIT 1
        ",$reserve_id);
    }

    
    
    /**
     * ������� ����� ������?
     * ����� �� �������
     * 
     * @param type $reserve_id
     * @return type
     */
    public function isPayoutByService($reserve_id)
    {
        return $this->db()->val("
            SELECT 1 
            FROM {$this->TABLE_REQV}
            WHERE reserve_id = ?i AND pay_type <> ?
            LIMIT 1
        ",$reserve_id, ReservesPayoutPopup::PAYMENT_TYPE_BANK);
    }
    



    /**
     * ���� �� ��� ������� ������� ������ ����������?
     * 
     * @param type $reserve_id
     * @return type
     */
    public function isExistPayoutReqv($reserve_id)
    {
        return $this->db()->val("
            SELECT reserve_id
            FROM {$this->TABLE_REQV}
            WHERE reserve_id = ?i
            LIMIT 1
        ",$reserve_id);
    }


    
    /**
     * ��������� ����������� ������� ������� ����� 
     * � ������� ��� ���������
     * 
     * @param type $uid
     * @param type $type
     * @param type $price
     * @return boolean
     */
    public function getUserReqvs($uid, $type, $price)
    {
        $reqvs = ReservesHelper::getInstance()->getUserReqvs($uid);
        if (!$reqvs) return false;

        $form_type = $reqvs['form_type'];
        $rez_type = $reqvs['rez_type'];
        $reqv = $reqvs[$form_type];
        $reqv['rez_type'] = $rez_type;
        $reqv['form_type'] = $form_type;
        $reqv['moderator_uid'] = $reqvs['moderator_uid'];
        $reqv['moderator_login'] = $reqvs['moderator_login'];
        
        $payments = ReservesHelper::getInstance()->getAllowedPayoutTypes(
                $form_type,
                $rez_type,
                $price);

        if (isset($payments[$type])) {
            
            $info = explode(',', @$reqv['address']);
            
            //������� �������� ��� ISO
            if ((!isset($reqv['country_iso']) || 
                  empty($reqv['country_iso'])) && 
                  count($info) > 3) {

                require_once($_SERVER['DOCUMENT_ROOT'] . '/classes/country.php' );
                $countryObject = new country();
                
                $country_name = trim($info[1]);
                $country_iso = $countryObject->getCountryISO($country_name);
                
                if ($country_iso) {
                   $reqv['country_iso'] = $country_iso;
                }
            } 
            
            //���� ���� mob_phone �� ������� �������� �� phone
            if(!isset($reqv['mob_phone']) || empty($reqv['mob_phone'])) {
                $reqv['mob_phone'] = @$reqv['phone'];
            }
            
            return $reqv;
        }
        
        return false;
    }


    
    /**
     * ��������� �������
     * 
     * @param type $reserveInstance
     * @param type $type
     * @return type
     */
    public function requestPayout($reserveInstance, $type)
    {
        switch($type)
        {
            //������� �� �������, ��������� ������
            case ReservesPayoutPopup::PAYMENT_TYPE_BANK: 
                $substatus = $this->payoutReestr($reserveInstance, $type);
                break;

            //������� ����� ������, ������ ������ � ������� �� ���������
            default:
                $substatus = $this->requestPayoutByService($reserveInstance, $type);
        }
        
        return $substatus;
    }


    
    
    /**
     * ������ ������� �� ������� 
     * � ����������� � ������� �� ���������
     * 
     * @param type $reserveInstance
     * @param type $type
     */
    public function requestPayoutByService($reserveInstance, $type)
    {
        $uid = $reserveInstance->getFrlId();
        $reserve_id = $reserveInstance->getID();
        $sum = $reserveInstance->getPayoutSum();        
        
        //���������� ������/���������� ��� ����������� ������ � API
        try {
            
            if ($sum <= 0) {
                throw new ReservesPayoutException(
                        ReservesPayoutException::WRONG_SUM);
            }
            
            $reqv = array();
            $request_list = $this->getPayouts($reserve_id);
            $is_allow_repeated = $this->isAllowRepeatedRequest($request_list);
            
            if ($is_allow_repeated) {
                //��� ��������� ������ �� ������� 
                //������ ������ ���������!
                
                //���� ������ ��������� ��������� �� ������ �� ������
                $data_reqv = $this->getPayoutReqv($reserve_id);
                if ($data_reqv) {
                    $reqv = mb_unserialize($data_reqv['fields']);
                    if ($reqv) {
                        $type = $data_reqv['pay_type'];
                    }
                }
                
                if (!$reqv) {
                    throw new ReservesPayoutException(
                            ReservesPayoutException::REQV_INVALID);
                }  
                
                $is_update = false;
                
                //���� ��� ���������� � ������������ ���������� 
                //�� ������� �������� �� �������� ��������
                if (!isset($reqv['mob_phone']) || empty($reqv['mob_phone'])) {
                    
                    $is_update = true;
                    
                    $reqv['mob_phone'] = @$reqv['phone'];
                    if (empty($reqv['mob_phone'])) {
                        throw new ReservesPayoutException(
                                ReservesPayoutException::PHONE_FAIL);
                    }
                }
                

                //���� ������� �� ����� �� �������� � ��������� �� �������
                if ($type == ReservesPayoutPopup::PAYMENT_TYPE_CARD && 
                    !isset($reqv['skr_destinationCardSynonim'])) {
                    
                    $is_update = true;
                    
                    $reqv['skr_destinationCardSynonim'] = $this->getDestinationCardSynonim($reqv['el_ccard'], $sum);
                    
                    if (!$reqv['skr_destinationCardSynonim']) {
                        throw new ReservesPayoutException(
                                ReservesPayoutException::CARD_SYNONIM_FAIL);
                    }
                }
                
                
                //��������� ���������
                if ($is_update) {
                    $this->updateReqv($reserve_id, $reqv);
                    //��������� �������� � �������
                    $this->saveToHistory($reserve_id);                    
                }
                
            } else {
                //����� ������� ����� ������ �� �������
                
                $reqv = $this->getUserReqvs($uid, $type, $sum);
                
                if (!$reqv) {
                    throw new ReservesPayoutException(
                            ReservesPayoutException::REQV_FAIL, 
                            $type, $uid);
                }
                
                
                //���� ������� �� ����� �� �������� � ��������� �� �������
                if ($type == ReservesPayoutPopup::PAYMENT_TYPE_CARD) {
                    
                    $reqv['skr_destinationCardSynonim'] = $this->getDestinationCardSynonim($reqv['el_ccard'], $sum);
                    
                    if (!$reqv['skr_destinationCardSynonim']) {
                        throw new ReservesPayoutException(
                                ReservesPayoutException::CARD_SYNONIM_FAIL);
                    }
                }
                
                //������� ������� �� ������� ���� ����� ������ �����
                $request_list = $this->calcRequestList($reserve_id, $sum);
                
                $this->db()->start();
                
                $ok_1 = $this->insertReqv($reserve_id, $type, $reqv);
                
                if ($ok_1) {
                    foreach ($request_list as $key => $payout) {
                        $ok_2 = $this->db()->insert($this->TABLE, $payout, 'id');
                        if(!$ok_2) break;
                        $request_list[$key]['id'] = $ok_2;
                    }
                }  
                
                //���� ��� ���������� ������ ����� ��� ���������� �������� ������ 
                //�� ���������� ����� ���������� � ������� � �������
                if (!$ok_1 || !$ok_2) {
                    $this->db()->rollback();
                    $request_list = array();
                    throw new ReservesPayoutException(ReservesPayoutException::INS_FAIL);
                }
            
                if (!$this->db()->commit()) {
                    $request_list = array();
                    throw new ReservesPayoutException(ReservesPayoutException::INS_FAIL);
                }
                
                //���� ��� �� - ��������� � �������
                $this->saveToHistory($reserve_id);
            }
            
            //�� ������ �������� ��������� ������� ������ ����� �� �������
            if (empty($request_list)) {
                throw new ReservesPayoutException(ReservesPayoutException::RQST_EMPTY);
            }
            
        } catch (Exception $e) {
            $this->errorLog($reserve_id, $e->getMessage());
            return ReservesModel::SUBSTATUS_ERR;
        }        
        
        //���� ��� ��������� ������ �� ������ �������� �������
        //��! ��� ���� �� �������� ������ � ����� �� reserves_payout
        if (!is_release()) {
            return ReservesModel::SUBSTATUS_PAYED;
        }
        
        //����� ������ ������ � ������� �� �������
        $this->db()->query("SELECT pgq.insert_event('reserves', 'payout', ?)", 
                http_build_query(array('reserve_id' => $reserve_id)));       
        
        //���������� ������ � ��������
        return ReservesModel::SUBSTATUS_INPROGRESS;
    }
    





    /**
     * ������� ����� ������
     * 
     * @param type $reserveInstance
     * @param type $type
     * @return type
     */
    public function payoutReestr($reserveInstance, $type)
    {
        $uid = $reserveInstance->getFrlId();
        $reserve_id = $reserveInstance->getID();
        
        try {
            
            $reqv = $this->getUserReqvs($uid, $type, 
                $reserveInstance->getPayoutSum());
                    
            if (!$reqv) {
                    throw new ReservesPayoutException(
                            ReservesPayoutException::REQV_FAIL, 
                            $type, $uid);
            }
            
            
            $done = $this->insertReqv($reserve_id, $type, $reqv);
            
            if (!$done) {
                throw new ReservesPayoutException(
                        ReservesPayoutException::RQST_ACTIVE);
            }

        } catch (Exception $e) {
            $this->errorLog($reserve_id, $e->getMessage());
            return ReservesModel::SUBSTATUS_ERR;
        }
        
        $this->saveToHistory($reserve_id, true);
        
        return ReservesModel::SUBSTATUS_INPROGRESS;
    }

    

    
    
    /**
     * ������ ������� � �������
     * 
     * @param type $reserve_id
     */
    public function doPayout($reserve_id)
    {
         //���� �� ���������� ������ �������
        $reserveInstance = ReservesModelFactory::getInstanceById($reserve_id);
        if (!$reserveInstance) {
            throw new ReservesPayoutQueueException(
                    ReservesPayoutQueueException::RESERVE_NOTFOUND);       
        }
        
        //���� ������ �� ����������
        if (!$reserveInstance->isAllowPayoutForQueue()) {
            throw new ReservesPayoutQueueException(
                    ReservesPayoutQueueException::RESERVE_STATUS_FAIL);            
        }

        //������ �� �������
        $request_list = $this->getPayouts($reserve_id);
        
        if (!$request_list) {
            throw new ReservesPayoutQueueException(
                    ReservesPayoutQueueException::NOTFOUND);            
        }
        
        $substatus = ReservesModel::SUBSTATUS_PAYED;
        $is_done = true;
        $_work_request = array();
        
        foreach ($request_list as $request) {
            if (in_array($request['status'], array(
                self::STATUS_NEW, 
                self::STATUS_INPROGRESS,
                self::STATUS_FAIL))) {

                $_work_request = $request;
                $is_done = false;
                break;
            }
        }

        
        //����������� ��� ��� ���������
        if ($is_done) {
            //���������� ������� ������
            $reserveInstance->changePayStatus($substatus);
            throw new ReservesPayoutQueueException(
                    ReservesPayoutQueueException::PAYED);             
        }
        
        
        $type = null;
        $reqv = array();
        
        //�������� ��������� �� ������
        $data_reqv = $this->getPayoutReqv($reserve_id);
        if ($data_reqv) {
            $reqv = mb_unserialize($data_reqv['fields']);
            if ($reqv) {
                $type = $data_reqv['pay_type'];
            }
        }

        //���� �� ��������� ��� �������
        if (empty($reqv)) {
            throw new ReservesPayoutException(
                    ReservesPayoutException::REQV_INVALID);
        }          
        
        
        //��������� ���� ���
        $last = (empty($_work_request['last']))?$_work_request['date']:$_work_request['last'];
        $is_timeout = $this->isTimeout($_work_request['cnt'], $last);
        
        if (!$is_timeout) {
            //������� ��� �� ����� ����� ��������� � �������
            return false;
        } elseif ($is_timeout === -1) {
            //�������� ����� ��������� ���� ��� ����� �������
            throw new ReservesPayoutQueueException(
                    ReservesPayoutQueueException::REQUEST_LIMIT);
        }
        

        //������������ ������� � API ������� � ��������� ������
        try {
        
            $depositionRequest = new DepositionRequest();
            $depositionRequest->setAgentId($this->agent_id);
            $depositionRequest->setCurrency($this->currency);
            $depositionRequest->setPofOfferAccepted(1);
            $depositionRequest->setSmsPhoneNumber(trim(str_replace('+', '', $reqv['mob_phone'])));
            
            foreach ($request_list as $key => $request) {
                
                if ($request['status'] == static::STATUS_SUCCESS) {
                    continue;
                }
                
                $depositionRequest->setAmount(number_format($request['price'], 2, '.', ''));
                $depositionRequest->setClientOrderId($request['id']);
            
                
                //��������� ����� ��������� ��� ��������
                //�������� ��������� �����
                switch($type) {
                    
                   case ReservesPayoutPopup::PAYMENT_TYPE_RS:
                       
                        //��������� �����
                        $fio = explode(' ', $reqv['fio']);
                        $depositionRequest->setTmpFirstName(@$fio[1]);
                        $depositionRequest->setTmpMiddleName(@$fio[2]);
                        $depositionRequest->setTmpLastName(@$fio[0]);
                        
                        
                   case ReservesPayoutPopup::PAYMENT_TYPE_CARD:   
                        
                        //��������� �����
                        $fio = explode(' ', $reqv['fio']);
                        $depositionRequest->setPdrFirstName(@$fio[1]);
                        $depositionRequest->setPdrMiddleName(@$fio[2]);
                        $depositionRequest->setPdrLastName(@$fio[0]);
                        
                        if (isset($reqv['rez_type']) && @$reqv['rez_type'] == sbr::RT_UABYKZ) {
                            $depositionRequest->setPdrDocType(10);
                        } else {
                            $depositionRequest->setPdrDocType(21);
                        }
                        
                        $depositionRequest->setPdrDocNumber(@$reqv['idcard_ser'] . @$reqv['idcard']);
                        
                        $date = explode('.', @$reqv['idcard_from']);
                        $depositionRequest->setPdrDocIssueYear(@$date[2]);
                        $depositionRequest->setPdrDocIssueMonth(@$date[1]);
                        $depositionRequest->setPdrDocIssueDay(@$date[0]);
                        
                        $depositionRequest->setPdrDocIssuedBy(@$reqv['idcard_by']);
                        
                        $country_iso = @$reqv['country_iso'];
                        //@todo: ���� �� ������� ������� ��� �� ������ ������� :D !
                        $depositionRequest->setPdrCountry($country_iso?$country_iso:643);
                        
                        
                        //������ ����� �� �������: 
                        //127287, ������, �. ������, ��. 2-� ��������� � 38� ���.9
                        $info = explode(',', @$reqv['address']);
                        
                        if (isset($reqv['index']) && !empty($reqv['index'])) {
                            $depositionRequest->setPdrPostcode($reqv['index']);
                        } else {
                            $depositionRequest->setPdrPostcode(trim(@$info[0]));
                        }
                        
                        if (isset($reqv['city']) && !empty($reqv['city'])) {
                            $depositionRequest->setPdrCity($reqv['city']);
                            $depositionRequest->setPdrBirthPlace($reqv['city']);
                        } else {
                            $depositionRequest->setPdrCity(trim(@$info[2]));
                            $depositionRequest->setPdrBirthPlace(trim(@$info[2]));
                        }
                        
                        if(count($info) > 4) {
                            unset($info[0],$info[1],$info[2]);
                            $depositionRequest->setPdrAddress(trim(implode(',', $info)));
                        } else {
                            $depositionRequest->setPdrAddress(trim(@$info[3]));
                        }

                        $depositionRequest->setPdrBirthDate(@$reqv['birthday']);

                        break;
                }
                
                
                //��������� ��� ������� ������� � �����������
                switch($type) {

                    case ReservesPayoutPopup::PAYMENT_TYPE_YA:
                        $depositionRequest->setDstAccount($reqv['el_yd']);
                        break;

                    case ReservesPayoutPopup::PAYMENT_TYPE_CARD:
                        $depositionRequest->setDstAccount(25700130535186);
                        $depositionRequest->setSkrDestinationCardSynonim($reqv['skr_destinationCardSynonim']);
                        break;

                    case ReservesPayoutPopup::PAYMENT_TYPE_RS:
                        $depositionRequest->setDstAccount(2570066962077);
                        
                        $bank = explode(',', $reqv['bank_name']);
                        $depositionRequest->setBankName(trim(@$bank[0]));
                        
                        if (isset($reqv['bank_city']) && !empty($reqv['bank_city'])) {
                            $depositionRequest->setBankCity(trim(@$reqv['bank_city']));
                        } else {
                            $depositionRequest->setBankCity(trim(@$bank[1]));
                        }
                        
                        $depositionRequest->setBankBIK(trim(@$reqv['bank_bik']));
                        $depositionRequest->setBankCorAccount(trim(@$reqv['bank_ks']));
                        $depositionRequest->setBankKPP(trim(@$reqv['bank_kpp']));
                        $depositionRequest->setBankINN(trim(@$reqv['bank_inn']));
                        
                        /*@todo: �� ������ ������ �� ����, ��� ����������
                        if (isset($reqv['bank_assignment']) && 
                           !empty($reqv['bank_assignment'])) {
                            $depositionRequest->setDepositAccount(@$reqv['bank_rs']);
                            $depositionRequest->setFaceAccount(@$reqv['bank_assignment']);
                        } else {
                            $depositionRequest->setRubAccount(@$reqv['bank_rs']);
                        }
                        */
                        
                        $bank_rs = trim(@$reqv['bank_rs']);
                        $depositionRequest->setDepositAccount($bank_rs);
                        $depositionRequest->setFaceAccount($bank_rs);
                        $depositionRequest->setCustAccount($bank_rs);
                        
                        break;
                        
                    default:
                        throw new ReservesPayoutException(
                                ReservesPayoutException::TYPE_INVALID, $type);
                }
                
                
                $current_substatus = ReservesModel::SUBSTATUS_PAYED;
                //������ � API
                $result = $this->getApiFacade()->testDeposition($depositionRequest);
                if (!$result->isSuccess() && $result->getError() != 26) {
                    $current_substatus = ReservesModel::SUBSTATUS_ERR;
                } else {
                    $result = $this->getApiFacade()->makeDeposition($depositionRequest);
                    if ($result->getStatus() == static::STATUS_INPROGRESS) { 
                        $current_substatus = ReservesModel::SUBSTATUS_INPROGRESS;
                    } elseif($result->getStatus() == static::STATUS_FAIL) {
                        $current_substatus = ReservesModel::SUBSTATUS_ERR;
                    }
                }
                
                $_request = array(
                    'status' => $result->getStatus(),
                    'error' => (!$result->isSuccess())?$result->getError():0,
                    'last' => 'NOW()',
                    'cnt' => $request['cnt'] + 1,
                    'techmessage' => $result->getTechMessage()
                );
                
                $is_done = $this->db()->update(
                        $this->TABLE, 
                        $_request, 
                        'id = ?i', 
                        $result->getClientOrderId());
                
                //�� ������� �������� ������ ������
                if (!$is_done) {
                    $current_substatus = ReservesModel::SUBSTATUS_ERR;
                }
                
                if ($current_substatus == ReservesModel::SUBSTATUS_ERR) {
                    
                    if ($substatus != ReservesModel::SUBSTATUS_INPROGRESS) {
                        $substatus = $current_substatus;
                    }
                    
                    $techmessage = $result->getTechMessage();
                    if ($techmessage) $techmessage = " ({$techmessage})";
                    
                    $this->errorLog($reserve_id, sprintf(
                            ReservesPayoutException::LAST_PAYED_FAIL, 
                            $result->getClientOrderId(), 
                            $result->getError(),
                            $techmessage));
                    
                } elseif($current_substatus == ReservesModel::SUBSTATUS_INPROGRESS) {
                    $substatus = $current_substatus;
                }
            }
            
            //��������� � ������� ������ � ���������� ������
            $this->saveToHistory($reserve_id);
            
        } catch (Exception $e) {
            $this->errorLog($reserve_id, $e->getMessage());
            $substatus = ReservesModel::SUBSTATUS_ERR;            
        }        
        
        //��� ������ ������ ������ ��� ��� ������� ��� � ����� �� �������
        //�������� ������ ������� ��������        
        if ($reserveInstance->getStatusPay() != $substatus) {
            //�� ������� ������� ������
            if (!$reserveInstance->changePayStatus($substatus)) {
                throw new ReservesPayoutQueueException(
                        ReservesPayoutQueueException::CANT_CHANGE_SUBSTATUS, true); 
            }
        }
        
        //������ ��� ������� ������� � ������� ��� ������
        if ($reserveInstance->isStatusPayError()) {
            throw new ReservesPayoutQueueException(
                    ReservesPayoutQueueException::API_CRITICAL_FAIL, $result->getError()); 
        }
        
        
        return $reserveInstance->isStatusPayPayed();
    }




    /**
     * ������������� ��������� ����� �������: ������ ������ ����� 1 ������, 
     * ��������� ��� � ����������� � 5 �����, ����� �� ���� ��� ��� � 30 �����.
     */
    private function isTimeout($cnt, $timeString)
    {
        if($cnt <= 0) return true;
        if($cnt >= 999) return -1;
        
        $timeout = 1800;
        if($cnt == 1) $timeout = 60;
        elseif($cnt >= 2 && $cnt <= 4) $timeout = 300;
        
        $time = strtotime($timeString) + $timeout;
        return (time() < $time)?false:true;
    }
    


    /**
     * @deprecated ���������� �������, ������ ������������ �������
     * 
     * ������� ����� ������
     * 
     * @param type $reserveInstance
     * @param type $type
     * @return substatus
     * @throws Exception
     */
    public function payout($reserveInstance, $type)
    {
        $uid = $reserveInstance->getFrlId();
        $reserve_id = $reserveInstance->getID();
        $sum = $reserveInstance->getPayoutSum();
        
        
        //���������� ������/���������� ��� ����������� ������ � API
        try {
            
            if ($sum <= 0) {
                throw new ReservesPayoutException(ReservesPayoutException::WRONG_SUM);
            }
            
            $reqv = array();
            $request_list = $this->getPayouts($reserve_id);
            $is_allow_repeated = $this->isAllowRepeatedRequest($request_list);
            
            if ($is_allow_repeated) {
                //��� ��������� ������ �� ������� 
                //������ ������ ���������!
                
                //���� ������ ��������� ��������� �� ������ �� ������
                $data_reqv = $this->getPayoutReqv($reserve_id);
                if ($data_reqv) {
                    $reqv = mb_unserialize($data_reqv['fields']);
                    if ($reqv) {
                        $type = $data_reqv['pay_type'];
                    }
                }
                
                if (!$reqv) {
                    throw new ReservesPayoutException(
                            ReservesPayoutException::REQV_INVALID);
                }  
                
                $is_update = false;
                
                //���� ��� ���������� � ������������ ���������� 
                //�� ������� �������� �� �������� ��������
                if (!isset($reqv['mob_phone']) || empty($reqv['mob_phone'])) {
                    
                    $is_update = true;
                    
                    $reqv['mob_phone'] = @$reqv['phone'];
                    if (empty($reqv['mob_phone'])) {
                        throw new ReservesPayoutException(
                                ReservesPayoutException::PHONE_FAIL);
                    }
                }
                

                //���� ������� �� ����� �� �������� � ��������� �� �������
                if ($type == ReservesPayoutPopup::PAYMENT_TYPE_CARD && 
                    !isset($reqv['skr_destinationCardSynonim'])) {
                    
                    $is_update = true;
                    
                    $reqv['skr_destinationCardSynonim'] = $this->getDestinationCardSynonim($reqv['el_ccard'], $sum);
                    
                    if (!$reqv['skr_destinationCardSynonim']) {
                        throw new ReservesPayoutException(
                                ReservesPayoutException::CARD_SYNONIM_FAIL);
                    }
                }
                
                
                //��������� ���������
                if ($is_update) {
                    $this->updateReqv($reserve_id, $reqv);
                    //��������� �������� � �������
                    $this->saveToHistory($reserve_id);                    
                }
                
            } else {
                //����� ������� ����� ������ �� �������
                
                $reqv = $this->getUserReqvs($uid, $type, $sum);
                
                if (!$reqv) {
                    throw new ReservesPayoutException(
                            ReservesPayoutException::REQV_FAIL, 
                            $type, $uid);
                }
                
                
                //���� ������� �� ����� �� �������� � ��������� �� �������
                if ($type == ReservesPayoutPopup::PAYMENT_TYPE_CARD) {
                    
                    $reqv['skr_destinationCardSynonim'] = $this->getDestinationCardSynonim($reqv['el_ccard'], $sum);
                    
                    if (!$reqv['skr_destinationCardSynonim']) {
                        throw new ReservesPayoutException(
                                ReservesPayoutException::CARD_SYNONIM_FAIL);
                    }
                }
                
                //������� ������� �� ������� ���� ����� ������ �����
                $request_list = $this->calcRequestList($reserve_id, $sum);
                
                $this->db()->start();
                
                $ok_1 = $this->insertReqv($reserve_id, $type, $reqv);
                
                if ($ok_1) {
                    foreach ($request_list as $key => $payout) {
                        $ok_2 = $this->db()->insert($this->TABLE, $payout, 'id');
                        if(!$ok_2) break;
                        $request_list[$key]['id'] = $ok_2;
                    }
                }  
                
                //���� ��� ���������� ������ ����� ��� ���������� �������� ������ 
                //�� ���������� ����� ���������� � ������� � �������
                if (!$ok_1 || !$ok_2) {
                    $this->db()->rollback();
                    $request_list = array();
                    throw new ReservesPayoutException(ReservesPayoutException::INS_FAIL);
                }
            
                if (!$this->db()->commit()) {
                    $request_list = array();
                    throw new ReservesPayoutException(ReservesPayoutException::INS_FAIL);
                }
                
                //���� ��� �� - ��������� � �������
                $this->saveToHistory($reserve_id);
            }
            
            //�� ������ �������� ��������� ������� ������ ����� �� �������
            if (empty($request_list)) {
                throw new ReservesPayoutException(ReservesPayoutException::RQST_EMPTY);
            }
            
        } catch (Exception $e) {
            $this->errorLog($reserve_id, $e->getMessage());
            return ReservesModel::SUBSTATUS_ERR;
        }
        
        
        
        $substatus = ReservesModel::SUBSTATUS_PAYED;
        
        //���� ��� �� ������ ������ �� ������ �������� �������
        //��! ��� ���� �� �������� ������ � ����� �� reserves_payout
        if (!is_release()) {
            return $substatus;
        }
        
        
        //������������ ������� � API ������� � ��������� ������
        try {
        
            $depositionRequest = new DepositionRequest();
            $depositionRequest->setAgentId($this->agent_id);
            $depositionRequest->setCurrency($this->currency);
            $depositionRequest->setPofOfferAccepted(1);
            $depositionRequest->setSmsPhoneNumber(trim(str_replace('+', '', $reqv['mob_phone'])));
            
            foreach ($request_list as $key => $request) {
                
                if ($request['status'] == static::STATUS_SUCCESS) {
                    continue;
                }
                
                $depositionRequest->setAmount(number_format($request['price'], 2, '.', ''));
                $depositionRequest->setClientOrderId($request['id']);
            
                
                //��������� ����� ��������� ��� ��������
                //�������� ��������� �����
                switch($type) {
                    
                   case ReservesPayoutPopup::PAYMENT_TYPE_RS:
                       
                        //��������� �����
                        $fio = explode(' ', $reqv['fio']);
                        $depositionRequest->setTmpFirstName(@$fio[1]);
                        $depositionRequest->setTmpMiddleName(@$fio[2]);
                        $depositionRequest->setTmpLastName(@$fio[0]);
                        
                        
                   case ReservesPayoutPopup::PAYMENT_TYPE_CARD:   
                        
                        //��������� �����
                        $fio = explode(' ', $reqv['fio']);
                        $depositionRequest->setPdrFirstName(@$fio[1]);
                        $depositionRequest->setPdrMiddleName(@$fio[2]);
                        $depositionRequest->setPdrLastName(@$fio[0]);
                        
                        if (isset($reqv['rez_type']) && @$reqv['rez_type'] == sbr::RT_UABYKZ) {
                            $depositionRequest->setPdrDocType(10);
                        } else {
                            $depositionRequest->setPdrDocType(21);
                        }
                        
                        $depositionRequest->setPdrDocNumber(@$reqv['idcard_ser'] . @$reqv['idcard']);
                        
                        $date = explode('.', @$reqv['idcard_from']);
                        $depositionRequest->setPdrDocIssueYear(@$date[2]);
                        $depositionRequest->setPdrDocIssueMonth(@$date[1]);
                        $depositionRequest->setPdrDocIssueDay(@$date[0]);
                        
                        $depositionRequest->setPdrDocIssuedBy(@$reqv['idcard_by']);
                        
                        $country_iso = @$reqv['country_iso'];
                        //@todo: ���� �� ������� ������� ��� �� ������ ������� :D !
                        $depositionRequest->setPdrCountry($country_iso?$country_iso:643);
                        
                        
                        //������ ����� �� �������: 
                        //127287, ������, �. ������, ��. 2-� ��������� � 38� ���.9
                        $info = explode(',', @$reqv['address']);
                        
                        if (isset($reqv['index']) && !empty($reqv['index'])) {
                            $depositionRequest->setPdrPostcode($reqv['index']);
                        } else {
                            $depositionRequest->setPdrPostcode(trim(@$info[0]));
                        }
                        
                        if (isset($reqv['city']) && !empty($reqv['city'])) {
                            $depositionRequest->setPdrCity($reqv['city']);
                            $depositionRequest->setPdrBirthPlace($reqv['city']);
                        } else {
                            $depositionRequest->setPdrCity(trim(@$info[2]));
                            $depositionRequest->setPdrBirthPlace(trim(@$info[2]));
                        }
                        
                        if(count($info) > 4) {
                            unset($info[0],$info[1],$info[2]);
                            $depositionRequest->setPdrAddress(trim(implode(',', $info)));
                        } else {
                            $depositionRequest->setPdrAddress(trim(@$info[3]));
                        }

                        $depositionRequest->setPdrBirthDate(@$reqv['birthday']);

                        break;
                }
                
                
                //��������� ��� ������� ������� � �����������
                switch($type) {

                    case ReservesPayoutPopup::PAYMENT_TYPE_YA:
                        $depositionRequest->setDstAccount($reqv['el_yd']);
                        break;

                    case ReservesPayoutPopup::PAYMENT_TYPE_CARD:
                        $depositionRequest->setDstAccount(25700130535186);
                        $depositionRequest->setSkrDestinationCardSynonim($reqv['skr_destinationCardSynonim']);
                        break;

                    case ReservesPayoutPopup::PAYMENT_TYPE_RS:
                        $depositionRequest->setDstAccount(2570066962077);
                        
                        $bank = explode(',', $reqv['bank_name']);
                        $depositionRequest->setBankName(trim(@$bank[0]));
                        
                        if (isset($reqv['bank_city']) && !empty($reqv['bank_city'])) {
                            $depositionRequest->setBankCity(trim(@$reqv['bank_city']));
                        } else {
                            $depositionRequest->setBankCity(trim(@$bank[1]));
                        }
                        
                        $depositionRequest->setBankBIK(trim(@$reqv['bank_bik']));
                        $depositionRequest->setBankCorAccount(trim(@$reqv['bank_ks']));
                        $depositionRequest->setBankKPP(trim(@$reqv['bank_kpp']));
                        $depositionRequest->setBankINN(trim(@$reqv['bank_inn']));
                        
                        /*@todo: �� ������ ������ �� ����, ��� ����������
                        if (isset($reqv['bank_assignment']) && 
                           !empty($reqv['bank_assignment'])) {
                            $depositionRequest->setDepositAccount(@$reqv['bank_rs']);
                            $depositionRequest->setFaceAccount(@$reqv['bank_assignment']);
                        } else {
                            $depositionRequest->setRubAccount(@$reqv['bank_rs']);
                        }
                        */
                        
                        $bank_rs = trim(@$reqv['bank_rs']);
                        $depositionRequest->setDepositAccount($bank_rs);
                        $depositionRequest->setFaceAccount($bank_rs);
                        $depositionRequest->setCustAccount($bank_rs);
                        
                        break;
                        
                    default:
                        throw new ReservesPayoutException(
                                ReservesPayoutException::TYPE_INVALID, $type);
                }
                
                
                $current_substatus = ReservesModel::SUBSTATUS_PAYED;
                //������ � API
                $result = $this->getApiFacade()->testDeposition($depositionRequest);
                if (!$result->isSuccess() && $result->getError() != 26) {
                    $current_substatus = ReservesModel::SUBSTATUS_ERR;
                } else {
                    $result = $this->getApiFacade()->makeDeposition($depositionRequest);
                    if ($result->getStatus() == static::STATUS_INPROGRESS) { 
                        $current_substatus = ReservesModel::SUBSTATUS_INPROGRESS;
                    } elseif($result->getStatus() == static::STATUS_FAIL) {
                        $current_substatus = ReservesModel::SUBSTATUS_ERR;
                    }
                }
                
                
                $_request = array(
                    'status' => $result->getStatus(),
                    'error' => (!$result->isSuccess())?$result->getError():0,
                    'last' => 'NOW()',
                    'cnt' => $request['cnt'] + 1,
                    'techmessage' => $result->getTechMessage()
                );
                
                $this->db()->update($this->TABLE, $_request, 'id = ?i', $result->getClientOrderId());
                
                
                
                if ($current_substatus == ReservesModel::SUBSTATUS_ERR) {
                    
                    if ($substatus != ReservesModel::SUBSTATUS_INPROGRESS) {
                        $substatus = $current_substatus;
                    }
                    
                    $techmessage = $result->getTechMessage();
                    if ($techmessage) $techmessage = " ({$techmessage})";
                    
                    $this->errorLog($reserve_id, sprintf(
                            ReservesPayoutException::LAST_PAYED_FAIL, 
                            $result->getClientOrderId(), 
                            $result->getError(),
                            $techmessage));
                    
                } elseif($current_substatus == ReservesModel::SUBSTATUS_INPROGRESS) {
                    $substatus = $current_substatus;
                }
            }
            
            //��������� � ������� ������ � ���������� ������
            $this->saveToHistory($reserve_id);
            
        } catch (Exception $e) {
            $this->errorLog($reserve_id, $e->getMessage());
            return ReservesModel::SUBSTATUS_ERR;            
        }
        

        return $substatus;
    }
    
    
    
    
    /**
     * ������� ��������� � �������������� ������� �� ������� �� �������
     * 
     * @param type $reserve_id
     * @return boolean
     */
    public function clearInvalidRequest($reserve_id)
    {
        //�������� ��� �� �������� �������� �� ����� �������
        $is_exist = $this->db()->val("
            SELECT 1 
            FROM {$this->TABLE} 
            WHERE status IN(?l) AND reserve_id = ?i", 
            array(static::STATUS_SUCCESS, static::STATUS_INPROGRESS),
            $reserve_id);
        
        if($is_exist) {
            return false;
        }            

        //������� �������������� � ��������� �������
        $this->db()->query("
            DELETE FROM {$this->TABLE} 
            WHERE reserve_id = ?i", $reserve_id);
        
        //������� ���������
        $this->db()->query("
            DELETE FROM {$this->TABLE_REQV} 
            WHERE reserve_id = ?i", 
        $reserve_id);
            
        return true;
    }

    

    /**
     * �������� ������ ����������
     * 
     * @param type $reserve_id
     * @param type $type
     * @param type $reqv
     * @return type
     */
    public function insertReqv($reserve_id, $type, $reqv)
    { 
        $is_done = $this->clearInvalidRequest($reserve_id);
        
        if (!$is_done) {
            return false;
        }
        
        return $this->db()->insert($this->TABLE_REQV, array(
            'reserve_id' => $reserve_id,
            'pay_type' => $type,
            'fields' => serialize($reqv)
        ),'reserve_id');
    }    
    
    
     /**
     * �������� ������ ����������
     * 
     * @param type $reserve_id
     * @param type $type
     * @param type $reqv
     * @return type
     */
    public function updateReqv($reserve_id, $reqv)
    {
        return $this->db()->update($this->TABLE_REQV, array(
            'fields' => serialize($reqv),
            'last' => 'NOW()'
        ), 'reserve_id = ?i', $reserve_id);
    }
    
    
    
    /**
     * �������� ������� ��������
     * 
     * @param type $card
     * @param type $sum
     * @return type
     */
    public function getDestinationCardSynonim($card, $sum)
    {
        $request = new HTTP_Request2('https://paymentcard.yamoney.ru/gates/card/storeCard');
        $request->setMethod(HTTP_Request2::METHOD_POST)
                ->addPostParameter(array(
                    'skr_destinationCardNumber' => $card,
                    'sum' => $sum,
                    'skr_errorUrl' => '',
                    'skr_successUrl' => ''
                ));
        
        //@todo: ��������� ������ � ������ ��������� ������������ ����������� �����������, �������� crul ��� �����
        $request->setConfig(array(
            //'ssl_verify_peer'   => FALSE,
            //'ssl_verify_host'   => FALSE
            'ssl_cafile' => '/etc/pki/tls/certs/ca-bundle.crt'
        ));
        
        $response = $request->send();
        $header = $response->getHeader();
        $query = parse_url($header['location'], PHP_URL_QUERY);
        $results = array();
        parse_str($query, $results);
        return isset($results['skr_destinationCardSynonim'])?$results['skr_destinationCardSynonim']:false;
    }
    
    
    
    /**
     * ��������� ������� ������ ����� �� �������
     * ����������� ���������� ������� � ���� �� �����������
     * 
     * @param type $request_list
     * @return boolean
     */
    public function isAllowRepeatedRequest($request_list)
    {
        $is_allow = false;

        if (!count($request_list)) {
            return $is_allow;
        }
        
        foreach ($request_list as $request) {
            if (in_array($request['status'], array(
                    static::STATUS_SUCCESS, 
                    static::STATUS_INPROGRESS))) {

                $is_allow = true;
                break;
            }
        }
        
        return $is_allow;
    }    
    
    
    
    /**
     * ��������� ����� ���� �������� 
     * �� ��������� ������ �� max_sum
     * 
     * @param type $reserve_id
     * @param type $sum
     * @return type
     */
    public function calcRequestList($reserve_id, $sum)
    {
        $sum;
        $request_list = array();

        while ($sum > $this->max_sum) {
            $request_list[] = array(
                'reserve_id' => $reserve_id,
                'price' => $this->max_sum,
                'status' => static::STATUS_NEW,
                'cnt' => 0
            );
            
            $sum -= $this->max_sum;
        }

        $request_list[] = array(
            'reserve_id' => $reserve_id,
            'price' => $sum,
            'status' => static::STATUS_NEW,
            'cnt' => 0
        );
        
        return $request_list;
    }    
    
    

    /**
     * ������� ��������
     * @return object
     */
    public static function getInstance() 
    {

        if (null === static::$instance) {
            static::$instance = new static;
        }

        return static::$instance;
    }
    
    
    
    
    /**
     * @deprecated �� ������������. ������� �������� � ������� PGQ ��� �� � �����������
     * 
     * ���� �������������� ������ API ������� ������
     * �� ������� � ������� �������� �������
     * 
     * @todo: ������������ API ����������� ���������� � ���������� ��� 30 �����
     * @todo: �������� �� ������ ����� ��� �����?
     * 
     * @param type $limit - ���������� ������ �������������� �� ������
     * @return int - ���������� ������� ������������ ������
     */
    public function cron($limit = 10)
    {
        return false;
        
        $reservesModel = new ReservesModel();
        $reserveDataList = $reservesModel->getReservesWithStatusPayByService(
                ReservesModel::SUBSTATUS_INPROGRESS, 
                $limit);
        
        $cnt = 0;
        
        if ($reserveDataList) {
    
            $log = new log('reserves_docs/' . SERVER . '-%d%m%Y.log', 'a', "%d.%m.%Y %H:%M:%S: ");
    
            foreach($reserveDataList as $reserveData) {

                $reserveInstance = ReservesModelFactory::getInstance($reserveData['type']);
                $reserveInstance->setReserveData($reserveData);
        
                $status = $this->payout($reserveInstance, $reserveData['pay_type']);
                $is_done = $reserveInstance->changePayStatus($status);
        
                if ($is_done && $reserveInstance->isClosed()) {
                    
                    $cnt++;
                    
                    $orderData = array(
                        'id' => $reserveData['src_id'],
                        'reserve_data' => $reserveInstance->getReserveData(),
                        'reserve' => $reserveInstance,
                        'employer' => array(
                            'login' => $reserveData['emp_login'],
                            'email' => $reserveData['emp_email']
                        )
                    );

                    try {
                        $doc = new DocGenReserves($orderData);
                        $doc->generateActServiceEmp();
                        $doc->generateAgentReport();
                    } catch(Exception $e) {
                        $log->writeln(sprintf("Order Id = %s: %s", $orderData['id'], iconv('CP1251','UTF-8',$e->getMessage())));
                    }
                }
            }
        }
        
        return $cnt;
    }

}