<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2013 Bitrix
 */

use Bitrix\Main;
use Bitrix\Main\Authentication\ApplicationPasswordTable;
use Bitrix\Main\Authentication\Internal\UserPasswordTable;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Security\Password;

IncludeModuleLangFile(__FILE__);

global $BX_GROUP_POLICY;
$BX_GROUP_POLICY = array(
	"SESSION_TIMEOUT"	=>	0, //minutes
	"SESSION_IP_MASK"	=>	"0.0.0.0",
	"MAX_STORE_NUM"		=>	10,
	"STORE_IP_MASK"		=>	"0.0.0.0",
	"STORE_TIMEOUT"		=>	60*24*365, //minutes
	"CHECKWORD_TIMEOUT"	=>	60*24*365,  //minutes
	"PASSWORD_LENGTH"	=>	false,
	"PASSWORD_UPPERCASE"	=>	"N",
	"PASSWORD_LOWERCASE"	=>	"N",
	"PASSWORD_DIGITS"	=>	"N",
	"PASSWORD_PUNCTUATION"	=>	"N",
	"PASSWORD_CHANGE_DAYS" => 0,
	"PASSWORD_UNIQUE_COUNT" => 0,
	"LOGIN_ATTEMPTS"	=>	0,
	"BLOCK_LOGIN_ATTEMPTS" => 0,
	"BLOCK_TIME" => 0,
);

class CAllUser extends CDBResult
{
	var $LAST_ERROR = "";
	var $bLoginByHash = false;
	protected $admin = null;
	/** @var Main\Session\SessionInterface  */
	protected static $kernelSession;
	protected static $CURRENT_USER = false;
	protected $justAuthorized = false;
	protected static $userGroupCache = array();

	const STATUS_ONLINE = 'online';
	const STATUS_OFFLINE = 'offline';

	//in seconds
	const PHONE_CODE_OTP_INTERVAL = 30;
	const PHONE_CODE_RESEND_INTERVAL = 60;

	/**
	 * CUser constructor.
	 */
	public function __construct()
	{
		static::$kernelSession = Main\Application::getInstance()->getKernelSession();
		parent::__construct();
	}

	public function GetParam($name)
	{
		if(isset(static::$kernelSession["SESS_AUTH"][$name]))
			return static::$kernelSession["SESS_AUTH"][$name];
		else
			return null;
	}

	public function SetParam($name, $value)
	{
		static::$kernelSession["SESS_AUTH"][$name] = $value;
	}

	public function GetSecurityPolicy()
	{
		if(!is_array($this->GetParam("POLICY")))
		{
			$this->SetParam("POLICY", static::GetGroupPolicy($this->GetID()));
		}
		return $this->GetParam("POLICY");
	}

	public function GetID()
	{
		if(!isset($this))
		{
			trigger_error("Static call CUser::GetID() is deprecated, will be removed soon. Use global \$USER.", E_USER_WARNING);

			global $USER;
			return $USER->GetID();
		}
		return $this->GetParam("USER_ID");
	}

	public function GetLogin()
	{
		if(!isset($this))
		{
			trigger_error("Static call CUser::GetLogin() is deprecated, will be removed soon. Use global \$USER.", E_USER_WARNING);

			global $USER;
			return $USER->GetLogin();
		}
		return $this->GetParam("LOGIN");
	}

	public function GetEmail()
	{
		if(!isset($this))
		{
			trigger_error("Static call CUser::GetEmail() is deprecated, will be removed soon. Use global \$USER.", E_USER_WARNING);

			global $USER;
			return $USER->GetEmail();
		}
		return $this->GetParam("EMAIL");
	}

	public function GetFullName()
	{
		if(!isset($this))
		{
			trigger_error("Static call CUser::GetFullName() is deprecated, will be removed soon. Use global \$USER.", E_USER_WARNING);

			global $USER;
			return $USER->GetFullName();
		}
		return $this->GetParam("NAME");
	}

	public function GetFirstName()
	{
		if(!isset($this))
		{
			trigger_error("Static call CUser::GetFirstName() is deprecated, will be removed soon. Use global \$USER.", E_USER_WARNING);

			global $USER;
			return $USER->GetFirstName();
		}
		return $this->GetParam("FIRST_NAME");
	}

	public function GetLastName()
	{
		if(!isset($this))
		{
			trigger_error("Static call CUser::GetLastName() is deprecated, will be removed soon. Use global \$USER.", E_USER_WARNING);

			global $USER;
			return $USER->GetLastName();
		}
		return $this->GetParam("LAST_NAME");
	}

	public function GetSecondName()
	{
		if(!isset($this))
		{
			trigger_error("Static call CUser::GetSecondName() is deprecated, will be removed soon. Use global \$USER.", E_USER_WARNING);

			global $USER;
			return $USER->GetSecondName();
		}
		return $this->GetParam("SECOND_NAME");
	}

	public function GetFormattedName($bUseBreaks = true, $bHTMLSpec = true)
	{
		return static::FormatName(CSite::GetNameFormat($bUseBreaks),
			array(
				"TITLE" => $this->GetParam("TITLE"),
				"NAME" => $this->GetFirstName(),
				"SECOND_NAME" => $this->GetSecondName(),
				"LAST_NAME" => $this->GetLastName(),
				"LOGIN" => $this->GetLogin(),
			),
			true,
			$bHTMLSpec
		);
	}

	public static function err_mess()
	{
		return "<br>Class: CUser<br>File: ".__FILE__;
	}

	public function Add($arFields)
	{
		/** @global CUserTypeManager $USER_FIELD_MANAGER */
		global $DB, $USER_FIELD_MANAGER, $CACHE_MANAGER;

		$ID = 0;
		if(!$this->CheckFields($arFields))
		{
			$Result = false;
			$arFields["RESULT_MESSAGE"] = &$this->LAST_ERROR;
		}
		else
		{
			unset($arFields["ID"]);
			unset($arFields["STORED_HASH"]);

			$arFields['ACTIVE'] = (is_set($arFields, 'ACTIVE') && $arFields['ACTIVE'] != 'Y'? 'N' : 'Y');
			$arFields['BLOCKED'] = (is_set($arFields, 'BLOCKED') && $arFields['BLOCKED'] == 'Y'? 'Y' : 'N');

			if($arFields["PERSONAL_GENDER"]=="NOT_REF" || ($arFields["PERSONAL_GENDER"]!="M" && $arFields["PERSONAL_GENDER"]!="F"))
				$arFields["PERSONAL_GENDER"] = "";

			$originalPassword = $arFields["PASSWORD"];
			$arFields["PASSWORD"] = Password::hash($arFields["PASSWORD"]);

			$checkword = ($arFields["CHECKWORD"] == ''? md5(uniqid().CMain::GetServerUniqID()) : $arFields["CHECKWORD"]);
			$arFields["CHECKWORD"] = Password::hash($checkword);

			$arFields["~CHECKWORD_TIME"] = $DB->CurrentTimeFunction();

			if(is_set($arFields, "WORK_COUNTRY"))
				$arFields["WORK_COUNTRY"] = intval($arFields["WORK_COUNTRY"]);

			if(is_set($arFields, "PERSONAL_COUNTRY"))
				$arFields["PERSONAL_COUNTRY"] = intval($arFields["PERSONAL_COUNTRY"]);

			if (
				array_key_exists("PERSONAL_PHOTO", $arFields)
				&& is_array($arFields["PERSONAL_PHOTO"])
				&& (
					!array_key_exists("MODULE_ID", $arFields["PERSONAL_PHOTO"])
					|| $arFields["PERSONAL_PHOTO"]["MODULE_ID"] == ''
				)
			)
				$arFields["PERSONAL_PHOTO"]["MODULE_ID"] = "main";

			CFile::SaveForDB($arFields, "PERSONAL_PHOTO", "main");

			if (
				array_key_exists("WORK_LOGO", $arFields)
				&& is_array($arFields["WORK_LOGO"])
				&& (
					!array_key_exists("MODULE_ID", $arFields["WORK_LOGO"])
					|| $arFields["WORK_LOGO"]["MODULE_ID"] == ''
				)
			)
				$arFields["WORK_LOGO"]["MODULE_ID"] = "main";

			CFile::SaveForDB($arFields, "WORK_LOGO", "main");

			$arInsert = $DB->PrepareInsert("b_user", $arFields);

			if(!is_set($arFields, "DATE_REGISTER"))
			{
				$arInsert[0] .= ", DATE_REGISTER";
				$arInsert[1] .= ", ".$DB->GetNowFunction();
			}

			$strSql = "
				INSERT INTO b_user (
					".$arInsert[0]."
				) VALUES (
					".$arInsert[1]."
				)
			";
			$DB->Query($strSql);
			$ID = $DB->LastID();

			$USER_FIELD_MANAGER->Update("USER", $ID, $arFields);

			CAccess::RecalculateForUser($ID, CUserAuthProvider::ID);

			if(is_set($arFields, "GROUP_ID"))
				static::SetUserGroup($ID, $arFields["GROUP_ID"], true);

			if(isset($arFields["PHONE_NUMBER"]) && $arFields["PHONE_NUMBER"] <> '')
			{
				Main\UserPhoneAuthTable::add(array(
					"USER_ID" => $ID,
					"PHONE_NUMBER" => $arFields["PHONE_NUMBER"],
				));
			}

			//update digest hash for http digest authorization
			if(COption::GetOptionString('main', 'use_digest_auth', 'N') == 'Y')
			{
				static::UpdateDigest($ID, $originalPassword);
			}

			//history of passwords
			UserPasswordTable::add([
				"USER_ID" => $ID,
				"PASSWORD" => $arFields["PASSWORD"],
				"DATE_CHANGE" => new Main\Type\DateTime(),
			]);

			if(Main\Config\Option::get("main", "user_profile_history") === "Y")
			{
				Main\UserProfileHistoryTable::addHistory($ID, Main\UserProfileHistoryTable::TYPE_ADD);
			}

			$Result = $ID;
			$arFields["ID"] = &$ID;
			$arFields["CHECKWORD"] = $checkword;
		}

		$arFields["RESULT"] = &$Result;

		foreach (GetModuleEvents("main", "OnAfterUserAdd", true) as $arEvent)
			ExecuteModuleEventEx($arEvent, array(&$arFields));

		if($ID > 0 && defined("BX_COMP_MANAGED_CACHE"))
		{
			$isRealUser = !$arFields['EXTERNAL_AUTH_ID'] || !in_array($arFields['EXTERNAL_AUTH_ID'], \Bitrix\Main\UserTable::getExternalUserTypes());

			$CACHE_MANAGER->ClearByTag("USER_CARD_".intval($ID / TAGGED_user_card_size));
			$CACHE_MANAGER->ClearByTag($isRealUser? "USER_CARD": "EXTERNAL_USER_CARD");

			$CACHE_MANAGER->ClearByTag("USER_NAME_".$ID);
			$CACHE_MANAGER->ClearByTag($isRealUser? "USER_NAME": "EXTERNAL_USER_NAME");
		}

		\Bitrix\Main\UserTable::indexRecord($ID);

		return $Result;
	}

	public static function GetDropDownList($strSqlSearch="and ACTIVE='Y'", $strSqlOrder="ORDER BY ID, NAME, LAST_NAME")
	{
		global $DB;
		$err_mess = (static::err_mess())."<br>Function: GetDropDownList<br>Line: ";
		$strSql = "
			SELECT
				ID as REFERENCE_ID,
				concat('[',ID,'] (',LOGIN,') ',ifnull(NAME,''),' ',ifnull(LAST_NAME,'')) as REFERENCE
			FROM
				b_user
			WHERE
				1=1
			$strSqlSearch
			$strSqlOrder
			";
		$res = $DB->Query($strSql, false, $err_mess.__LINE__);
		return $res;
	}

	public static function GetList(&$by, &$order, $arFilter=Array(), $arParams=Array())
	{
		/** @global CUserTypeManager $USER_FIELD_MANAGER */
		global $DB, $USER_FIELD_MANAGER;

		$err_mess = (static::err_mess())."<br>Function: GetList<br>Line: ";

		if (is_array($by))
		{
			$bSingleBy = false;
			$arOrder = $by;
		}
		else
		{
			$bSingleBy = true;
			$arOrder = array($by=>$order);
		}

		static $obUserFieldsSql;
		if (!isset($obUserFieldsSql))
		{
			$obUserFieldsSql = new CUserTypeSQL;
			$obUserFieldsSql->SetEntity("USER", "U.ID");
			$obUserFieldsSql->obWhere->AddFields(array(
				"F_LAST_NAME" => array(
					"TABLE_ALIAS" => "U",
					"FIELD_NAME" => "U.LAST_NAME",
					"MULTIPLE" => "N",
					"FIELD_TYPE" => "string",
					"JOIN" => false,
				),
			));
		}
		$obUserFieldsSql->SetSelect($arParams["SELECT"]);
		$obUserFieldsSql->SetFilter($arFilter);
		$obUserFieldsSql->SetOrder($arOrder);

		$arFields_m = array("ID", "ACTIVE", "LAST_LOGIN", "LOGIN", "EMAIL", "NAME", "LAST_NAME", "SECOND_NAME", "TIMESTAMP_X", "PERSONAL_BIRTHDAY", "IS_ONLINE", "IS_REAL_USER");
		$arFields = array(
			"DATE_REGISTER", "PERSONAL_PROFESSION", "PERSONAL_WWW", "PERSONAL_ICQ", "PERSONAL_GENDER", "PERSONAL_PHOTO", "PERSONAL_PHONE", "PERSONAL_FAX",
			"PERSONAL_MOBILE", "PERSONAL_PAGER", "PERSONAL_STREET", "PERSONAL_MAILBOX", "PERSONAL_CITY", "PERSONAL_STATE", "PERSONAL_ZIP", "PERSONAL_COUNTRY", "PERSONAL_NOTES",
			"WORK_COMPANY", "WORK_DEPARTMENT", "WORK_POSITION", "WORK_WWW", "WORK_PHONE", "WORK_FAX", "WORK_PAGER", "WORK_STREET", "WORK_MAILBOX", "WORK_CITY", "WORK_STATE",
			"WORK_ZIP", "WORK_COUNTRY", "WORK_PROFILE", "WORK_NOTES", "ADMIN_NOTES", "XML_ID", "LAST_NAME", "SECOND_NAME", "STORED_HASH", "CHECKWORD_TIME", "EXTERNAL_AUTH_ID",
			"CONFIRM_CODE", "LOGIN_ATTEMPTS", "LAST_ACTIVITY_DATE", "AUTO_TIME_ZONE", "TIME_ZONE", "TIME_ZONE_OFFSET", "PASSWORD", "CHECKWORD", "LID", "LANGUAGE_ID", "TITLE",
		);
		$arFields_all = array_merge($arFields_m, $arFields);

		$arSelectFields = array();
		$online_interval = (array_key_exists("ONLINE_INTERVAL", $arParams) && intval($arParams["ONLINE_INTERVAL"]) > 0 ? $arParams["ONLINE_INTERVAL"] : static::GetSecondsForLimitOnline());
		if (isset($arParams['FIELDS']) && is_array($arParams['FIELDS']) && count($arParams['FIELDS']) > 0 && !in_array("*", $arParams['FIELDS']))
		{
			foreach ($arParams['FIELDS'] as $field)
			{
				$field = strtoupper($field);
				if ($field == 'TIMESTAMP_X' || $field == 'DATE_REGISTER' || $field == 'LAST_LOGIN')
					$arSelectFields[$field] = $DB->DateToCharFunction("U.".$field)." ".$field.", U.".$field." ".$field."_DATE";
				elseif ($field == 'PERSONAL_BIRTHDAY')
					$arSelectFields[$field] = $DB->DateToCharFunction("U.PERSONAL_BIRTHDAY", "SHORT")." PERSONAL_BIRTHDAY, U.PERSONAL_BIRTHDAY PERSONAL_BIRTHDAY_DATE";
				elseif ($field == 'IS_ONLINE')
					$arSelectFields[$field] = "IF(U.LAST_ACTIVITY_DATE > DATE_SUB(NOW(), INTERVAL ".$online_interval." SECOND), 'Y', 'N') IS_ONLINE";
				elseif ($field == 'IS_REAL_USER')
					$arSelectFields[$field] = "IF(U.EXTERNAL_AUTH_ID IN ('".join("', '", static::GetExternalUserTypes())."'), 'N', 'Y') IS_REAL_USER";
				elseif (in_array($field, $arFields_all))
					$arSelectFields[$field] = 'U.'.$field;
			}
		}
		if (empty($arSelectFields))
		{
			$arSelectFields[] = 'U.*';
			$arSelectFields['TIMESTAMP_X'] =	$DB->DateToCharFunction("U.TIMESTAMP_X")." TIMESTAMP_X";
			$arSelectFields['IS_ONLINE'] =	"IF(U.LAST_ACTIVITY_DATE > DATE_SUB(NOW(), INTERVAL ".$online_interval." SECOND), 'Y', 'N') IS_ONLINE";
			$arSelectFields['DATE_REGISTER'] =	$DB->DateToCharFunction("U.DATE_REGISTER")." DATE_REGISTER";
			$arSelectFields['LAST_LOGIN'] =	$DB->DateToCharFunction("U.LAST_LOGIN")." LAST_LOGIN";
			$arSelectFields['PERSONAL_BIRTHDAY'] =	$DB->DateToCharFunction("U.PERSONAL_BIRTHDAY", "SHORT")." PERSONAL_BIRTHDAY";
		}

		$arSqlSearch = Array();
		$strJoin = "";

		if(is_array($arFilter))
		{
			foreach ($arFilter as $key => $val)
			{
				$key = strtoupper($key);
				if(is_array($val))
				{
					if(count($val) <= 0)
						continue;
				}
				elseif
				(
					$key != "LOGIN_EQUAL_EXACT"
					&& $key != "CONFIRM_CODE"
					&& $key != "!CONFIRM_CODE"
					&& $key != "LAST_ACTIVITY"
					&& $key != "!LAST_ACTIVITY"
					&& $key != "LAST_LOGIN"
					&& $key != "!LAST_LOGIN"
					&& $key != "EXTERNAL_AUTH_ID"
					&& $key != "!EXTERNAL_AUTH_ID"
					&& $key != "IS_REAL_USER"
				)
				{
					if((string)$val == '' || $val === "NOT_REF")
						continue;
				}
				$match_value_set = array_key_exists($key."_EXACT_MATCH", $arFilter);
				switch($key)
				{
				case "ID":
					$arSqlSearch[] = GetFilterQuery("U.ID",$val,"N");
					break;
				case ">ID":
					$arSqlSearch[] = "U.ID > ".intval($val);
					break;
				case "!ID":
					$arSqlSearch[] = "U.ID <> ".intval($val);
					break;
				case "ID_EQUAL_EXACT":
					$arSqlSearch[] = "U.ID='".intval($val)."'";
					break;
				case "TIMESTAMP_1":
					$arSqlSearch[] = "U.TIMESTAMP_X >= FROM_UNIXTIME('".MkDateTime(FmtDate($val,"D.M.Y"),"d.m.Y")."')";
					break;
				case "TIMESTAMP_2":
					$arSqlSearch[] = "U.TIMESTAMP_X <= FROM_UNIXTIME('".MkDateTime(FmtDate($val,"D.M.Y")." 23:59:59","d.m.Y")."')";
					break;
				case "TIMESTAMP_X_1":
					$arSqlSearch[] = "U.TIMESTAMP_X >= FROM_UNIXTIME('".MkDateTime(FmtDate($val,"DD.MM.YYYY HH:MI:SS"),"d.m.Y H:i:s")."')";
					break;
				case "TIMESTAMP_X_2":
					$arSqlSearch[] = "U.TIMESTAMP_X <= FROM_UNIXTIME('".MkDateTime(FmtDate($val,"DD.MM.YYYY HH:MI:SS"),"d.m.Y H:i:s")."')";
					break;
				case "LAST_LOGIN_1":
					$arSqlSearch[] = "U.LAST_LOGIN >= FROM_UNIXTIME('".MkDateTime(FmtDate($val,"D.M.Y"),"d.m.Y")."')";
					break;
				case "LAST_LOGIN_2":
					$arSqlSearch[] = "U.LAST_LOGIN <= FROM_UNIXTIME('".MkDateTime(FmtDate($val,"D.M.Y")." 23:59:59","d.m.Y")."')";
					break;
				case "LAST_LOGIN":
					if ($val === false)
						$arSqlSearch[] = "U.LAST_LOGIN IS NULL";
					break;
				case "!LAST_LOGIN":
					if ($val === false)
						$arSqlSearch[] = "U.LAST_LOGIN IS NOT NULL";
					break;
				case "DATE_REGISTER_1":
					$arSqlSearch[] = "U.DATE_REGISTER >= FROM_UNIXTIME('".MkDateTime(FmtDate($val,"D.M.Y"),"d.m.Y")."')";
					break;
				case "DATE_REGISTER_2":
					$arSqlSearch[] = "U.DATE_REGISTER <= FROM_UNIXTIME('".MkDateTime(FmtDate($val,"D.M.Y")." 23:59:59","d.m.Y")."')";
					break;
				case "ACTIVE":
					$arSqlSearch[] = ($val=="Y") ? "U.ACTIVE='Y'" : "U.ACTIVE='N'";
					break;
				case "LOGIN_EQUAL":
					$arSqlSearch[] = GetFilterQuery("U.LOGIN", $val, "N");
					break;
				case "LOGIN":
					$arSqlSearch[] = GetFilterQuery("U.LOGIN", $val);
					break;
				case "EXTERNAL_AUTH_ID":
					if($val <> '')
						$arSqlSearch[] = "U.EXTERNAL_AUTH_ID='".$DB->ForSQL($val, 255)."'";
					else
						$arSqlSearch[] = "(U.EXTERNAL_AUTH_ID IS NULL OR U.EXTERNAL_AUTH_ID='')";
					break;
				case "!EXTERNAL_AUTH_ID":
  					if (
						is_array($val)
						&& count($val) > 0
					)
					{
						$strTmp = "";
						foreach($val as $authId)
						{
							if ($authId <> '')
							{
								$strTmp .= ($strTmp <> '' ? "," : "")."'".$DB->ForSQL($authId, 255)."'";
							}
						}
						if ($strTmp <> '')
						{
							$arSqlSearch[] = "U.EXTERNAL_AUTH_ID NOT IN (".$strTmp.") OR U.EXTERNAL_AUTH_ID IS NULL";
						}
					}
					elseif (!is_array($val))
					{
						if($val <> '')
							$arSqlSearch[] = "U.EXTERNAL_AUTH_ID <> '".$DB->ForSql($val, 255)."' OR U.EXTERNAL_AUTH_ID IS NULL";
						else
							$arSqlSearch[] = "(U.EXTERNAL_AUTH_ID IS NOT NULL AND LENGTH(U.EXTERNAL_AUTH_ID) > 0)";
					}
					break;
				case "LOGIN_EQUAL_EXACT":
					$arSqlSearch[] = "U.LOGIN='".$DB->ForSql($val)."'";
					break;
				case "XML_ID":
					$arSqlSearch[] = "U.XML_ID='".$DB->ForSql($val)."'";
					break;
				case "CONFIRM_CODE":
					if($val <> '')
						$arSqlSearch[] = "U.CONFIRM_CODE='".$DB->ForSql($val)."'";
					else
						$arSqlSearch[] = "(U.CONFIRM_CODE IS NULL OR LENGTH(U.CONFIRM_CODE) <= 0)";
					break;
				case "!CONFIRM_CODE":
					if($val <> '')
						$arSqlSearch[] = "U.CONFIRM_CODE <> '".$DB->ForSql($val)."'";
					else
						$arSqlSearch[] = "(U.CONFIRM_CODE IS NOT NULL AND LENGTH(U.CONFIRM_CODE) > 0)";
					break;
				case "COUNTRY_ID":
				case "WORK_COUNTRY":
					$arSqlSearch[] = "U.WORK_COUNTRY=".intval($val);
					break;
				case "PERSONAL_COUNTRY":
					$arSqlSearch[] = "U.PERSONAL_COUNTRY=".intval($val);
					break;
				case "NAME":
					$arSqlSearch[] = GetFilterQuery("U.NAME, U.LAST_NAME, U.SECOND_NAME", $val);
					break;
				case "NAME_SEARCH":
					$arSqlSearch[] = GetFilterQuery("U.NAME, U.LAST_NAME, U.SECOND_NAME, U.EMAIL, U.LOGIN", $val);
					break;
				case "EMAIL":
					$arSqlSearch[] = GetFilterQuery("U.EMAIL", $val, "Y", array("@","_",".","-"));
					break;
				case "=EMAIL":
					$arSqlSearch[] = "U.EMAIL = '".$DB->ForSQL(trim($val))."'";
					break;
				case "GROUP_MULTI":
				case "GROUPS_ID":
					if(is_numeric($val) && intval($val)>0)
						$val = array($val);
					if(is_array($val) && count($val)>0)
					{
						$ar = array();
						foreach($val as $id)
							$ar[intval($id)] = intval($id);
						$strJoin .=
							" INNER JOIN (SELECT DISTINCT UG.USER_ID FROM b_user_group UG
							WHERE UG.GROUP_ID in (".implode(",", $ar).")
								and (UG.DATE_ACTIVE_FROM is null or	UG.DATE_ACTIVE_FROM <= ".$DB->CurrentTimeFunction().")
								and (UG.DATE_ACTIVE_TO is null or UG.DATE_ACTIVE_TO >= ".$DB->CurrentTimeFunction().")
							) UG ON UG.USER_ID=U.ID ";
					}
					break;
				case "PERSONAL_BIRTHDATE_1":
					$arSqlSearch[] = "U.PERSONAL_BIRTHDATE>=".$DB->CharToDateFunction($val);
					break;
				case "PERSONAL_BIRTHDATE_2":
					$arSqlSearch[] = "U.PERSONAL_BIRTHDATE<=".$DB->CharToDateFunction($val." 23:59:59");
					break;
				case "PERSONAL_BIRTHDAY_1":
					$arSqlSearch[] = "U.PERSONAL_BIRTHDAY>=".$DB->CharToDateFunction($DB->ForSql($val), "SHORT");
					break;
				case "PERSONAL_BIRTHDAY_2":
					$arSqlSearch[] = "U.PERSONAL_BIRTHDAY<=".$DB->CharToDateFunction($DB->ForSql($val), "SHORT");
					break;
				case "PERSONAL_BIRTHDAY_DATE":
					$arSqlSearch[] = "DATE_FORMAT(U.PERSONAL_BIRTHDAY, '%m-%d') = '".$DB->ForSql($val)."'";
					break;
				case "KEYWORDS":
					$arSqlSearch[] = GetFilterQuery(implode(",",$arFields), $val);
					break;
				case "CHECK_SUBORDINATE":
					if(is_array($val))
					{
						$strSubord = "0";
						foreach($val as $grp)
							$strSubord .= ",".intval($grp);
						if(intval($arFilter["CHECK_SUBORDINATE_AND_OWN"]) > 0)
							$arSqlSearch[] = "(U.ID=".intval($arFilter["CHECK_SUBORDINATE_AND_OWN"])." OR NOT EXISTS(SELECT 'x' FROM b_user_group UGS WHERE UGS.USER_ID=U.ID AND UGS.GROUP_ID NOT IN (".$strSubord.")))";
						else
							$arSqlSearch[] = "NOT EXISTS(SELECT 'x' FROM b_user_group UGS WHERE UGS.USER_ID=U.ID AND UGS.GROUP_ID NOT IN (".$strSubord."))";
					}
					break;
				case "NOT_ADMIN":
					if($val !== true)
						break;
					$arSqlSearch[] = "not exists (SELECT * FROM b_user_group UGNA WHERE UGNA.USER_ID=U.ID AND UGNA.GROUP_ID = 1)";
					break;
				case "LAST_ACTIVITY":
					if ($val === false)
						$arSqlSearch[] = "U.LAST_ACTIVITY_DATE IS NULL";
					elseif (intval($val)>0)
						$arSqlSearch[] = "U.LAST_ACTIVITY_DATE > DATE_SUB(NOW(), INTERVAL ".intval($val)." SECOND)";
					break;
				case "!LAST_ACTIVITY":
					if ($val === false)
						$arSqlSearch[] = "U.LAST_ACTIVITY_DATE IS NOT NULL";
					break;
				case "INTRANET_USERS":
					$arSqlSearch[] = "U.ACTIVE = 'Y' AND U.LAST_LOGIN IS NOT NULL AND EXISTS(SELECT 'x' FROM b_utm_user UF1, b_user_field F1 WHERE F1.ENTITY_ID = 'USER' AND F1.FIELD_NAME = 'UF_DEPARTMENT' AND UF1.FIELD_ID = F1.ID AND UF1.VALUE_ID = U.ID AND UF1.VALUE_INT IS NOT NULL AND UF1.VALUE_INT <> 0)";
					break;
				case "IS_REAL_USER":
					if($val === true || $val === 'Y')
					{
						$arSqlSearch[] = "U.EXTERNAL_AUTH_ID NOT IN ('".join("', '", static::GetExternalUserTypes())."') OR U.EXTERNAL_AUTH_ID IS NULL";
					}
					else
					{
						$arSqlSearch[] = "U.EXTERNAL_AUTH_ID IN ('".join("', '", static::GetExternalUserTypes())."')";
					}
					break;
				default:
					if(in_array($key, $arFields))
						$arSqlSearch[] = GetFilterQuery('U.'.$key, $val, ($arFilter[$key."_EXACT_MATCH"]=="Y" && $match_value_set? "N" : "Y"));
				}
			}
		}

