<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED!==true)die();

use Bitrix\Main;
use Bitrix\UI;
use Bitrix\Ui\EntityForm\Scope;

Main\Loader::includeModule('ui');

/**
 * Class UIFormComponent
 */
class UIFormComponent extends \CBitrixComponent
{
	public const COLUMN_DEFAULT = 'default_column';
	public const SECTION_MAIN = 'main';
	public const SECTION_REQUIRED = 'required';
	public const SECTION_ADDITIONAL = 'additional';

	protected const COLUMN_TYPE = 'column';
	protected const SECTION_TYPE = 'section';
	protected const INCLUDED_AREA_TYPE = 'included_area';

	/** @var int */
	protected $userID = 0;
	/** @var string */
	protected $entityTypeName = '';
	/** @var int */
	protected $entityID = 0;
	/** @var string */
	protected $guid = '';
	/** @var string */
	protected $configID = '';
	protected $configuration;

	/**
	 * Execute component.
	 */
	public function executeComponent()
	{
		$this->initialize();
		$this->emitOnUIFormInitializeEvent();
		$this->includeComponentTemplate();
	}

	protected function getConfiguration(): UI\Form\EntityEditorConfiguration
	{
		if($this->configuration === null)
		{
			$this->configuration = new UI\Form\EntityEditorConfiguration($this->getConfigurationCategoryName());
		}

		return $this->configuration;
	}

	protected function getConfigurationCategoryName(): string
	{
		return 'ui.form.editor';
	}

	protected function getConfigurationOptionCategoryName(): string
	{
		return 'ui.entity.editor';
	}

	protected function emitOnUIFormInitializeEvent()
	{
		$event = new Main\Event('ui', 'onUIFormInitialize', ['TEMPLATE' => $this->getTemplateName()]);
		$event->send();
	}

	protected function getDefaultParameters(): array
	{
		return [
			'GUID' => 'form_editor',
			'CONFIG_ID' => null,
			'READ_ONLY' => null,
			'INITIAL_MODE' => '',
			'ENABLE_MODE_TOGGLE' => true,
			'ENABLE_CONFIG_CONTROL' => true,
			'ENABLE_VISIBILITY_POLICY' => true,
			'ENABLE_TOOL_PANEL' => true,
			'ENABLE_BOTTOM_PANEL' => true,
			'ENABLE_FIELDS_CONTEXT_MENU' => true,
			'IS_EMBEDDED' => null,
			'ENTITY_TYPE_NAME' => '',
			'ENTITY_ID' => 0,
			'IS_IDENTIFIABLE_ENTITY' => true,
			'ENTITY_DATA' => [],
			'ENTITY_FIELDS' => [],
			'ENTITY_CONFIG' => [],
			'ENTITY_VALIDATORS' => [],
			'DETAIL_MANAGER_ID' => '',
			'ENTITY_CONTROLLERS' => [],
			'ENTITY_CONFIG_SCOPE' => '',
			'FORCE_DEFAULT_SECTION_NAME' => false,
			'FORCE_DEFAULT_CONFIG' => false,
			'ENABLE_CONFIG_SCOPE_TOGGLE' => true,
			'ENABLE_AJAX_FORM' => true,
			'ENABLE_REQUIRED_USER_FIELD_CHECK' => true,
			'ENABLE_USER_FIELD_CREATION' => false,
			'ENABLE_USER_FIELD_MANDATORY_CONTROL' => true,
			'USER_FIELD_ENTITY_ID' => '',
			'USER_FIELD_PREFIX' => '',
			'USER_FIELD_CREATE_PAGE_URL' => '',
			'USER_FIELD_CREATE_SIGNATURE' => '',
			'ENABLE_SETTINGS_FOR_ALL' => false,
			'ENABLE_SECTION_EDIT' => false,
			'ENABLE_SECTION_CREATION' => false,
			'ENABLE_SECTION_DRAG_DROP' => true,
			'ENABLE_FIELD_DRAG_DROP' => true,
			'SERVICE_URL' => '',
			'EXTERNAL_CONTEXT_ID' => '',
			'CONTEXT_ID' => '',
			'CONTEXT' => [],
			'COMPONENT_AJAX_DATA' => [],
			'SCOPE' => null,
			'SCOPE_PREFIX' => '',
		];
	}

