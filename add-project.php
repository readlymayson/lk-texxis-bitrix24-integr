<?
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
include_once ($_SERVER["DOCUMENT_ROOT"] . "/verstka/php/functions.php");
include_once($_SERVER["DOCUMENT_ROOT"].'/local/php_interface/lk.php');
$userProjects=GetUserProcects();
$userData=GetUserDataLK();
$company=getCompanyByXMLID($userData["~WORK_COMPANY"]);

require_once  $_SERVER["DOCUMENT_ROOT"].'/local/php_interface/lk/src/classes/Logger.php';
require_once  $_SERVER["DOCUMENT_ROOT"].'/local/php_interface/lk/src/classes/Bitrix24API.php';
$config = require_once $_SERVER["DOCUMENT_ROOT"].'/local/php_interface/lk/src/config/bitrix24.php';

$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);
$mapping = $config['field_mapping']['smart_process'];
		
if (check_bitrix_sessid() && $_POST["PROP"] && count((array)$_POST["PROP"])>0 && $_SESSION["project_added"]!==true)
{
	$error="";
	$props=$_POST["PROP"];
	if (!$userData["XML_ID"])
		$error.="У пользователя не указан идентификатор, обратитесь к Вашему менеджеру";

	
	if (!$error)
	{

		$data=[
			'organization_name' => $props["UF_ORGANIZATION_NAME"],
			'object_name' => $props["UF_OBJECT_NAME"],
			'system_types' => $props["UF_SYSTEM_TYPE"],
			'location' => $props["UF_LOCATION"],
			'implementation_date' => $props["UF_IMPLEMENTATION_DATE"],
			
			'request_type' => $props["UF_REQUEST_TYPE"],
			'equipment_list' => $props["UF_EQUIPMENT_LIST"],
			'equipment_list_text' => $props["UF_EQUIPMENT_LIST_TEXT"],
			'competitors' => $props["UF_COMPETITORS"],
			'marketing_discount' => isset($props["UF_MARKETING_DISCOUNT"]),
			'technical_description' => $props["UF_TECHNICAL_DESCRIPTION"],
			'status' => 'NEW',
			'client_id' => $userData["XML_ID"],
			'manager_id' =>str_replace('USER_', '',$userData["MANAGER"]["XML_ID"])
		];
		
		$data['equipment_list'] = [];
		$uploadErrors = [];

		if (!empty($_FILES["UF_EQUIPMENT_LIST"]['tmp_name'])) {
			$fileTmpNames = $_FILES["UF_EQUIPMENT_LIST"]['tmp_name'];
			$fileErrors = $_FILES["UF_EQUIPMENT_LIST"]['error'];
			$fileSizes = $_FILES["UF_EQUIPMENT_LIST"]['size'];
			$fileNames = $_FILES["UF_EQUIPMENT_LIST"]['name'];

			// Нормализуем к массиву, если передан один файл
			if (!is_array($fileTmpNames)) {
				$fileTmpNames = [$fileTmpNames];
				$fileErrors = [$fileErrors];
				$fileSizes = [$fileSizes];
				$fileNames = [$fileNames];
			}

			foreach ($fileTmpNames as $k => $tmpPath) {
				$errorCode = $fileErrors[$k] ?? UPLOAD_ERR_NO_FILE;
				$fileSize = $fileSizes[$k] ?? 0;
				$origName = $fileNames[$k] ?? 'unknown';

				// Проверка ошибок PHP при загрузке
				if ($errorCode !== UPLOAD_ERR_OK) {
					$errorMsg = match ($errorCode) {
						UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'превышен максимальный размер файла',
						UPLOAD_ERR_PARTIAL => 'файл загружен частично',
						UPLOAD_ERR_NO_FILE => 'файл не выбран',
						default => 'ошибка загрузки (код ' . $errorCode . ')',
					};
					$uploadErrors[] = "{$origName}: {$errorMsg}";
					continue;
				}

				// Проверка на пустой файл
				if ($fileSize === 0) {
					$uploadErrors[] = "{$origName}: файл пустой (0 байт)";
					continue;
				}

				// Загружаем файл в Bitrix24
				$uploadResult = $bitrixAPI->uploadFile($tmpPath);
				if (is_array($uploadResult) && isset($uploadResult['internal_link'])) {
					$data['equipment_list'][] = $uploadResult['internal_link'];
				}
			}
		}

		if (!empty($uploadErrors)) {
			$error .= implode('<br>', $uploadErrors) . '<br>';
		}
		//print_r($data);
		//print_r($_FILES);
		         
		
		$fields=[];
		foreach ($data as $k=>$v)
		{
			if ($v && !empty($mapping[$k])) {
				$fields[$mapping[$k]] = $v;
			}
		}
		$res=$bitrixAPI->addSmartProcessItem($config["bitrix24"]["smart_process_id"], $fields);
		//$res = $bitrixAPI->createProjectCard($userData["XML_ID"], $fields, $fileIdParam, $filePathParam, $localStorage);
		//print_r($res);
		if (!$res["id"])
			$error="ошибка добавления проекта, обратитесь к Вашему менеджеру";
		if (!$error)
		{
		?>
			<div class="b-message-personal">
				<div class="b-message-personal__hgroup">
					<div class="b-popup__title b-title b-title--h2">Ваш проект принят!</div>
					<div class="b-message-personal__intro">В течение одного рабочего дня менеджер подтвердит изменение данных или свяжется с вами для уточнения информации</div>
				</div>
				<div class="b-message-personal__button">
					<span class="btn btn--blue js-close-popup">Закрыть</span>
				</div>
				
				<?if ($userData["MANAGER"]):?>
				<?$APPLICATION->IncludeFile(
					$APPLICATION->GetTemplatePath("/personal/inc/manager.php"),
					Array("userData"=>$userData),
					Array("MODE"=>"php")
				);?>
				<?endif?>
			</div>
	<?
			$_SESSION["project_added"]=true;
			die();
		}
	}
}
$_SESSION["project_added"]=false;
$listFields = $bitrixAPI->getSmartProcessListFields($config["bitrix24"]["smart_process_id"]);
//print_r($listFields);
//print_r($userProjects);
$arSystemType=[
2826 => "СКС, ЛВС - структурированная кабельная система, локальная вычислительная сеть",
2828 => "АТС - телефония",
2830 => "СКУД, СОВ - система контроля и управления доступом, система охраны входов (домофония)",
2832 => "АПС, СОУЭ, АУПТ - автоматическая пожарная сигнализация, система оповещения и управления эвакуацией, автоматические установки пожаротушения",
2834 => "СОТ, ССОИ - система охранного телевидения, система сбора и обработки информации",
2836 => "ИСБ, КСБ - интегрированная/комплексная система безопасности",
2838 => "АСУД, АСУ ТП - автоматизированная система управления диспетчеризацией, автоматизированная система управления технологическим процессом",
2840 => "Другое",

];
?>
<div class="b-popup b-popup--add-project" id="form__add-project">
    <form class="b-form-project b-form" method="post" data-href-ajax="/personal/ajax/add-project.php" name="add-project">
		<?=bitrix_sessid_post()?>
        <div class="b-form-project__head">
            <div class="b-popup__title b-title b-title--h2" data-name>Регистрация проекта</div>
			<?if ($error):?><div class="b-form__intro"><?=ShowError($error);?></div><?endif;?>
            <div class="b-form__clue b-form__clue--warning">
                <?=template_svg("b-form__clue-graphic","0 0 512 512","i-warning")?>
                <div class="b-form__clue-desc">Чем подробнее Вы укажете данные по объекту, тем больше гарантий мы сможем предоставить для защиты вашего проекта</div>
            </div>
        </div>
		
		<div class="b-form__list b-form__list--full">
            <div class="b-form__item">
                <label class="b-form__label b-form__label--required" for="UF_ORGANIZATION_NAME"><?=$userProjects["FIELD"]["UF_ORGANIZATION_NAME"]?></label>
                <input type="text" placeholder="Например, АНО «Московский спорт»" id="UF_ORGANIZATION_NAME" class="b-inp" name="PROP[UF_ORGANIZATION_NAME]" value="<?=$props["UF_ORGANIZATION_NAME"]?>" data-validation="length" data-validation-length="min2">
            </div>
            <div class="b-form__item">
                <label class="b-form__label b-form__label--required" for="UF_OBJECT_NAME"><?=$userProjects["FIELD"]["UF_OBJECT_NAME"]?></label>
                <input type="text" placeholder="Например, Дворец спорта «Мегаспорт»" id="UF_OBJECT_NAME" class="b-inp" name="PROP[UF_OBJECT_NAME]" value="<?=$props["UF_OBJECT_NAME"]?>" data-validation="length" data-validation-length="min2">
            </div>

            <div class="b-form__item">
                <label class="b-form__label b-form__label--required" for="UF_REQUEST_TYPE"><?=$userProjects["FIELD"]["UF_REQUEST_TYPE"]?></label>
                <div class="b-select" data-select>
                    <select name="PROP[UF_REQUEST_TYPE]" class="b-select__default js-select" id="UF_REQUEST_TYPE" data-validation="select">
						<?$ar=$listFields[$mapping["request_type"]]["values"]?>
                        <option value="0">Выберите из списка</option>
						<?foreach($ar as $k=>$v):?>
                        <option <?if($props["UF_REQUEST_TYPE"]==$k) echo 'selected '?>value="<?=$k?>"><?=$v?></option>
						<?endforeach?>
                    </select>
                </div>
            </div>

            <div class="b-form__item">
                <div class="b-form__checkboxs">
                    <div class="b-form__label b-form__label--required"><?=$userProjects["FIELD"]["UF_SYSTEM_TYPE"]?></div>
					<?$ar=$listFields[$mapping["system_types"]]["values"]?>
                    <?foreach ($ar as $k=>$v):?>
						<?if ($arSystemType[$k]) $v=$arSystemType[$k]?>
						<span class="b-checkbox">
							<input
									type="checkbox"
									name="PROP[UF_SYSTEM_TYPE][]"
									id="UF_SYSTEM_TYPE-<?=$k?>"
									class="b-checkbox__inp"
									data-validation="checkbox_group"
									data-validation-qty="min1"
									value="<?=$k?>"
									<?=in_array($k, (array)$props["UF_SYSTEM_TYPE"]) ? 'checked' :''?>
							/>
							<label class="b-checkbox__label" for="UF_SYSTEM_TYPE-<?=$k?>"><?=$v?></label>
						</span>
					<?endforeach;?>
			    </div>
            </div>
			<div class="b-form__item">
                <label class="b-form__label b-form__label--required" for="UF_LOCATION"><?=$userProjects["FIELD"]["UF_LOCATION"]?></label>
                <input type="text" placeholder="Местонахождение объекта" id="UF_LOCATION" class="b-inp" name="PROP[UF_LOCATION]" value="<?=$props["UF_LOCATION"]?>" data-validation="length" data-validation-length="min2">
            </div>			
		
			<div class="b-form__item">
				<label class="b-form__label<?/* b-form__label--required*/?>" for="UF_EQUIPMENT_LIST"><?=$userProjects["FIELD"]["UF_EQUIPMENT_LIST"]?></label>
				<div class="b-form-file" data-files-contain data-files-class-filled="b-form-file--filled">
					<div class="b-form-file__visual">
						<input type="file" name="UF_EQUIPMENT_LIST[]" <?/*data-validation="file"*/?> id="UF_EQUIPMENT_LIST" class="b-form-file__visual-inp js-file-upload" multiple accept=".pdf,.docx,.doc">
						<div class="b-form-file__visual-desc">
							<div class="b-form-file__visual-desc___name">Добавьте файл</div>
							<div class="b-form-file__visual-desc___button">
								<span class="b-btn b-btn--blue b-btn--small-2">
									<?=template_svg("b-btn__icon","0 0 100 100","i-file-fastening")?>
									<span class="b-btn__desc">Прикрепить</span>
								</span>
							</div>
						</div>
					</div>
					<div class="b-form-file__list" data-files></div>
				</div>
			</div>					
			<div class="b-form__item">
				<label class="b-form__label" for="UF_EQUIPMENT_LIST_TEXT"><?=$userProjects["FIELD"]["UF_EQUIPMENT_LIST_TEXT"]?></label>
				<textarea name="PROP[UF_EQUIPMENT_LIST_TEXT]" id="UF_EQUIPMENT_LIST_TEXT" placeholder="Опишите состав оборудования из спецификации проекта" class="b-textarea"><?=$props["UF_EQUIPMENT_LIST_TEXT"]?></textarea>
			</div>
			<div class="b-form__item">
				<label class="b-form__label" for="UF_TECHNICAL_DESCRIPTION"><?=$userProjects["FIELD"]["UF_TECHNICAL_DESCRIPTION"]?></label>
				<textarea name="PROP[UF_TECHNICAL_DESCRIPTION]" id="UF_TECHNICAL_DESCRIPTION" placeholder="Опишите важные особенности, если есть. Например, видеоаналитика, распознавание лиц, государственных регистрационных знаков или интеграция" class="b-textarea"><?=$props["UF_TECHNICAL_DESCRIPTION"]?></textarea>
			</div>
			<div class="b-form__item">
				<label class="b-form__label" for="UF_COMPETITORS"><?=$userProjects["FIELD"]["UF_COMPETITORS"]?></label>
				<textarea name="PROP[UF_COMPETITORS]" id="UF_COMPETITORS" placeholder="Опишите важных конкурентов и оборудование с которым они могут зайти в проект" class="b-textarea"><?=$props["UF_COMPETITORS"]?></textarea>
			</div>					
			<div class="b-form__item">
				<label class="b-form__label b-form__label--required" for="UF_IMPLEMENTATION_DATE"><?=$userProjects["FIELD"]["UF_IMPLEMENTATION_DATE"]?></label>
				<input type="text" placeholder="Предполагаемая дата реализации" id="UF_IMPLEMENTATION_DATE" class="b-inp" name="PROP[UF_IMPLEMENTATION_DATE]" value="<?=$props["UF_IMPLEMENTATION_DATE"]?>" data-validation="length" data-validation-length="min2">
			</div>

            <div class="b-form__clue b-form__clue--information">
                <?=template_svg("b-form__clue-graphic","0 0 512 512","i-info-round")?>
                <div class="b-form__clue-desc">Зарегистрировав проект вы получите дополнительную скидку на оборудование. Кроме скидки за регистрацию проекта, вы также можете получить маркетинговую скидку, если вашем проекте будут присутствовать интересные технические решения или сам объект представляет культурную или социальную значимость.</div>
            </div>
            <div class="b-form__item">
                <span class="b-checkbox">
                    <input
                            type="checkbox"
                            name="PROP[UF_MARKETING_DISCOUNT]"
                            id="UF_MARKETING_DISCOUNT"
                            class="b-checkbox__inp"
                    />
                    <label class="b-checkbox__label" for="UF_MARKETING_DISCOUNT">Хочу маркетинговую скидку</label>
                </span>
            </div>
			
            </div>
            <div class="b-form__item">
                <span class="b-form__label">Поля отмеченные (<span class="b-color b-color--red">*</span>) обязательны для заполнения</span>
            </div>
            <div class="b-form__item">
                <button class="b-btn b-btn--blue" name="send" type="submit">Отправить заявку</button>
            </div>
		</div>
		
        <?/*?>
		<div class="b-form__list b-form__list--full">
            <div class="b-form__item">
                <label class="b-form__label b-form__label--required" for="area_1">Название организации конечного заказчика</label>
                <input type="text" placeholder="Например, АНО «Московский спорт»" id="area_1" class="b-inp" name="area_1" data-validation="length" data-validation-length="min2">
            </div>
            <div class="b-form__item">
                <label class="b-form__label b-form__label--required" for="area_2">Название/тип объекта</label>
                <input type="text" placeholder="Например, Дворец спорта «Мегаспорт»" id="area_2" class="b-inp" name="area_2" data-validation="length" data-validation-length="min2">
            </div>
            <div class="b-form__item">
                <label class="b-form__label b-form__label--required" for="area_3">Тип запроса</label>
                <div class="b-select" data-select>
                    <select name="area_3" class="b-select__default js-select" id="area_3" data-validation="select">
                        <option value="0">Выберите из списка</option>
                        <option value="Выбор 1">Выбор 1</option>
                        <option value="Выбор 2">Выбор 2</option>
                        <option value="Выбор 3">Выбор 3</option>
                        <option value="Выбор 4">Выбор 4</option>
                        <option value="Выбор 5">Выбор 5</option>
                    </select>
                </div>
            </div>
            <div class="b-form__item">
                <div class="b-form__checkboxs">
                    <div class="b-form__label b-form__label--required">Тип запроса</div>
                    <span class="b-checkbox">
                        <input
                                type="checkbox"
                                name="area_4[]"
                                id="area_4-1"
                                class="b-checkbox__inp"
                                data-validation="checkbox_group"
                                data-validation-qty="min1"
                        />
                        <label class="b-checkbox__label" for="area_4-1">СКС, ЛВС - структурированная кабельная система, локальная вычислительная сеть</label>
                    </span>
                    <span class="b-checkbox">
                        <input
                                type="checkbox"
                                name="area_4[]"
                                id="area_4-2"
                                class="b-checkbox__inp"
                        />
                        <label class="b-checkbox__label" for="area_4-2">АТС - телефония</label>
                    </span>
                    <span class="b-checkbox">
                        <input
                                type="checkbox"
                                name="area_4[]"
                                id="area_4-3"
                                class="b-checkbox__inp"
                        />
                        <label class="b-checkbox__label" for="area_4-3">СКУД, СОВ - система контроля и управления доступом, система охраны входов (домофония)</label>
                    </span>
                    <span class="b-checkbox">
                        <input
                                type="checkbox"
                                name="area_4[]"
                                id="area_4-4"
                                class="b-checkbox__inp"
                        />
                        <label class="b-checkbox__label" for="area_4-4">АПС, СОУЭ, АУПТ - автоматическая пожарная сигнализация, система оповещения и управления эвакуацией, автоматические установки пожаротушения</label>
                    </span>
                    <span class="b-checkbox">
                        <input
                                type="checkbox"
                                name="area_4[]"
                                id="area_4-5"
                                class="b-checkbox__inp"
                        />
                        <label class="b-checkbox__label" for="area_4-5">СОТ, ССОИ - система охранного телевидения, система сбора и обработки информации</label>
                    </span>
                    <span class="b-checkbox">
                        <input
                                type="checkbox"
                                name="area_4[]"
                                id="area_4-6"
                                class="b-checkbox__inp"
                        />
                        <label class="b-checkbox__label" for="area_4-6">ИСБ, КСБ - интегрированная/комплексная система безопасности</label>
                    </span>
                    <span class="b-checkbox">
                        <input
                                type="checkbox"
                                name="area_4[]"
                                id="area_4-7"
                                class="b-checkbox__inp"
                        />
                        <label class="b-checkbox__label" for="area_4-7">АСУД, АСУ ТП - автоматизированная система управления диспетчеризацией, автоматизированная система управления технологическим процессом</label>
                    </span>
                    <span class="b-checkbox">
                        <input
                                type="checkbox"
                                name="area_4[]"
                                id="area_4-8"
                                class="b-checkbox__inp"
                        />
                        <label class="b-checkbox__label" for="area_4-8">Другое</label>
                    </span>
                </div>
            </div>
            <div class="b-form__item">
                <label class="b-form__label b-form__label--required" for="area_5">Местонахождение объекта</label>
                <input type="text" placeholder="Начните вводить адрес" id="area_5" class="b-inp" name="area_5" data-validation="length" data-validation-length="min2">
            </div>
            <div class="b-form__item">
                <label class="b-form__label b-form__label--required" for="area_6">Перечень оборудования</label>
                <div class="b-form-file" data-files-contain data-files-class-filled="b-form-file--filled">
                    <div class="b-form-file__visual">
                        <input type="file" name="files[]" data-validation="file" id="area_6" class="b-form-file__visual-inp js-file-upload" multiple accept=".pdf,.docx,.doc">
                        <div class="b-form-file__visual-desc">
                            <div class="b-form-file__visual-desc___name">Добавьте файл(ы)</div>
                            <div class="b-form-file__visual-desc___button">
                                <span class="b-btn b-btn--blue b-btn--small-2">
                                    <?=template_svg("b-btn__icon","0 0 100 100","i-file-fastening")?>
                                    <span class="b-btn__desc">Прикрепить</span>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="b-form-file__list" data-files></div>
                </div>
            </div>
            <div class="b-form__item">
                <label class="b-form__label" for="area_7">Техническое описание проекта</label>
                <textarea name="area_7" id="area_7" placeholder="Опишите важные особенности, если есть. Например, видеоаналитика, распознавание лиц, государственных регистрационных знаков или интеграция" class="b-textarea"></textarea>
            </div>
            <div class="b-form__item">
                <label class="b-form__label" for="area_8">Возможные конкуренты</label>
                <textarea name="area_8" id="area_8" placeholder="Опишите важных конкурентов и оборудование с которым они могут зайти в проект" class="b-textarea"></textarea>
            </div>
            <div class="b-form__item">
                <label class="b-form__label" for="area_9">Предполагаемая дата реализации</label>
                <div class="b-select" data-select>
                    <select name="area_9" class="b-select__default js-select" id="area_9">
                        <option value="Выберите дату">Выберите дату</option>
                        <option value="Выбор 1">Выбор 1</option>
                        <option value="Выбор 2">Выбор 2</option>
                        <option value="Выбор 3">Выбор 3</option>
                        <option value="Выбор 4">Выбор 4</option>
                        <option value="Выбор 5">Выбор 5</option>
                    </select>
                </div>
            </div>
            <div class="b-form__clue b-form__clue--information">
                <?=template_svg("b-form__clue-graphic","0 0 512 512","i-info-round")?>
                <div class="b-form__clue-desc">Зарегистрировав проект вы получите дополнительную скидку на оборудование. Кроме скидки за регистрацию проекта, вы также можете получить маркетинговую скидку, если вашем проекте будут присутствовать интересные технические решения или сам объект представляет культурную или социальную значимость.</div>
            </div>
            <div class="b-form__item">
                <span class="b-checkbox">
                    <input
                            type="checkbox"
                            name="area_10"
                            id="area_10"
                            class="b-checkbox__inp"
                    />
                    <label class="b-checkbox__label" for="area_10">Хочу маркетинговую скидку</label>
                </span>
            </div>
            <div class="b-form__item">
                <span class="b-form__label">Поля отмеченные (<span class="b-color b-color--red">*</span>) обязательны для заполнения</span>
            </div>
            <div class="b-form__item">
                <button class="b-btn b-btn--blue" name="send" type="submit">Отправить заявку</button>
            </div>
        </div>
		<?*/?>
    </form>
</div>