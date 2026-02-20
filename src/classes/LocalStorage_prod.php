<?php
if (!$_SERVER['DOCUMENT_ROOT'])
	$_SERVER['DOCUMENT_ROOT'] ="/home/t/texxis/public_html";
if (!$_SERVER["HTTP_HOST"])
	$_SERVER["HTTP_HOST"]="texxis.ru";
require_once($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/main/include/prolog_before.php");
\Bitrix\Main\Loader::includeModule("highloadblock");
/**
 * Класс для локального хранения данных интеграции с Битрикс24
 * Заменяет API личного кабинета локальным хранением
 */
class LocalStorage
{
    private $logger;
    private $config;
    private $dataDir;
    private $contactsFile;
    private $companiesFile;
    private $projectsFile;
    private $managersFile;
    private $b24ListFile;
    private $cachedData = [
        'contacts' => null,
        'companies' => null,
        'deals' => null,
        'projects' => null,
        'managers' => null,
        'b24list' => null,
    ];
    public $b24List = [
        'contacts' => [],
        'companies' => [],
        'projects' => [],
    ];
    private $siteUserGroup = [6,7];
    private $siteManagerGroup = [6,8];
    private $siteUserRoleGroup = [47=>9, 63=>10, 75=>11]; //Инсталлятор, интегратор =47, Торговый дом = 63, Проектировщик = 75
    private $siteNewUserText = [47=>'integrator.txt', 63=>'trading.txt', 75=>'project.txt']; //Инсталлятор, интегратор =47, Торговый дом = 63, Проектировщик = 75
    private $CompanyHLEntityID;
    private $ProjectHLEntityID;
    private $bitrixAPI;
	

    public function __construct($logger, $config = null)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->dataDir = __DIR__ . '/../data';
        $this->contactsFile = $this->dataDir . '/contacts.json';
        $this->companiesFile = $this->dataDir . '/companies.json';
        $this->projectsFile = $this->dataDir . '/projects.json';
        $this->managersFile = $this->dataDir . '/managers.json';
        $this->b24ListFile = $this->dataDir . '/b24list.json';

        // Инициализация ID Highload-блоков из конфигурации
        $this->CompanyHLEntityID = $config['local_storage']['company_hl_entity_id'] ?? 2;
        $this->ProjectHLEntityID = $config['local_storage']['project_hl_entity_id'] ?? 3;
        $this->ensureDataDirectory();
        if ($config["reconect"])
            $this->Reconnect();

        require_once $_SERVER["DOCUMENT_ROOT"].'/local/php_interface/lk/src/classes/Bitrix24API.php';
        $this->b24List = $this->readData($this->b24ListFile, 'b24list');
		$date=time();
		$this->bitrixAPI = new Bitrix24API($config, $logger);
        if (!$this->b24List || ($date-60*60)>$this->b24List['date'])
		{	
			$contactList=$this->bitrixAPI->getContactListFields();
			$companyList=$this->bitrixAPI->getCompanyListFields();
			$projectList=$this->bitrixAPI->getSmartProcessListFields($config["bitrix24"]["smart_process_id"]);
			$map=$this->config['field_mapping']["contact"];
			foreach ($map as $k=>$v)
			{
                if (is_string($v) && !empty($v) && isset($contactList[$v]))
                    $this->b24List['contacts'][$k]=$contactList[$v]['values'];
			}
			$map=$this->config['field_mapping']["company"];
			foreach ($map as $k=>$v)
			{
                if (is_string($v) && !empty($v) && isset($companyList[$v]))
                    $this->b24List['companies'][$k]=$companyList[$v]['values'];
			}
			$map=$this->config['field_mapping']["smart_process"];
			foreach ($map as $k=>$v)
			{
                if (is_string($v) && !empty($v) && isset($projectList[$v]))
                    $this->b24List['projects'][$k]=$projectList[$v]['values'];
            }
            $this->b24List['date']=time();

            $this->writeData($this->b24ListFile, $this->b24List, 'b24list');
		}

    }
	
	public function SendUserInfo($siteUserID, $checkword=false)
	{
		if (!$checkword)
		{
			$checkword=Bitrix\Main\Security\Random::getString(32);
			$user = new CUser;
			//$user->Update($siteUserID, ["CHECKWORD"=>$checkword]);
		}
		$this->logger->info('Отправка данных пользователю', ['ID' => $siteUserID, "CHECKWORD"=>$checkword]);
		$rs=CUser::GetList("id","asc", ["ID"=>$siteUserID], ["FIELDS"=>[], "SELECT"=> ["UF_*"]]);
		if ($ar=$rs->Fetch())
		{
			if (intval($ar["UF_MANAGER_ID"])>0)
			{
				$rs2=CUser::GetList("id","asc", ["ID"=>$ar["UF_MANAGER_ID"]], ["FIELDS"=>[], "SELECT"=>["UF_*"]]);
				if ($arManager=$rs2->Fetch())
				{
					
				}
			}
			else $arManager=[];
            $lkKey=array_search($ar["UF_LK_CLIENT"], $this->b24List['contacts']['lk_client_field']);
			//echo print_r($ar,true)."lk=".$ar["UF_LK_CLIENT"]."key=".$lkKey.'temp='.$this->siteNewUserText[$lkKey].intval(($lkKey && $this->siteNewUserText[$lkKey]));
			if ($lkKey && $this->siteNewUserText[$lkKey])
			{
				$file=$this->dataDir.'/'.$this->siteNewUserText[$lkKey];
				if (!file_exists($file)) {
					return [];
				}
				
				$data = file_get_contents($file);
				$site='https://'.$_SERVER["HTTP_HOST"].'/';
				$url=$site.'personal/?change_password=yes&lang=ru&USER_CHECKWORD='.$checkword.'&USER_LOGIN='.urlencode($ar["LOGIN"]);
				$data=str_replace(
					["#NAME#", "#SITE#", "#NEW_LK#", '#MANAGER_LAST_NAME#', '#MANAGER_NAME#', '#MANAGER_EMAIL#', '#MANAGER_PHONEW#'],
					[$ar["NAME"], $site, $url, $arManager["LAST_NAME"], $arManager["NAME"], $arManager["EMAIL"], $arManager["WORK_PHONE"]],
					$data
				);
				CUser::SendUserInfo($siteUserID, 's1', $data, false, "USER_INFO", $checkword);
				$this->logger->info('Отправка данных пользователю завершена', ['USER' => $ar, "MANAGER"=>$arManager]);
			}
		}
	}
	
	private function GetUserGroup($type, $lkClient)
	{
		if ($type=="manager")
		{
			return $this->siteManagerGroup;
		}
		else
		{
			$group=$this->siteUserGroup;
			if ($lkClient && isset($this->siteUserRoleGroup[$lkClient]))
				$group[]=$this->siteUserRoleGroup[$lkClient];
			return $group;
		}
	}


    /**
     * Создание директории для данных
     */
    private function ensureDataDirectory()
    {
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    /**
     * Чтение данных из файла с кэшированием
     */
    private function readData($file, $cacheKey = null)
    {
        if ($cacheKey && isset($this->cachedData[$cacheKey]) && $this->cachedData[$cacheKey] !== null) {
            return $this->cachedData[$cacheKey];
        }

        if (!file_exists($file)) {
            return [];
        }

        $data = json_decode(file_get_contents($file), true);
        $result = $data ?: [];

        if ($cacheKey) {
            $this->cachedData[$cacheKey] = $result;
        }

        return $result;
    }

    /**
     * Запись данных в файл
     */
    private function writeData($file, $data, $cacheKey = null)
    {
        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($jsonData === false) {
            $this->logger->error('JSON encoding failed', [
                'file' => basename($file),
                'json_error' => json_last_error_msg()
            ]);
            return false;
        }

        $writeResult = file_put_contents($file, $jsonData);

        if ($writeResult === false) {
            $this->logger->error('File write failed', [
                'file' => basename($file),
                'file_writable' => is_writable($file),
                'disk_free_space' => disk_free_space(dirname($file)) ?? 'unknown'
            ]);
            return false;
        }

        if ($cacheKey) {
            $this->cachedData[$cacheKey] = $data;
        }

        $this->logger->debug('File write successful', [
            'file' => basename($file),
            'bytes_written' => $writeResult
        ]);

        return $writeResult;
    }

    /**
     * Создание личного кабинета для контакта
     */
    public function createLK($contactData)
    {
		$this->logger->debug('Добавление пользователя', ['contact_id' => $contactData['ID']]);
		
		$user = new CUser;
		$pass= $contactData['PASSWORD'] ? $contactData['PASSWORD'] : $this->SetPassword();
		if (is_array($contactData['EMAIL']) && $contactData['EMAIL'][0]["VALUE"])
			$contactData['EMAIL']=$contactData['EMAIL'][0]["VALUE"];
		if (is_array($contactData['PHONE']) && $contactData['PHONE'][0]["VALUE"])
			$contactData['PHONE']=$contactData['PHONE'][0]["VALUE"];
		
		// Получаем значение поля агентского договора
        $agentContractValue = $this->b24List['contacts']['agent_contract_status'][$this->getFieldValue($contactData, 'contact', 'agent_contract_status')];
        $lkClientValue = $this->b24List['contacts']['lk_client_field'][$this->getFieldValue($contactData, 'contact', 'lk_client_field')];
		//$agentContractValue = $this->getFieldValue($contactData, 'contact', 'agent_contract_status');
		//$lkClientValue = $this->getFieldValue($contactData, 'contact', 'lk_client_field');
		$checkword=Bitrix\Main\Security\Random::getString(32);
		$lkData = [
			"XML_ID" =>  $contactData['ID'],
			'NAME' => $contactData['NAME'] ?? '',
            'LAST_NAME' => $contactData['LAST_NAME'] ?? '',
            'SECOND_NAME' => $contactData['SECOND_NAME'] ?? '',
            'EMAIL' => $contactData['EMAIL'] ?? 'b24user'.$contactData['ID'].'@texxis.ru',
			'LOGIN' => $contactData['EMAIL'] ?? 'b24user'.$contactData['ID'].'@texxis.ru',
            'PERSONAL_PHONE' => $contactData['PHONE'] ?? '',
            'WORK_COMPANY' => $contactData['COMPANY_ID'] ?? null,
			"GROUP_ID" =>  $this->GetUserGroup('client', $lkClientValue),
			"PASSWORD" => $pass,
			"CONFIRM_PASSWORD" => $pass,
			"CHECKWORD"=> $checkword,
			"ADMIN_NOTES" => $pass,
			"UF_B24_MD5" => md5(serialize($contactData)),
			"UF_MANAGER_ID"=> $this->GetUserIDByXMLID("USER_".$contactData['ASSIGNED_BY_ID']),
			"UF_TYPE_ID" => $contactData['TYPE_ID'],
			"UF_AGENT_CONTRACT_STATUS" => $agentContractValue,
			"UF_LK_CLIENT " => $lkClientValue,
		];
		$siteID=$user->Add($lkData);
		if (intval($siteID) > 0)
			$this->logger->info('Успешное добавление пользователя', ['ID' => $siteID]);
		else
		{
			$this->logger->error('Ошибка добавления пользователя', ["error"=>$user->LAST_ERROR]);
			return false;
		}
		//$rs=CUser::GetList("id","asc", ["ID"=>$siteID], ["FIELDS"=>["LOGIN","CHECKWORD"]]);
		//if ($ar=$rs->Fetch())
		//{
			$url='https://'.$_SERVER["HTTP_HOST"].'/personal/?change_password=yes&lang=ru&USER_CHECKWORD='.$checkword.'&USER_LOGIN='.urlencode($lkData["LOGIN"]);
			$this->bitrixAPI->startEmailBusinessProcess($contactData['ID'], $url);
			//$this->SendUserInfo($siteID, $checkword);
		//}

        return true;
    }

    /**
     * Создание компании
     */
    public function createCompany($companyData, $inn = null)
    {
        $companyId = $companyData['ID'] ?? $companyData['id'] ?? null;
		
		$this->logger->debug('Добавление компании', ['company_id' => $companyId, 'inn_provided' => $inn !== null]);
		
		if (is_array($companyData['EMAIL']) && $companyData['EMAIL'][0]["VALUE"])
			$companyData['EMAIL']=$companyData['EMAIL'][0]["VALUE"];
		if (is_array($companyData['PHONE']) && $companyData['PHONE'][0]["VALUE"])
			$companyData['PHONE']=$companyData['PHONE'][0]["VALUE"];
		if (is_array($companyData['WEB']) && $companyData['WEB'][0]["VALUE"])
			$companyData['WEB']=$companyData['WEB'][0]["VALUE"];
		// Получаем значение поля партнерского договора
        //$partnerContractValue = $this->getFieldValue($companyData, 'company', 'partner_contract_status');
        $partnerContractValue = $this->b24List['companies']['partner_contract_status'][$this->getFieldValue($companyData, 'company', 'partner_contract_status')];
		
		$innValue = $inn ?? $companyData['INN'] ?? $companyData['inn'] ?? null;
		$arData= [
			"UF_DATE_UPDATE" => new \Bitrix\Main\Type\DateTime,
			"UF_DATE_INSERT" => new \Bitrix\Main\Type\DateTime,
			"UF_PARTNER_CONTRACT_STATUS" => $partnerContractValue,
			"UF_EMAIL" => $companyData['EMAIL'],
			"UF_WEB" => $companyData['WEB'],
			"UF_INN" => $innValue,
			//"UF_INN" => print_r($companyData, true),
			"UF_CONTACT_ID" => $companyData['CONTACT_ID'],
			"UF_PHONE" => $companyData['PHONE'],
			"UF_NAME" => $companyData['TITLE'],
			"UF_ID" => $companyId
		];
		
		$hlblock =\Bitrix\Highloadblock\HighloadBlockTable::getById($this->CompanyHLEntityID)->fetch(); 
		$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock); 
		$entity_data_class = $entity->getDataClass();

        $result = $entity_data_class::add($arData);
        if ($result->isSuccess())
        {
            $this->logger->info('Компания добавлена', ['company_id' => $companyId, 'inn' => $innValue]);
            return $result->getId();
        }
        else
        {
            $this->logger->error('Ошибка добавления компании', ["error"=>$result->getErrorMessages()]);
            return false;
        }
		
		

        return true;
    }

    /**
     * Синхронизация данных контакта по Bitrix ID
     */
    public function syncContactByBitrixId($contactId, $contactData)
    {
        $existingContact = $this->GetUserIDByXMLID($contactId);

        if (!$existingContact) {
            $this->logger->warning('Контакт не найден', [
                'contact_id' => $contactId
            ]);
            return false;
        }

        return $this->syncContact($contactId, $existingContact, $contactData);
    }

    /**
     * Синхронизация данных контакта
     */
    public function syncContact($lkId, $siteID, $contactData)
    {
		$existingContact = $this->GetUserIDByXMLID($contactId);
		if (!$existingContact) {
            $this->logger->warning('Контакт не найден, создаем новый', [
                'contact_id' => $lkId,
                //'available_contacts' => $contactData,
            ]);
            return $this->createLK($contactData);
        }
		
		$this->logger->debug('Обновление данных контакта', [
            'lk_id' => $lkId,
            'contact_id' => $lkId
        ]);
		
		$user = new CUser;
		
		$lkData = [];
		if (is_array($contactData['PHONE']) && $contactData['PHONE'][0]["VALUE"])
			$contactData['PHONE']=$contactData['PHONE'][0]["VALUE"];
		if (is_array($contactData['EMAIL']) && $contactData['EMAIL'][0]["VALUE"])
			$contactData['EMAIL']=$contactData['EMAIL'][0]["VALUE"];
		
        $agentContractValue = $this->b24List['contacts']['agent_contract_status'][$this->getFieldValue($contactData, 'contact', 'agent_contract_status')];
        $lkClientValue = $this->b24List['contacts']['lk_client_field'][$this->getFieldValue($contactData, 'contact', 'lk_client_field')];
		//$agentContractValue = $this->getFieldValue($contactData, 'contact', 'agent_contract_status');
		//$lkClientValue = $this->getFieldValue($contactData, 'contact', 'lk_client_field');
		
		$bitrixManagerId=$this->GetUserIDByXMLID("USER_".$contactData['ASSIGNED_BY_ID']);
		if (!$bitrixManagerId && $contactData['ASSIGNED_BY_ID'])
		{
			$assignedById=$contactData['ASSIGNED_BY_ID'];
			$managerData = $this->bitrixAPI->getEntityData('user', $assignedById);
			
			if ($managerData && isset($managerData['result'])) {
				$userData = $managerData['result'];
				if (is_array($userData) && isset($userData[0])) {
					$userData = $userData[0];
				}
			}
			$this->syncManagerByBitrixId($assignedById, $userData);
			$bitrixManagerId=$this->GetUserIDByXMLID("USER_".$contactData['ASSIGNED_BY_ID']);
		}
		
		if ($contactData['NAME']) $lkData['NAME'] = $contactData['NAME'];
		if ($contactData['LAST_NAME']) $lkData['LAST_NAME'] = $contactData['LAST_NAME'];
		if ($contactData['SECOND_NAME']) $lkData['SECOND_NAME'] = $contactData['SECOND_NAME'];
		if ($contactData['EMAIL']) { $lkData['EMAIL'] = $contactData['EMAIL']; $lkData['LOGIN'] = $contactData['EMAIL'];}
		if ($contactData['PHONE']) $lkData['PERSONAL_PHONE'] = $contactData['PHONE'];	
		if ($contactData['COMPANY_ID']) $lkData['WORK_COMPANY'] = $contactData['COMPANY_ID'];
		if ($contactData['PASSWORD']) { $lkData['PASSWORD'] = $contactData['PASSWORD']; $lkData["CONFIRM_PASSWORD"]=$contactData['PASSWORD'];}
		if ($contactData['ASSIGNED_BY_ID']) $lkData['UF_MANAGER_ID'] = $bitrixManagerId;
		if ($agentContractValue) $lkData["UF_AGENT_CONTRACT_STATUS"] = $agentContractValue;
		if ($contactData['TYPE_ID']) $lkData["UF_TYPE_ID"] = $contactData['TYPE_ID'];
		if ($lkClientValue) $lkData["UF_LK_CLIENT"] = $lkClientValue;

		$lkData["UF_B24_MD5"] = md5(serialize($contactData));
		
		$res=$user->Update($siteID, $lkData);
		if ($res)
			$this->logger->info('Пользователь обновлен', ['ID' => $siteID, 'contactData'=>$contactData, /*'lkData'=> $lkData,  'agentContractValue'=>$agentContractValue*/]);
		else
		{
			$this->logger->error('Ошибка обновления пользователя', ["error"=>$user->LAST_ERROR]);
			return false;
		}

        return true;
    }

    /**
     * Синхронизация данных компании
     */
    public function syncCompany($lkId, $companyData, $inn = null)
    {	
		$this->logger->debug('Обновление компании', ['lk_id' => $lkId, 'company_id' => $companyData['ID'], 'inn_provided' => $inn !== null]);
		$company=$this->getCompanyByXMLID($lkId);
		if ($company["SITE_ID"])
		{
			$arData= [];
			$arData["UF_DATE_UPDATE"] = new \Bitrix\Main\Type\DateTime;
			if (is_array($companyData['EMAIL']) && $companyData['EMAIL'][0]["VALUE"])
				$companyData['EMAIL']=$companyData['EMAIL'][0]["VALUE"];
			if (is_array($companyData['PHONE']) && $companyData['PHONE'][0]["VALUE"])
				$companyData['PHONE']=$companyData['PHONE'][0]["VALUE"];
			if (is_array($companyData['WEB']) && $companyData['WEB'][0]["VALUE"])
				$companyData['WEB']=$companyData['WEB'][0]["VALUE"];
			// Получаем значение поля партнерского договора
			//$partnerContractValue = $this->getFieldValue($companyData, 'company', 'partner_contract_status');
	        $partnerContractValue = $this->b24List['companies']['partner_contract_status'][$this->getFieldValue($companyData, 'company', 'partner_contract_status')];
			$innValue = $inn ?? $companyData['INN'] ?? $companyData['inn'] ?? null;
				
			if ($partnerContractValue) $arData["UF_PARTNER_CONTRACT_STATUS"] = $partnerContractValue;
			if ($companyData['EMAIL']) $arData["UF_EMAIL"] = $companyData['EMAIL'];
			if ($companyData['WEB']) $arData["UF_WEB"]= $companyData['WEB'];
			if ($innValue) $arData["UF_INN"] = $innValue;
			if ($companyData['CONTACT_ID']) $arData["UF_CONTACT_ID"] = $companyData['CONTACT_ID'];
			if ($companyData['PHONE']) $arData["UF_PHONE"] = $companyData['PHONE'];
			if ($companyData['TITLE']) $arData["UF_NAME"] = $companyData['TITLE'];
			
			
			
			$hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById($this->CompanyHLEntityID)->fetch(); 
			$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock); 
			$entity_data_class = $entity->getDataClass();

			$result = $entity_data_class::update($company["SITE_ID"], $arData);
			if ($result->isSuccess())
			{
				$this->logger->info('Компания обновлена', ['company_id' => $companyData['ID'], 'inn' => $innValue]);
				return $result->getId();
			}
			else
			{
				this->logger->error('Ошибка обновления компании', ["error"=>$result->getErrorMessages()]);
				return false;
			}
		}

        return true;
    }

  
    /**
     * Получение контакта по ID
     */
    public function getContact($contactId)
    {
        /*$contacts = $this->readData($this->contactsFile, 'contacts');
        return $contacts[$contactId] ?? null;*/
		return $this->GetUserDataByXMLID($contactId);
		
    }

    /**
     * Получение компании по ID
     */
    public function getCompany($companyId) 
    {
        /*$companies = $this->readData($this->companiesFile, 'companies');
        return $companies[$companyId] ?? null;*/
		return $this->getCompanyByXMLID($companyId);
    }


    /**
     * Добавление проекта
     */
    public function addProject($projectData) 
    {
		$projectId = $projectData['id'] ?? $projectData['ID'] ?? $projectData['bitrix_id'] ?? null;
		if ($_SESSION["addProject"]==$projectId) return false;
		$_SESSION["addProject"]=$projectId;
		$project=$this->getProjectByXMLID($projectId);
		if ($project) {
			$this->logger->warning('Такой проект уже есть', [
                'project_id' => $projectId,
				'project_data' => $projectData,
            ]);
			return false;
		}
		$this->logger->debug('Добавление проекта', ['project_id' => $projectId]);
		if(is_array($projectData['system_types']))
		{
			foreach ($projectData['system_types'] as $k=>$v)
			{
                $projectData['system_types'][$k]=$this->b24List['projects']['system_types'][$v];
			}
			$projectData['system_types']=implode(', ', $projectData['system_types']);
		}
		else
		{
            $projectData['system_types']=$this->b24List['projects']['system_types'][$projectData['system_types']];
		}
        $projectData['request_type']=$this->b24List['projects']['request_type'][$projectData['request_type']];
		$projectData['location'] = trim(explode('|', $projectData['location'])[0]);
		
		$project=$this->getProjectByXMLID($projectId);
		$arData= [
			"UF_DATE_UPDATE" => new \Bitrix\Main\Type\DateTime,
			"UF_DATE_INSERT" => new \Bitrix\Main\Type\DateTime,
			'UF_ID' => $projectId,
            'UF_ORGANIZATION_NAME' => $projectData['organization_name'] ?? '',
            'UF_OBJECT_NAME' => $projectData['object_name'] ?? '',
            'UF_SYSTEM_TYPE' => $projectData['system_types'] ?? '',
            'UF_LOCATION' => $projectData['location'] ?? '',
            'UF_IMPLEMENTATION_DATE' => $projectData['implementation_date'] ?? null,
            'UF_STATUS' => $projectData['status'] ?? '',
            'UF_CLIENT_ID' => $projectData['client_id'] ?? null,
            'UF_MANAGER_ID' =>  "USER_".$projectData['manager_id'] ?? null,
			'UF_REQUEST_TYPE' => $projectData['request_type'] ?? null,
			//'UF_EQUIPMENT_LIST' => $projectData['equipment_list'] ?? null,
			'UF_EQUIPMENT_LIST_TEXT' => $projectData['equipment_list_text'] ?? '',
			'UF_COMPETITORS' => $projectData['competitors'] ?? null,
			'UF_MARKETING_DISCOUNT' => $projectData['marketing_discount'] ?? null,
			'UF_TECHNICAL_DESCRIPTION' => $projectData['technical_description'] ?? null,
		];
		
		$hlblock =\Bitrix\Highloadblock\HighloadBlockTable::getById($this->ProjectHLEntityID)->fetch(); 
		$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock); 
		$entity_data_class = $entity->getDataClass();

		$result = $entity_data_class::add($arData);
		if ($result->isSuccess())
		{
			$this->logger->info('Проект добавлен', ['project_id' => $projectId, 'site_id' => $result->getId()]);
			return $result->getId();
		}
		else
		{
			this->logger->error('Ошибка добавления проекта', ["error"=>$result->getErrorMessages()]);
			return false;
		}
		$_SESSION["addProject"]=false;
	
        return true;
    }

    /**
     * Добавление менеджера
     */
    public function addManager($managerData)
    {
		$this->logger->debug('Добавление менеджера', ['manager_id' => $managerData["ID"]/*, "data"=>$managerData*/]);
		$user = new CUser;
		$pass=$managerData['PASSWORD'] ? $managerData['PASSWORD'] : $this->SetPassword();
		if (is_array($managerData['EMAIL']) && $managerData['EMAIL'][0]["VALUE"])
			$managerData['EMAIL']=$managerData['EMAIL'][0]["VALUE"];
		/*if (is_array($managerData['PHONE']) && $managerData['PHONE'][0]["VALUE"])
			$managerData['PHONE']=$managerData['PHONE'][0]["VALUE"];
		elseif (is_array($managerData['PHONE']) && $managerData['PHONE'][0]["VALUE"])
			$managerData['PHONE']=$managerData['PHONE'][0]["VALUE"];*/
		
		$lkData = [
			"XML_ID" =>  "USER_".$managerData["ID"],
			'NAME' => $managerData['NAME'] ?? '',
            'LAST_NAME' => $managerData['LAST_NAME'] ?? '',
            'SECOND_NAME' => $managerData['SECOND_NAME'] ?? '',
            'EMAIL' => $managerData['EMAIL'] ?? 'b24user'.$managerData['ID'].'@texxis.ru',
			'LOGIN' => $managerData['EMAIL'] ?? 'b24user'.$managerData['ID'].'@texxis.ru',
            'WORK_PHONE' => $this->extractManagerPhone($managerData),
            'WORK_POSITION' => $managerData['WORK_POSITION'] ?? null,
			'PERSONAL_PHOTO' => @CFile::MakeFileArray($managerData['PERSONAL_PHOTO']),
			"UF_B24_MD5" => md5(serialize($managerData)),
			"GROUP_ID" => $this->GetUserGroup('manager', ''),
			"PASSWORD"          => $pass,
			"CONFIRM_PASSWORD"  => $pass,
			"ADMIN_NOTES" => $pass,
			'UF_MESSENGERS' => serialize($this->extractManagerMessengers($managerData)),
		];
		$siteID=$user->Add($lkData);
		if (intval($siteID) > 0)
			$this->logger->info('Менеджер добавлен', ['ID' => $siteID]);
		else
		{
			$this->logger->error('Ошибка добавления менеджера', ["error"=>$user->LAST_ERROR]);
			return false;
		}		

        return true;
    }

    /**
     * Синхронизация данных компании по Bitrix ID
     */
    public function syncCompanyByBitrixId($companyId, $companyData, $inn = null) 
    {
        $existingCompany = $this->getCompany($companyId);

        if (!$existingCompany) {
            $this->logger->warning('Компания не найдена, создаем новую', [
                'company_id' => $companyId,
				'inn_provided' => $inn !== null
            ]);
            return $this->createCompany($companyData, $inn);
        }

        return $this->syncCompany($companyId, $companyData, $inn);
    }

    /**
     * Синхронизация данных проекта по Bitrix ID
     */
    public function syncProjectByBitrixId($projectId, $projectData) 
    {
	
		$project=$this->getProjectByXMLID($projectId);
		if (!$project) {
            $this->logger->warning('Проект не найден, добавляем проект', [
                'project_id' => $projectId,
				'project_data' => $projectData,
            ]);
			if ($projectData)
				return $this->addProject($projectData);
        }
		
		$this->logger->debug('Обновление проекта', ['project_id' => $projectId, 'project_data' => $projectData,]);
		
		
		$arData=[
			"UF_DATE_UPDATE" => new \Bitrix\Main\Type\DateTime,
		];
		
		if(is_array($projectData['system_types']))
		{
			foreach ($projectData['system_types'] as $k=>$v)
			{
                $projectData['system_types'][$k]=$this->b24List['projects']['system_types'][$v];
			}
			$projectData['system_types']=implode(', ', $projectData['system_types']);
		}
		else
		{
            $projectData['system_types']=$this->b24List['projects']['system_types'][$projectData['system_types']];
		}
        $projectData['request_type']=$this->b24List['projects']['request_type'][$projectData['request_type']];
		$projectData['location'] = trim(explode('|', $projectData['location'])[0]);
		
		if ($projectData['organization_name']) $arData['UF_ORGANIZATION_NAME'] = $projectData['organization_name'];
		if ($projectData['object_name']) $arData['UF_OBJECT_NAME'] = $projectData['object_name'];
		if ($projectData['system_types']) $arData['UF_SYSTEM_TYPE'] = $projectData['system_types'];
		if ($projectData['location']) $arData['UF_LOCATION'] = $projectData['location'];
		if ($projectData['implementation_date']) $arData['UF_IMPLEMENTATION_DATE'] = $projectData['implementation_date'];
		if ($projectData['status']) $arData['UF_STATUS'] = $projectData['status'];
		if ($projectData['client_id']) $arData['UF_CLIENT_ID'] = $projectData['client_id'];
		if ($projectData['manager_id']) $arData['UF_MANAGER_ID'] = "USER_".$projectData['manager_id'];
		if ($projectData['request_type']) $arData['UF_REQUEST_TYPE'] = $projectData['request_type'];
		//if ($projectData['equipment_list']["id"]) $arData['UF_EQUIPMENT_LIST'] = @CFile::MakeFileArray($projectData['equipment_list']["id"]);
		if ($projectData['competitors']) $arData['UF_COMPETITORS'] = $projectData['competitors'];
		if (isset($projectData['marketing_discount'])) $arData['UF_MARKETING_DISCOUNT'] = $projectData['marketing_discount'];
		if ($projectData['technical_description']) $arData['UF_TECHNICAL_DESCRIPTION'] = $projectData['technical_description'];
		
					
		$hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById($this->ProjectHLEntityID)->fetch(); 
		$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock); 
		$entity_data_class = $entity->getDataClass();

		$result = $entity_data_class::update($project["SITE_ID"], $arData);
		if ($result->isSuccess())
		{
			 $this->logger->info('Проект обновлен', ['project_id' => $projectId]);
			return $result->getId();
		}
		else
		{
			this->logger->error('Ошибка обновления проекта', ["error"=>$result->getErrorMessages()]);
			return false;
		}

        return true;
    }

    /**
     * Синхронизация данных менеджера по Bitrix ID
     */
    public function syncManagerByBitrixId($managerId, $managerData)
    {
		$contact=$this->GetUserDataByXMLID("USER_".$managerId);
		if (!$contact) {
            $this->logger->warning('Менеджер не найден, создаем нового', [
                'manager_id' => $managerId
            ]);
            return $this->addManager($managerData);
        }
		
		if (is_array($managerData['EMAIL']) && $managerData['EMAIL'][0]["VALUE"])
			$managerData['EMAIL']=$managerData['EMAIL'][0]["VALUE"];
		/*if (is_array($managerData['PHONE']) && $managerData['PHONE'][0]["VALUE"])
			$managerData['PHONE']=$managerData['PHONE'][0]["VALUE"];*/
		$messengers = $this->extractManagerMessengers($managerData);
		$managerData['PHONE']=$this->extractManagerPhone($managerData);
		
		if ($managerData['NAME']) $lkData['NAME'] = $managerData['NAME'];
		if ($managerData['LAST_NAME']) $lkData['LAST_NAME'] = $managerData['LAST_NAME'];
		if ($managerData['SECOND_NAME']) $lkData['SECOND_NAME'] = $managerData['SECOND_NAME'];
		//if ($managerData['EMAIL']) { $lkData['EMAIL'] = $managerData['EMAIL']; $lkData['LOGIN'] = $managerData['EMAIL'];}
		if ($managerData['PHONE']) $lkData['WORK_PHONE'] = $managerData['PHONE'];	
		if ($managerData['COMPANY_ID']) $lkData['WORK_COMPANY'] = $managerData['COMPANY_ID'];
		if ($managerData['PASSWORD']) { $lkData['PASSWORD'] = $managerData['PASSWORD']; $lkData["CONFIRM_PASSWORD"]=$managerData['PASSWORD'];}
		if ($managerData['WORK_POSITION']) $lkData['WORK_POSITION'] = $managerData['WORK_POSITION'];
		if ($managerData['PERSONAL_PHOTO']) $lkData['PERSONAL_PHOTO'] = @CFile::MakeFileArray($managerData['PERSONAL_PHOTO']);
		if ($messengers) $lkData['UF_MESSENGERS'] = serialize($messengers);
		
		
		$siteID= $contact["SITE_ID"];
		$user = new CUser;
		$res=$user->Update($siteID, $lkData);
		if ($res)
			$this->logger->info('Менеджер обновлен', ['ID' => $siteID]);
		else
		{
			$this->logger->error('Ошибка обновления менеджера', ["site_id"=>$siteID, "manager_id"=>$managerId,"error"=>$user->LAST_ERROR]);
			return false;
		}

        return true;
    }


    /**
     * Удаление всех данных контакта из базы данных
     * Удаляет контакт и все связанные с ним сущности: компании, сделки и проекты
     * 
     * @param string $contactId ID контакта в Bitrix24
     * @return bool true при успешном удалении, false при ошибке
     */
    public function deleteContactData($contactId) 
    {
        $this->logger->debug('Удаление всех данных контакта и всего связанного с ним', ['contact_id' => $contactId]);

        $contactId = (string)$contactId;
		
		$SITE_ID=$this->GetUserIDByXMLID($contactId);
		$this->DeleteUserCompanies($contactId);
		$this->DeleteUserProjects($contactId);
		$user = new CUser;
		if ($SITE_ID)
		{
			//$user->Update($SITE_ID, ["ACTIVE"=>"N"]);
			\Bitrix\Main\UserAuthActionTable::addLogoutAction($SITE_ID);
			$user->Delete($SITE_ID);
		}
		$this->logger->info('Удаление всех данных контакта и всего связанного с ним завершено', ['contact_id' => $contactId]);		
        return true;
    }
	
	public function GetUserDataByXMLID($XML_ID)
	{
		global $USER;
		$rs=CUser::GetList("id","asc", ["XML_ID"=>$XML_ID], ["FIELDS"=>[], "SELECT"=> ["UF_*"], "NAV_PARAMS"=> ["nTopCount"=>1]]);
		if ($ar=$rs->Fetch())
		{
			$ar["SITE_ID"]=$ar["ID"];
			$ar["ID"]=$ar["XML_ID"];
			return $ar;
		}
	}
	
	public function GetUserIDByXMLID($XML_ID)
	{
		global $USER;
		$rs=CUser::GetList("id","asc", ["XML_ID"=>$XML_ID], ["FIELDS"=>["ID"], "NAV_PARAMS"=> ["nTopCount"=>1]]);
		if ($ar=$rs->Fetch())
		{
			return $ar["ID"];
		}
		return false;
	}
	public function getCompanyByXMLID($XML_ID)
	{
		$hlblock =\Bitrix\Highloadblock\HighloadBlockTable::getById($this->CompanyHLEntityID)->fetch(); 
		$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock); 
		$entity_data_class = $entity->getDataClass();
		$rsData = $entity_data_class::getList(array(
		   "select" => array("*"),
		   "filter" => array("UF_ID"=>$XML_ID),
		   "limit" => 1,
		));
		if($arData = $rsData->Fetch()){
			$arData["SITE_ID"]=$arData["ID"];
			$arData["ID"]=$arData["UF_ID"];
			return $arData;
		}
		return false;
	}
	
	public function DeleteUserCompanies($XML_ID)
	{
		$hlblock =\Bitrix\Highloadblock\HighloadBlockTable::getById($this->CompanyHLEntityID)->fetch(); 
		$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock); 
		$entity_data_class = $entity->getDataClass();
		$rsData = $entity_data_class::getList(array(
		   "select" => array("*"),
		   "filter" => array("UF_CONTACT_ID"=>$XML_ID),
		   //"limit" => 1,
		));
		while($arData = $rsData->Fetch()){
			$entity_data_class::Delete($arData["ID"]);
			$this->logger->debug('Удаление компании', ['contact_id' => $XML_ID, "site_id" => $arData["ID"]]);
		}
		return false;
	}
	
	public function getUserCompaniesID($XML_ID)
	{
		$hlblock =\Bitrix\Highloadblock\HighloadBlockTable::getById($this->CompanyHLEntityID)->fetch(); 
		$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock); 
		$entity_data_class = $entity->getDataClass();
		$rsData = $entity_data_class::getList(array(
		   "select" => array("ID"),
		   "filter" => array("UF_CONTACT_ID"=>$XML_ID),
		   //"limit" => 1,
		));
		while($arData = $rsData->Fetch()){
			$arDatas[]=$arData;
		}
		return $arDatas;
		
	}

	public function getProjectByXMLID($XML_ID)
	{
		$hlblock =\Bitrix\Highloadblock\HighloadBlockTable::getById($this->ProjectHLEntityID)->fetch(); 
		$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock); 
		$entity_data_class = $entity->getDataClass();
		$rsData = $entity_data_class::getList(array(
		   "select" => array("*"),
		   "filter" => array("UF_ID"=>$XML_ID),
		   "limit" => 1,
		));
		if($arData = $rsData->Fetch()){
			$arData["SITE_ID"]=$arData["ID"];
			$arData["ID"]=$arData["UF_ID"];
			return $arData;
		}
		return false;
	}
	
	public function getUserProjectsID($XML_ID)
	{
		$hlblock =\Bitrix\Highloadblock\HighloadBlockTable::getById($this->ProjectHLEntityID)->fetch(); 
		$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock); 
		$entity_data_class = $entity->getDataClass();
		$rsData = $entity_data_class::getList(array(
		   "select" => array("ID"),
		   "filter" => array("UF_CLIENT_ID"=>$XML_ID),
		   //"limit" => 1,
		));
		while($arData = $rsData->Fetch()){
			$arDatas[]=$arData;
		}
		return $arDatas;
		
	}
	public function DeleteUserProjects($XML_ID)
	{
		$hlblock =\Bitrix\Highloadblock\HighloadBlockTable::getById($this->ProjectHLEntityID)->fetch(); 
		$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock); 
		$entity_data_class = $entity->getDataClass();
		$rsData = $entity_data_class::getList(array(
		   "select" => array("ID"),
		   "filter" => array("UF_CLIENT_ID"=>$XML_ID),
		   //"limit" => 1,
		));
		while($arData = $rsData->Fetch()){
			$entity_data_class::Delete($arData["ID"]);
			$this->logger->debug('Удаление проекта', ['client_id' => $XML_ID, "site_id"=>$arData["ID"]]);
		}
	}
	
	public function SetPassword()
	{
		return randString(7, array(
			"abcdefghijklnmopqrstuvwxyz",
			"ABCDEFGHIJKLNMOPQRSTUVWXYZ",
			"0123456789",
			"!@#\$%^&*()",
		));
	}
	
    /**
     * Извлечение ссылки на фото менеджера по маппингу
     */
    private function extractManagerPhoto($managerData)
    {
        $photoField = $this->config['field_mapping']['user']['photo'] ?? 'PERSONAL_PHOTO';
        return $managerData[$photoField] ?? '';
    }

    /**
     * Извлечение номера телефона менеджера с учетом маппинга
     */
    private function extractManagerPhone($managerData)
    {
        $phoneField = $this->config['field_mapping']['user']['phone'] ?? 'PERSONAL_PHONE';
        return $managerData[$phoneField] ?? ($managerData['PHONE'] ?? '');
    }

    /**
     * Преобразование полей мессенджеров менеджера в удобный формат
     */
    private function extractManagerMessengers($managerData)
    {
        $messengerMapping = $this->config['field_mapping']['user']['messengers'] ?? [];
        $messengers = [];

        foreach ($messengerMapping as $messenger => $fieldCode) {
            if (empty($fieldCode)) {
                continue;
            }

            $rawValue = $managerData[$fieldCode] ?? null;
            if ($rawValue === null || $rawValue === '') {
                continue;
            }

            $messengers[$messenger] = $this->normalizeMessengerLink($messenger, $rawValue);
        }

        return $messengers;
    }

    /**
     * Нормализация ссылок для мессенджеров
     */
    private function normalizeMessengerLink($type, $value)
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (!is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if ($value === '') {
            return $value;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_contains($value, '://')) {
            return $value;
        }

        switch ($type) {
            case 'telegram':
                $username = ltrim($value, '@');
                return 'https://t.me/' . $username;
            case 'whatsapp':
                $digits = preg_replace('/\D+/', '', $value);
                return $digits ? 'https://wa.me/' . $digits : $value;
            case 'viber':
                $digits = preg_replace('/\D+/', '', $value);
                return $digits ? 'viber://chat?number=' . $digits : $value;
            default:
                return $value;
        }
    }

    /**
     * Получение значения поля из данных сущности по маппингу конфигурации
     * 
     * @param array $entityData Данные сущности из Bitrix24
     * @param string $entityType Тип сущности ('contact', 'company', etc.)
     * @param string $fieldKey Ключ поля в маппинге конфигурации
     * @return mixed Значение поля или null
     */
    private function getFieldValue($entityData, $entityType, $fieldKey)
    {
        if (!$this->config || !isset($this->config['field_mapping'][$entityType][$fieldKey])) {
            return null;
        }

        $fieldName = $this->config['field_mapping'][$entityType][$fieldKey];
        
        if (empty($fieldName) || !isset($entityData[$fieldName])) {
            return null;
        }

        return $entityData[$fieldName];
    }
	
    public function deleteProject($projectId)
    {
        $this->logger->debug('Удаление проекта', ['project_id' => $projectId]);

        $projectId = (string)$projectId;
        
		$hlblock =\Bitrix\Highloadblock\HighloadBlockTable::getById($this->ProjectHLEntityID)->fetch(); 
		$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock); 
		$entity_data_class = $entity->getDataClass();
		$rsData = $entity_data_class::getList(array(
		   "select" => array("ID"),
		   "filter" => array("UF_ID"=>$projectId),
		   "limit" => 1,
		));
		$res=false;
		if($arData = $rsData->Fetch()){
			$entity_data_class::Delete($arData["ID"]);
			$this->logger->debug('Удаление проекта', ['project_id' => $projectId, "site_id"=>$arData["ID"]]);
			$res=true;
		}       
        
        if ($res !== false) {
            $this->logger->info('Проект удален', ['project_id' => $projectId]);
            return true;
        } else {
            $this->logger->error('Проект не найден', ['project_id' => $projectId]);
            return false;
        }
    }

    public function deleteCompany($companyId)
    {
        $this->logger->debug('Удаление компании', ['company_id' => $companyId]);

        $companyId = (string)$companyId;
        
		$hlblock =\Bitrix\Highloadblock\HighloadBlockTable::getById($this->CompanyHLEntityID)->fetch(); 
		$entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock); 
		$entity_data_class = $entity->getDataClass();
		$rsData = $entity_data_class::getList(array(
		   "select" => array("ID"),
		   "filter" => array("UF_ID"=>$companyId),
		   "limit" => 1,
		));
		$res=false;
		if($arData = $rsData->Fetch()){
			$entity_data_class::Delete($arData["ID"]);
			$this->logger->debug('Удаление компании', ['company_id' => $companyId, "site_id"=>$arData["ID"]]);
			$res=true;
		}       
        
        if ($res !== false) {
            $this->logger->info('Компания удалена', ['company_id' => $companyId]);
            return true;
        } else {
            $this->logger->error('Компания не найдена', ['company_id' => $companyId]);
            return false;
        }
    }
	
	public function Disconnect()
	{
		$cn = \Bitrix\Main\Application::getConnection();
		$cn->disconnect();
	}
	
	public function Reconnect()
	{
		$cn = \Bitrix\Main\Application::getConnection();
		$cn->disconnect();
		$cn->connect();
	}
}