	protected function prepareParameters(array $params): array
	{
		$result = [];

		foreach ($this->getDefaultParameters() as $name => $defaultValue) {
			$nameInParams = '~' . $name;
			if (is_array($defaultValue) && isset($params[$nameInParams]) && is_array($params[$nameInParams])) {
				$result[$name] = $params[$nameInParams];
				continue;
			}

			if(isset($params[$nameInParams]))
			{
				if($defaultValue === 0)
				{
					$value = (int) $params[$nameInParams];
				}
				else
				{
					$value = $params[$nameInParams];
				}
			}
			else
			{
				$value = $defaultValue;
			}
			$result[$name] = $value;
		}

		return $result;
	}

	protected function getSavedScopeAndConfiguration(
		UI\Form\EntityEditorConfiguration $configuration,
		$configScope,
		bool $isForceDefaultConfig
	): array
	{
		$scopeConfigId = (empty($this->arResult['SCOPE_PREFIX'])
			? $this->configID
			: $this->arResult['SCOPE_PREFIX']
		);
		if (!$configScope)
		{
			$configScope = UI\Form\EntityEditorConfigScope::UNDEFINED;
		}
		if (!UI\Form\EntityEditorConfigScope::isDefined($configScope))
		{
			$configScope = $configuration->getScope($scopeConfigId);
		}

		if (is_array($configScope))
		{
			$userScopeId = $configScope['userScopeId'];
			$configScope = $configScope['scope'];
		}
		else
		{
			$userScopeId = null;
		}

		$userScopes = (
			isset($scopeConfigId)
				? Scope::getInstance()->getUserScopes($scopeConfigId, ($this->arParams['MODULE_ID'] ?? null))
				: null
		);

		$config = null;
		if (!$isForceDefaultConfig)
		{
			if($configScope === UI\Form\EntityEditorConfigScope::CUSTOM)
			{
				$config = Scope::getInstance()->getScopeById($userScopeId);
				if(!$config)
				{
					$configScope = UI\Form\EntityEditorConfigScope::UNDEFINED;
				}
			}
			if (!$config && UI\Form\EntityEditorConfigScope::isDefined($configScope))
			{
				$config = $configuration->get($this->configID, $configScope);
			}
			elseif(!$config)
			{
				//Try to resolve current scope by stored configuration
				$config = $configuration->get($this->configID, UI\Form\EntityEditorConfigScope::PERSONAL);
				if (is_array($config) && !empty($config))
				{
					$configScope = UI\Form\EntityEditorConfigScope::PERSONAL;
				}
				else
				{
					$config = $configuration->get($this->configID, UI\Form\EntityEditorConfigScope::COMMON);
					$configScope = UI\Form\EntityEditorConfigScope::COMMON;
				}
			}
		}

		if($configScope === UI\Form\EntityEditorConfigScope::UNDEFINED)
		{
			$configScope = is_array($configuration->get($this->configID, UI\Form\EntityEditorConfigScope::PERSONAL))
				? UI\Form\EntityEditorConfigScope::PERSONAL
				: UI\Form\EntityEditorConfigScope::COMMON;
		}

		return [$configScope, $config, $userScopes, $userScopeId];
	}

	protected function processParamsConfig(
		array $config,
		bool $isForceDefaultSectionName,
		array $entityConfig = []
	): array
	{
		$defaultConfig = [];
		if (!is_array($config) || empty($config))
		{
			$config = $entityConfig;
		}
		elseif (!empty($entityConfig))
		{
			foreach($this->arParams['~ENTITY_CONFIG'] as $section)
			{
				$defaultConfig[$section['name']] = $section;
			}
		}

		if (!empty($defaultConfig) && $isForceDefaultSectionName)
		{
			foreach ($config as $key => $section)
			{
				if (isset($defaultConfig[$section["name"]]))
				{
					$config[$key]["title"] = $defaultConfig[$section["name"]]["title"];
				}
			}
		}

		return [$config, $defaultConfig];
	}