		$arSqlOrder = array();
		foreach ($arOrder as $field => $dir)
		{
			$field = strtoupper($field);
			if(strtolower($dir) <> "asc")
			{
				$dir = "desc";
				if ($bSingleBy)
					$order = "desc";
			}

			if($field == "CURRENT_BIRTHDAY")
			{
				$cur_year = intval(date('Y'));
				$arSqlOrder[$field] = "IF(ISNULL(U.PERSONAL_BIRTHDAY), '9999-99-99', IF (
					DATE_FORMAT(U.PERSONAL_BIRTHDAY, '".$cur_year."-%m-%d') < DATE_FORMAT(DATE_ADD(".$DB->CurrentTimeFunction().", INTERVAL ".CTimeZone::GetOffset()." SECOND), '%Y-%m-%d'),
					DATE_FORMAT(U.PERSONAL_BIRTHDAY, '".($cur_year + 1)."-%m-%d'),
					DATE_FORMAT(U.PERSONAL_BIRTHDAY, '".$cur_year."-%m-%d')
				)) ".$dir;
			}
			elseif($field == "IS_ONLINE")
			{
				$arSelectFields[$field] = "IF(U.LAST_ACTIVITY_DATE > DATE_SUB(NOW(), INTERVAL ".$online_interval." SECOND), 'Y', 'N') IS_ONLINE";
				$arSqlOrder[$field] = "IS_ONLINE ".$dir;
			}
			elseif(in_array($field,$arFields_all))
			{
				$arSqlOrder[$field] = "U.".$field." ".$dir;
			}
			elseif($s = $obUserFieldsSql->GetOrder($field))
			{
				$arSqlOrder[$field] = strtoupper($s)." ".$dir;
			}
			elseif(preg_match('/^RATING_(\d+)$/i', $field, $matches))
			{
				$ratingId = intval($matches[1]);
				if ($ratingId > 0)
				{
					$arSqlOrder[$field] = $field."_ISNULL ASC, ".$field." ".$dir;
					$arParams['SELECT'][] = $field;
				}
				else
				{
					$field = "TIMESTAMP_X";
					$arSqlOrder[$field] = "U.".$field." ".$dir;
					if ($bSingleBy)
						$by = strtolower($field);
				}
			}
			elseif ($field == 'FULL_NAME')
			{
				$arSqlOrder[$field] = sprintf(
					"IF(U.LAST_NAME IS NULL OR U.LAST_NAME = '', 1, 0) %1\$s,
					IF(U.LAST_NAME IS NULL OR U.LAST_NAME = '', 1, U.LAST_NAME) %1\$s,
					IF(U.NAME IS NULL OR U.NAME = '', 1, 0) %1\$s,
					IF(U.NAME IS NULL OR U.NAME = '', 1, U.NAME) %1\$s,
					IF(U.SECOND_NAME IS NULL OR U.SECOND_NAME = '', 1, 0) %1\$s,
					IF(U.SECOND_NAME IS NULL OR U.SECOND_NAME = '', 1, U.SECOND_NAME) %1\$s,
					U.LOGIN %1\$s", $dir
				);
			}
		}

		$userFieldsSelect = $obUserFieldsSql->GetSelect();
		$arSqlSearch[] = $obUserFieldsSql->GetFilter();
		$strSqlSearch = GetFilterSqlSearch($arSqlSearch);

		$sSelect = ($obUserFieldsSql->GetDistinct()? "DISTINCT " : "")
			.implode(', ',$arSelectFields)."
			".$userFieldsSelect."
		";

		if (is_array($arParams['SELECT']))
		{
			$arRatingInSelect = array();
			foreach ($arParams['SELECT'] as $column)
			{
				if(preg_match('/^RATING_(\d+)$/i', $column, $matches))
				{
					$ratingId = intval($matches[1]);
					if ($ratingId > 0 && !in_array($ratingId, $arRatingInSelect))
					{
						$sSelect .= ", RR".$ratingId.".CURRENT_POSITION IS NULL as RATING_".$ratingId."_ISNULL";
						$sSelect .= ", RR".$ratingId.".CURRENT_VALUE as RATING_".$ratingId;
						$sSelect .= ", RR".$ratingId.".CURRENT_VALUE as RATING_".$ratingId."_CURRENT_VALUE";
						$sSelect .= ", RR".$ratingId.".PREVIOUS_VALUE as RATING_".$ratingId."_PREVIOUS_VALUE";
						$sSelect .= ", RR".$ratingId.".CURRENT_POSITION as RATING_".$ratingId."_CURRENT_POSITION";
						$sSelect .= ", RR".$ratingId.".PREVIOUS_POSITION as RATING_".$ratingId."_PREVIOUS_POSITION";
						$strJoin .=	" LEFT JOIN  b_rating_results RR".$ratingId."
							ON RR".$ratingId.".RATING_ID=".$ratingId."
							and RR".$ratingId.".ENTITY_TYPE_ID = 'USER'
							and RR".$ratingId.".ENTITY_ID = U.ID ";
						$arRatingInSelect[] = $ratingId;
					}
				}
			}
		}
		$strFrom = "
			FROM
				b_user U
				".$obUserFieldsSql->GetJoin("U.ID")."
				".$strJoin."
			WHERE
				".$strSqlSearch."
			";

		$strSqlOrder = '';
		if (!empty($arSqlOrder))
			$strSqlOrder = 'ORDER BY '.implode(', ', $arSqlOrder);

		$strSql = "SELECT ".$sSelect.$strFrom.$strSqlOrder;

		if(array_key_exists("NAV_PARAMS", $arParams) && is_array($arParams["NAV_PARAMS"]))
		{
			$nTopCount = intval($arParams['NAV_PARAMS']['nTopCount']);
			if($nTopCount > 0)
			{
				$strSql = $DB->TopSql($strSql, $nTopCount);
				$res = $DB->Query($strSql, false, $err_mess.__LINE__);
				if($userFieldsSelect <> '')
					$res->SetUserFields($USER_FIELD_MANAGER->GetUserFields("USER"));
			}
			else
			{
				$res_cnt = $DB->Query("SELECT COUNT(".($obUserFieldsSql->GetDistinct()? "DISTINCT ":"")."U.ID) as C ".$strFrom);
				$res_cnt = $res_cnt->Fetch();
				$res = new CDBResult();
				if($userFieldsSelect <> '')
					$res->SetUserFields($USER_FIELD_MANAGER->GetUserFields("USER"));
				$res->NavQuery($strSql, $res_cnt["C"], $arParams["NAV_PARAMS"]);
			}
		}
		else
		{
			$res = $DB->Query($strSql, false, $err_mess.__LINE__);
			if($userFieldsSelect <> '')
				$res->SetUserFields($USER_FIELD_MANAGER->GetUserFields("USER"));
		}

		$res->is_filtered = IsFiltered($strSqlSearch);
		return $res;
	}

	public static function IsOnLine($id, $interval = null)
	{
		global $DB;

		$id = intval($id);
		if ($id <= 0)
		{
			return false;
		}

		if (is_null($interval))
		{
			$interval = static::GetSecondsForLimitOnline();
		}
		else
		{
			$interval = intval($interval);
			if ($interval <= 0)
			{
				$interval = static::GetSecondsForLimitOnline();
			}
		}

		$dbRes = $DB->Query("SELECT 'x' FROM b_user WHERE ID = ".$id." AND LAST_ACTIVITY_DATE > DATE_SUB(NOW(), INTERVAL ".$interval." SECOND)");
		return $arRes = $dbRes->Fetch()? true: false;
	}

	public function GetUserGroupArray()
	{
		$groups = $this->GetParam("GROUPS");

		if(!is_array($groups) || empty($groups))
		{
			return [2];
		}

		//always unique and sorted, containing group ID=2
		return $groups;
	}

	public function SetUserGroupArray($arr)
	{
		$arr = array_map("intval", $arr);
		$arr = array_filter($arr);
		$arr[] = 2;
		$arr = array_values(array_unique($arr));
		sort($arr);
		$this->SetParam("GROUPS", $arr);
	}

	public function GetUserGroupString()
	{
		return $this->GetGroups();
	}

	public function GetGroups()
	{
		return implode(",", $this->GetUserGroupArray());
	}

	public function RequiredHTTPAuthBasic($Realm = "Bitrix")
	{
		header("WWW-Authenticate: Basic realm=\"{$Realm}\"");
		if(stristr(php_sapi_name(), "cgi") !== false)
			header("Status: 401 Unauthorized");
		else
			header($_SERVER["SERVER_PROTOCOL"]." 401 Unauthorized");

		return false;
	}

	public function LoginByCookies()
	{
		global $USER;

		if(COption::GetOptionString("main", "store_password", "Y") == "Y")
		{
			$bLogout = isset($_REQUEST["logout"]) && (strtolower($_REQUEST["logout"]) == "yes");

			$cookie_prefix = COption::GetOptionString('main', 'cookie_name', 'BITRIX_SM');
			$cookie_login = strval($_COOKIE[$cookie_prefix.'_UIDL']);
			if($cookie_login == '')
			{
				//compatibility reasons
				$cookie_login = strval($_COOKIE[$cookie_prefix.'_LOGIN']);
			}
			$cookie_md5pass = strval($_COOKIE[$cookie_prefix.'_UIDH']);

			if($cookie_login <> '' && $cookie_md5pass <> '' && !$bLogout)
			{
				if(static::$kernelSession["SESS_PWD_HASH_TESTED"] !== md5($cookie_login."|".$cookie_md5pass))
				{
					$USER->LoginByHash($cookie_login, $cookie_md5pass);
					static::$kernelSession["SESS_PWD_HASH_TESTED"] = md5($cookie_login."|".$cookie_md5pass);
				}
			}
		}
	}

	public function LoginByHash($login, $hash)
	{
		/** @global CMain $APPLICATION */
		global $DB, $APPLICATION;

		$result_message = true;
		$user_id = 0;
		$arParams = array(
			"LOGIN" => $login,
			"HASH" => $hash,
		);

		$APPLICATION->ResetException();
		$bOk = true;
		foreach(GetModuleEvents("main", "OnBeforeUserLoginByHash", true) as $arEvent)
		{
			if(ExecuteModuleEventEx($arEvent, array(&$arParams))===false)
			{
				if($err = $APPLICATION->GetException())
					$result_message = array("MESSAGE"=>$err->GetString()."<br>", "TYPE"=>"ERROR");
				else
				{
					$APPLICATION->ThrowException("Unknown error");
					$result_message = array("MESSAGE"=>"Unknown error"."<br>", "TYPE"=>"ERROR");
				}

				$bOk = false;
				break;
			}
		}

		if($bOk && $arParams['HASH'] <> '')
		{
			$strSql =
				"SELECT U.ID, U.ACTIVE, U.STORED_HASH, U.EXTERNAL_AUTH_ID ".
				"FROM b_user U ".
				"WHERE U.LOGIN='".$DB->ForSQL($arParams['LOGIN'], 50)."' ";
			$result = $DB->Query($strSql, false, "FILE: ".__FILE__."<br> LINE: ".__LINE__);

			$bFound = false;
			$bHashFound = false;
			while(($arUser = $result->Fetch()))
			{
				$bFound = true;
				//there is no stored auth for external authorization, but domain spread auth should work
				$bExternal = ($arUser["EXTERNAL_AUTH_ID"] <> '');
				if(
					// if old method (STORED_HASH <> '') and exact match
					($arUser["STORED_HASH"] <> '' && $arUser["STORED_HASH"] == $arParams['HASH'])
					|| // or new method
					(static::CheckStoredHash($arUser["ID"], $arParams['HASH'], $bExternal))
				)
				{
					$bHashFound = true;
					if($arUser["ACTIVE"] == "Y")
					{
						$user_id = $arUser["ID"];
						$this->SetParam("SESSION_HASH", $arParams['HASH']);
						$this->bLoginByHash = true;
						$this->Authorize($arUser["ID"], !$bExternal);
					}
					else
					{
						$APPLICATION->ThrowException(GetMessage("LOGIN_BLOCK"));
						$result_message = array("MESSAGE"=>GetMessage("LOGIN_BLOCK")."<br>", "TYPE"=>"ERROR");
					}
					break;
				}
				else
				{
					//Delete invalid stored auth cookie
					$spread = (COption::GetOptionString("main", "auth_multisite", "N") == "Y"? (Main\Web\Cookie::SPREAD_SITES | Main\Web\Cookie::SPREAD_DOMAIN) : Main\Web\Cookie::SPREAD_DOMAIN);

					$cookie = new Main\Web\Cookie("UIDH", "", 0);
					$cookie->setSpread($spread);
					$cookie->setHttpOnly(true);
					Main\Context::getCurrent()->getResponse()->addCookie($cookie);
				}
			}
			if(!$bFound)
			{
				$APPLICATION->ThrowException(GetMessage("WRONG_LOGIN"));
				$result_message = array("MESSAGE"=>GetMessage("WRONG_LOGIN")."<br>", "TYPE"=>"ERROR");
			}
			elseif(!$bHashFound)
			{
				$APPLICATION->ThrowException(GetMessage("USER_WRONG_HASH"));
				$result_message = array("MESSAGE"=>GetMessage("USER_WRONG_HASH")."<br>", "TYPE"=>"ERROR");
			}
		}

		$arParams["USER_ID"] = $user_id;
		$arParams["RESULT_MESSAGE"] = $result_message;

		foreach (GetModuleEvents("main", "OnAfterUserLoginByHash", true) as $arEvent)
			ExecuteModuleEventEx($arEvent, array(&$arParams));

		if(($result_message !== true) && (COption::GetOptionString("main", "event_log_login_fail", "N") === "Y"))
			CEventLog::Log("SECURITY", "USER_LOGINBYHASH", "main", $login, $result_message["MESSAGE"]);

		return $arParams["RESULT_MESSAGE"];
	}

	public function LoginByHttpAuth()
	{
		$arAuth = CHTTP::ParseAuthRequest();

		foreach(GetModuleEvents("main", "onBeforeUserLoginByHttpAuth", true) as $arEvent)
		{
			$res = ExecuteModuleEventEx($arEvent, array(&$arAuth));
			if($res !== null)
			{
				return $res;
			}
		}

		if(isset($arAuth["basic"]) && $arAuth["basic"]["username"] <> '' && $arAuth["basic"]["password"] <> '')
		{
			// Authorize user, if it is http basic authorization, with no remembering
			if(!$this->IsAuthorized() || $this->GetLogin() <> $arAuth["basic"]["username"])
			{
				return $this->Login($arAuth["basic"]["username"], $arAuth["basic"]["password"], "N");
			}
		}
		elseif(isset($arAuth["digest"]) && $arAuth["digest"]["username"] <> '' && COption::GetOptionString('main', 'use_digest_auth', 'N') == 'Y')
		{
			// Authorize user by http digest authorization
			if(!$this->IsAuthorized() || $this->GetLogin() <> $arAuth["digest"]["username"])
			{
				return $this->LoginByDigest($arAuth["digest"]);
			}
		}

		return null;
	}

	public function LoginByDigest($arDigest)
	{
		//array("username"=>"", "nonce"=>"", "uri"=>"", "response"=>"")
		/** @global CMain $APPLICATION */
		global $DB, $APPLICATION;

		$APPLICATION->ResetException();

		$strSql =
			"SELECT U.ID, U.PASSWORD, UD.DIGEST_HA1, U.EXTERNAL_AUTH_ID ".
			"FROM b_user U LEFT JOIN b_user_digest UD ON UD.USER_ID=U.ID ".
			"WHERE U.LOGIN='".$DB->ForSQL($arDigest["username"])."' ";
		$res = $DB->Query($strSql);

		if($arUser = $res->Fetch())
		{
			$method = (isset($_SERVER['REDIRECT_REQUEST_METHOD']) ? $_SERVER['REDIRECT_REQUEST_METHOD'] : $_SERVER['REQUEST_METHOD']);
			$HA2 = md5($method.':'.$arDigest['uri']);

			if($arUser["EXTERNAL_AUTH_ID"] == '' && $arUser["DIGEST_HA1"] <> '')
			{
				//digest is for internal authentication only
				static::$kernelSession["BX_HTTP_DIGEST_ABSENT"] = false;

				$HA1 = $arUser["DIGEST_HA1"];
				$valid_response = md5($HA1.':'.$arDigest['nonce'].':'.$HA2);

				if($arDigest["response"] === $valid_response)
				{
					//regular user password
					return $this->Login($arDigest["username"], $arUser["PASSWORD"], "N", "N");
				}
			}

			//check for an application password, including external users
			if(($appPassword = ApplicationPasswordTable::findDigestPassword($arUser["ID"], $arDigest)) !== false)
			{
				return $this->Login($arDigest["username"], $appPassword["PASSWORD"], "N", "N");
			}

			if($arUser["DIGEST_HA1"] == '')
			{
				//this indicates that we still have no user digest hash
				static::$kernelSession["BX_HTTP_DIGEST_ABSENT"] = true;
			}
		}

		$APPLICATION->ThrowException(GetMessage("USER_AUTH_DIGEST_ERR"));
		return array("MESSAGE"=>GetMessage("USER_AUTH_DIGEST_ERR")."<br>", "TYPE"=>"ERROR");
	}

	public static function UpdateDigest($ID, $pass)
	{
		global $DB;
		$ID = intval($ID);

		$res = $DB->Query("
			SELECT U.LOGIN, UD.DIGEST_HA1
			FROM b_user U LEFT JOIN b_user_digest UD on UD.USER_ID=U.ID
			WHERE U.ID=".$ID
		);
		if($arRes = $res->Fetch())
		{
			if(defined('BX_HTTP_AUTH_REALM'))
				$realm = BX_HTTP_AUTH_REALM;
			else
				$realm = "Bitrix Site Manager";

			$digest = md5($arRes["LOGIN"].':'.$realm.':'.$pass);

			if($arRes["DIGEST_HA1"] == '')
			{
				//new digest
				$DB->Query("insert into b_user_digest (user_id, digest_ha1) values('".$ID."', '".$DB->ForSQL($digest)."')");
			}
			else
			{
				//update digest (login, password or realm were changed)
				if($arRes["DIGEST_HA1"] !== $digest)
					$DB->Query("update b_user_digest set digest_ha1='".$DB->ForSQL($digest)."' where user_id=".$ID);
			}
		}
	}

	public function LoginHitByHash()
	{
		/** @global CMain $APPLICATION */
		global $DB, $APPLICATION;

		$hash = trim($_REQUEST["bx_hit_hash"]);
		if ($hash == '')
			return false;

		$APPLICATION->ResetException();

		$strSql =
			"SELECT UH.USER_ID AS USER_ID ".
			"FROM b_user_hit_auth UH ".
			"INNER JOIN b_user U ON U.ID = UH.USER_ID AND U.ACTIVE ='Y' ".
			"WHERE UH.HASH = '".$DB->ForSQL($hash, 32)."' ".
			"	AND '".$DB->ForSqlLike($APPLICATION->GetCurPageParam("", array(), true), 500)."' LIKE ".$DB->Concat("UH.URL", "'%'");

		if(!defined("ADMIN_SECTION") || ADMIN_SECTION !== true)
			$strSql .= " AND UH.SITE_ID = '".SITE_ID."'";

		$result = $DB->Query($strSql, false, "FILE: ".__FILE__."<br> LINE: ".__LINE__);
		if($arUser = $result->Fetch())
		{
			setSessionExpired(true);
			$this->Authorize($arUser["USER_ID"], false);

			$DB->Query("UPDATE b_user_hit_auth SET TIMESTAMP_X = ".$DB->GetNowFunction()." WHERE HASH='".$DB->ForSQL($hash, 32)."'");
			return true;
		}
		else
			return false;
	}

	public static function AddHitAuthHash($url, $user_id = false, $site_id = false)
	{
		global $USER, $DB;

		if ($url == '')
			return false;

		if (!$user_id)
			$user_id = $USER->GetID();

		if (!$site_id && (!defined("ADMIN_SECTION") || ADMIN_SECTION !== true))
			$site_id = SITE_ID;

		$hash = false;

		if ($user_id)
		{
			$hash = Main\Security\Random::getString(32);
			$arFields = array(
				'USER_ID' => $user_id,
				'URL' => $DB->ForSqlLike(trim($url), 500),
				'HASH' => $hash,
				'SITE_ID' => $DB->ForSQL(trim($site_id), 2),
				'~TIMESTAMP_X'=>$DB->CurrentTimeFunction()
			);
			$DB->Add("b_user_hit_auth", $arFields);
		}

		return $hash;
	}

	public static function GetHitAuthHash($url_mask, $userID = false)
	{
		global $USER, $DB;

		$url_mask = trim($url_mask);
		if ($url_mask == '')
			return false;

		if (!$userID)
		{
			if (!$USER->IsAuthorized())
				return false;
			else
				$userID = $USER->GetID();
		}

		$strSql =
			"SELECT ID, HASH ".
			"FROM b_user_hit_auth ".
			"WHERE URL = '".$DB->ForSqlLike($url_mask, 500)."' AND USER_ID = ".intval($userID);

		$result = $DB->Query($strSql, false, "FILE: ".__FILE__."<br> LINE: ".__LINE__);
		if($arTmp = $result->Fetch())
			return $arTmp["HASH"];
		else
			return false;
	}

	public static function CleanUpHitAuthAgent()
	{
		global $DB;
		$cleanup_days = COption::GetOptionInt("main", "hit_auth_cleanup_days", 30);
		if($cleanup_days > 0)
		{
			$arDate = localtime(time());
			$date = mktime(0, 0, 0, $arDate[4]+1, $arDate[3]-$cleanup_days, 1900+$arDate[5]);
			$DB->Query("DELETE FROM b_user_hit_auth WHERE TIMESTAMP_X <= ".$DB->CharToDateFunction(ConvertTimeStamp($date, "FULL")));

		}
		return "CUser::CleanUpHitAuthAgent();";
	}

	protected function UpdateSessionData($id, $applicationId = null)
	{
		global $DB, $APPLICATION;

		unset(static::$kernelSession["SESS_OPERATIONS"]);
		unset(static::$kernelSession["MODULE_PERMISSIONS"]);
		$APPLICATION->SetNeedCAPTHA(false);

		$strSql =
			"SELECT U.* ".
			"FROM b_user U  ".
			"WHERE U.ID='".intval($id)."' ";
		$result = $DB->Query($strSql);

		if($arUser = $result->Fetch())
		{
			$data = [
				"AUTHORIZED" => "Y",
				"USER_ID" => $arUser["ID"],
				"LOGIN" => $arUser["LOGIN"],
				"EMAIL" => $arUser["EMAIL"],
				"TITLE" => $arUser["TITLE"],
				"NAME" => $arUser["NAME"].($arUser["NAME"] == '' || $arUser["LAST_NAME"] == ''? "":" ").$arUser["LAST_NAME"],
				"FIRST_NAME" => $arUser["NAME"],
				"SECOND_NAME" => $arUser["SECOND_NAME"],
				"LAST_NAME" => $arUser["LAST_NAME"],
				"PERSONAL_PHOTO" => $arUser["PERSONAL_PHOTO"],
				"PERSONAL_GENDER" => $arUser["PERSONAL_GENDER"],
				"PERSONAL_WWW" => $arUser["PERSONAL_WWW"],
				"EXTERNAL_AUTH_ID" => $arUser["EXTERNAL_AUTH_ID"],
				"XML_ID" => $arUser["XML_ID"],
				"ADMIN" => false,
				"POLICY" => static::GetGroupPolicy($arUser["ID"]),
				"AUTO_TIME_ZONE" => trim($arUser["AUTO_TIME_ZONE"]),
				"TIME_ZONE" => $arUser["TIME_ZONE"],
				"TIME_ZONE_OFFSET" => $arUser["TIME_ZONE_OFFSET"],
				"APPLICATION_ID" => $applicationId,
				"BX_USER_ID" => $arUser["BX_USER_ID"],
				"GROUPS" => Main\UserTable::getUserGroupIds($arUser["ID"]),
				"SESSION_HASH" => $this->GetParam("SESSION_HASH"),
			];

			foreach ($data["GROUPS"] as $groupId)
			{
				if ($groupId == 1)
				{
					$data["ADMIN"] = true;
					break;
				}
			}

			static::$kernelSession["SESS_AUTH"] = $data;

			return $arUser;
		}
		return false;
	}

	/**
	 * Performs the user authorization:
	 *    fills session parameters;
	 *    remembers auth;
	 *    spreads auth through sites.
	 * @param int $id An user ID.
	 * @param bool $bSave Save authorization in cookies.
	 * @param bool $bUpdate Update last login information in DB.
	 * @param string|null $applicationId An application password ID.
	 * @return bool
	 */
	public function Authorize($id, $bSave = false, $bUpdate = true, $applicationId = null)
	{
		global $DB;

		$arUser = $this->UpdateSessionData($id, $applicationId);

		if($arUser !== false)
		{
			self::$CURRENT_USER = false;
			$this->justAuthorized = true;
			$this->SetControllerAdmin(false);

			//sometimes we don't need to update db (REST)
			if($bUpdate)
			{
				$tz = '';
				if(CTimeZone::Enabled())
				{
					if(!CTimeZone::IsAutoTimeZone(trim($arUser["AUTO_TIME_ZONE"])) || CTimeZone::GetCookieValue() !== null)
					{
						$offset = CTimeZone::GetOffset();
						$tz = ', TIME_ZONE_OFFSET = '.$offset;
						$this->SetParam("TIME_ZONE_OFFSET", $offset);
					}
				}

				$bxUid = '';
				if (!empty($_COOKIE['BX_USER_ID']) && preg_match('/^[0-9a-f]{32}$/', $_COOKIE['BX_USER_ID']))
				{
					if ($_COOKIE['BX_USER_ID'] != $arUser['BX_USER_ID'])
					{
						// save new bxuid value
						$bxUid = ", BX_USER_ID = '".$_COOKIE['BX_USER_ID']."'";

						$arUser['BX_USER_ID'] = $_COOKIE['BX_USER_ID'];
						$this->SetParam("BX_USER_ID", $_COOKIE['BX_USER_ID']);
					}
				}

				$DB->Query("
					UPDATE b_user SET
						STORED_HASH = NULL,
						LAST_LOGIN = ".$DB->GetNowFunction().",
						TIMESTAMP_X = TIMESTAMP_X,
						LOGIN_ATTEMPTS = 0
						".$tz."
						".$bxUid."
					WHERE
						ID=".$arUser["ID"]
				);

				if ($bSave || COption::GetOptionString("main", "auth_multisite", "N") == "Y")
				{
					$response = Main\Context::getCurrent()->getResponse();

					$hash = $this->GetSessionHash();
					$secure = (COption::GetOptionString("main", "use_secure_password_cookies", "N")=="Y" && CMain::IsHTTPS());

					if($bSave)
					{
						$period = time()+60*60*24*30*12;
						$spread = Main\Web\Cookie::SPREAD_SITES | Main\Web\Cookie::SPREAD_DOMAIN;
					}
					else
					{
						$period = 0;
						$spread = Main\Web\Cookie::SPREAD_SITES;
					}

					$cookie = new Bitrix\Main\Web\Cookie("UIDH", $hash, $period);

					$cookie->setSecure($secure)
						->setSpread($spread)
						->setHttpOnly(true);

					$response->addCookie($cookie);

					$cookie = new Bitrix\Main\Web\Cookie("UIDL", $arUser["LOGIN"], $period);

					$cookie->setSecure($secure)
						->setSpread($spread)
						->setHttpOnly(true);

					$response->addCookie($cookie);

					$stored_id = static::CheckStoredHash($arUser["ID"], $hash);
					if($stored_id)
					{
						$DB->Query(
							"UPDATE b_user_stored_auth SET
								LAST_AUTH = ".$DB->CurrentTimeFunction().",
								".($this->bLoginByHash? "" : "TEMP_HASH = '".($bSave? "N" : "Y")."', ")."
								IP_ADDR = '".sprintf("%u", ip2long($_SERVER["REMOTE_ADDR"]))."'
							WHERE ID = ".$stored_id
						);
					}
					else
					{
						$arFields = array(
							'USER_ID' => $arUser["ID"],
							'~DATE_REG' => $DB->CurrentTimeFunction(),
							'~LAST_AUTH' => $DB->CurrentTimeFunction(),
							'TEMP_HASH' => ($bSave? "N" : "Y"),
							'~IP_ADDR' => sprintf("%u", ip2long($_SERVER["REMOTE_ADDR"])),
							'STORED_HASH' => $hash
						);
						$stored_id = $DB->Add("b_user_stored_auth", $arFields);
					}
					$this->SetParam("STORED_AUTH_ID", $stored_id);
				}

				if(COption::GetOptionString("main", "event_log_login_success", "N") === "Y")
					CEventLog::Log("SECURITY", "USER_AUTHORIZE", "main", $arUser["ID"], $applicationId);
			}

			$this->admin = null;

			$arParams = array(
				"user_fields" => $arUser,
				"save" => $bSave,
				"update" => $bUpdate,
				"applicationId" => $applicationId,
			);

			foreach (GetModuleEvents("main", "OnAfterUserAuthorize", true) as $arEvent)
				ExecuteModuleEventEx($arEvent, array($arParams));

			foreach (GetModuleEvents("main", "OnUserLogin", true) as $arEvent)
				ExecuteModuleEventEx($arEvent, array($this->GetID(), $arParams));

			if($bUpdate)
			{
				Main\Composite\Engine::onUserLogin();
			}

			//we need it mostrly for the $this->justAuthorized flag
			$this->CheckAuthActions();

			return true;
		}
		return false;
	}

	public function GetSessionHash()
	{
		if($this->GetParam("SESSION_HASH") == '')
		{
			$this->SetParam("SESSION_HASH", md5(uniqid("", true).CMain::GetServerUniqID()));
		}
		return $this->GetParam("SESSION_HASH");
	}

	/** @deprecated */
	public function GetPasswordHash($PASSWORD_HASH)
	{
		$add = COption::GetOptionString("main", "pwdhashadd", "");
		if($add == '')
		{
			$add = md5(uniqid(rand(), true));
			COption::SetOptionString("main", "pwdhashadd", $add);
		}

		return md5($add.$PASSWORD_HASH);
	}

	/** @deprecated */
	public function SavePasswordHash()
	{
		$hash = $this->GetSessionHash();
		$time = time()+60*60*24*30*60;
		$secure = (COption::GetOptionString("main", "use_secure_password_cookies", "N")=="Y" && CMain::IsHTTPS());
		$spread = (COption::GetOptionString("main", "auth_multisite", "N") == "Y"? (Main\Web\Cookie::SPREAD_SITES | Main\Web\Cookie::SPREAD_DOMAIN) : Main\Web\Cookie::SPREAD_DOMAIN);

		$cookie = new Main\Web\Cookie("UIDH", $hash, $time);

		$cookie->setSpread($spread)
			->setSecure($secure)
			->setHttpOnly(true);

		Main\Context::getCurrent()->getResponse()->addCookie($cookie);
	}

	/**
	 * Authenticates the user and then authorizes him
	 * @param string $login
	 * @param string $password
	 * @param string $remember
	 * @param string $password_original
	 * @return array|bool
	 */
	public function Login($login, $password, $remember="N", $password_original="Y")
	{
		global $APPLICATION;

		$result_message = true;
		$user_id = 0;
		$applicationId = null;
		$applicationPassId = null;

		$arParams = array(
			"LOGIN" => &$login,
			"PASSWORD" => &$password,
			"REMEMBER" => &$remember,
			"PASSWORD_ORIGINAL" => &$password_original,
		);

		unset(static::$kernelSession["SESS_OPERATIONS"]);
		unset(static::$kernelSession["MODULE_PERMISSIONS"]);
		$APPLICATION->SetNeedCAPTHA(false);

		$bOk = true;
		$APPLICATION->ResetException();
		foreach(GetModuleEvents("main", "OnBeforeUserLogin", true) as $arEvent)
		{
			if(ExecuteModuleEventEx($arEvent, array(&$arParams))===false)
			{
				if($err = $APPLICATION->GetException())
				{
					$result_message = array("MESSAGE"=>$err->GetString()."<br>", "TYPE"=>"ERROR");
				}
				else
				{
					$APPLICATION->ThrowException("Unknown login error");
					$result_message = array("MESSAGE"=>"Unknown login error"."<br>", "TYPE"=>"ERROR");
				}

				$bOk = false;
				break;
			}
		}

		if($bOk)
		{
			//external authentication
			foreach(GetModuleEvents("main", "OnUserLoginExternal", true) as $arEvent)
			{
				$user_id = ExecuteModuleEventEx($arEvent, array(&$arParams));

				if(isset($arParams["RESULT_MESSAGE"]))
				{
					$result_message = $arParams["RESULT_MESSAGE"];
				}
				if($user_id > 0)
				{
					break;
				}
			}

			if($user_id <= 0)
			{
				//internal authentication OR application password for external user

				$user_id = self::LoginInternal($arParams, $result_message, $applicationId, $applicationPassId);

				if($user_id <= 0)
				{
					//no user found by login - try to find an external user
					foreach(GetModuleEvents("main", "OnFindExternalUser", true) as $arEvent)
					{
						if(($external_user_id = intval(ExecuteModuleEventEx($arEvent, array($arParams["LOGIN"])))) > 0)
						{
							//external user authentication
							//let's try to find application password for the external user
							if(($appPassword = ApplicationPasswordTable::findPassword($external_user_id, $arParams["PASSWORD"], ($arParams["PASSWORD_ORIGINAL"] == "Y"))) !== false)
							{
								//bingo, the user has the application password
								$user_id = $external_user_id;
								$result_message = true;
								$applicationId = $appPassword["APPLICATION_ID"];
								$applicationPassId = $appPassword["ID"];
							}
							break;
						}
					}
				}
			}
		}

		// All except Admin
		if ($user_id > 1 && $arParams["CONTROLLER_ADMIN"] !== "Y")
		{
			if(!static::CheckUsersCount($user_id))
			{
				$user_id = 0;
				$APPLICATION->ThrowException(GetMessage("LIMIT_USERS_COUNT"));
				$result_message = array(
					"MESSAGE" => GetMessage("LIMIT_USERS_COUNT")."<br>",
					"TYPE" => "ERROR",
				);
			}
		}

		$arParams["USER_ID"] = $user_id;

		$doAuthorize = true;

		if($user_id > 0)
		{
			if($applicationId === null && CModule::IncludeModule("security"))
			{
				/*
				MFA can allow or disallow authorization.
				Allowed if:
				- OTP is not active for the user;
				- correct "OTP" in the $arParams (filled by the OnBeforeUserLogin event handler).
				Disallowed if:
				- OTP is not provided;
				- OTP is not correct.
				When authorization is disallowed the OTP form will be shown on the next hit.
				Note: there is no MFA check for an application password.
				*/

				$arParams["CAPTCHA_WORD"] = $_REQUEST["captcha_word"];
				$arParams["CAPTCHA_SID"] = $_REQUEST["captcha_sid"];

				$doAuthorize = \Bitrix\Security\Mfa\Otp::verifyUser($arParams);
			}

			if($doAuthorize)
			{
				$this->Authorize($user_id, ($arParams["REMEMBER"] == "Y"), true, $applicationId);

				if($applicationPassId !== null)
				{
					//update usage statistics for the application
					Main\Authentication\ApplicationPasswordTable::update($applicationPassId, array(
						'DATE_LOGIN' => new Main\Type\DateTime(),
						'LAST_IP' => $_SERVER["REMOTE_ADDR"],
					));
				}
			}
			else
			{
				$result_message = false;
			}

			if($applicationId === null && $arParams["LOGIN"] <> '')
			{
				//the cookie is for authentication forms mostly, does not make sense for applications
				$cookie = new Bitrix\Main\Web\Cookie("LOGIN", $arParams["LOGIN"], time()+60*60*24*30*12);
				Main\Context::getCurrent()->getResponse()->addCookie($cookie);
			}
		}
		else
		{
			if(CModule::IncludeModule("security"))
			{
				//disable OTP from if login was incorrect
				\Bitrix\Security\Mfa\Otp::setDeferredParams(null);
			}
		}

		$arParams["RESULT_MESSAGE"] = $result_message;

		$APPLICATION->ResetException();
		foreach(GetModuleEvents("main", "OnAfterUserLogin", true) as $arEvent)
			ExecuteModuleEventEx($arEvent, array(&$arParams));

		if($doAuthorize == true && $result_message !== true && (COption::GetOptionString("main", "event_log_login_fail", "N") === "Y"))
			CEventLog::Log("SECURITY", "USER_LOGIN", "main", $login, $result_message["MESSAGE"]);

		return $arParams["RESULT_MESSAGE"];
	}

	/**
	 * Internal authentication by login and password.
	 * @param array $arParams
	 * @param array|bool $result_message
	 * @param string|null $applicationId
	 * @param string|null $applicationPassId
	 * @return int User ID on success or 0 on failure. Additionally, $result_message will hold an error.
	 */
	public static function LoginInternal(&$arParams, &$result_message = true, &$applicationId = null, &$applicationPassId = null)
	{
		global $DB, $APPLICATION;

		$user_id = 0;
		$message = GetMessage("WRONG_LOGIN");
		$errorType = "LOGIN";

		$strSql =
			"SELECT U.ID, U.LOGIN, U.ACTIVE, U.BLOCKED, U.PASSWORD, U.LOGIN_ATTEMPTS, U.CONFIRM_CODE, U.EMAIL ".
			"FROM b_user U  ".
			"WHERE U.LOGIN='".$DB->ForSQL($arParams["LOGIN"])."' ";

		if(isset($arParams["EXTERNAL_AUTH_ID"]) && $arParams["EXTERNAL_AUTH_ID"] <> '')
		{
			//external user
			$strSql .= " AND EXTERNAL_AUTH_ID='".$DB->ForSql($arParams["EXTERNAL_AUTH_ID"])."'";
		}
		else
		{
			//internal user (by default)
			$strSql .= " AND (EXTERNAL_AUTH_ID IS NULL OR EXTERNAL_AUTH_ID='') ";
		}

		$result = $DB->Query($strSql);

		if(($arUser = $result->Fetch()))
		{
			$passwordCorrect = false;
			$policy = [];
			$original = ($arParams["PASSWORD_ORIGINAL"] == "Y");
			$loginAttempts = intval($arUser["LOGIN_ATTEMPTS"]) + 1;

			if($arUser["BLOCKED"] <> "Y")
			{
				$policy = static::GetGroupPolicy($arUser["ID"]);

				//show captcha after a serial of incorrect login attempts
				$correctCaptcha = true;
				$policyLoginAttempts = intval($policy["LOGIN_ATTEMPTS"]);
				if($policyLoginAttempts > 0 && $loginAttempts > $policyLoginAttempts)
				{
					$APPLICATION->SetNeedCAPTHA(true);
					if(!$APPLICATION->CaptchaCheckCode($_REQUEST["captcha_word"], $_REQUEST["captcha_sid"]))
					{
						$correctCaptcha = false;
					}
				}

				if($correctCaptcha)
				{
					$passwordCorrect = Password::equals($arUser["PASSWORD"], $arParams["PASSWORD"], $original);

					if(!$passwordCorrect)
					{
						if($arParams["OTP"] <> '' && $original)
						{
							//may be we have OTP added to the password
							$passwordWithoutOtp = mb_substr($arParams["PASSWORD"], 0, -6);
							$passwordCorrect = Password::equals($arUser["PASSWORD"], $passwordWithoutOtp);
						}
					}
					else
					{
						//this password has no added otp for sure
						$arParams["OTP"] = '';
					}

					if(!$passwordCorrect)
					{
						//let's try to find application password
						if(($appPassword = ApplicationPasswordTable::findPassword($arUser["ID"], $arParams["PASSWORD"], $original)) !== false)
						{
							$passwordCorrect = true;
							$applicationId = $appPassword["APPLICATION_ID"];
							$applicationPassId = $appPassword["ID"];
						}
					}
				}

				if(!$passwordCorrect)
				{
					//block the user after numerous incorrect login attempts
					$policyBlockAttempts = intval($policy["BLOCK_LOGIN_ATTEMPTS"]);
					$policyBlockTime = intval($policy["BLOCK_TIME"]);
					if($policyBlockAttempts > 0 && $policyBlockTime > 0 && $loginAttempts >= $policyBlockAttempts)
					{
						if($arUser["ACTIVE"] == "Y")
						{
							static::blockUser($arUser["ID"], $policyBlockTime, $loginAttempts);
						}
					}
				}
			}

			if($passwordCorrect)
			{
				//applied only to "human" passwords
				if($applicationId === null)
				{
					//only for original passwords
					if($original)
					{
						//update the old password hash to the new one with a salt
						if(Password::needRehash($arUser["PASSWORD"]))
						{
							$newPassword = Password::hash($arParams["PASSWORD"]);
							$DB->Query("UPDATE b_user SET PASSWORD='".$DB->ForSQL($newPassword)."', TIMESTAMP_X = TIMESTAMP_X WHERE ID = ".intval($arUser["ID"]));
						}

						//update digest hash for http digest authorization
						if(COption::GetOptionString('main', 'use_digest_auth', 'N') == 'Y')
						{
							static::UpdateDigest($arUser["ID"], $arParams["PASSWORD"]);
						}
					}

					$policyChangeDays = (int)$policy["PASSWORD_CHANGE_DAYS"];
					if($policyChangeDays > 0)
					{
						//require to change the password after N days
						if(UserPasswordTable::passwordExpired($arUser["ID"], $policyChangeDays))
						{
							$passwordCorrect = false;
							$message = GetMessage("MAIN_LOGIN_CHANGE_PASSWORD");
							$errorType = "CHANGE_PASSWORD";
						}
					}
				}

				if($passwordCorrect)
				{
					if($arUser["ACTIVE"] == "Y")
					{
						//success
						$user_id = $arUser["ID"];
					}
					else
					{
						//something wrong with the inactive user
						if($arUser["CONFIRM_CODE"] <> '')
						{
							//unconfirmed email registration
							$message = GetMessage("MAIN_LOGIN_EMAIL_CONFIRM", array("#EMAIL#" => $arUser["EMAIL"]));
						}
						else
						{
							//user deactivated
							$message = GetMessage("LOGIN_BLOCK");

							//or possibly unconfirmed phone registration
							if(COption::GetOptionString("main", "new_user_phone_auth", "N") == "Y")
							{
								$row = Main\UserPhoneAuthTable::getRowById($arUser["ID"]);
								if($row && $row["CONFIRMED"] == 'N')
								{
									$message = GetMessage("main_login_need_phone_confirmation", array("#PHONE#" => $row["PHONE_NUMBER"]));
								}
							}
						}
					}
				}
			}
			else
			{
				//incorrect password
				$DB->Query("UPDATE b_user SET LOGIN_ATTEMPTS = ".$loginAttempts.", TIMESTAMP_X = TIMESTAMP_X WHERE ID = ".intval($arUser["ID"]));
			}
		}

		if($user_id == 0)
		{
			$APPLICATION->ThrowException($message);
			$result_message = array("MESSAGE" => $message."<br>", "TYPE" => "ERROR", "ERROR_TYPE" => $errorType);
		}

		return $user_id;
	}

	protected static function blockUser($userId, $blockTime, $loginAttempts)
	{
		$user = new CUser();
		$user->Update($userId, ["BLOCKED" => "Y"], false);

		$unblockDate = new Main\Type\DateTime();
		$unblockDate->add("T{$blockTime}M"); //minutes

		CAgent::AddAgent("CUser::UnblockAgent({$userId});", "main", "Y", 0, "", "Y", $unblockDate->toString());

		if(COption::GetOptionString("main", "event_log_block_user", "N") === "Y")
		{
			CEventLog::Log("SECURITY", "USER_BLOCKED", "main", $userId, "Attempts: {$loginAttempts}, Block period: {$blockTime}");
		}
	}

	protected static function CheckUsersCount($user_id)
	{
		$limitUsersCount = intval(COption::GetOptionInt("main", "PARAM_MAX_USERS", 0));
		if ($limitUsersCount > 0)
		{
			$by = "ID";
			$order = "ASC";
			$arFilter = array("LAST_LOGIN_1" => ConvertTimeStamp());

			//Intranet users only
			$intranet = IsModuleInstalled("intranet");
			if ($intranet)
			{
				$arFilter["!=UF_DEPARTMENT"] = false;
			}

			$rsUsers = static::GetList($by, $order, $arFilter, array("FIELDS" => array("ID")));

			while ($user = $rsUsers->fetch())
			{
				if ($user["ID"] == $user_id)
				{
					$limitUsersCount = 1;
					break;
				}
				$limitUsersCount--;
			}

			if ($limitUsersCount <= 0)
			{
				if($intranet)
				{
					//only intranet users are NOT allowed
					$currUserRs = static::GetByID($user_id);
					if($currUser = $currUserRs->Fetch())
					{
						if(!empty($currUser["UF_DEPARTMENT"]))
						{
							return false;
						}
					}
				}
				else
				{
					return false;
				}
			}
		}
		return true;
	}

	public function LoginByOtp($otp, $remember_otp = "N", $captcha_word = "", $captcha_sid = "")
	{
		if(!CModule::IncludeModule("security") || !\Bitrix\Security\Mfa\Otp::isOtpRequired())
		{
			return array("MESSAGE" => GetMessage("USER_LOGIN_OTP_ERROR")."<br>", "TYPE" => "ERROR");
		}

		$userParams = \Bitrix\Security\Mfa\Otp::getDeferredParams();

		$userParams["OTP"] = $otp;
		$userParams["OTP_REMEMBER"] = ($remember_otp === "Y");
		$userParams["CAPTCHA_WORD"] = $captcha_word;
		$userParams["CAPTCHA_SID"] = $captcha_sid;

		if(!\Bitrix\Security\Mfa\Otp::verifyUser($userParams))
		{
			return array("MESSAGE" => GetMessage("USER_LOGIN_OTP_INCORRECT")."<br>", "TYPE" => "ERROR");
		}

		$this->Authorize($userParams["USER_ID"], ($userParams["REMEMBER"] == "Y"));
		return true;
	}

	public function AuthorizeWithOtp($user_id)
	{
		$doAuthorize = true;

		if(CModule::IncludeModule("security"))
		{
			/*
			MFA can allow or disallow authorization.
			Allowed only if:
			- OTP is not active for the user;
			When authorization is disallowed the OTP form will be shown on the next hit.
			*/
			$doAuthorize = \Bitrix\Security\Mfa\Otp::verifyUser(array("USER_ID" => $user_id));
		}

		if($doAuthorize)
		{
			return $this->Authorize($user_id);
		}

		return false;
	}

	public function ChangePassword($LOGIN, $CHECKWORD, $PASSWORD, $CONFIRM_PASSWORD, $SITE_ID=false, $captcha_word = "", $captcha_sid = 0, $authActions = true, $phoneNumber = "", $currentPassword = "")
	{
		/** @global CMain $APPLICATION */
		global $DB, $APPLICATION;

		$arParams = array(
			"LOGIN" => &$LOGIN,
			"CHECKWORD" => &$CHECKWORD,
			"PASSWORD" => &$PASSWORD,
			"CONFIRM_PASSWORD" => &$CONFIRM_PASSWORD,
			"SITE_ID" => &$SITE_ID,
			"PHONE_NUMBER" => &$phoneNumber,
			"CURRENT_PASSWORD" => &$currentPassword,
		);

		$APPLICATION->ResetException();
		foreach(GetModuleEvents("main", "OnBeforeUserChangePassword", true) as $arEvent)
		{
			if(ExecuteModuleEventEx($arEvent, array(&$arParams)) === false)
			{
				if($err = $APPLICATION->GetException())
				{
					return array("MESSAGE"=>$err->GetString()."<br>", "TYPE"=>"ERROR");
				}
				return array("MESSAGE"=>GetMessage("main_change_pass_error")."<br>", "TYPE"=>"ERROR");
			}
		}

		if(COption::GetOptionString("main", "captcha_restoring_password", "N") == "Y")
		{
			if (!($APPLICATION->CaptchaCheckCode($captcha_word, $captcha_sid)))
			{
				return array("MESSAGE"=>GetMessage("main_user_captcha_error")."<br>", "TYPE"=>"ERROR");
			}
		}

		$phoneAuth = ($arParams["PHONE_NUMBER"] <> '' && COption::GetOptionString("main", "new_user_phone_auth", "N") == "Y");

		$strAuthError = "";
		if(mb_strlen($arParams["LOGIN"]) < 3 && !$phoneAuth)
		{
			$strAuthError .= GetMessage('MIN_LOGIN')."<br>";
		}
		if($arParams["CHECKWORD"] == '' && $arParams["CURRENT_PASSWORD"] == '')
		{
			$strAuthError .= GetMessage("main_change_pass_empty_checkword")."<br>";
		}
		if($arParams["PASSWORD"] <> $arParams["CONFIRM_PASSWORD"])
		{
			$strAuthError .= GetMessage('WRONG_CONFIRMATION')."<br>";
		}
		if($strAuthError <> '')
		{
			return array("MESSAGE"=>$strAuthError, "TYPE"=>"ERROR");
		}

		$updateFields = array(
			"PASSWORD" => $arParams["PASSWORD"],
		);

		$res = [];
		if($phoneAuth)
		{
			$userId = self::VerifyPhoneCode($arParams["PHONE_NUMBER"], $arParams["CHECKWORD"]);

			if(!$userId)
			{
				return array("MESSAGE" => GetMessage("main_change_pass_code_error"), "TYPE" => "ERROR");
			}

			//activate user after phone number confirmation
			$updateFields["ACTIVE"] = "Y";
		}
		else
		{
			CTimeZone::Disable();
			$db_check = $DB->Query(
				"SELECT ID, LID, CHECKWORD, ".$DB->DateToCharFunction("CHECKWORD_TIME", "FULL")." as CHECKWORD_TIME, PASSWORD, LOGIN_ATTEMPTS, ACTIVE, BLOCKED ".
				"FROM b_user ".
				"WHERE LOGIN='".$DB->ForSql($arParams["LOGIN"], 0)."'".
				(
					// $arParams["EXTERNAL_AUTH_ID"] can be changed in the OnBeforeUserChangePassword event
					$arParams["EXTERNAL_AUTH_ID"] <> ''?
						"	AND EXTERNAL_AUTH_ID='".$DB->ForSQL($arParams["EXTERNAL_AUTH_ID"])."' " :
						"	AND (EXTERNAL_AUTH_ID IS NULL OR EXTERNAL_AUTH_ID='') "
				)
			);
			CTimeZone::Enable();

			if(!($res = $db_check->Fetch()))
			{
				return array("MESSAGE"=>preg_replace("/#LOGIN#/i", htmlspecialcharsbx($arParams["LOGIN"]), GetMessage('LOGIN_NOT_FOUND')), "TYPE"=>"ERROR", "FIELD" => "LOGIN");
			}

			$userId = $res["ID"];
		}

		$arPolicy = static::GetGroupPolicy($userId);

		$passwordErrors = self::CheckPasswordAgainstPolicy($arParams["PASSWORD"], $arPolicy);
		if (!empty($passwordErrors))
		{
			return array("MESSAGE" => implode("<br>", $passwordErrors)."<br>", "TYPE" => "ERROR");
		}

		if(!$phoneAuth)
		{
			if($arParams["CHECKWORD"] <> '')
			{
				//change the password using the checkword
				if($res["CHECKWORD"] == '' || !Password::equals($res["CHECKWORD"], $arParams["CHECKWORD"]))
				{
					return array("MESSAGE"=>preg_replace("/#LOGIN#/i", htmlspecialcharsbx($arParams["LOGIN"]), GetMessage("CHECKWORD_INCORRECT"))."<br>", "TYPE"=>"ERROR", "FIELD"=>"CHECKWORD");
				}

				$site_format = CSite::GetDateFormat();
				if(time()-$arPolicy["CHECKWORD_TIMEOUT"]*60 > MakeTimeStamp($res["CHECKWORD_TIME"], $site_format))
				{
					return array("MESSAGE"=>preg_replace("/#LOGIN#/i", htmlspecialcharsbx($arParams["LOGIN"]), GetMessage("CHECKWORD_EXPIRE"))."<br>", "TYPE"=>"ERROR", "FIELD"=>"CHECKWORD_EXPIRE");
				}
			}
			else
			{
				//change the password using the current password
				$loginAttempts = intval($res["LOGIN_ATTEMPTS"]) + 1;

				//show captcha after a serial of incorrect login attempts
				$policyLoginAttempts = intval($arPolicy["LOGIN_ATTEMPTS"]);
				if($policyLoginAttempts > 0 && $loginAttempts > $policyLoginAttempts)
				{
					$APPLICATION->SetNeedCAPTHA(true);
					if(!$APPLICATION->CaptchaCheckCode($captcha_word, $captcha_sid))
					{
						return array("MESSAGE"=>GetMessage("main_user_captcha_error")."<br>", "TYPE"=>"ERROR");
					}
				}

				$passwordCorrect = false;

				if($res["BLOCKED"] <> "Y")
				{
					$passwordCorrect = Password::equals($res["PASSWORD"], $arParams["CURRENT_PASSWORD"]);

					if(!$passwordCorrect)
					{
						//block the user after numerous incorrect login attempts
						$policyBlockAttempts = intval($arPolicy["BLOCK_LOGIN_ATTEMPTS"]);
						$policyBlockTime = intval($arPolicy["BLOCK_TIME"]);
						if($policyBlockAttempts > 0 && $policyBlockTime > 0 && $loginAttempts >= $policyBlockAttempts)
						{
							if($res["ACTIVE"] == "Y")
							{
								static::blockUser($res["ID"], $policyBlockTime, $loginAttempts);
							}
						}
					}

					if($passwordCorrect)
					{
						$passwordErrors = self::CheckPasswordAgainstPolicy($arParams["PASSWORD"], $arPolicy, $res["ID"]);
						if (!empty($passwordErrors))
						{
							return array("MESSAGE" => implode("<br>", $passwordErrors)."<br>", "TYPE" => "ERROR");
						}

						$APPLICATION->SetNeedCAPTHA(false);
					}
				}

				if(!$passwordCorrect)
				{
					//incorrect password
					$DB->Query("UPDATE b_user SET LOGIN_ATTEMPTS = ".$loginAttempts.", TIMESTAMP_X = TIMESTAMP_X WHERE ID = ".intval($res["ID"]));

					return array("MESSAGE"=>GetMessage("main_change_pass_incorrect_pass")."<br>", "TYPE"=>"ERROR", "FIELD"=>"CURRENT_PASSWORD");
				}
			}

			if($arParams["SITE_ID"] === false)
			{
				if(defined("ADMIN_SECTION") && ADMIN_SECTION===true)
					$arParams["SITE_ID"] = CSite::GetDefSite($res["LID"]);
				else
					$arParams["SITE_ID"] = SITE_ID;
			}
		}

		// change the password
		$obUser = new CUser;
		$res = $obUser->Update($userId, $updateFields, $authActions);

		if(!$res && $obUser->LAST_ERROR <> '')
		{
			return array("MESSAGE"=>$obUser->LAST_ERROR."<br>", "TYPE"=>"ERROR");
		}

		if($phoneAuth)
		{
			return array("MESSAGE"=>GetMessage("main_change_pass_changed")."<br>", "TYPE"=>"OK");
		}
		else
		{
			static::SendUserInfo($userId, $arParams["SITE_ID"], GetMessage('CHANGE_PASS_SUCC'), true, 'USER_PASS_CHANGED');

			return array("MESSAGE"=>GetMessage('PASSWORD_CHANGE_OK')."<br>", "TYPE"=>"OK");
		}
	}

	public static function GeneratePasswordByPolicy(array $groups)
	{
		$arPolicy = self::GetGroupPolicy($groups);

		$password_min_length = intval($arPolicy["PASSWORD_LENGTH"]);
		if($password_min_length <= 0)
			$password_min_length = 6;

		$password_chars = Random::ALPHABET_NUM | Random::ALPHABET_ALPHALOWER | Random::ALPHABET_ALPHAUPPER;

		if($arPolicy["PASSWORD_PUNCTUATION"] === "Y")
			$password_chars |= Random::ALPHABET_SPECIAL;

		return Random::getStringByAlphabet($password_min_length, $password_chars);
	}

	public static function CheckPasswordAgainstPolicy($password, $arPolicy, $userId = null)
	{
		$errors = array();

		$password_min_length = intval($arPolicy["PASSWORD_LENGTH"]);
		if($password_min_length <= 0)
			$password_min_length = 6;
		if(mb_strlen($password) < $password_min_length)
			$errors[] = GetMessage("MAIN_FUNCTION_REGISTER_PASSWORD_LENGTH", array("#LENGTH#" => $arPolicy["PASSWORD_LENGTH"]));

		if(($arPolicy["PASSWORD_UPPERCASE"] === "Y") && !preg_match("/[A-Z]/", $password))
			$errors[] = GetMessage("MAIN_FUNCTION_REGISTER_PASSWORD_UPPERCASE");

		if(($arPolicy["PASSWORD_LOWERCASE"] === "Y") && !preg_match("/[a-z]/", $password))
			$errors[] = GetMessage("MAIN_FUNCTION_REGISTER_PASSWORD_LOWERCASE");

		if(($arPolicy["PASSWORD_DIGITS"] === "Y") && !preg_match("/[0-9]/", $password))
			$errors[] = GetMessage("MAIN_FUNCTION_REGISTER_PASSWORD_DIGITS");

		if(($arPolicy["PASSWORD_PUNCTUATION"] === "Y") && !preg_match("/[,.<>\\/?;:'\"[\\]\\{\\}\\\\|`~!@#\$%^&*()_+=-]/", $password))
			$errors[] = GetMessage("MAIN_FUNCTION_REGISTER_PASSWORD_PUNCTUATION");

		if($userId !== null && $arPolicy["PASSWORD_UNIQUE_COUNT"] > 0)
		{
			$passwords = UserPasswordTable::getUserPasswords($userId, $arPolicy["PASSWORD_UNIQUE_COUNT"]);

			foreach($passwords as $previousPassword)
			{
				if(Password::equals($previousPassword["PASSWORD"], $password))
				{
					$errors[] = GetMessage("MAIN_FUNCTION_REGISTER_PASSWORD_UNIQUE");
					break;
				}
			}
		}

		return $errors;
	}

	/**
	 * Sends a profile information to email
	 */
	public static function SendUserInfo($ID, $SITE_ID, $MSG, $bImmediate=false, $eventName="USER_INFO", $checkword = null)
	{
		global $DB;

		$arParams = [
			"ID" => $ID,
			"SITE_ID" => $SITE_ID,
		];

		foreach(GetModuleEvents("main", "OnBeforeSendUserInfo", true) as $arEvent)
		{
			if(ExecuteModuleEventEx($arEvent, array(&$arParams)) === false)
			{
				return;
			}
		}

		$ID = intval($ID);

		if($checkword === null)
		{
			// change CHECKWORD
			$checkword = md5(uniqid().CMain::GetServerUniqID());

			$strSql = "UPDATE b_user SET ".
				"	CHECKWORD = '".Password::hash($checkword)."', ".
				"	CHECKWORD_TIME = ".$DB->CurrentTimeFunction().", ".
				"	LID = '".$DB->ForSql($SITE_ID, 2)."', ".
				"   TIMESTAMP_X = TIMESTAMP_X ".
				"WHERE ID = '".$ID."'".
				(
					// $arParams["EXTERNAL_AUTH_ID"] can be changed in the OnBeforeSendUserInfo event
					$arParams["EXTERNAL_AUTH_ID"] <> ''?
						"	AND EXTERNAL_AUTH_ID='".$DB->ForSQL($arParams["EXTERNAL_AUTH_ID"])."' " :
						"	AND (EXTERNAL_AUTH_ID IS NULL OR EXTERNAL_AUTH_ID='') "
				);

			$DB->Query($strSql, false, "FILE: ".__FILE__."<br> LINE: ".__LINE__);
		}

		$res = $DB->Query(
			"SELECT u.* ".
			"FROM b_user u ".
			"WHERE ID='".$ID."'".
			(
				$arParams["EXTERNAL_AUTH_ID"] <> ''?
					"	AND EXTERNAL_AUTH_ID='".$DB->ForSQL($arParams["EXTERNAL_AUTH_ID"])."' " :
					"	AND (EXTERNAL_AUTH_ID IS NULL OR EXTERNAL_AUTH_ID='') "
			)
		);

		if($res_array = $res->Fetch())
		{
			$event = new CEvent;
			$arFields = array(
				"USER_ID"=>$res_array["ID"],
				"STATUS"=>($res_array["ACTIVE"]=="Y"?GetMessage("STATUS_ACTIVE"):GetMessage("STATUS_BLOCKED")),
				"MESSAGE"=>$MSG,
				"LOGIN"=>$res_array["LOGIN"],
				"URL_LOGIN"=>urlencode($res_array["LOGIN"]),
				"CHECKWORD"=>$checkword,
				"NAME"=>$res_array["NAME"],
				"LAST_NAME"=>$res_array["LAST_NAME"],
				"EMAIL"=>$res_array["EMAIL"]
			);

			$arParams = array(
				"FIELDS" => &$arFields,
				"USER_FIELDS" => $res_array,
				"SITE_ID" => &$SITE_ID,
				"EVENT_NAME" => &$eventName,
			);

			foreach (GetModuleEvents("main", "OnSendUserInfo", true) as $arEvent)
				ExecuteModuleEventEx($arEvent, array(&$arParams));

			if (!$bImmediate)
				$event->Send($eventName, $SITE_ID, $arFields, "Y", "", array(), $res_array["LANGUAGE_ID"]);
			else
				$event->SendImmediate($eventName, $SITE_ID, $arFields, "Y", "", array(), $res_array["LANGUAGE_ID"]);
		}
	}

	public static function SendPassword($LOGIN, $EMAIL, $SITE_ID = false, $captcha_word = "", $captcha_sid = 0, $phoneNumber = "", $shortCode = false)
	{
		/** @global CMain $APPLICATION */
		global $DB, $APPLICATION;

		$arParams = array(
			"LOGIN" => $LOGIN,
			"EMAIL" => $EMAIL,
			"SITE_ID" => $SITE_ID,
			"PHONE_NUMBER" => $phoneNumber,
			"SHORT_CODE" => $shortCode,
		);

		$result_message = array("MESSAGE"=>GetMessage('ACCOUNT_INFO_SENT')."<br>", "TYPE"=>"OK");
		$APPLICATION->ResetException();
		$bOk = true;
		foreach(GetModuleEvents("main", "OnBeforeUserSendPassword", true) as $arEvent)
		{
			if(ExecuteModuleEventEx($arEvent, array(&$arParams))===false)
			{
				if($err = $APPLICATION->GetException())
					$result_message = array("MESSAGE"=>$err->GetString()."<br>", "TYPE"=>"ERROR");

				$bOk = false;
				break;
			}
		}

		if($bOk && $arParams["SHORT_CODE"] == false && COption::GetOptionString("main", "captcha_restoring_password", "N") == "Y")
		{
			if (!($APPLICATION->CaptchaCheckCode($captcha_word, $captcha_sid)))
			{
				$result_message = array("MESSAGE"=>GetMessage("main_user_captcha_error")."<br>", "TYPE"=>"ERROR");
				$bOk = false;
			}
		}

		if($bOk)
		{
			$found = false;
			if($arParams["PHONE_NUMBER"] <> '')
			{
				//user registered by phone number

				$siteId = ($arParams["SITE_ID"] === false? null : $arParams["SITE_ID"]);

				$result = static::SendPhoneCode($arParams["PHONE_NUMBER"], "SMS_USER_RESTORE_PASSWORD", $siteId);

				if($result->isSuccess())
				{
					$found = true;
					$result_message = array("MESSAGE"=>GetMessage("main_user_pass_request_sent")."<br>", "TYPE"=>"OK", "TEMPLATE" => "SMS_USER_RESTORE_PASSWORD");

					if(COption::GetOptionString("main", "event_log_password_request", "N") === "Y")
					{
						$data = $result->getData();
						CEventLog::Log("SECURITY", "USER_INFO", "main", $data["USER_ID"]);
					}
				}
				else
				{
					if($result->getErrorCollection()->getErrorByCode("ERR_NOT_FOUND") === null)
					{
						//user found but there is another error
						$found = true;
						$result_message = array("MESSAGE"=>implode("<br>", $result->getErrorMessages()), "TYPE"=>"ERROR");
					}
				}
			}
			elseif($arParams["LOGIN"] <> '' || $arParams["EMAIL"] <> '')
			{
				$confirmation = (COption::GetOptionString("main", "new_user_registration_email_confirmation", "N") == "Y");

				$strSql = "";
				if($arParams["LOGIN"] <> '')
				{
					$strSql =
						"SELECT ID, LID, ACTIVE, BLOCKED, CONFIRM_CODE, LOGIN, EMAIL, NAME, LAST_NAME, LANGUAGE_ID ".
						"FROM b_user u ".
						"WHERE LOGIN='".$DB->ForSQL($arParams["LOGIN"])."' ".
						"	AND (ACTIVE='Y' OR NOT(CONFIRM_CODE IS NULL OR CONFIRM_CODE='')) ".
						(
							// $arParams["EXTERNAL_AUTH_ID"] can be changed in the OnBeforeUserSendPassword event
							$arParams["EXTERNAL_AUTH_ID"] <> ''?
								"	AND EXTERNAL_AUTH_ID='".$DB->ForSQL($arParams["EXTERNAL_AUTH_ID"])."' " :
								"	AND (EXTERNAL_AUTH_ID IS NULL OR EXTERNAL_AUTH_ID='') "
						);
				}
				if($arParams["EMAIL"] <> '')
				{
					if($strSql <> '')
					{
						$strSql .= "\nUNION\n";
					}
					$strSql .=
						"SELECT ID, LID, ACTIVE, BLOCKED, CONFIRM_CODE, LOGIN, EMAIL, NAME, LAST_NAME, LANGUAGE_ID ".
						"FROM b_user u ".
						"WHERE EMAIL='".$DB->ForSQL($arParams["EMAIL"])."' ".
						"	AND (ACTIVE='Y' OR NOT(CONFIRM_CODE IS NULL OR CONFIRM_CODE='')) ".
						(
							$arParams["EXTERNAL_AUTH_ID"] <> ''?
								"	AND EXTERNAL_AUTH_ID='".$DB->ForSQL($arParams["EXTERNAL_AUTH_ID"])."' " :
								"	AND (EXTERNAL_AUTH_ID IS NULL OR EXTERNAL_AUTH_ID='') "
						);
				}
				$res = $DB->Query($strSql);

				while($arUser = $res->Fetch())
				{
					if($arParams["SITE_ID"]===false)
					{
						if(defined("ADMIN_SECTION") && ADMIN_SECTION===true)
							$arParams["SITE_ID"] = CSite::GetDefSite($arUser["LID"]);
						else
							$arParams["SITE_ID"] = SITE_ID;
					}

					if($arUser["ACTIVE"] == "Y")
					{
						if($arUser["BLOCKED"] <> "Y")
						{
							$found = true;

							if($arParams["SHORT_CODE"] == true)
							{
								$result = static::SendEmailCode($arUser["ID"], $arParams["SITE_ID"]);

								if($result->isSuccess())
								{
									$result_message = array("MESSAGE"=>GetMessage("main_send_password_email_code")."<br>", "TYPE"=>"OK", "USER_ID" => $arUser["ID"], "RESULT" => $result);
								}
								else
								{
									$result_message = array("MESSAGE"=>implode("<br>", $result->getErrorMessages()), "TYPE"=>"ERROR", "RESULT" => $result);
								}
							}
							else
							{
								static::SendUserInfo($arUser["ID"], $arParams["SITE_ID"], GetMessage("INFO_REQ"), true, 'USER_PASS_REQUEST');
							}
						}
					}
					elseif($confirmation)
					{
						$found = true;

						//unconfirmed registration - resend confirmation email
						$arFields = array(
							"USER_ID" => $arUser["ID"],
							"LOGIN" => $arUser["LOGIN"],
							"EMAIL" => $arUser["EMAIL"],
							"NAME" => $arUser["NAME"],
							"LAST_NAME" => $arUser["LAST_NAME"],
							"CONFIRM_CODE" => $arUser["CONFIRM_CODE"],
							"USER_IP" => $_SERVER["REMOTE_ADDR"],
							"USER_HOST" => @gethostbyaddr($_SERVER["REMOTE_ADDR"]),
						);

						$event = new CEvent;
						$event->SendImmediate("NEW_USER_CONFIRM", $arParams["SITE_ID"], $arFields, "Y", "", array(), $arUser["LANGUAGE_ID"]);

						$result_message = array("MESSAGE"=>GetMessage("MAIN_SEND_PASS_CONFIRM")."<br>", "TYPE"=>"OK");
					}

					if(COption::GetOptionString("main", "event_log_password_request", "N") === "Y")
					{
						CEventLog::Log("SECURITY", "USER_INFO", "main", $arUser["ID"]);
					}
				}
			}
			if(!$found)
			{
				return array("MESSAGE"=>GetMessage('DATA_NOT_FOUND1')."<br>", "TYPE"=>"ERROR");
			}
		}
		return $result_message;
	}

	public function Register($USER_LOGIN, $USER_NAME, $USER_LAST_NAME, $USER_PASSWORD, $USER_CONFIRM_PASSWORD, $USER_EMAIL, $SITE_ID = false, $captcha_word = "", $captcha_sid = 0, $bSkipConfirm = false, $USER_PHONE_NUMBER = "")
	{
		/**
		 * @global CMain $APPLICATION
		 * @global CUserTypeManager $USER_FIELD_MANAGER
		 */
		global $APPLICATION, $DB, $USER_FIELD_MANAGER;

		$APPLICATION->ResetException();
		if(defined("ADMIN_SECTION") && ADMIN_SECTION===true && $SITE_ID!==false)
		{
			$APPLICATION->ThrowException(GetMessage("MAIN_FUNCTION_REGISTER_NA_INADMIN"));
			return array("MESSAGE"=>GetMessage("MAIN_FUNCTION_REGISTER_NA_INADMIN"), "TYPE"=>"ERROR");
		}

		$strError = "";

		if (COption::GetOptionString("main", "captcha_registration", "N") == "Y")
		{
			if (!($APPLICATION->CaptchaCheckCode($captcha_word, $captcha_sid)))
			{
				$strError .= GetMessage("MAIN_FUNCTION_REGISTER_CAPTCHA")."<br>";
			}
		}

		if($strError)
		{
			if(COption::GetOptionString("main", "event_log_register_fail", "N") === "Y")
			{
				CEventLog::Log("SECURITY", "USER_REGISTER_FAIL", "main", false, $strError);
			}

			$APPLICATION->ThrowException($strError);
			return array("MESSAGE"=>$strError, "TYPE"=>"ERROR");
		}

		if($SITE_ID === false)
			$SITE_ID = SITE_ID;

		$bConfirmReq = !$bSkipConfirm && (COption::GetOptionString("main", "new_user_registration_email_confirmation", "N") == "Y" && COption::GetOptionString("main", "new_user_email_required", "Y") <> "N");
		$phoneRegistration = (COption::GetOptionString("main", "new_user_phone_auth", "N") == "Y");
		$phoneRequired = ($phoneRegistration && COption::GetOptionString("main", "new_user_phone_required", "N") == "Y");

		$checkword = md5(uniqid().CMain::GetServerUniqID());
		$active = ($bConfirmReq || $phoneRequired? "N": "Y");

		$arFields = array(
			"LOGIN" => $USER_LOGIN,
			"NAME" => $USER_NAME,
			"LAST_NAME" => $USER_LAST_NAME,
			"PASSWORD" => $USER_PASSWORD,
			"CHECKWORD" => Password::hash($checkword),
			"~CHECKWORD_TIME" => $DB->CurrentTimeFunction(),
			"CONFIRM_PASSWORD" => $USER_CONFIRM_PASSWORD,
			"EMAIL" => $USER_EMAIL,
			"PHONE_NUMBER" => $USER_PHONE_NUMBER,
			"ACTIVE" => $active,
			"CONFIRM_CODE" => ($bConfirmReq? Random::getString(8, true): ""),
			"SITE_ID" => $SITE_ID,
			"LANGUAGE_ID" => LANGUAGE_ID,
			"USER_IP" => $_SERVER["REMOTE_ADDR"],
			"USER_HOST" => @gethostbyaddr($_SERVER["REMOTE_ADDR"]),
		);
		$USER_FIELD_MANAGER->EditFormAddFields("USER", $arFields);

		$def_group = COption::GetOptionString("main", "new_user_registration_def_group", "");
		if($def_group!="")
			$arFields["GROUP_ID"] = explode(",", $def_group);

		$bOk = true;
		$result_message = true;
		foreach(GetModuleEvents("main", "OnBeforeUserRegister", true) as $arEvent)
		{
			if(ExecuteModuleEventEx($arEvent, array(&$arFields)) === false)
			{
				if($err = $APPLICATION->GetException())
				{
					$result_message = array("MESSAGE"=>$err->GetString()."<br>", "TYPE"=>"ERROR");
				}
				else
				{
					$APPLICATION->ThrowException("Unknown error");
					$result_message = array("MESSAGE"=>"Unknown error"."<br>", "TYPE"=>"ERROR");
				}

				$bOk = false;
				break;
			}
		}

		$ID = false;
		$phoneReg = false;
		if($bOk)
		{
			if($arFields["SITE_ID"] === false)
			{
				$arFields["SITE_ID"] = CSite::GetDefSite();
			}
			$arFields["LID"] = $arFields["SITE_ID"];

			if($ID = $this->Add($arFields))
			{
				if($phoneRegistration && $arFields["PHONE_NUMBER"] <> '')
				{
					$phoneReg = true;

					//added the phone number for the user, now sending a confirmation SMS
					list($code, $phoneNumber) = static::GeneratePhoneCode($ID);

					$sms = new \Bitrix\Main\Sms\Event(
						"SMS_USER_CONFIRM_NUMBER",
						[
							"USER_PHONE" => $phoneNumber,
							"CODE" => $code,
						]
					);
					$sms->setSite($arFields["SITE_ID"]);
					$smsResult = $sms->send(true);

					$signedData = \Bitrix\Main\Controller\PhoneAuth::signData(['phoneNumber' => $phoneNumber]);

					if($smsResult->isSuccess())
					{
						$result_message = array(
							"MESSAGE" => GetMessage("main_register_sms_sent"),
							"TYPE" => "OK",
							"SIGNED_DATA" => $signedData,
							"ID" => $ID,
						);
					}
					else
					{
						$result_message = array(
							"MESSAGE" => $smsResult->getErrorMessages(),
							"TYPE" => "ERROR",
							"SIGNED_DATA" => $signedData,
							"ID" => $ID,
						);
					}

				}
				else
				{
					$result_message = array(
						"MESSAGE" => GetMessage("USER_REGISTER_OK"),
						"TYPE" => "OK",
						"ID" => $ID
					);
				}

				$arFields["USER_ID"] = $ID;
				$arFields["CHECKWORD"] = $checkword;

				$arEventFields = $arFields;
				unset($arEventFields["PASSWORD"]);
				unset($arEventFields["CONFIRM_PASSWORD"]);
				unset($arEventFields["~CHECKWORD_TIME"]);

				$event = new CEvent;
				$event->SendImmediate("NEW_USER", $arEventFields["SITE_ID"], $arEventFields);
				if($bConfirmReq)
				{
					$event->SendImmediate("NEW_USER_CONFIRM", $arEventFields["SITE_ID"], $arEventFields);
				}
			}
			else
			{
				$APPLICATION->ThrowException($this->LAST_ERROR);
				$result_message = array("MESSAGE"=>$this->LAST_ERROR, "TYPE"=>"ERROR");
			}
		}

		if(is_array($result_message))
		{
			if($result_message["TYPE"] == "OK")
			{
				if(COption::GetOptionString("main", "event_log_register", "N") === "Y")
				{
					$res_log["user"] = ($USER_NAME != "" || $USER_LAST_NAME != "") ? trim($USER_NAME." ".$USER_LAST_NAME) : $USER_LOGIN;
					CEventLog::Log("SECURITY", "USER_REGISTER", "main", $ID, serialize($res_log));
				}
			}
			else
			{
				if(COption::GetOptionString("main", "event_log_register_fail", "N") === "Y")
				{
					CEventLog::Log("SECURITY", "USER_REGISTER_FAIL", "main", $ID, $result_message["MESSAGE"]);
				}
			}
		}

		//authorize succesfully registered user, except email or phone confirmation is required
		$isAuthorize = false;
		if($ID !== false && $arFields["ACTIVE"] === "Y" && $phoneReg === false)
		{
			$isAuthorize = $this->Authorize($ID);
		}

		$agreementId = intval(COption::getOptionString("main", "new_user_agreement", ""));
		if ($agreementId && $isAuthorize)
		{
			$agreementObject = new \Bitrix\Main\UserConsent\Agreement($agreementId);
			if ($agreementObject->isExist() && $agreementObject->isActive() && $_REQUEST["USER_AGREEMENT"] == "Y")
			{
				\Bitrix\Main\UserConsent\Consent::addByContext($agreementId, "main/reg", "register");
			}
		}

		$arFields["RESULT_MESSAGE"] = $result_message;
		foreach (GetModuleEvents("main", "OnAfterUserRegister", true) as $arEvent)
			ExecuteModuleEventEx($arEvent, array(&$arFields));

		return $arFields["RESULT_MESSAGE"];
	}

	public function SimpleRegister($USER_EMAIL, $SITE_ID = false)
	{
		/** @global CMain $APPLICATION */
		global $APPLICATION, $DB;

		$APPLICATION->ResetException();
		if(defined("ADMIN_SECTION") && ADMIN_SECTION===true && $SITE_ID===false)
		{
			$APPLICATION->ThrowException(GetMessage("MAIN_FUNCTION_SIMPLEREGISTER_NA_INADMIN"));
			return array("MESSAGE"=>GetMessage("MAIN_FUNCTION_SIMPLEREGISTER_NA_INADMIN"), "TYPE"=>"ERROR");
		}

		if($SITE_ID===false)
			$SITE_ID = SITE_ID;

		global $REMOTE_ADDR;

		$checkword = md5(uniqid().CMain::GetServerUniqID());
		$arFields = array(
			"CHECKWORD" => Password::hash($checkword),
			"~CHECKWORD_TIME" => $DB->CurrentTimeFunction(),
			"EMAIL" => $USER_EMAIL,
			"ACTIVE" => "Y",
			"NAME"=>"",
			"LAST_NAME"=>"",
			"USER_IP"=>$REMOTE_ADDR,
			"USER_HOST"=>@gethostbyaddr($REMOTE_ADDR),
			"SITE_ID" => $SITE_ID,
			"LANGUAGE_ID" => LANGUAGE_ID,
		);

		$def_group = COption::GetOptionString("main", "new_user_registration_def_group", "");
		if($def_group!="")
		{
			$arFields["GROUP_ID"] = explode(",", $def_group);
		}
		else
		{
			$arFields["GROUP_ID"] = array();
		}
		$arFields["PASSWORD"] = $arFields["CONFIRM_PASSWORD"] = self::GeneratePasswordByPolicy($arFields["GROUP_ID"]);

		$bOk = true;
		$result_message = false;
		foreach(GetModuleEvents("main", "OnBeforeUserSimpleRegister", true) as $arEvent)
		{
			if(ExecuteModuleEventEx($arEvent, array(&$arFields)) === false)
			{
				if($err = $APPLICATION->GetException())
					$result_message = array("MESSAGE"=>$err->GetString()."<br>", "TYPE"=>"ERROR");
				else
				{
					$APPLICATION->ThrowException("Unknown error");
					$result_message = array("MESSAGE"=>"Unknown error"."<br>", "TYPE"=>"ERROR");
				}

				$bOk = false;
				break;
			}
		}

		$bRandLogin = false;
		if(!is_set($arFields, "LOGIN"))
		{
			$arFields["LOGIN"] = Random::getString(50);
			$bRandLogin = true;
		}

		$ID = 0;
		if($bOk)
		{
			$arFields["LID"] = $arFields["SITE_ID"];
			$arFields["CHECKWORD"] = $checkword;
			if($ID = $this->Add($arFields))
			{
				if($bRandLogin)
				{
					$this->Update($ID, array("LOGIN"=>"user".$ID));
					$arFields["LOGIN"] = "user".$ID;
				}

				$this->Authorize($ID);

				$event = new CEvent;
				$arFields["USER_ID"] = $ID;

				$arEventFields = $arFields;
				unset($arEventFields["PASSWORD"]);
				unset($arEventFields["CONFIRM_PASSWORD"]);

				$event->SendImmediate("NEW_USER", $arEventFields["SITE_ID"], $arEventFields);
				static::SendUserInfo($ID, $arEventFields["SITE_ID"], GetMessage("USER_REGISTERED_SIMPLE"), true);
				$result_message = array("MESSAGE"=>GetMessage("USER_REGISTER_OK"), "TYPE"=>"OK");
			}
			else
				$result_message = array("MESSAGE"=>$this->LAST_ERROR, "TYPE"=>"ERROR");
		}

		if(is_array($result_message))
		{
			if($result_message["TYPE"] == "OK")
			{
				if(COption::GetOptionString("main", "event_log_register", "N") === "Y")
				{
					$res_log["user"] = $arFields["LOGIN"];
					CEventLog::Log("SECURITY", "USER_REGISTER", "main", $ID, serialize($res_log));
				}
			}
			else
			{
				if(COption::GetOptionString("main", "event_log_register_fail", "N") === "Y")
				{
					CEventLog::Log("SECURITY", "USER_REGISTER_FAIL", "main", $ID, $result_message["MESSAGE"]);
				}
			}
		}

		$arFields["RESULT_MESSAGE"] = $result_message;
		foreach(GetModuleEvents("main", "OnAfterUserSimpleRegister", true) as $arEvent)
			ExecuteModuleEventEx($arEvent, array(&$arFields));

		return $arFields["RESULT_MESSAGE"];
	}

	public function IsAuthorized()
	{
		if(!isset($this))
		{
			trigger_error("Static call CUser::IsAuthorized() is deprecated, will be removed soon. Use global \$USER.", E_USER_WARNING);

			global $USER;
			return $USER->IsAuthorized();
		}
		return ($this->GetParam("AUTHORIZED") == "Y");
	}

	public function HasNoAccess()
	{
		if (!$this->IsAuthorized())
		{
			return true;
		}

		$filePath = \Bitrix\Main\Context::getCurrent()->getRequest()->getScriptFile();

		return !$this->CanDoFileOperation('fm_view_file', [SITE_ID, $filePath]);
	}

	public function IsJustAuthorized()
	{
		return $this->justAuthorized;
	}

	public function IsJustBecameOnline()
	{
		if(!$this->GetParam('PREV_LAST_ACTIVITY'))
		{
			return true;
		}
		else
		{
			return (($this->GetParam('SET_LAST_ACTIVITY') - $this->GetParam('PREV_LAST_ACTIVITY')) > Main\UserTable::getSecondsForLimitOnline());
		}
	}

	public function IsAdmin()
	{
		if ($this->admin === null)
		{
			if(
				COption::GetOptionString("main", "controller_member", "N") == "Y"
				&& COption::GetOptionString("main", "~controller_limited_admin", "N") == "Y"
			)
			{
				$this->admin = ($this->GetParam("CONTROLLER_ADMIN") === true);
			}
			else
			{
				$this->admin = ($this->GetParam("ADMIN") === true);
			}
		}
		return $this->admin;
	}

	public function SetControllerAdmin($isAdmin = true)
	{
		$this->SetParam("CONTROLLER_ADMIN", (bool)$isAdmin);
	}

	public function Logout()
	{
		/** @global CMain $APPLICATION */
		global $APPLICATION, $DB;

		$USER_ID = $this->GetID();

		$arParams = array(
			"USER_ID" => &$USER_ID
		);

		$APPLICATION->ResetException();
		$bOk = true;
		foreach(GetModuleEvents("main", "OnBeforeUserLogout", true) as $arEvent)
		{
			if(ExecuteModuleEventEx($arEvent, array(&$arParams))===false)
			{
				if(!($APPLICATION->GetException()))
				{
					$APPLICATION->ThrowException("Unknown logout error");
				}

				$bOk = false;
				break;
			}
		}

		if($bOk)
		{
			foreach(GetModuleEvents("main", "OnUserLogout", true) as $arEvent)
				ExecuteModuleEventEx($arEvent, array($USER_ID));

			if(($storedAuthId = $this->GetParam("STORED_AUTH_ID")) > 0)
			{
				$DB->Query("DELETE FROM b_user_stored_auth WHERE ID=".intval($storedAuthId));
			}

			$this->justAuthorized = false;
			$this->admin = null;

			static::$kernelSession["SESS_AUTH"] = array();
			unset(static::$kernelSession["SESS_AUTH"]);
			unset(static::$kernelSession["SESS_OPERATIONS"]);
			unset(static::$kernelSession["MODULE_PERMISSIONS"]);
			unset(static::$kernelSession["SESS_PWD_HASH_TESTED"]);
			unset(static::$kernelSession['fixed_session_id']);

			//change session id for security reason after logout
			if(COption::GetOptionString("security", "session", "N") === "Y" && CModule::IncludeModule("security"))
			{
				CSecuritySession::UpdateSessID();
			}
			else
			{
				$compositeSessionManager = Main\Application::getInstance()->getCompositeSessionManager();
				//todo here was session_regenerate_id(true). Should we delete old?
				$compositeSessionManager->regenerateId();
			}

			$response = Main\Context::getCurrent()->getResponse();
			$spread = (COption::GetOptionString("main", "auth_multisite", "N") == "Y"? (Main\Web\Cookie::SPREAD_SITES | Main\Web\Cookie::SPREAD_DOMAIN) : Main\Web\Cookie::SPREAD_DOMAIN);

			$cookie = new Main\Web\Cookie("UIDH",  "", 0);
			$cookie->setSpread($spread);
			$cookie->setHttpOnly(true);
			$response->addCookie($cookie);

			$cookie = new Main\Web\Cookie("UIDL",  "", 0);
			$cookie->setSpread($spread);
			$cookie->setHttpOnly(true);
			$response->addCookie($cookie);

			Main\Composite\Engine::onUserLogout();
		}

		$arParams["SUCCESS"] = $bOk;
		foreach(GetModuleEvents("main", "OnAfterUserLogout", true) as $arEvent)
			ExecuteModuleEventEx($arEvent, array(&$arParams));

		if(COption::GetOptionString("main", "event_log_logout", "N") === "Y")
			CEventLog::Log("SECURITY", "USER_LOGOUT", "main", $USER_ID);
	}

	public static function GetUserGroup($ID)
	{
		$ID = (int)$ID;
		if (!isset(self::$userGroupCache[$ID]))
		{
			$arr = array();
			$res = static::GetUserGroupEx($ID);
			while ($r = $res->Fetch())
				$arr[] = $r["GROUP_ID"];

			self::$userGroupCache[$ID] = $arr;
		}

		return self::$userGroupCache[$ID];
	}

	public static function GetUserGroupEx($ID)
	{
		global $DB;

		$strSql = "
			SELECT UG.GROUP_ID, G.STRING_ID,
				".$DB->DateToCharFunction("UG.DATE_ACTIVE_FROM", "FULL")." as DATE_ACTIVE_FROM,
				".$DB->DateToCharFunction("UG.DATE_ACTIVE_TO", "FULL")." as DATE_ACTIVE_TO
			FROM b_user_group UG INNER JOIN b_group G ON G.ID=UG.GROUP_ID
			WHERE UG.USER_ID = ".intval($ID)."
				and ((UG.DATE_ACTIVE_FROM IS NULL) OR (UG.DATE_ACTIVE_FROM <= ".$DB->CurrentTimeFunction()."))
				and ((UG.DATE_ACTIVE_TO IS NULL) OR (UG.DATE_ACTIVE_TO >= ".$DB->CurrentTimeFunction()."))
				and G.ACTIVE = 'Y'
			UNION SELECT 2, 'everyone', NULL, NULL ".($DB->type == "ORACLE"? " FROM dual " : "");

		$res = $DB->Query($strSql, false, "FILE: ".__FILE__."<br> LINE: ".__LINE__);

		return $res;
	}

	public static function GetUserGroupList($ID)
	{
		global $DB;

		$strSql = "
			SELECT
				UG.GROUP_ID,
				".$DB->DateToCharFunction("UG.DATE_ACTIVE_FROM", "FULL")." as DATE_ACTIVE_FROM,
				".$DB->DateToCharFunction("UG.DATE_ACTIVE_TO", "FULL")." as DATE_ACTIVE_TO
			FROM
				b_user_group UG
			WHERE
				UG.USER_ID = ".intval($ID)."
			UNION SELECT 2, NULL, NULL ".($DB->type == "ORACLE"? " FROM dual " : "");

		$res = $DB->Query($strSql, false, "FILE: ".__FILE__."<br> LINE: ".__LINE__);

		return $res;
	}

	public function CheckFields(&$arFields, $ID=false)
	{
		/**
		 * @global CMain $APPLICATION
		 * @global CUserTypeManager $USER_FIELD_MANAGER
		 */
		global $DB, $APPLICATION, $USER_FIELD_MANAGER;

		$this->LAST_ERROR = "";

		$bInternal = true;
		if(is_set($arFields, "EXTERNAL_AUTH_ID"))
		{
			if(trim($arFields["EXTERNAL_AUTH_ID"]) <> '')
			{
				$bInternal = false;
			}
		}
		else
		{
			if($ID > 0)
			{
				$dbr = $DB->Query("SELECT EXTERNAL_AUTH_ID FROM b_user WHERE ID=".intval($ID));
				if(($ar = $dbr->Fetch()))
				{
					if($ar['EXTERNAL_AUTH_ID'] <> '')
					{
						$bInternal = false;
					}
				}
			}
		}

		if($bInternal)
		{
			$this->LAST_ERROR .= self::CheckInternalFields($arFields, $ID);
		}
		else
		{
			if(is_set($arFields, "EMAIL"))
			{
				if($arFields["EMAIL"] <> '' && !check_email($arFields["EMAIL"], true))
				{
					$this->LAST_ERROR .= GetMessage("WRONG_EMAIL")."<br>";
				}
			}
		}

		if(is_set($arFields, "PERSONAL_PHOTO") && $arFields["PERSONAL_PHOTO"]["name"] == '' && $arFields["PERSONAL_PHOTO"]["del"] == '')
			unset($arFields["PERSONAL_PHOTO"]);

		$maxWidth = COption::GetOptionInt("main", "profile_image_width", 0);
		$maxHeight = COption::GetOptionInt("main", "profile_image_height", 0);
		$maxSize = COption::GetOptionInt("main", "profile_image_size", 0);

		if(is_set($arFields, "PERSONAL_PHOTO"))
		{
			$res = CFile::CheckImageFile($arFields["PERSONAL_PHOTO"], $maxSize, $maxWidth, $maxHeight);
			if($res <> '')
				$this->LAST_ERROR .= $res."<br>";
		}

		if(is_set($arFields, "PERSONAL_BIRTHDAY") && $arFields["PERSONAL_BIRTHDAY"] <> '' && !CheckDateTime($arFields["PERSONAL_BIRTHDAY"]))
			$this->LAST_ERROR .= GetMessage("WRONG_PERSONAL_BIRTHDAY")."<br>";

		if(is_set($arFields, "WORK_LOGO") && $arFields["WORK_LOGO"]["name"] == '' && $arFields["WORK_LOGO"]["del"] == '')
			unset($arFields["WORK_LOGO"]);

		if(is_set($arFields, "WORK_LOGO"))
		{
			$res = CFile::CheckImageFile($arFields["WORK_LOGO"], $maxSize, $maxWidth, $maxHeight);
			if($res <> '')
				$this->LAST_ERROR .= $res."<br>";
		}

		if(is_set($arFields, "LOGIN"))
		{
			$res = $DB->Query(
				"SELECT 'x' ".
				"FROM b_user ".
				"WHERE LOGIN='".$DB->ForSql($arFields["LOGIN"], 50)."'	".
				"	".($ID===false ? "" : " AND ID<>".intval($ID)).
				"	".(!$bInternal ? "	AND EXTERNAL_AUTH_ID='".$DB->ForSql($arFields["EXTERNAL_AUTH_ID"])."' " : " AND (EXTERNAL_AUTH_ID IS NULL OR ".$DB->Length("EXTERNAL_AUTH_ID")."<=0)")
				);

			if($res->Fetch())
				$this->LAST_ERROR .= str_replace("#LOGIN#", htmlspecialcharsbx($arFields["LOGIN"]), GetMessage("USER_EXIST"))."<br>";
		}

		if(is_object($APPLICATION))
		{
			$APPLICATION->ResetException();

			if($ID===false)
				$events = GetModuleEvents("main", "OnBeforeUserAdd", true);
			else
			{
				$arFields["ID"] = $ID;
				$events = GetModuleEvents("main", "OnBeforeUserUpdate", true);
			}

			foreach($events as $arEvent)
			{
				$bEventRes = ExecuteModuleEventEx($arEvent, array(&$arFields));
				if($bEventRes===false)
				{
					if($err = $APPLICATION->GetException())
						$this->LAST_ERROR .= $err->GetString()." ";
					else
					{
						$APPLICATION->ThrowException("Unknown error");
						$this->LAST_ERROR .= "Unknown error. ";
					}
					break;
				}
			}
		}

		if(is_object($APPLICATION))
			$APPLICATION->ResetException();
		if (!$USER_FIELD_MANAGER->CheckFields("USER", $ID, $arFields))
		{
			if(is_object($APPLICATION) && $APPLICATION->GetException())
			{
				$e = $APPLICATION->GetException();
				$this->LAST_ERROR .= $e->GetString();
				$APPLICATION->ResetException();
			}
			else
			{
				$this->LAST_ERROR .= "Unknown error. ";
			}
		}

		if($this->LAST_ERROR <> '')
			return false;

		return true;
	}

	/**
	 * @param array $arFields
	 * @param int|bool $ID
	 * @return string
	 */
	public static function CheckInternalFields($arFields, $ID = false)
	{
		global $DB;

		$resultError = '';

		$emailRequired = (COption::GetOptionString("main", "new_user_email_required", "Y") <> "N");
		$phoneRequired = (COption::GetOptionString("main", "new_user_phone_required", "N") == "Y");

		if($ID === false)
		{
			if(!isset($arFields["LOGIN"]))
			{
				$resultError .= GetMessage("user_login_not_set")."<br>";
			}

			if(!isset($arFields["PASSWORD"]))
			{
				$resultError .= GetMessage("user_pass_not_set")."<br>";
			}

			if($emailRequired && !isset($arFields["EMAIL"]))
			{
				$resultError .= GetMessage("user_email_not_set")."<br>";
			}

			if($phoneRequired && !isset($arFields["PHONE_NUMBER"]))
			{
				$resultError .= GetMessage("main_user_check_no_phone")."<br>";
			}
		}
		if(is_set($arFields, "LOGIN") && $arFields["LOGIN"] <> trim($arFields["LOGIN"]))
		{
			$resultError .= GetMessage("LOGIN_WHITESPACE")."<br>";
		}

		if(is_set($arFields, "LOGIN") && mb_strlen($arFields["LOGIN"]) < 3)
		{
			$resultError .= GetMessage("MIN_LOGIN")."<br>";
		}

		if(is_set($arFields, "PASSWORD"))
		{
			if(array_key_exists("GROUP_ID", $arFields))
			{
				$arGroups = array();
				if(is_array($arFields["GROUP_ID"]))
				{
					foreach($arFields["GROUP_ID"] as $arGroup)
					{
						if(is_array($arGroup))
						{
							$arGroups[] = $arGroup["GROUP_ID"];
						}
						else
						{
							$arGroups[] = $arGroup;
						}
					}
				}
				$arPolicy = self::GetGroupPolicy($arGroups);
			}
			elseif($ID !== false)
			{
				$arPolicy = self::GetGroupPolicy($ID);
			}
			else
			{
				$arPolicy = self::GetGroupPolicy(array());
			}

			$passwordErrors = self::CheckPasswordAgainstPolicy($arFields["PASSWORD"], $arPolicy, ($ID !== false? $ID : null));
			if(!empty($passwordErrors))
			{
				$resultError .= implode("<br>", $passwordErrors)."<br>";
			}
		}

		if(is_set($arFields, "EMAIL"))
		{
			if(($emailRequired && mb_strlen($arFields["EMAIL"]) < 3) || ($arFields["EMAIL"] <> '' && !check_email($arFields["EMAIL"], true)))
			{
				$resultError .= GetMessage("WRONG_EMAIL")."<br>";
			}
			elseif(COption::GetOptionString("main", "new_user_email_uniq_check", "N") === "Y")
			{
				if($arFields["EMAIL"] <> '')
				{
					$oldEmail = '';
					if($ID > 0)
					{
						//the option 'new_user_email_uniq_check' might have been switched on after the DB already contained identical emails
						//so we let a user to have the old email, but not the existing new one
						$dbr = $DB->Query("SELECT EMAIL FROM b_user WHERE ID=".intval($ID));
						if(($ar = $dbr->Fetch()))
						{
							$oldEmail = $ar['EMAIL'];
						}
					}
					if($ID == false || $arFields["EMAIL"] <> $oldEmail)
					{
						$b = $o = "";
						$res = static::GetList($b, $o,
							array(
								"=EMAIL" => $arFields["EMAIL"],
								"EXTERNAL_AUTH_ID" => $arFields["EXTERNAL_AUTH_ID"]
							),
							array(
								"FIELDS" => array("ID")
							)
						);
						while($ar = $res->Fetch())
						{
							if(intval($ar["ID"]) !== intval($ID))
							{
								$resultError .= GetMessage("USER_WITH_EMAIL_EXIST", array("#EMAIL#" => htmlspecialcharsbx($arFields["EMAIL"])))."<br>";
							}
						}
					}
				}
			}
		}

		if(is_set($arFields, "PASSWORD") && is_set($arFields, "CONFIRM_PASSWORD") && $arFields["PASSWORD"] !== $arFields["CONFIRM_PASSWORD"])
		{
			$resultError .= GetMessage("WRONG_CONFIRMATION")."<br>";
		}

		if(isset($arFields["PHONE_NUMBER"]))
		{
			if($phoneRequired && $arFields["PHONE_NUMBER"] == '')
			{
				$resultError .= GetMessage("main_user_check_no_phone")."<br>";
			}
			elseif($arFields["PHONE_NUMBER"] <> '')
			{
				//normalize the number: we need it normalized for validation
				$phoneNumber = Main\UserPhoneAuthTable::normalizePhoneNumber($arFields["PHONE_NUMBER"]);

				//validation
				$field = Main\UserPhoneAuthTable::getEntity()->getField("PHONE_NUMBER");
				$result = new Main\ORM\Data\Result();
				$primary = ($ID === false? [] : ["USER_ID" => $ID]);
				$field->validateValue($phoneNumber, $primary, [], $result);
				if(!$result->isSuccess())
				{
					$resultError .= implode("<br>", $result->getErrorMessages());
				}
			}
		}

		if(is_array($arFields["GROUP_ID"]) && count($arFields["GROUP_ID"]) > 0)
		{
			if(is_array($arFields["GROUP_ID"][0]) && count($arFields["GROUP_ID"][0]) > 0)
			{
				foreach($arFields["GROUP_ID"] as $arGroup)
				{
					if($arGroup["DATE_ACTIVE_FROM"] <> '' && !CheckDateTime($arGroup["DATE_ACTIVE_FROM"]))
					{
						$error = str_replace("#GROUP_ID#", $arGroup["GROUP_ID"], GetMessage("WRONG_DATE_ACTIVE_FROM"));
						$resultError .= $error."<br>";
					}

					if($arGroup["DATE_ACTIVE_TO"] <> '' && !CheckDateTime($arGroup["DATE_ACTIVE_TO"]))
					{
						$error = str_replace("#GROUP_ID#", $arGroup["GROUP_ID"], GetMessage("WRONG_DATE_ACTIVE_TO"));
						$resultError .= $error."<br>";
					}
				}
			}
		}

		return $resultError;
	}

	public static function GetByID($ID)
	{
		global $USER;

		$ID = intval($ID);

		if($ID < 1)
		{
			//actually, here should be an exception
			$rs = new CDBResult;
			$rs->InitFromArray([]);
			return $rs;
		}

		$userID = (is_object($USER)? intval($USER->GetID()): 0);
		if($userID > 0 && $ID == $userID && is_array(self::$CURRENT_USER))
		{
			$rs = new CDBResult;
			$rs->InitFromArray(self::$CURRENT_USER);
		}
		else
		{
			$by = "id";
			$order = "asc";
			$rs = static::GetList($by, $order, array("ID_EQUAL_EXACT"=>intval($ID)), array("SELECT"=>array("UF_*")));
			if($userID > 0 && $ID == $userID)
			{
				self::$CURRENT_USER = array($rs->Fetch());
				$rs = new CDBResult;
				$rs->InitFromArray(self::$CURRENT_USER);
			}
		}
		return $rs;
	}

	public static function GetByLogin($LOGIN)
	{
		$by = "id";
		$order = "asc";
		$rs = static::GetList($by, $order, array("LOGIN_EQUAL_EXACT"=>$LOGIN), array("SELECT"=>array("UF_*")));
		return $rs;
	}

	public function Update($ID, $arFields, $authActions = true)
	{
		/** @global CUserTypeManager $USER_FIELD_MANAGER */
		global $DB, $USER_FIELD_MANAGER, $CACHE_MANAGER, $USER;

		$ID = intval($ID);

		if(!$this->CheckFields($arFields, $ID))
		{
			$result = false;
			$arFields["RESULT_MESSAGE"] = &$this->LAST_ERROR;
		}
		else
		{
			unset($arFields["ID"]);

			if(is_set($arFields, "ACTIVE") && $arFields["ACTIVE"] != "Y")
				$arFields["ACTIVE"] = "N";
			if(is_set($arFields, "BLOCKED") && $arFields["BLOCKED"] != "Y")
				$arFields["BLOCKED"] = "N";

			if(is_set($arFields, "PERSONAL_GENDER") && ($arFields["PERSONAL_GENDER"]!="M" && $arFields["PERSONAL_GENDER"]!="F"))
				$arFields["PERSONAL_GENDER"] = "";

			$saveHistory = (Main\Config\Option::get("main", "user_profile_history") === "Y");

			//we need old values for some actions
			$arUser = null;
			if((isset($arFields["ACTIVE"]) && $arFields["ACTIVE"] == "N") || isset($arFields["PASSWORD"]) || $saveHistory)
			{
				$rUser = static::GetByID($ID);
				$arUser = $rUser->Fetch();
			}

			$originalPassword = '';
			$passwordChanged = false;
			if(is_set($arFields, "PASSWORD"))
			{
				$originalPassword = $arFields["PASSWORD"];
				$arFields["PASSWORD"] = Password::hash($arFields["PASSWORD"]);

				if($arUser)
				{
					if(!Password::equals($arUser["PASSWORD"], $originalPassword))
					{
						//password changed, remove stored authentication
						$DB->Query("DELETE FROM b_user_stored_auth WHERE USER_ID=".$ID);

						$passwordChanged = true;
					}
				}
				if(COption::GetOptionString("main", "event_log_password_change", "N") === "Y")
					CEventLog::Log("SECURITY", "USER_PASSWORD_CHANGED", "main", $ID);
			}
			unset($arFields["STORED_HASH"]);

			$checkword = '';
			if(!is_set($arFields, "CHECKWORD"))
			{
				if(is_set($arFields, "PASSWORD") || is_set($arFields, "EMAIL") || is_set($arFields, "LOGIN")  || is_set($arFields, "ACTIVE"))
				{
					$checkword = md5(uniqid().CMain::GetServerUniqID());
					$arFields["CHECKWORD"] = Password::hash($checkword);
				}
			}
			else
			{
				$checkword = $arFields["CHECKWORD"];
				$arFields["CHECKWORD"] = Password::hash($checkword);
			}

			if(is_set($arFields, "CHECKWORD") && !is_set($arFields, "CHECKWORD_TIME"))
				$arFields["~CHECKWORD_TIME"] = $DB->CurrentTimeFunction();

			if(is_set($arFields, "WORK_COUNTRY"))
				$arFields["WORK_COUNTRY"] = intval($arFields["WORK_COUNTRY"]);

			if(is_set($arFields, "PERSONAL_COUNTRY"))
				$arFields["PERSONAL_COUNTRY"] = intval($arFields["PERSONAL_COUNTRY"]);

			if (
				array_key_exists("PERSONAL_PHOTO", $arFields)
				&& is_array($arFields["PERSONAL_PHOTO"])
				&& (
					!array_key_exists("MODULE_ID", $arFields["PERSONAL_PHOTO"])
					|| $arFields["PERSONAL_PHOTO"]["MODULE_ID"] == ''
				)
			)
			{
				$arFields["PERSONAL_PHOTO"]["MODULE_ID"] = "main";
			}

			CFile::SaveForDB($arFields, "PERSONAL_PHOTO", "main");

			if (
				array_key_exists("WORK_LOGO", $arFields)
				&& is_array($arFields["WORK_LOGO"])
				&& (
					!array_key_exists("MODULE_ID", $arFields["WORK_LOGO"])
					|| $arFields["WORK_LOGO"]["MODULE_ID"] == ''
				)
			)
			{
				$arFields["WORK_LOGO"]["MODULE_ID"] = "main";
			}

			CFile::SaveForDB($arFields, "WORK_LOGO", "main");

			$strUpdate = $DB->PrepareUpdate("b_user", $arFields);

			if(!is_set($arFields, "TIMESTAMP_X"))
				$strUpdate .= ($strUpdate <> ""? ",":"")." TIMESTAMP_X = ".$DB->GetNowFunction();

			$strSql = "UPDATE b_user SET ".$strUpdate." WHERE ID=".$ID;

			$DB->Query($strSql, false, "FILE: ".__FILE__."<br> LINE: ".__LINE__);

			$USER_FIELD_MANAGER->Update("USER", $ID, $arFields);

			if(isset($arFields["PHONE_NUMBER"]))
			{
				$numberExists = false;
				if($arFields["PHONE_NUMBER"] <> '')
				{
					$arFields["PHONE_NUMBER"] = Main\UserPhoneAuthTable::normalizePhoneNumber($arFields["PHONE_NUMBER"]);

					$numberExists = Main\UserPhoneAuthTable::getList(["filter" => [
						"=USER_ID" => $ID,
						"=PHONE_NUMBER" => $arFields["PHONE_NUMBER"],
					]])->fetch();
				}
				if($arFields["PHONE_NUMBER"] == '' || !$numberExists)
				{
					//number changed or added
					Main\UserPhoneAuthTable::delete($ID);

					if($arFields["PHONE_NUMBER"] <> '')
					{
						Main\UserPhoneAuthTable::add([
							"USER_ID" => $ID,
							"PHONE_NUMBER" => $arFields["PHONE_NUMBER"],
						]);
					}
				}
			}

			if(COption::GetOptionString("main", "event_log_user_edit", "N") === "Y")
			{
				$res_log["user"] = ($arFields["NAME"] != "" || $arFields["LAST_NAME"] != "") ? trim($arFields["NAME"]." ".$arFields["LAST_NAME"]) : $arFields["LOGIN"];
				CEventLog::Log("SECURITY", "USER_EDIT", "main", $ID, serialize($res_log));
			}

			if(is_set($arFields, "GROUP_ID"))
			{
				static::SetUserGroup($ID, $arFields["GROUP_ID"]);
			}

			if($arUser && $passwordChanged)
			{
				if(COption::GetOptionString('main', 'use_digest_auth', 'N') == 'Y')
				{
					//update digest hash for http digest authorization
					static::UpdateDigest($arUser["ID"], $originalPassword);
				}

				//history of passwords
				UserPasswordTable::add([
					"USER_ID" => $arUser["ID"],
					"PASSWORD" => $arFields["PASSWORD"],
					"DATE_CHANGE" => new Main\Type\DateTime(),
				]);
			}

			if($arUser && $authActions == true)
			{
				$authAction = false;
				if(isset($arFields["ACTIVE"]) && $arUser["ACTIVE"] == "Y" && $arFields["ACTIVE"] == "N")
				{
					$authAction = true;
				}

				$internalUser = true;
				if(isset($arFields["EXTERNAL_AUTH_ID"]))
				{
					if($arFields["EXTERNAL_AUTH_ID"] <> '')
					{
						$internalUser = false;
					}
				}
				elseif($arUser["EXTERNAL_AUTH_ID"] <> '')
				{
					$internalUser = false;
				}

				if($internalUser && isset($arFields["PASSWORD"]) && $passwordChanged)
				{
					$authAction = true;
					if(is_object($USER) && $USER->GetID() == $ID)
					{
						//changed password by himself
						$USER->SetParam("AUTH_ACTION_SKIP_LOGOUT", true);
					}
				}

				if($authAction)
				{
					Main\UserAuthActionTable::addLogoutAction($ID);
				}
			}

			$result = true;
			$arFields["CHECKWORD"] = $checkword;

			//update session information and cache for current user
			if(is_object($USER) && $USER->GetID() == $ID)
			{
				static $arSessFields = array(
					'LOGIN'=>'LOGIN', 'EMAIL'=>'EMAIL', 'TITLE'=>'TITLE', 'FIRST_NAME'=>'NAME', 'SECOND_NAME'=>'SECOND_NAME', 'LAST_NAME'=>'LAST_NAME',
					'PERSONAL_PHOTO'=>'PERSONAL_PHOTO', 'PERSONAL_GENDER'=>'PERSONAL_GENDER', 'AUTO_TIME_ZONE'=>'AUTO_TIME_ZONE', 'TIME_ZONE'=>'TIME_ZONE');
				foreach($arSessFields as $key => $val)
					if(isset($arFields[$val]))
						$USER->SetParam($key, $arFields[$val]);
				$name = $USER->GetParam("FIRST_NAME");
				$last_name = $USER->GetParam("LAST_NAME");
				$USER->SetParam("NAME", $name.($name == '' || $last_name == ''? "":" ").$last_name);

				//cache for GetByID()
				self::$CURRENT_USER = false;
			}

			if($saveHistory && $arUser)
			{
				$rUser = static::GetByID($ID);
				$newUser = $rUser->Fetch();

				Main\UserProfileHistoryTable::addHistory($ID, Main\UserProfileHistoryTable::TYPE_UPDATE, $arUser, $newUser);
			}
		}

		$arFields["ID"] = $ID;
		$arFields["RESULT"] = &$result;

		foreach (GetModuleEvents("main", "OnAfterUserUpdate", true) as $arEvent)
			ExecuteModuleEventEx($arEvent, array(&$arFields));

		if($arFields["RESULT"] == true)
		{
			\Bitrix\Main\UserTable::indexRecord($ID);

			if(defined("BX_COMP_MANAGED_CACHE"))
			{
				$userData = \Bitrix\Main\UserTable::getById($ID)->fetch();
				$isRealUser = !$userData['EXTERNAL_AUTH_ID'] || !in_array($userData['EXTERNAL_AUTH_ID'], \Bitrix\Main\UserTable::getExternalUserTypes());

				$CACHE_MANAGER->ClearByTag("USER_CARD_".intval($ID / TAGGED_user_card_size));
				$CACHE_MANAGER->ClearByTag($isRealUser? "USER_CARD": "EXTERNAL_USER_CARD");

				static $arNameFields = array("NAME", "ACTIVE", "LAST_NAME", "SECOND_NAME", "LOGIN", "EMAIL", "PERSONAL_GENDER", "PERSONAL_PHOTO", "WORK_POSITION", "PERSONAL_PROFESSION", "PERSONAL_WWW", "PERSONAL_BIRTHDAY", "TITLE", "EXTERNAL_AUTH_ID", "UF_DEPARTMENT");
				$bClear = false;
				foreach($arNameFields as $val)
				{
					if(isset($arFields[$val]))
					{
						$bClear = true;
						break;
					}
				}
				if ($bClear)
				{
					$CACHE_MANAGER->ClearByTag("USER_NAME_".$ID);
					$CACHE_MANAGER->ClearByTag($isRealUser? "USER_NAME": "EXTERNAL_USER_NAME");
				}
			}
		}

		return $result;
	}

	public static function SetUserGroup($USER_ID, $arGroups, $newUser = false)
	{
		global $DB;

		$USER_ID = intval($USER_ID);

		if ($USER_ID === 0)
		{
			return false;
		}

		//remember previous groups of the user
		$aPrevGroups = array();
		$res = static::GetUserGroupList($USER_ID);
		while($res_arr = $res->Fetch())
			if($res_arr["GROUP_ID"] <> 2)
				$aPrevGroups[$res_arr["GROUP_ID"]] = $res_arr;

		$DB->Query("DELETE FROM b_user_group WHERE USER_ID=".$USER_ID, false, "FILE: ".__FILE__."<br> LINE: ".__LINE__);

		$inserted = array();
		if(is_array($arGroups))
		{
			$values = [];
			foreach($arGroups as $group)
			{
				if(!is_array($group))
				{
					$group = array("GROUP_ID" => $group);
				}
				//we must preserve fields order for the insertion sql
				$groupFields = [
					"GROUP_ID" => $group["GROUP_ID"],
					"DATE_ACTIVE_FROM" => (isset($group["DATE_ACTIVE_FROM"])? $group["DATE_ACTIVE_FROM"] : ''),
					"DATE_ACTIVE_TO" => (isset($group["DATE_ACTIVE_TO"])? $group["DATE_ACTIVE_TO"] : ''),
				];

				$group_id = intval($groupFields["GROUP_ID"]);
				if($group_id > 0 && $group_id <> 2 && !isset($inserted[$group_id]))
				{
					$arInsert = $DB->PrepareInsert("b_user_group", $groupFields);
					$values[] = "(".$USER_ID.",	".$arInsert[1].")";
					$inserted[$group_id] = $groupFields;
				}
			}
			if(!empty($values))
			{
				$strSql = "
					INSERT IGNORE INTO b_user_group (USER_ID, GROUP_ID, DATE_ACTIVE_FROM, DATE_ACTIVE_TO) 
					VALUES ".implode(", ", $values);
				$DB->Query($strSql, false, "FILE: ".__FILE__."<br> LINE: ".__LINE__);
			}
		}
		self::clearUserGroupCache($USER_ID);

		foreach (GetModuleEvents("main", "OnAfterSetUserGroup", true) as $arEvent)
		{
			ExecuteModuleEventEx($arEvent, array("USER_ID"=>$USER_ID, "GROUPS"=>$inserted));
		}

		if($aPrevGroups <> $inserted)
		{
			if($newUser == false)
			{
				$authActionCommon = false;
				$now = new Main\Type\DateTime();
				foreach($inserted as $group)
				{
					foreach(array("DATE_ACTIVE_FROM", "DATE_ACTIVE_TO") as $field)
					{
						if($group[$field] <> '')
						{
							$date = Main\Type\DateTime::createFromUserTime($group[$field]);
							if($date > $now)
							{
								//group membership is in the future, we need separate records for each group
								Main\UserAuthActionTable::addUpdateAction($USER_ID, $date);
							}
							else
							{
								$authActionCommon = true;
							}
						}
						else
						{
							$authActionCommon = true;
						}
					}
				}

				if($authActionCommon == true)
				{
					//one action for all groups without dates in the future
					Main\UserAuthActionTable::addUpdateAction($USER_ID);
				}
			}

			if(COption::GetOptionString("main", "event_log_user_groups", "N") === "Y")
			{
				$UserName = '';
				$rsUser = static::GetByID($USER_ID);
				if($arUser = $rsUser->GetNext())
					$UserName = ($arUser["NAME"] != "" || $arUser["LAST_NAME"] != "") ? trim($arUser["NAME"]." ".$arUser["LAST_NAME"]) : $arUser["LOGIN"];
				$res_log = array(
					"groups" => serialize($aPrevGroups)." => ".serialize($inserted),
					"user" => $UserName
				);
				CEventLog::Log("SECURITY", "USER_GROUP_CHANGED", "main", $USER_ID, serialize($res_log));
			}
		}
		return null;
	}

	/**
	 * Appends groups to the list of existing user's groups.
	 *
	 * @param int $user_id
	 * @param array|int $groups A single number, or an array of numbers, or an array of arrays("GROUP_ID"=>$val, "DATE_ACTIVE_FROM"=>$val, "DATE_ACTIVE_TO"=>$val)
	 */
	public static function AppendUserGroup($user_id, $groups)
	{
		$arGroups = array();
		$res = static::GetUserGroupList($user_id);
		while($res_arr = $res->Fetch())
		{
			$arGroups[] = array(
				"GROUP_ID" => $res_arr["GROUP_ID"],
				"DATE_ACTIVE_FROM" => $res_arr["DATE_ACTIVE_FROM"],
				"DATE_ACTIVE_TO" => $res_arr["DATE_ACTIVE_TO"],
			);
		}

		if(!is_array($groups))
		{
			$groups = array($groups);
		}

		foreach($groups as $group)
		{
			if(!is_array($group))
			{
				$group = array("GROUP_ID" => $group);
			}
			$arGroups[] = $group;
		}

		static::SetUserGroup($user_id, $arGroups);
	}

	public static function GetCount()
	{
		global $DB;
		$r = $DB->Query("SELECT COUNT('x') as C FROM b_user");
		$r = $r->Fetch();
		return intval($r["C"]);
	}

	public static function Delete($ID)
	{
		/** @global CMain $APPLICATION */
		/** @global CUserTypeManager $USER_FIELD_MANAGER */
		global $DB, $APPLICATION, $USER_FIELD_MANAGER, $CACHE_MANAGER;

		$ID = intval($ID);

		@set_time_limit(600);

		$rsUser = $DB->Query("SELECT ID, LOGIN, NAME, LAST_NAME, EXTERNAL_AUTH_ID FROM b_user WHERE ID=".$ID." AND ID<>1");
		$arUser = $rsUser->Fetch();
		if(!$arUser)
			return false;

		$events = array_merge(GetModuleEvents("main", "OnBeforeUserDelete", true), GetModuleEvents("main", "OnUserDelete", true));

		foreach($events as $arEvent)
		{
			if(ExecuteModuleEventEx($arEvent, array($ID))===false)
			{
				$err = GetMessage("MAIN_BEFORE_DEL_ERR1").' '.$arEvent['TO_MODULE_ID'];
				if($ex = $APPLICATION->GetException())
					$err .= ': '.$ex->GetString();
				$APPLICATION->throwException($err);
				if(COption::GetOptionString("main", "event_log_user_delete", "N") === "Y")
				{
					$UserName = ($arUser["NAME"] != "" || $arUser["LAST_NAME"] != "") ? trim($arUser["NAME"]." ".$arUser["LAST_NAME"]) : $arUser["LOGIN"];
					$res_log = array(
						"user" => $UserName,
						"err" => $err
					);
					CEventLog::Log("SECURITY", "USER_DELETE", "main", $ID, serialize($res_log));
				}
				return false;
			}
		}

		$strSql = "SELECT F.ID FROM	b_user U, b_file F WHERE U.ID='$ID' and (F.ID=U.PERSONAL_PHOTO or F.ID=U.WORK_LOGO)";
		$z = $DB->Query($strSql, false, "FILE: ".__FILE__." LINE:".__LINE__);
		while ($zr = $z->Fetch())
			CFile::Delete($zr["ID"]);

		CAccess::OnUserDelete($ID);

		if(!$DB->Query("DELETE FROM b_user_group WHERE USER_ID=".$ID))
			return false;

		if(!$DB->Query("DELETE FROM b_user_digest WHERE USER_ID=".$ID))
			return false;

		if(!$DB->Query("DELETE FROM b_app_password WHERE USER_ID=".$ID))
			return false;

		Main\UserPhoneAuthTable::delete($ID);

		Main\Authentication\ShortCode::deleteByUser($ID);

		UserPasswordTable::deleteByFilter(["=USER_ID" => $ID]);

		$USER_FIELD_MANAGER->Delete("USER", $ID);

		if(COption::GetOptionString("main", "event_log_user_delete", "N") === "Y")
		{
			$res_log["user"] = ($arUser["NAME"] != "" || $arUser["LAST_NAME"] != "") ? trim($arUser["NAME"]." ".$arUser["LAST_NAME"]) : $arUser["LOGIN"];
			CEventLog::Log("SECURITY", "USER_DELETE", "main", $arUser["LOGIN"], serialize($res_log));
		}

		if(!$DB->Query("DELETE FROM b_user WHERE ID=".$ID." AND ID<>1"))
			return false;

		if(defined("BX_COMP_MANAGED_CACHE"))
		{
			$isRealUser = !$arUser['EXTERNAL_AUTH_ID'] || !in_array($arUser['EXTERNAL_AUTH_ID'], \Bitrix\Main\UserTable::getExternalUserTypes());

			$CACHE_MANAGER->ClearByTag("USER_CARD_".intval($ID / TAGGED_user_card_size));
			$CACHE_MANAGER->ClearByTag($isRealUser? "USER_CARD": "EXTERNAL_USER_CARD");

			$CACHE_MANAGER->ClearByTag("USER_NAME_".$ID);
			$CACHE_MANAGER->ClearByTag($isRealUser? "USER_NAME": "EXTERNAL_USER_CARD");
		}

		self::clearUserGroupCache($ID);

		Main\UserAuthActionTable::addLogoutAction($ID);

		if(Main\Config\Option::get("main", "user_profile_history") === "Y")
		{
			Main\UserProfileHistoryTable::deleteByUser($ID);
			Main\UserProfileHistoryTable::addHistory($ID, Main\UserProfileHistoryTable::TYPE_DELETE);
		}

		\Bitrix\Main\UserTable::deleteIndexRecord($ID);

		foreach(GetModuleEvents("main", "OnAfterUserDelete", true) as $arEvent)
		{
			ExecuteModuleEventEx($arEvent, array($ID));
		}

		return true;
	}

	public static function GetExternalAuthList()
	{
		$arAll = array();
		foreach(GetModuleEvents("main", "OnExternalAuthList", true) as $arEvent)
		{
			$arRes = ExecuteModuleEventEx($arEvent);
			if(is_array($arRes))
			{
				foreach($arRes as $v)
				{
					$arAll[] = $v;
				}
			}
		}

		$result = new CDBResult;
		$result->InitFromArray($arAll);
		return $result;
	}

	public static function GetGroupPolicy($iUserId)
	{
		global $DB;
		static $arPOLICY_CACHE;
		if(!is_array($arPOLICY_CACHE))
			$arPOLICY_CACHE = array();
		$CACHE_ID = md5(serialize($iUserId));
		if(array_key_exists($CACHE_ID, $arPOLICY_CACHE))
			return $arPOLICY_CACHE[$CACHE_ID];

		global $BX_GROUP_POLICY;
		$arPolicy = $BX_GROUP_POLICY;
		if($arPolicy["SESSION_TIMEOUT"]<=0)
			$arPolicy["SESSION_TIMEOUT"] = ini_get("session.gc_maxlifetime")/60;

		$arSql = array();
		$arSql[] =
			"SELECT G.SECURITY_POLICY ".
			"FROM b_group G ".
			"WHERE G.ID=2";

		if(is_array($iUserId))
		{
			$arGroups = array();
			foreach($iUserId as $value)
			{
				$value = intval($value);
				if($value > 0 && $value != 2)
					$arGroups[$value] = $value;
			}
			if(count($arGroups) > 0)
			{
				$arSql[] =
					"SELECT G.ID GROUP_ID, G.SECURITY_POLICY ".
					"FROM b_group G ".
					"WHERE G.ID in (".implode(", ", $arGroups).")";
			}
		}
		elseif(intval($iUserId) > 0)
		{
			$arSql[] =
				"SELECT UG.GROUP_ID, G.SECURITY_POLICY ".
				"FROM b_user_group UG, b_group G ".
				"WHERE UG.USER_ID = ".intval($iUserId)." ".
				"	AND UG.GROUP_ID = G.ID ".
				"	AND ((UG.DATE_ACTIVE_FROM IS NULL) OR (UG.DATE_ACTIVE_FROM <= ".$DB->CurrentTimeFunction().")) ".
				"	AND ((UG.DATE_ACTIVE_TO IS NULL) OR (UG.DATE_ACTIVE_TO >= ".$DB->CurrentTimeFunction().")) ";
		}

		foreach($arSql as $strSql)
		{
			$res = $DB->Query($strSql, false, "FILE: ".__FILE__."<br> LINE: ".__LINE__);
			while($ar = $res->Fetch())
			{
				if($ar["SECURITY_POLICY"])
					$arGroupPolicy = unserialize($ar["SECURITY_POLICY"]);
				else
					continue;

				if(!is_array($arGroupPolicy))
					continue;

				foreach($arGroupPolicy as $key=>$val)
				{
					switch($key)
					{
					case "STORE_IP_MASK":
					case "SESSION_IP_MASK":
					case "BLOCK_TIME":
					case "PASSWORD_UNIQUE_COUNT":
						if($arPolicy[$key]<$val)
							$arPolicy[$key] = $val;
						break;
					case "SESSION_TIMEOUT":
						if($arPolicy[$key]<=0 || $arPolicy[$key]>$val)
							$arPolicy[$key] = $val;
						break;
					case "PASSWORD_LENGTH":
						if($arPolicy[$key]<=0 || $arPolicy[$key] < $val)
							$arPolicy[$key] = $val;
						break;
					case "PASSWORD_UPPERCASE":
					case "PASSWORD_LOWERCASE":
					case "PASSWORD_DIGITS":
					case "PASSWORD_PUNCTUATION":
						if($val === "Y")
							$arPolicy[$key] = "Y";
						break;
					case "LOGIN_ATTEMPTS":
					case "BLOCK_LOGIN_ATTEMPTS":
					case "PASSWORD_CHANGE_DAYS":
						if($val > 0 && ($arPolicy[$key] <= 0 || $arPolicy[$key] > $val))
							$arPolicy[$key] = $val;
						break;
					default:
						if($arPolicy[$key]>$val)
							$arPolicy[$key] = $val;
					}
				}
			}
			if($arPolicy["PASSWORD_LENGTH"] === false)
				$arPolicy["PASSWORD_LENGTH"] = 6;
		}
		$ar = array(
			GetMessage("MAIN_GP_PASSWORD_LENGTH", array("#LENGTH#" => intval($arPolicy["PASSWORD_LENGTH"])))
		);
		if($arPolicy["PASSWORD_UPPERCASE"] === "Y")
			$ar[] = GetMessage("MAIN_GP_PASSWORD_UPPERCASE");
		if($arPolicy["PASSWORD_LOWERCASE"] === "Y")
			$ar[] = GetMessage("MAIN_GP_PASSWORD_LOWERCASE");
		if($arPolicy["PASSWORD_DIGITS"] === "Y")
			$ar[] = GetMessage("MAIN_GP_PASSWORD_DIGITS");
		if($arPolicy["PASSWORD_PUNCTUATION"] === "Y")
			$ar[] = GetMessage("MAIN_GP_PASSWORD_PUNCTUATION");
		$arPolicy["PASSWORD_REQUIREMENTS"] = implode(", ", $ar).".";

		if(count($arPOLICY_CACHE)<=10)
			$arPOLICY_CACHE[$CACHE_ID] = $arPolicy;

		return $arPolicy;
	}

	public static function CheckStoredHash($iUserId, $sHash, $bTempHashOnly=false)
	{
		global $DB;
		$arPolicy = static::GetGroupPolicy($iUserId);

		$cnt = 0;
		$auth_id = false;
		$site_format = CSite::GetDateFormat();

		CTimeZone::Disable();
		$strSql =
			"SELECT A.*, ".
			"	".$DB->DateToCharFunction("A.DATE_REG", "FULL")." as DATE_REG, ".
			"	".$DB->DateToCharFunction("A.LAST_AUTH", "FULL")." as LAST_AUTH ".
			"FROM b_user_stored_auth A ".
			"WHERE A.USER_ID = ".intval($iUserId)." ".
			"ORDER BY A.LAST_AUTH DESC";
		$res = $DB->Query($strSql);
		CTimeZone::Enable();

		while($ar = $res->Fetch())
		{
			if($ar["TEMP_HASH"]=="N")
				$cnt++;
			if($arPolicy["MAX_STORE_NUM"] < $cnt
				|| ($ar["TEMP_HASH"]=="N" && time()-$arPolicy["STORE_TIMEOUT"]*60 > MakeTimeStamp($ar["LAST_AUTH"], $site_format))
				|| ($ar["TEMP_HASH"]=="Y" && time()-$arPolicy["SESSION_TIMEOUT"]*60 > MakeTimeStamp($ar["LAST_AUTH"], $site_format))
			)
			{
				$DB->Query("DELETE FROM b_user_stored_auth WHERE ID=".$ar["ID"]);
			}
			elseif(!$auth_id)
			{
				//for domain spreaded external auth we should check only temporary hashes
				if($bTempHashOnly == false || $ar["TEMP_HASH"] == "Y")
				{
					$remote_net = ip2long($arPolicy["STORE_IP_MASK"]) & ip2long($_SERVER["REMOTE_ADDR"]);
					$stored_net = ip2long($arPolicy["STORE_IP_MASK"]) & (float)$ar["IP_ADDR"];
					if($sHash === $ar["STORED_HASH"] && $remote_net == $stored_net)
						$auth_id = $ar["ID"];
				}
			}
		}
		return $auth_id;
	}

	public function GetAllOperations($arGroups = false)
	{
		global $DB;

		if (is_array($arGroups))
		{
			$userGroups = "2,".implode(",", array_map("intval", $arGroups));
		}
		else
		{
			$userGroups = $this->GetGroups();
		}

		$sql_str = "
			SELECT O.NAME OPERATION_NAME
			FROM b_group_task GT
				INNER JOIN b_task_operation T_O ON T_O.TASK_ID=GT.TASK_ID
				INNER JOIN b_operation O ON O.ID=T_O.OPERATION_ID
			WHERE GT.GROUP_ID IN(".$userGroups.")
			UNION
			SELECT O.NAME OPERATION_NAME
			FROM b_option OP
				INNER JOIN b_task_operation T_O ON T_O.TASK_ID=".$DB->ToChar("OP.VALUE", 18)."
				INNER JOIN b_operation O ON O.ID=T_O.OPERATION_ID
			WHERE OP.NAME='GROUP_DEFAULT_TASK'
			UNION
			SELECT O.NAME OPERATION_NAME
			FROM b_option OP
				INNER JOIN b_task T ON T.MODULE_ID=OP.MODULE_ID AND T.BINDING='module' AND T.LETTER=".$DB->ToChar("OP.VALUE", 1)." AND T.SYS='Y'
				INNER JOIN b_task_operation T_O ON T_O.TASK_ID=T.ID
				INNER JOIN b_operation O ON O.ID=T_O.OPERATION_ID
			WHERE OP.NAME='GROUP_DEFAULT_RIGHT'
		";

		$z = $DB->Query($sql_str, false, "FILE: ".__FILE__."<br> LINE: ".__LINE__);
		$arr = array();
		while($r = $z->Fetch())
			$arr[$r['OPERATION_NAME']] = $r['OPERATION_NAME'];

		return $arr;
	}

	public function CanDoOperation($op_name, $user_id = 0)
	{
		if ($user_id > 0)
		{
			$arGroups = array();
			$rsGroups = $this->GetUserGroupEx($user_id);
			while ($group = $rsGroups->Fetch())
			{
				$arGroups[] = $group["GROUP_ID"];
			}
			if (!$arGroups)
				return false;

			$op = $this->GetAllOperations($arGroups);
			return isset($op[$op_name]);
		}
		else
		{
			if ($this->IsAdmin())
				return true;

			if(!isset(static::$kernelSession["SESS_OPERATIONS"]))
				static::$kernelSession["SESS_OPERATIONS"] = $this->GetAllOperations();

			return isset(static::$kernelSession["SESS_OPERATIONS"][$op_name]);
		}
	}

	public static function GetFileOperations($arPath, $arGroups=false)
	{
		/** @global CMain $APPLICATION */
		global $APPLICATION;

		$ar = $APPLICATION->GetFileAccessPermission($arPath, $arGroups, true);
		$arFileOperations = array();

		for ($i = 0, $len = count($ar); $i < $len; $i++)
			$arFileOperations = array_merge($arFileOperations, CTask::GetOperations($ar[$i], true));
		$arFileOperations = array_values(array_unique($arFileOperations));

		return $arFileOperations;
	}


	public function CanDoFileOperation($op_name, $arPath)
	{
		global $APPLICATION, $USER;

		if ($this->IsAdmin())
			return true;

		if(!isset($APPLICATION->FILEMAN_OPERATION_CACHE))
			$APPLICATION->FILEMAN_OPERATION_CACHE = array();

		$k = addslashes($arPath[0].'|'.$arPath[1]);
		if(array_key_exists($k, $APPLICATION->FILEMAN_OPERATION_CACHE))
		{
			$arFileOperations = $APPLICATION->FILEMAN_OPERATION_CACHE[$k];
		}
		else
		{
			$arFileOperations = $this->GetFileOperations($arPath);
			$APPLICATION->FILEMAN_OPERATION_CACHE[$k] = $arFileOperations;
		}

		$arAlowedOperations = array('fm_delete_file','fm_rename_folder','fm_view_permission');
		if(mb_substr($arPath[1], -10) == "/.htaccess" && !$USER->CanDoOperation('edit_php') && !in_array($op_name,$arAlowedOperations))
			return false;
		if(mb_substr($arPath[1], -12) == "/.access.php")
			return false;

		return in_array($op_name, $arFileOperations);
	}

	public static function UserTypeRightsCheck($entity_id)
	{
		global $USER;

		if($entity_id == "USER" && $USER->CanDoOperation('edit_other_settings'))
		{
			return "W";
		}
		else
			return "D";
	}

	public function CanAccess($arCodes)
	{
		if(!is_array($arCodes) || empty($arCodes))
			return false;

		if(in_array('G2', $arCodes))
			return true;

		if($this->IsAuthorized() && in_array('AU', $arCodes))
			return true;

		$bEmpty = true;
		foreach($arCodes as $code)
		{
			if(trim($code) <> '')
			{
				$bEmpty = false;
				break;
			}
		}

		if($bEmpty)
			return false;

		$res = CAccess::GetUserCodes($this->GetID(), array("ACCESS_CODE"=>$arCodes));
		if($res->Fetch())
			return true;

		return false;
	}

	public function GetAccessCodes()
	{
		if(!$this->IsAuthorized())
			return array('G2');

		static $arCodes = array();

		$USER_ID = intval($this->GetID());

		if(!array_key_exists($USER_ID, $arCodes))
		{
			$arCodes[$USER_ID] = CAccess::GetUserCodesArray($USER_ID);

			if($this->IsAuthorized())
				$arCodes[$USER_ID][] = "AU";
		}

		return $arCodes[$USER_ID];
	}

	public static function CleanUpAgent()
	{
		$cleanup_days = COption::GetOptionInt("main", "new_user_registration_cleanup_days", 7);
		if($cleanup_days > 0)
		{
			$date = new Main\Type\Date();
			$date->add("-{$cleanup_days}D");

			if(COption::GetOptionString("main", "new_user_registration_email_confirmation", "N") === "Y")
			{
				//unconfirmed email confirmations
				$filter = array(
					"!CONFIRM_CODE" => false,
					"=ACTIVE" => "N",
					"<DATE_REGISTER" => $date,
				);
				$users = Main\UserTable::getList([
					"filter" => $filter,
					"select" => ["ID"],
				]);
				while($user = $users->fetch())
				{
					static::Delete($user["ID"]);
				}
			}
			if(COption::GetOptionString("main", "new_user_phone_auth", "N") === "Y")
			{
				//unconfirmed phone confirmations
				$filter = array(
					'=\Bitrix\Main\UserPhoneAuthTable:USER.CONFIRMED' => "N",
					"=ACTIVE" => "N",
					"<DATE_REGISTER" => $date,
				);
				$users = Main\UserTable::getList([
					"filter" => $filter,
					"select" => ["ID"],
				]);
				while($user = $users->fetch())
				{
					static::Delete($user["ID"]);
				}
			}
		}
		return "CUser::CleanUpAgent();";
	}

	public static function DeactivateAgent()
	{
		$blockDays = COption::GetOptionInt("main", "inactive_users_block_days", 0);
		if($blockDays > 0)
		{
			$log = (COption::GetOptionString("main", "event_log_block_user", "N") === "Y");

			$userObj = new CUser();

			$date = new Main\Type\Date();
			$date->add("-{$blockDays}D");

			$filter = array(
				"=ACTIVE" => "Y",
				"=BLOCKED" => "N",
				"<LAST_LOGIN" => $date,
			);
			$users = Main\UserTable::getList([
				"filter" => $filter,
				"select" => ["ID"],
			]);
			while($user = $users->fetch())
			{
				$groups = static::GetUserGroup($user["ID"]);
				$admin = in_array(1, $groups);

				//admins shouldn't be blocked
				if($admin == false)
				{
					$userObj->Update($user["ID"], ["BLOCKED" => "Y"], false);

					if($log)
					{
						CEventLog::Log("SECURITY", "USER_BLOCKED", "main", $user["ID"], "Inactive days: {$blockDays}");
					}
				}
			}
		}
		return "CUser::DeactivateAgent();";
	}

	public static function UnblockAgent($userId)
	{
		$user = new CUser();
		$user->Update($userId, ["BLOCKED" => "N"]);

		return "";
	}

	public static function GetActiveUsersCount()
	{
		global $DB;

		$q = "SELECT COUNT(ID) as C FROM b_user WHERE ACTIVE = 'Y' AND LAST_LOGIN IS NOT NULL";
		if (IsModuleInstalled("intranet"))
			$q = "SELECT COUNT(U.ID) as C FROM b_user U WHERE U.ACTIVE = 'Y' AND U.LAST_LOGIN IS NOT NULL AND EXISTS(SELECT 'x' FROM b_utm_user UF, b_user_field F WHERE F.ENTITY_ID = 'USER' AND F.FIELD_NAME = 'UF_DEPARTMENT' AND UF.FIELD_ID = F.ID AND UF.VALUE_ID = U.ID AND UF.VALUE_INT IS NOT NULL AND UF.VALUE_INT <> 0)";

		$dbRes = $DB->Query($q, true);
		if ($dbRes && ($arRes = $dbRes->Fetch()))
			return $arRes["C"];
		else
			return 0;
	}

	public static function SetLastActivityDate($userId = null, $cache = false)
	{
		global $USER;

		if (is_null($userId))
		{
			$userId = $USER->GetId();
		}

		$userId = intval($userId);
		if ($userId <= 0)
		{
			return false;
		}

		if($userId == $USER->GetId())
		{
			if ($cache)
			{
				if (intval($USER->GetParam('SET_LAST_ACTIVITY'))+60 > time())
				{
					return false;
				}
			}

			$USER->SetParam('PREV_LAST_ACTIVITY', $USER->GetParam('SET_LAST_ACTIVITY'));
			$USER->SetParam('SET_LAST_ACTIVITY', time());
		}

		self::SetLastActivityDateByArray(array($userId), $_SERVER['REMOTE_ADDR']);

		return true;
	}

	public static function SetLastActivityDateByArray($arUsers, $ip = null)
	{
		global $DB;

		if (!is_array($arUsers) || count($arUsers) <= 0)
			return false;

		$strSqlPrefix = "UPDATE b_user SET ".
			"TIMESTAMP_X = ".($DB->type == "ORACLE"? "NULL":"TIMESTAMP_X").", ".
			"LAST_ACTIVITY_DATE = ".$DB->CurrentTimeFunction()." WHERE ID IN (";
		$strSqlPostfix = ")";
		$maxValuesLen = 2048;
		$strSqlValues = "";

		$arUsers = array_map("intval", $arUsers);
		foreach($arUsers as $userId)
		{
			$strSqlValues .= ",$userId";
			if(mb_strlen($strSqlValues) > $maxValuesLen)
			{
				$DB->Query($strSqlPrefix.mb_substr($strSqlValues, 1).$strSqlPostfix, false, "", array("ignore_dml"=>true));
				$strSqlValues = "";
			}
		}

		if($strSqlValues <> '')
		{
			$DB->Query($strSqlPrefix.mb_substr($strSqlValues, 1).$strSqlPostfix, false, "", array("ignore_dml"=>true));
		}

		$event = new \Bitrix\Main\Event("main", "OnUserSetLastActivityDate", array($arUsers, $ip));
		$event->send();

		return true;
	}

	public static function GetSecondsForLimitOnline()
	{
		return \Bitrix\Main\UserTable::getSecondsForLimitOnline();
	}

	public static function GetExternalUserTypes()
	{
		return Main\UserTable::getExternalUserTypes();
	}

	public static function GetOnlineStatus($userId, $lastseen, $now = false)
	{
		$userId = intval($userId);

		if ($lastseen instanceof \Bitrix\Main\Type\DateTime)
		{
			$lastseen = $lastseen->getTimestamp();
		}
		else if (is_int($lastseen))
		{
			$lastseen = intval($lastseen);
		}
		else
		{
			$lastseen = 0;
		}

		if ($now === false)
		{
			$now = time();
		}
		else if ($now instanceof \Bitrix\Main\Type\DateTime)
		{
			$now = $now->getTimestamp();
		}
		else
		{
			$now = intval($now);
		}

		$result = Array(
			'IS_ONLINE' => false,
			'STATUS' => self::STATUS_OFFLINE,
			'STATUS_TEXT' =>  GetMessage('USER_STATUS_OFFLINE'),
			'LAST_SEEN' => $lastseen,
			'LAST_SEEN_TEXT' => "",
			'NOW' => $now,
		);

		if ($lastseen === false)
		{
			return $result;
		}

		$result['IS_ONLINE'] = $now - $lastseen <= self::GetSecondsForLimitOnline();
		$result['STATUS'] = $result['IS_ONLINE']? self::STATUS_ONLINE: self::STATUS_OFFLINE;
		$result['STATUS_TEXT'] = GetMessage('USER_STATUS_'.strtoupper($result['STATUS']));

		if ($lastseen && $now - $lastseen > 300)
		{
			$result['LAST_SEEN_TEXT'] = self::FormatLastActivityDate($lastseen, $now);
		}

		if ($userId > 0)
		{
			if ($result['IS_ONLINE'])
			{
				foreach(GetModuleEvents("main", "OnUserOnlineStatusGetCustomOnlineStatus", true) as $arEvent)
				{
					$customStatus = ExecuteModuleEventEx($arEvent, array($userId, $lastseen, $now, self::STATUS_ONLINE));
					if (is_array($customStatus))
					{
						if (!empty($customStatus['STATUS']) && !empty($customStatus['STATUS_TEXT']))
						{
							$result['STATUS'] = strtolower($customStatus['STATUS']);
							$result['STATUS_TEXT'] = $customStatus['STATUS_TEXT'];
						}
						if (isset($customStatus['LAST_SEEN']) && intval($customStatus['LAST_SEEN']) > 0)
						{
							$result['LAST_SEEN'] = intval($customStatus['LAST_SEEN']);
						}
						if (isset($customStatus['LAST_SEEN_TEXT']))
						{
							$result['LAST_SEEN_TEXT'] = $customStatus['LAST_SEEN_TEXT'];
						}
					}
				}
			}
			else
			{
				foreach(GetModuleEvents("main", "OnUserOnlineStatusGetCustomOfflineStatus", true) as $arEvent)
				{
					$customStatus = ExecuteModuleEventEx($arEvent, array($userId, $lastseen, $now, self::STATUS_OFFLINE));
					if (is_array($customStatus))
					{
						if (!empty($customStatus['STATUS']) && !empty($customStatus['STATUS_TEXT']))
						{
							$result['STATUS'] = strtolower($customStatus['STATUS']);
							$result['STATUS_TEXT'] = $customStatus['STATUS_TEXT'];
						}
						if (isset($customStatus['LAST_SEEN']) && intval($customStatus['LAST_SEEN']) > 0)
						{
							$result['LAST_SEEN'] = intval($customStatus['LAST_SEEN']);
						}
						if (isset($customStatus['LAST_SEEN_TEXT']))
						{
							$result['LAST_SEEN_TEXT'] = $customStatus['LAST_SEEN_TEXT'];
						}
					}
				}
			}
		}

		return $result;
	}

	/**
	 * @param int|bool|\Bitrix\Main\Type\DateTime $timestamp
	 * @param int|bool|\Bitrix\Main\Type\DateTime $now
	 *
	 * @return string
	 */
	public static function FormatLastActivityDate($timestamp, $now = false)
	{
		global $DB;

		if ($timestamp instanceof \Bitrix\Main\Type\DateTime)
		{
			$timestamp = $timestamp->getTimestamp();
		}
		else if (is_int($timestamp))
		{
			$timestamp = intval($timestamp);
		}
		else
		{
			return "";
		}

		if ($now === false)
		{
			$now = time();
		}
		else if ($now instanceof \Bitrix\Main\Type\DateTime)
		{
			$now = $now->getTimestamp();
		}
		else
		{
			$now = intval($now);
		}

		$ampm = IsAmPmMode(true);
		$timeFormat = ($ampm === AM_PM_LOWER? "g:i a" : ($ampm === AM_PM_UPPER? "g:i A" : "H:i"));

		$formattedDate = FormatDate(array(
			"tomorrow" => "#01#{$timeFormat}",
			"now" => "#02#",
			"todayFuture" => "#03#{$timeFormat}",
			"yesterday" => "#04#{$timeFormat}",
			"-" => preg_replace('/:s$/', '', $DB->DateFormatToPHP(CSite::GetDateFormat("FULL"))),
			"s60" => "sago",
			"i60" => "iago",
			"H5" => "Hago",
			"H24" => "#03#{$timeFormat}",
			"d31" => "dago",
			"m12>1" => "mago",
			"m12>0" => "dago",
			"" => "#05#",
		), $timestamp, $now);

		if (preg_match('/^#(\d+)#(.*)/', $formattedDate, $match))
		{
			switch($match[1])
			{
				case "01":
					$formattedDate = str_replace("#TIME#", $match[2], GetMessage('USER_LAST_SEEN_TOMORROW'));
				break;
				case "02":
					$formattedDate = GetMessage('USER_LAST_SEEN_NOW');
				break;
				case "03":
					$formattedDate = str_replace("#TIME#", $match[2], GetMessage('USER_LAST_SEEN_TODAY'));
				break;
				case "04":
					$formattedDate = str_replace("#TIME#", $match[2], GetMessage('USER_LAST_SEEN_YESTERDAY'));
				break;
				case "05":
					$formattedDate = GetMessage('USER_LAST_SEEN_MORE_YEAR');
				break;
				default:
					$formattedDate = $match[2];
				break;
			}
		}

		return $formattedDate;
	}

	public static function SearchUserByName($arName, $email = "", $bLoginMode = false)
	{
		global $DB;

		$arNameReady = array();
		foreach ($arName as $s)
		{
			$s = Trim($s);
			if ($s <> '')
				$arNameReady[] = $s;
		}

		if (Count($arNameReady) <= 0)
			return false;

		$strSqlWhereEMail = (($email <> '') ? " AND upper(U.EMAIL) = upper('".$DB->ForSql($email)."') " : "");

		if ($bLoginMode)
		{
			if (count($arNameReady) > 3)
			{
				$strSql =
					"SELECT U.ID, U.NAME, U.LAST_NAME, U.SECOND_NAME, U.LOGIN, U.EMAIL ".
					"FROM b_user U ".
					"WHERE (";
				$bFirst = true;
				for ($i = 0; $i < 4; $i++)
				{
					for ($j = 0; $j < 4; $j++)
					{
						if ($i == $j)
							continue;

						for ($k = 0; $k < 4; $k++)
						{
							if ($i == $k || $j == $k)
								continue;

							for ($l = 0; $l < 4; $l++)
							{
								if ($i == $l || $j == $l || $k == $l)
									continue;

								if (!$bFirst)
									$strSql .= " OR ";

								$strSql .= "(U.NAME IS NOT NULL AND upper(U.NAME) LIKE upper('".$DB->ForSql($arNameReady[$i])."%') ".
									"AND U.LAST_NAME IS NOT NULL AND upper(U.LAST_NAME) LIKE upper('".$DB->ForSql($arNameReady[$j])."%') ".
									"AND U.LOGIN IS NOT NULL AND upper(U.LOGIN) LIKE upper('".$DB->ForSql($arNameReady[$k])."%') ".
									"AND U.EMAIL IS NOT NULL AND upper(U.EMAIL) LIKE upper('".$DB->ForSql($arNameReady[$l])."%'))";

								$bFirst = false;
							}
						}
					}
				}
				$strSql .= ")";
			}
			elseif (Count($arNameReady) == 3)
			{
				$strSql =
					"SELECT U.ID, U.NAME, U.LAST_NAME, U.SECOND_NAME, U.LOGIN, U.EMAIL ".
					"FROM b_user U ".
					"WHERE (";
				$bFirst = true;
				for ($i = 0; $i < 3; $i++)
				{
					for ($j = 0; $j < 3; $j++)
					{
						if ($i == $j)
							continue;

						for ($k = 0; $k < 3; $k++)
						{
							if ($i == $k || $j == $k)
								continue;

							if (!$bFirst)
								$strSql .= " OR ";

							$strSql .= "(";
							$strSql .= "(U.NAME IS NOT NULL AND upper(U.NAME) LIKE upper('".$DB->ForSql($arNameReady[$i])."%') ".
								"AND U.LAST_NAME IS NOT NULL AND upper(U.LAST_NAME) LIKE upper('".$DB->ForSql($arNameReady[$j])."%') ".
								"AND U.LOGIN IS NOT NULL AND upper(U.LOGIN) LIKE upper('".$DB->ForSql($arNameReady[$k])."%'))";
							$strSql .= " OR ";
							$strSql .= "(U.NAME IS NOT NULL AND upper(U.NAME) LIKE upper('".$DB->ForSql($arNameReady[$i])."%') ".
								"AND U.LAST_NAME IS NOT NULL AND upper(U.LAST_NAME) LIKE upper('".$DB->ForSql($arNameReady[$j])."%') ".
								"AND U.EMAIL IS NOT NULL AND upper(U.EMAIL) LIKE upper('".$DB->ForSql($arNameReady[$k])."%'))";
							$strSql .= " OR ";
							$strSql .= "(U.NAME IS NOT NULL AND upper(U.NAME) LIKE upper('".$DB->ForSql($arNameReady[$i])."%') ".
								"AND U.LOGIN IS NOT NULL AND upper(U.LOGIN) LIKE upper('".$DB->ForSql($arNameReady[$j])."%') ".
								"AND U.EMAIL IS NOT NULL AND upper(U.EMAIL) LIKE upper('".$DB->ForSql($arNameReady[$k])."%'))";
							$strSql .= " OR ";
							$strSql .= "(U.LAST_NAME IS NOT NULL AND upper(U.LAST_NAME) LIKE upper('".$DB->ForSql($arNameReady[$i])."%') ".
								"AND U.LOGIN IS NOT NULL AND upper(U.LOGIN) LIKE upper('".$DB->ForSql($arNameReady[$j])."%') ".
								"AND U.EMAIL IS NOT NULL AND upper(U.EMAIL) LIKE upper('".$DB->ForSql($arNameReady[$k])."%'))";
							$strSql .= ")";

							$bFirst = false;
						}
					}
				}
				$strSql .= ")";
			}
			elseif (Count($arNameReady) == 2)
			{
				$strSql =
					"SELECT U.ID, U.NAME, U.LAST_NAME, U.SECOND_NAME, U.LOGIN, U.EMAIL ".
					"FROM b_user U ".
					"WHERE (";
				$bFirst = true;
				for ($i = 0; $i < 2; $i++)
				{
					for ($j = 0; $j < 2; $j++)
					{
						if ($i == $j)
							continue;

						if (!$bFirst)
							$strSql .= " OR ";

						$strSql .= "(";
						$strSql .= "(U.NAME IS NOT NULL AND upper(U.NAME) LIKE upper('".$DB->ForSql($arNameReady[$i])."%') ".
							"AND U.LAST_NAME IS NOT NULL AND upper(U.LAST_NAME) LIKE upper('".$DB->ForSql($arNameReady[$j])."%'))";
						$strSql .= " OR ";
						$strSql .= "(U.NAME IS NOT NULL AND upper(U.NAME) LIKE upper('".$DB->ForSql($arNameReady[$i])."%') ".
							"AND U.LOGIN IS NOT NULL AND upper(U.LOGIN) LIKE upper('".$DB->ForSql($arNameReady[$j])."%'))";
						$strSql .= " OR ";
						$strSql .= "(U.LAST_NAME IS NOT NULL AND upper(U.LAST_NAME) LIKE upper('".$DB->ForSql($arNameReady[$i])."%') ".
							"AND U.LOGIN IS NOT NULL AND upper(U.LOGIN) LIKE upper('".$DB->ForSql($arNameReady[$j])."%'))";
						$strSql .= " OR ";
						$strSql .= "(U.LAST_NAME IS NOT NULL AND upper(U.LAST_NAME) LIKE upper('".$DB->ForSql($arNameReady[$i])."%') ".
							"AND U.EMAIL IS NOT NULL AND upper(U.EMAIL) LIKE upper('".$DB->ForSql($arNameReady[$j])."%'))";
						$strSql .= " OR ";
						$strSql .= "(U.NAME IS NOT NULL AND upper(U.NAME) LIKE upper('".$DB->ForSql($arNameReady[$i])."%') ".
							"AND U.EMAIL IS NOT NULL AND upper(U.EMAIL) LIKE upper('".$DB->ForSql($arNameReady[$j])."%'))";
						$strSql .= " OR ";
						$strSql .= "(U.LOGIN IS NOT NULL AND upper(U.LOGIN) LIKE upper('".$DB->ForSql($arNameReady[$i])."%') ".
							"AND U.EMAIL IS NOT NULL AND upper(U.EMAIL) LIKE upper('".$DB->ForSql($arNameReady[$j])."%'))";
						$strSql .= ")";
						$bFirst = false;
					}
				}
				$strSql .= ")";
			}
			else
			{
				$strSql =
					"SELECT U.ID, U.NAME, U.LAST_NAME, U.SECOND_NAME, U.LOGIN, U.EMAIL ".
					"FROM b_user U ".
					"WHERE (U.LAST_NAME IS NOT NULL AND upper(U.LAST_NAME) LIKE upper('".$DB->ForSql($arNameReady[0])."%') ".
					"	OR U.LOGIN IS NOT NULL AND upper(U.LOGIN) LIKE upper('".$DB->ForSql($arNameReady[0])."%') ".
					"	OR U.EMAIL IS NOT NULL AND upper(U.EMAIL) LIKE upper('".$DB->ForSql($arNameReady[0])."%') ".
					"	OR U.NAME IS NOT NULL AND upper(U.NAME) LIKE upper('".$DB->ForSql($arNameReady[0])."%')) ";
			}
			$strSql .= $strSqlWhereEMail;
		}
		else
		{
			if (Count($arNameReady) >= 3)
			{
				$strSql =
					"SELECT U.ID, U.NAME, U.LAST_NAME, U.SECOND_NAME, U.LOGIN, U.EMAIL ".
					"FROM b_user U ".
					"WHERE ";
				$bFirst = true;
				for ($i = 0; $i < 3; $i++)
				{
					for ($j = 0; $j < 3; $j++)
					{
						if ($i == $j)
							continue;

						for ($k = 0; $k < 3; $k++)
						{
							if ($i == $k || $j == $k)
								continue;

							if (!$bFirst)
								$strSql .= " OR ";

							$strSql .= "(U.NAME IS NOT NULL AND upper(U.NAME) LIKE upper('".$DB->ForSql($arNameReady[$i])."%') ".
								"AND U.LAST_NAME IS NOT NULL AND upper(U.LAST_NAME) LIKE upper('".$DB->ForSql($arNameReady[$j])."%') ".
								"AND U.SECOND_NAME IS NOT NULL AND upper(U.SECOND_NAME) LIKE upper('".$DB->ForSql($arNameReady[$k])."%')".$strSqlWhereEMail.")";

							$bFirst = false;
						}
					}
				}
			}
			elseif (Count($arNameReady) == 2)
			{
				$strSql =
					"SELECT U.ID, U.NAME, U.LAST_NAME, U.SECOND_NAME, U.LOGIN, U.EMAIL ".
					"FROM b_user U ".
					"WHERE ";
				$bFirst = true;
				for ($i = 0; $i < 2; $i++)
				{
					for ($j = 0; $j < 2; $j++)
					{
						if ($i == $j)
							continue;

						if (!$bFirst)
							$strSql .= " OR ";

						$strSql .= "(U.NAME IS NOT NULL AND upper(U.NAME) LIKE upper('".$DB->ForSql($arNameReady[$i])."%') ".
							"AND U.LAST_NAME IS NOT NULL AND upper(U.LAST_NAME) LIKE upper('".$DB->ForSql($arNameReady[$j])."%')".$strSqlWhereEMail.")";

						$bFirst = false;
					}
				}
			}
			else
			{
				$strSql =
					"SELECT U.ID, U.NAME, U.LAST_NAME, U.SECOND_NAME, U.LOGIN, U.EMAIL ".
					"FROM b_user U ".
					"WHERE U.LAST_NAME IS NOT NULL AND upper(U.LAST_NAME) LIKE upper('".$DB->ForSql($arNameReady[0])."%') ".
					$strSqlWhereEMail;
			}
		}

		$dbRes = $DB->Query($strSql);
		return $dbRes;
	}

	public static function FormatName($NAME_TEMPLATE, $arUser, $bUseLogin = false, $bHTMLSpec = true)
	{
		if (isset($arUser["ID"]))
			$ID = intval($arUser['ID']);
		else
			$ID = '';

		$NAME_SHORT = ($arUser['NAME'] <> ''? mb_substr($arUser['NAME'], 0, 1).'.' : '');
		$LAST_NAME_SHORT = ($arUser['LAST_NAME'] <> ''? mb_substr($arUser['LAST_NAME'], 0, 1).'.' : '');
		$SECOND_NAME_SHORT = ($arUser['SECOND_NAME'] <> ''? mb_substr($arUser['SECOND_NAME'], 0, 1).'.' : '');

		$res = str_replace(
			array('#TITLE#', '#NAME#', '#LAST_NAME#', '#SECOND_NAME#', '#NAME_SHORT#', '#LAST_NAME_SHORT#', '#SECOND_NAME_SHORT#', '#EMAIL#', '#ID#'),
			array($arUser['TITLE'], $arUser['NAME'], $arUser['LAST_NAME'], $arUser['SECOND_NAME'], $NAME_SHORT, $LAST_NAME_SHORT, $SECOND_NAME_SHORT, $arUser['EMAIL'], $ID),
			$NAME_TEMPLATE
		);

		while(strpos($res, "  ") !== false)
		{
			$res = str_replace("  ", " ", $res);
		}
		$res = trim($res);

		$res_check = "";
		if (mb_strpos($NAME_TEMPLATE, '#NAME#') !== false || mb_strpos($NAME_TEMPLATE, '#NAME_SHORT#') !== false)
			$res_check .= $arUser['NAME'];
		if (mb_strpos($NAME_TEMPLATE, '#LAST_NAME#') !== false || mb_strpos($NAME_TEMPLATE, '#LAST_NAME_SHORT#') !== false)
			$res_check .= $arUser['LAST_NAME'];
		if (mb_strpos($NAME_TEMPLATE, '#SECOND_NAME#') !== false || mb_strpos($NAME_TEMPLATE, '#SECOND_NAME_SHORT#') !== false)
			$res_check .= $arUser['SECOND_NAME'];

		if (trim($res_check) == '')
		{
			if ($bUseLogin && $arUser['LOGIN'] <> '')
				$res = $arUser['LOGIN'];
			else
				$res = GetMessage('FORMATNAME_NONAME');

			if (mb_strpos($NAME_TEMPLATE, '[#ID#]') !== false)
				$res .= " [".$ID."]";
		}

		if ($bHTMLSpec)
			$res = htmlspecialcharsbx($res);

		$res = str_replace(array('#NOBR#', '#/NOBR#'), '', $res);

		return $res;
	}

	public static function clearUserGroupCache($ID = false)
	{
		if ($ID === false)
		{
			self::$userGroupCache = array();
		}
		else
		{
			$ID = (int)$ID;
			if (isset(self::$userGroupCache[$ID]))
				unset(self::$userGroupCache[$ID]);
		}
	}

	public function CheckAuthActions()
	{
		if(!$this->IsAuthorized())
		{
			return;
		}

		if(!is_array(static::$kernelSession["AUTH_ACTIONS_PERFORMED"]))
		{
			static::$kernelSession["AUTH_ACTIONS_PERFORMED"] = array();
		}

		$user_id = $this->GetID();

		$now = new Main\Type\DateTime();

		$actions = Main\UserAuthActionTable::getList(array(
			"filter" => array("=USER_ID" => $user_id),
			"order" => array("USER_ID" => "ASC", "PRIORITY" => "ASC", "ID" => "DESC"),
			"cache" => array("ttl" => 3600),
		));

		while($action = $actions->fetch())
		{
			if(isset(static::$kernelSession["AUTH_ACTIONS_PERFORMED"][$action["ID"]]))
			{
				//already processed the action in this session
				continue;
			}

			if($action["APPLICATION_ID"] <> '' && $this->GetParam("APPLICATION_ID") <> $action["APPLICATION_ID"])
			{
				//this action is for the specific application only
				continue;
			}

			/** @var Main\Type\DateTime() $actionDate */
			$actionDate = $action["ACTION_DATE"];

			if($actionDate <= $now)
			{
				//remember that we already did the action
				static::$kernelSession["AUTH_ACTIONS_PERFORMED"][$action["ID"]] = true;

				if($this->IsJustAuthorized())
				{
					//no need to update the session
					continue;
				}

				switch($action["ACTION"])
				{
					case Main\UserAuthActionTable::ACTION_LOGOUT:
						if($this->GetParam("AUTH_ACTION_SKIP_LOGOUT") == true)
						{
							//user's changed password by himself, skip logout
							$this->SetParam("AUTH_ACTION_SKIP_LOGOUT", false);
							break;
						}
						//redirect is possible
						$this->Logout();
						break;

					case Main\UserAuthActionTable::ACTION_UPDATE:
						$this->UpdateSessionData($user_id, $this->GetParam("APPLICATION_ID"));
						break;
				}

				//we need to process only the first action by proirity
				break;
			}
		}
	}

	public static function AuthActionsCleanUpAgent()
	{
		$date = new Main\Type\DateTime();
		$date->add("-1D");
		Main\UserAuthActionTable::deleteByFilter(array("<ACTION_DATE" => $date));
		return 'CUser::AuthActionsCleanUpAgent();';
	}

	/**
	 * @param int $userId
	 * @return array|bool [code, phone_number]
	 */
	public static function GeneratePhoneCode($userId)
	{
		$row = Main\UserPhoneAuthTable::getRowById($userId);
		if($row && $row["OTP_SECRET"] <> '')
		{
			$totp = new Main\Security\Mfa\TotpAlgorithm();
			$totp->setInterval(self::PHONE_CODE_OTP_INTERVAL);
			$totp->setSecret($row["OTP_SECRET"]);

			$timecode = $totp->timecode(time());
			$code = $totp->generateOTP($timecode);

			Main\UserPhoneAuthTable::update($userId, array(
				"ATTEMPTS" => 0,
				"DATE_SENT" => new Main\Type\DateTime(),
			));

			return [$code, $row["PHONE_NUMBER"]];
		}
		return false;
	}

	/**
	 * @param string $phoneNumber
	 * @param string $code
	 * @return bool|int User ID on success, false on error
	 */
	public static function VerifyPhoneCode($phoneNumber, $code)
	{
		if($code == '')
		{
			return false;
		}

		$phoneNumber = Main\UserPhoneAuthTable::normalizePhoneNumber($phoneNumber);

		$row = Main\UserPhoneAuthTable::getList(["filter" => ["=PHONE_NUMBER" => $phoneNumber]])->fetch();
		if($row && $row["OTP_SECRET"] <> '')
		{
			if($row["ATTEMPTS"] >= 3)
			{
				return false;
			}

			$totp = new Main\Security\Mfa\TotpAlgorithm();
			$totp->setInterval(self::PHONE_CODE_OTP_INTERVAL);
			$totp->setSecret($row["OTP_SECRET"]);

			try
			{
				list($result, ) = $totp->verify($code);
			}
			catch(Main\ArgumentException $e)
			{
				return false;
			}

			$data = array();
			if($result)
			{
				if($row["CONFIRMED"] == "N")
				{
					$data["CONFIRMED"] = "Y";
				}

				$data['DATE_SENT'] = '';
			}
			else
			{
				$data["ATTEMPTS"] = (int)$row["ATTEMPTS"] + 1;
			}

			if(!empty($data))
			{
				Main\UserPhoneAuthTable::update($row["USER_ID"], $data);
			}

			if($result)
			{
				return $row["USER_ID"];
			}
		}
		return false;
	}

	/**
	 * @param string $phoneNumber
	 * @param string $smsTemplate
	 * @param string|null $siteId
	 * @return Main\Result
	 */
	public static function SendPhoneCode($phoneNumber, $smsTemplate, $siteId = null)
	{
		$result = new Main\Result();

		$phoneNumber = Main\UserPhoneAuthTable::normalizePhoneNumber($phoneNumber);

		$select = ["USER_ID", "DATE_SENT", "USER.LANGUAGE_ID"];

		if($siteId === null)
		{
			$context = Main\Context::getCurrent();
			$siteId = $context->getSite();

			if($siteId === null)
			{
				$select[] = "USER.LID";
			}
		}

		$userPhone = Main\UserPhoneAuthTable::getList([
			"select" => $select,
			"filter" =>	[
				"=PHONE_NUMBER" => $phoneNumber
			]
		])->fetchObject();

		if(!$userPhone)
		{
			$result->addError(new Main\Error(Loc::getMessage("main_register_no_user"), "ERR_NOT_FOUND"));
			return $result;
		}

		//alowed only once in a minute
		if($userPhone->getDateSent())
		{
			$currentDateTime = new Main\Type\DateTime();
			if(($currentDateTime->getTimestamp() - $userPhone->getDateSent()->getTimestamp()) < static::PHONE_CODE_RESEND_INTERVAL)
			{
				$result->addError(new Main\Error(Loc::getMessage("main_register_timeout"), "ERR_TIMEOUT"));
				return $result;
			}
		}

		list($code, $phoneNumber) = static::GeneratePhoneCode($userPhone->getUserId());

		if($siteId === null)
		{
			$siteId = CSite::GetDefSite($userPhone->getUser()->getLid());
		}
		$language = $userPhone->getUser()->getLanguageId();

		$sms = new Main\Sms\Event(
			$smsTemplate,
			[
				"USER_PHONE" => $phoneNumber,
				"CODE" => $code,
			]
		);

		$sms->setSite($siteId);
		if($language <> '')
		{
			//user preferred language
			$sms->setLanguage($language);
		}

		$result = $sms->send(true);

		$result->setData(["USER_ID" => $userPhone->getUserId()]);

		return $result;
	}

	protected static function SendEmailCode($userId, $siteId)
	{
		$result = new Main\Result();

		$context = new Main\Authentication\Context();
		$context->setUserId($userId);

		$shortCode = new Main\Authentication\ShortCode($context);

		//alowed only once in a minute
		$check = $shortCode->checkDateSent();

		if($check->isSuccess())
		{
			$code = $shortCode->generate();

			static::SendUserInfo($userId, $siteId, "", true, 'USER_CODE_REQUEST', $code);

			$shortCode->saveDateSent();
		}
		else
		{
			$result->addError(new Main\Error(Loc::getMessage("main_register_timeout"), "ERR_TIMEOUT"));
		}

		$result->setData($check->getData());

		return $result;
	}
}

//compatibility
class CUser extends CAllUser
{
}
/**
 * @deprecated Use CGroup
 */
class CAllGroup extends CGroup
{
}
/**
 * @deprecated Use CTask
 */
class CAllTask extends CTask
{
}
/**
 * @deprecated Use COperation
 */
class CAllOperation extends COperation
{
}