	protected function getFieldsInfo(array $entityFields, array $entityData): array
	{
		$availableFields = [];
		$requiredFields = [];
		$hasEmptyRequiredFields = false;
		$htmlFieldNames = [];
		foreach($entityFields as $field)
		{
			$name = $field['name'] ?? '';
			if($name === '')
			{
				continue;
			}

			$fieldType = $field['type'] ?? '';
			if($fieldType === 'html')
			{
				$htmlFieldNames[] = $name;
			}

			$availableFields[$name] = $field;
			if(isset($field['required']) && $field['required'] === true)
			{
				$requiredFields[$name] = $field;
				if($hasEmptyRequiredFields)
				{
					continue;
				}

				//HACK: Skip if user field of type Boolean. Absence of value is treated as equivalent to FALSE.
				if($fieldType === 'userField')
				{
					$fieldInfo = $field['data']['fieldInfo'] ?? [];

					if(isset($fieldInfo['USER_TYPE_ID']) && $fieldInfo['USER_TYPE_ID'] === 'boolean')
					{
						continue;
					}
				}

				if(
					isset($entityData[$name]['IS_EMPTY'])
					&& $entityData[$name]['IS_EMPTY']
				)
				{
					$hasEmptyRequiredFields = true;
				}
			}
		}

		return [
			'available' => $availableFields,
			'required' => $requiredFields,
			'hasEmptyRequiredFields' => $hasEmptyRequiredFields,
			'html' => $htmlFieldNames,
		];
	}

	protected function processScheme(
		array $config,
		array $defaultConfig,
		string $configScope,
		array &$fieldsInfo
	): array
	{
		$availableFields = $fieldsInfo['available'];
		$requiredFields = $fieldsInfo['required'];
		$scheme = [];

		$primaryColumnIndex = 0;
		$primarySectionIndex = 0;

		$serviceColumnIndex = 0;
		$serviceSectionIndex = -1;

		foreach ($config as $j => $column)
		{
			$columnScheme = [];

			foreach ($column['elements'] as $i => $section)
			{
				$type = $section['type'] ?? '';

				if ($type !== self::SECTION_TYPE && $type !== self::INCLUDED_AREA_TYPE)
				{
					continue;
				}

				$sectionName = $section['name'] ?? '';

				if ($sectionName === 'main')
				{
					$primaryColumnIndex = $j;
					$primarySectionIndex = $i;
				}
				elseif ($sectionName === 'required')
				{
					$serviceColumnIndex = $j;
					$serviceSectionIndex = $i;
				}

				if (!empty($defaultConfig[$sectionName]['data']))
				{
					$section['data'] = $defaultConfig[$sectionName]['data'];
				}

				$elements = isset($section['elements']) && is_array($section['elements'])
					? $section['elements'] : [];

				$schemeElements = [];

				foreach ($elements as $element)
				{
					$name = $element['name'] ?? '';

					if ($name === '')
					{
						continue;
					}

					$schemeElement = $availableFields[$name];
					$fieldType = $schemeElement['type'] ?? '';

					//User fields in common scope must have original names.
					$title = '';
					if (isset($element['title'])
						&& !($fieldType === 'userField' && $configScope === UI\Form\EntityEditorConfigScope::COMMON)
					)
					{
						$title = $element['title'];
					}

					if ($title !== '')
					{
						if (isset($schemeElement['title']))
						{
							$schemeElement['originalTitle'] = $schemeElement['title'];
						}

						$schemeElement['title'] = $title;
					}

					if (isset($element['optionFlags']))
					{
						$schemeElement['optionFlags'] = (int)$element['optionFlags'];
					}

					$schemeElement['options'] = (isset($element['options']) && is_array($element['options']))
						? $element['options']
						: [];

					$schemeElements[] = $schemeElement;
					unset($availableFields[$name]);

					if (isset($requiredFields[$name]))
					{
						unset($requiredFields[$name]);
					}
				}

				$columnScheme[] = array_merge($section, ['elements' => $schemeElements]);
			}

			$scheme[] = array_merge($column, ['elements' => $columnScheme]);
		}

		$hasEmptyRequiredFields = $fieldsInfo['hasEmptyRequiredFields'];
		$requiredFields = $fieldsInfo['required'];

		//Add section 'Required Fields'
		if(!$this->arResult['READ_ONLY'])
		{
			//Force Edit mode if empty required fields are found.
			if($hasEmptyRequiredFields)
			{
				$this->arResult['INITIAL_MODE'] = 'edit';
			}

			// todo check required fields
			if(!empty($requiredFields))
			{
				$schemeElements = array();
				if($serviceSectionIndex >= 0)
				{
					$section = $config[$serviceColumnIndex][$serviceSectionIndex];
					if(
						isset($scheme[$serviceColumnIndex][$serviceSectionIndex]['elements'])
						&& is_array($scheme[$serviceColumnIndex][$serviceSectionIndex]['elements'])
					)
					{
						$schemeElements = $scheme[$serviceColumnIndex][$serviceSectionIndex]['elements'];
					}
				}
				else
				{
					$section = [
						'name' => 'required',
						'title' => Main\Localization\Loc::getMessage('UI_FORM_REQUIRED_FIELD_SECTION'),
						'type' => self::SECTION_TYPE,
						'elements' => []
					];

					$serviceColumnIndex = $primaryColumnIndex;
					$serviceSectionIndex = $primarySectionIndex + 1;

					array_splice(
						$config[$serviceColumnIndex],
						$serviceSectionIndex,
						0,
						array($section)
					);

					array_splice(
						$scheme[$serviceColumnIndex],
						$serviceSectionIndex,
						0,
						array(array_merge($section, array('elements' => array())))
					);
				}

				foreach($requiredFields as $fieldName => $fieldInfo)
				{
					$section['elements'][] = array('name' => $fieldName);
					$schemeElements[] = $fieldInfo;
				}

				$scheme[$serviceColumnIndex][$serviceSectionIndex]['elements'] = $schemeElements;
			}
		}

		$fieldsInfo['available'] = $availableFields;

		return $scheme;
	}

	protected function prepareConfig()
	{
		$configuration = $this->getConfiguration();

		$isForceDefaultConfig = ((isset($this->arParams['~FORCE_DEFAULT_CONFIG']) && $this->arParams['~FORCE_DEFAULT_CONFIG']));

		[$configScope, $config, $userScopes, $userScopeId] = $this->getSavedScopeAndConfiguration(
			$configuration,
			$this->arResult['SCOPE'],
			$isForceDefaultConfig
		);

		$this->arResult['USER_SCOPES'] = $userScopes;
		$this->arResult['USER_SCOPE_ID'] = $userScopeId;

		$config = $config ?? [];

		[$config, $defaultConfig] = $this->processParamsConfig($config, $this->arResult['FORCE_DEFAULT_SECTION_NAME'], $this->arResult['ENTITY_CONFIG']);

		$fieldsInfo = $this->getFieldsInfo($this->arResult['ENTITY_FIELDS'], $this->arResult['ENTITY_DATA']);

		$config = $this->initializeConfigWithColumns($config);

		$scheme = $this->processScheme($config, $defaultConfig, $configScope, $fieldsInfo);

		$this->arResult['ENTITY_CONFIG_SCOPE'] = $configScope;
		$this->arResult['ENTITY_CONFIG'] = $config;
		$this->arResult['ENTITY_SCHEME'] = $scheme;

		$this->arResult['ENTITY_AVAILABLE_FIELDS'] = array_values($fieldsInfo['available']);
		$this->arResult['ENTITY_HTML_FIELD_NAMES'] = $fieldsInfo['html'];
	}

	protected function loadLanguages(): array
	{
		$languages = [];

		$dbResultLangs = \CLanguage::GetList($by = '', $order = '');
		while($lang = $dbResultLangs->Fetch())
		{
			$languages[] = ['LID' => $lang['LID'], 'NAME' => $lang['NAME']];
		}

		return $languages;
	}

	protected function initialize()
	{
		$this->arResult = $this->prepareParameters($this->arParams);

		$this->guid = $this->arResult['GUID'];
		$this->configID = $this->arResult['CONFIG_ID'] ?? $this->guid;
		$this->arResult['CONFIG_ID'] = $this->configID;

		$this->entityTypeName = $this->arResult['ENTITY_TYPE_NAME'];
		$this->entityID = $this->arResult['ENTITY_ID'];

		$this->prepareConfig();

		if(isset($this->arParams['~ENABLE_CONFIGURATION_UPDATE']))
		{
			$this->arResult['CAN_UPDATE_PERSONAL_CONFIGURATION'] = $this->arParams['~ENABLE_CONFIGURATION_UPDATE'];
			$this->arResult['CAN_UPDATE_COMMON_CONFIGURATION'] = $this->arParams['~ENABLE_CONFIGURATION_UPDATE'];
		}
		else
		{
			$this->arResult['CAN_UPDATE_PERSONAL_CONFIGURATION'] = !isset($this->arParams['~ENABLE_PERSONAL_CONFIGURATION_UPDATE'])
				|| $this->arParams['~ENABLE_PERSONAL_CONFIGURATION_UPDATE'];

			$this->arResult['CAN_UPDATE_COMMON_CONFIGURATION'] = isset($this->arParams['~ENABLE_COMMON_CONFIGURATION_UPDATE'])
				&& $this->arParams['~ENABLE_COMMON_CONFIGURATION_UPDATE'];
		}

		$this->arResult['CONTEXT']['EDITOR_CONFIG_ID'] = $this->configID;

		$this->arResult['LANGUAGES'] = $this->loadLanguages();

		$this->arResult['ENTITY_CONFIG_OPTIONS'] = $this->getEntityConfigOptions();

		$this->arResult['EDITOR_OPTIONS'] = array('show_always' => 'Y');
	}

	protected function getEntityConfigOptions(): array
	{
		$optionId = $this->arParams['~OPTION_PREFIX'] ?? $this->configID;
		$optionId = \Bitrix\UI\Form\EntityEditorConfiguration::prepareOptionsName(
			$optionId,
			$this->arResult['ENTITY_CONFIG_SCOPE'],
			(int) $this->arResult['USER_SCOPE_ID']
		);
		if($this->arResult['ENTITY_CONFIG_SCOPE'] === UI\Form\EntityEditorConfigScope::COMMON)
		{
			$userId = 0;
		}
		else
		{
			$userId = false;
		}

		return \CUserOptions::GetOption(
			$this->getConfigurationOptionCategoryName(),
			$optionId,
			$this->getDefaultEntityConfigOptions(),
			$userId
		);
	}

	protected function getDefaultEntityConfigOptions(): array
	{
		return [];
	}

	private function initializeConfigWithColumns(array $config): array
	{
		$columns = [];
		$elementsWithoutColumn = [];

		foreach ($config as $element)
		{
			$type = $element['type'] ?? '';

			if ($type === self::COLUMN_TYPE)
			{
				if (!isset($element['elements']) || !is_array($element['elements']))
				{
					$element['elements'] = [];
				}

				$columns[] = $element;
			}
			else
			{
				$elementsWithoutColumn[] = $element;
			}
		}

		if (empty($columns))
		{
			$columns = [
				[
					'name' => static::COLUMN_DEFAULT,
					'title' => '',
					'type' => self::COLUMN_TYPE,
					'elements' => [],
				],
			];
		}

		if (!empty($elementsWithoutColumn))
		{
			$columns[0]['elements'] = array_merge($columns[0]['elements'], $elementsWithoutColumn);
		}

		return $columns;
	}
}