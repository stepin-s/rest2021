<?
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();
?>
<?$this->setFrameMode(true);?>
<?
$arHidden = array("NAME" => true, "14" => true, "61" => true);
?>
<div id="f_anketa">
<div id="driver_form">

    <div class="modal-header">
        <h3><a href="javascript: void(0);"><span><?=$arParams['TITLE']?></span></a></h3>
        <div id="errors">
        <?if (count($arResult["ERRORS"])):?>
			<?
			foreach ($arResult["ERRORS"] as $i => $strError) {
				foreach ($arResult['USER_ERRORS'] as $propertyID => $arError) {
					if ($strError === $arError['ERROR']) {
						$arResult["ERRORS"][$i] = $arError['ERROR_REPLACE'];
					}
				}
			}
			?>
        	<?=ShowError(implode("<br />", $arResult["ERRORS"]))?>
        <?endif?>
        </div>
        <?if (strlen($arResult["MESSAGE"]) > 0):?>
        	<?=ShowNote($arResult["MESSAGE"])?>
        <?endif?>
    </div>
    <div class="modal-body-b">
<?$bxajaxid = CAjax::GetComponentID($component->__name, $templateName);?>
<div id="<?='ajax_form_' . $bxajaxid?>">
<span class="result_message">
<?
if (!empty($arResult["ERRORS"])):?>
	<?ShowError(implode("<br />", $arResult["ERRORS"]))?>
<?endif;
if (strlen($arResult["MESSAGE"]) > 0):?>
	<?ShowNote($arResult["MESSAGE"])?>
<?endif?>
</span>
        <form class="form-horizontal ajax_form" id="fff" name="iblock_add" action="<?=POST_FORM_ACTION_URI?>" method="post" enctype="multipart/form-data">
        
        	<?=bitrix_sessid_post()?>
        
        	<?if ($arParams["MAX_FILE_SIZE"] > 0):?><input type="hidden" name="MAX_FILE_SIZE" value="<?=$arParams["MAX_FILE_SIZE"]?>" /><?endif?>
        		<?if (is_array($arResult["PROPERTY_LIST"]) && !empty($arResult["PROPERTY_LIST"])):?>
                    <?
                        $m = 0;
                        $k = 0;
                    ?>
        			<?foreach ($arResult["PROPERTY_LIST"] as $propertyID):?>
                        <?
                            if((stripos($arResult["PROPERTY_LIST_FULL"][$propertyID]["CODE"], "person") !== false) || $propertyID == "NAME")
                            {
                                $title = GetMessage("personal_information");
                                $m++;
                            }
                            elseif(stripos($arResult["PROPERTY_LIST_FULL"][$propertyID]["CODE"], "car") !== false)
                            {
                                $title = GetMessage("options_car");
                                $k++;
                            }
                        ?>
                        <?if($m == 1 || $k == 1):?>
                            <h3><?=$title?></h3>
                        <?endif?>
        				<div <? if (array_key_exists($propertyID, $arHidden)) echo "style=display:none" ?> class="control-group" <?if($propertyID == 81):?> id="other"<?endif?>>
                            <?if($propertyID != 82):?>
        						<label class="control-label"><?if (intval($propertyID) > 0):?><?=tr($arResult["PROPERTY_LIST_FULL"][$propertyID]["CODE"])?><?else:?><?=!empty($arParams["CUSTOM_TITLE_".$propertyID]) ? $arParams["CUSTOM_TITLE_".$propertyID] : GetMessage("IBLOCK_FIELD_".$propertyID)?><?endif?><?if(in_array($propertyID, $arResult["PROPERTY_REQUIRED"])):?><span class="starrequired">*</span><?endif?></label>
                            <?endif?>
        					<div class="controls">
        						<?
        						//echo "<pre>"; print_r($arResult["PROPERTY_LIST_FULL"]); echo "</pre>";
        						if (intval($propertyID) > 0)
        						{
        							if (
        								$arResult["PROPERTY_LIST_FULL"][$propertyID]["PROPERTY_TYPE"] == "T"
        								&&
        								$arResult["PROPERTY_LIST_FULL"][$propertyID]["ROW_COUNT"] == "1"
        							)
        								$arResult["PROPERTY_LIST_FULL"][$propertyID]["PROPERTY_TYPE"] = "S";
        							elseif (
        								(
        									$arResult["PROPERTY_LIST_FULL"][$propertyID]["PROPERTY_TYPE"] == "S"
        									||
        									$arResult["PROPERTY_LIST_FULL"][$propertyID]["PROPERTY_TYPE"] == "N"
        								)
        								&&
        								$arResult["PROPERTY_LIST_FULL"][$propertyID]["ROW_COUNT"] > "1"
        							)
        								$arResult["PROPERTY_LIST_FULL"][$propertyID]["PROPERTY_TYPE"] = "T";
        						}
        						elseif (($propertyID == "TAGS") && CModule::IncludeModule('search'))
        							$arResult["PROPERTY_LIST_FULL"][$propertyID]["PROPERTY_TYPE"] = "TAGS";
        
        						if ($arResult["PROPERTY_LIST_FULL"][$propertyID]["MULTIPLE"] == "Y")
        						{
        							$inputNum = ($arParams["ID"] > 0 || count($arResult["ERRORS"]) > 0) ? count($arResult["ELEMENT_PROPERTIES"][$propertyID]) : 0;
                                    if($arResult["PROPERTY_LIST_FULL"][$propertyID]["CODE"] == "person_scan_passport")
                                        $arResult["PROPERTY_LIST_FULL"][$propertyID]["MULTIPLE_CNT"] = 2;
        							$inputNum += $arResult["PROPERTY_LIST_FULL"][$propertyID]["MULTIPLE_CNT"];
        						}
        						else
        						{
        							$inputNum = 1;
        						}
                                
                                if($propertyID == 54){
                                    $arResult["PROPERTY_LIST_FULL"][$propertyID]["GetPublicEditHTML"] = 0;
                                    $arResult["PROPERTY_LIST_FULL"][$propertyID]["PROPERTY_TYPE"] = 'N';
                                }
                                
        						if($arResult["PROPERTY_LIST_FULL"][$propertyID]["GetPublicEditHTML"])
        							$INPUT_TYPE = "USER_TYPE";
        						else
        							$INPUT_TYPE = $arResult["PROPERTY_LIST_FULL"][$propertyID]["PROPERTY_TYPE"];
        						switch ($INPUT_TYPE):
        							case "USER_TYPE":
        								for ($i = 0; $i<$inputNum; $i++)
        								{
        									if ($arParams["ID"] > 0 || count($arResult["ERRORS"]) > 0)
        									{
        										$value = intval($propertyID) > 0 ? $arResult["ELEMENT_PROPERTIES"][$propertyID][$i]["~VALUE"] : $arResult["ELEMENT"][$propertyID];
        										$description = intval($propertyID) > 0 ? $arResult["ELEMENT_PROPERTIES"][$propertyID][$i]["DESCRIPTION"] : "";
        									}
        									elseif ($i == 0)
        									{
        										$value = intval($propertyID) <= 0 ? "" : $arResult["PROPERTY_LIST_FULL"][$propertyID]["DEFAULT_VALUE"];
        										$description = "";
        									}
        									else
        									{
        										$value = "";
        										$description = "";
        									}
											?>

											<?
											$GLOBALS["APPLICATION"]->IncludeComponent(
												'bitrix:main.calendar',
												'calendar_templ',
												array(
													'FORM_NAME' => "iblock_add",
													'INPUT_NAME' => "PROPERTY[".$propertyID."][".$i."][VALUE]",
													'DESCRIPTION' => "PROPERTY[".$propertyID."][".$i."][DESCRIPTION]",
													'INPUT_VALUE' => $value,
													'SHOW_TIME' => "N",
                                                    "SHOW_INPUT" => "Y",
												),
												null,
												array('HIDE_ICONS' => 'Y')
											);
											/*
        									echo call_user_func_array($arResult["PROPERTY_LIST_FULL"][$propertyID]["GetPublicEditHTML"],
        										array(
        											$arResult["PROPERTY_LIST_FULL"][$propertyID],
        											array(
        												"VALUE" => $value,
        												"DESCRIPTION" => $description,
        											),
        											array(
        												"VALUE" => "PROPERTY[".$propertyID."][".$i."][VALUE]",
        												"DESCRIPTION" => "PROPERTY[".$propertyID."][".$i."][DESCRIPTION]",
        												"FORM_NAME"=>"iblock_add",
        											),
        										));
											*/
        								?><br /><?
        								}
        							break;
        							case "TAGS":
        								$APPLICATION->IncludeComponent(
        									"bitrix:search.tags.input",
        									"",
        									array(
        										"VALUE" => $arResult["ELEMENT"][$propertyID],
        										"NAME" => "PROPERTY[".$propertyID."][0]",
        										"TEXT" => 'size="'.$arResult["PROPERTY_LIST_FULL"][$propertyID]["COL_COUNT"].'"',
        									), null, array("HIDE_ICONS"=>"Y")
        								);
        								break;
        							case "HTML":
        								$LHE = new CLightHTMLEditor;
        								$LHE->Show(array(
        									'id' => preg_replace("/[^a-z0-9]/i", '', "PROPERTY[".$propertyID."][0]"),
        									'width' => '100%',
        									'height' => '200px',
        									'inputName' => "PROPERTY[".$propertyID."][0]",
        									'content' => $arResult["ELEMENT"][$propertyID],
        									'bUseFileDialogs' => false,
        									'bFloatingToolbar' => false,
        									'bArisingToolbar' => false,
        									'toolbarConfig' => array(
        										'Bold', 'Italic', 'Underline', 'RemoveFormat',
        										'CreateLink', 'DeleteLink', 'Image', 'Video',
        										'BackColor', 'ForeColor',
        										'JustifyLeft', 'JustifyCenter', 'JustifyRight', 'JustifyFull',
        										'InsertOrderedList', 'InsertUnorderedList', 'Outdent', 'Indent',
        										'StyleList', 'HeaderList',
        										'FontList', 'FontSizeList',
        									),
        								));
        								break;
        							case "T":
        								for ($i = 0; $i<$inputNum; $i++)
        								{
        
        									if ($arParams["ID"] > 0 || count($arResult["ERRORS"]) > 0)
        									{
        										$value = intval($propertyID) > 0 ? $arResult["ELEMENT_PROPERTIES"][$propertyID][$i]["VALUE"] : $arResult["ELEMENT"][$propertyID];
        									}
        									elseif ($i == 0)
        									{
        										$value = intval($propertyID) > 0 ? "" : $arResult["PROPERTY_LIST_FULL"][$propertyID]["DEFAULT_VALUE"];
        									}
        									else
        									{
        										$value = "";
        									}
        								?>
        						<textarea cols="<?=$arResult["PROPERTY_LIST_FULL"][$propertyID]["COL_COUNT"]?>" rows="<?=$arResult["PROPERTY_LIST_FULL"][$propertyID]["ROW_COUNT"]?>" name="PROPERTY[<?=$propertyID?>][<?=$i?>]"><?=$value?></textarea>
        								<?
        								}
        							break;
        
        							case "S":
        							case "N":
        								for ($i = 0; $i<$inputNum; $i++)
        								{
        									if ($arParams["ID"] > 0 || count($arResult["ERRORS"]) > 0)
        									{
        										$value = intval($propertyID) > 0 ? $arResult["ELEMENT_PROPERTIES"][$propertyID][$i]["VALUE"] : $arResult["ELEMENT"][$propertyID];
        									}
											elseif ($propertyID == "NAME") {
												$value = tr("driver_anket_title");
											}
        									elseif ($i == 0)
        									{
        										$value = intval($propertyID) <= 0 ? "" : $arResult["PROPERTY_LIST_FULL"][$propertyID]["DEFAULT_VALUE"];
        
        									}
        									else {
        										$value = "";
        									}
        								?>
        								<input type="text" name="PROPERTY[<?=$propertyID?>][<?=$i?>]" class="span12" value="<?=$value?>" /><?
        								if($arResult["PROPERTY_LIST_FULL"][$propertyID]["USER_TYPE"] == "DateTime"):?><?
        									$APPLICATION->IncludeComponent(
        										'bitrix:main.calendar',
        										'',
        										array(
        											'FORM_NAME' => 'iblock_add',
        											'INPUT_NAME' => "PROPERTY[".$propertyID."][".$i."]",
        											'INPUT_VALUE' => $value,
                                                    "SHOW_TIME" => "N",
                                                    "HIDE_TIMEBAR" => "Y",
                                                    "SHOW_INPUT" => "Y"
        										),
        										null,
        										array('HIDE_ICONS' => 'N')
        									);
        									?><!--<small><?=GetMessage("IBLOCK_FORM_DATE_FORMAT")?><?=FORMAT_DATETIME?></small>--><?
        								endif
        								?><?
        								}
        							break;
        
        							case "F":
        								for ($i = 0; $i<$inputNum; $i++)
        								{
        									$value = intval($propertyID) > 0 ? $arResult["ELEMENT_PROPERTIES"][$propertyID][$i]["VALUE"] : $arResult["ELEMENT"][$propertyID];
        									?>
        						<input type="hidden" name="PROPERTY[<?=$propertyID?>][<?=$arResult["ELEMENT_PROPERTIES"][$propertyID][$i]["VALUE_ID"] ? $arResult["ELEMENT_PROPERTIES"][$propertyID][$i]["VALUE_ID"] : $i?>]" value="<?=$value?>" />
        						<input class="span12" type="file" size="<?=$arResult["PROPERTY_LIST_FULL"][$propertyID]["COL_COUNT"]?>"  name="PROPERTY_FILE_<?=$propertyID?>_<?=$arResult["ELEMENT_PROPERTIES"][$propertyID][$i]["VALUE_ID"] ? $arResult["ELEMENT_PROPERTIES"][$propertyID][$i]["VALUE_ID"] : $i?>" />
        									<?
        
        									if (!empty($value) && is_array($arResult["ELEMENT_FILES"][$value]))
        									{
        										?>
        					<input type="checkbox" name="DELETE_FILE[<?=$propertyID?>][<?=$arResult["ELEMENT_PROPERTIES"][$propertyID][$i]["VALUE_ID"] ? $arResult["ELEMENT_PROPERTIES"][$propertyID][$i]["VALUE_ID"] : $i?>]" id="file_delete_<?=$propertyID?>_<?=$i?>" value="Y" /><label for="file_delete_<?=$propertyID?>_<?=$i?>"><?=GetMessage("IBLOCK_FORM_FILE_DELETE")?></label><br />
        										<?
        
        										if ($arResult["ELEMENT_FILES"][$value]["IS_IMAGE"])
        										{
        											?>
        					<img src="<?=$arResult["ELEMENT_FILES"][$value]["SRC"]?>" height="<?=$arResult["ELEMENT_FILES"][$value]["HEIGHT"]?>" width="<?=$arResult["ELEMENT_FILES"][$value]["WIDTH"]?>" border="0" /><br />
        											<?
        										}
        										else
        										{
        											?>
        					<?=GetMessage("IBLOCK_FORM_FILE_NAME")?>: <?=$arResult["ELEMENT_FILES"][$value]["ORIGINAL_NAME"]?><br />
        					<?=GetMessage("IBLOCK_FORM_FILE_SIZE")?>: <?=$arResult["ELEMENT_FILES"][$value]["FILE_SIZE"]?> b<br />
        					[<a href="<?=$arResult["ELEMENT_FILES"][$value]["SRC"]?>"><?=GetMessage("IBLOCK_FORM_FILE_DOWNLOAD")?></a>]<br />
        											<?
        										}
        									}
        								}
                            echo '<br />';
        							break;
        							case "L":
        
        								if ($arResult["PROPERTY_LIST_FULL"][$propertyID]["LIST_TYPE"] == "C")
        									$type = $arResult["PROPERTY_LIST_FULL"][$propertyID]["MULTIPLE"] == "Y" ? "checkbox" : "radio";
        								else
        									$type = $arResult["PROPERTY_LIST_FULL"][$propertyID]["MULTIPLE"] == "Y" ? "multiselect" : "dropdown";
        
        								switch ($type):
        									case "checkbox":?>
                                               <?foreach ($arResult["PROPERTY_LIST_FULL"][$propertyID]["ENUM"] as $key => $arEnum):?>
                                                <label class="checkbox">
                                                  <input type="checkbox" name="PROPERTY[<?=$propertyID?>]<?=$type == "checkbox" ? "[".$key."]" : ""?>" value="<?=$key?>" id="property_<?=$key?>"<?=$checked ? " checked=\"checked\"" : ""?> /> <?=$arEnum["VALUE"]?>
                                                </label>
                                                <?endforeach?>
                                        <?  break;
        									case "radio":
        
        										//echo "<pre>"; print_r($arResult["PROPERTY_LIST_FULL"][$propertyID]); echo "</pre>";
        
        										foreach ($arResult["PROPERTY_LIST_FULL"][$propertyID]["ENUM"] as $key => $arEnum)
        										{
        											$checked = false;
        											if ($arParams["ID"] > 0 || count($arResult["ERRORS"]) > 0)
        											{
        												if (is_array($arResult["ELEMENT_PROPERTIES"][$propertyID]))
        												{
        													foreach ($arResult["ELEMENT_PROPERTIES"][$propertyID] as $arElEnum)
        													{
        														if ($arElEnum["VALUE"] == $key) {$checked = true; break;}
        													}
        												}
        											}
        											else
        											{
        												if ($arEnum["DEF"] == "Y") $checked = true;
        											}
        
        											?>
        							<input type="<?=$type?>" name="PROPERTY[<?=$propertyID?>]<?=$type == "checkbox" ? "[".$key."]" : ""?>" value="<?=$key?>" id="property_<?=$key?>"<?=$checked ? " checked=\"checked\"" : ""?> /><label for="property_<?=$key?>"><?=$arEnum["VALUE"]?></label><br />
        											<?
        										}
        									break;
        
        									case "dropdown":
        									case "multiselect":
        									?>
        							<select class="span12" name="PROPERTY[<?=$propertyID?>]<?=$type=="multiselect" ? "[]\" size=\"".$arResult["PROPERTY_LIST_FULL"][$propertyID]["ROW_COUNT"]."\" multiple=\"multiple" : ""?>">
        									<?
        										if (intval($propertyID) > 0) $sKey = "ELEMENT_PROPERTIES";
        										else $sKey = "ELEMENT";
        
        										foreach ($arResult["PROPERTY_LIST_FULL"][$propertyID]["ENUM"] as $key => $arEnum)
        										{
        											$checked = false;
        											if ($arParams["ID"] > 0 || count($arResult["ERRORS"]) > 0)
        											{
        												foreach ($arResult[$sKey][$propertyID] as $elKey => $arElEnum)
        												{
        													if ($key == $arElEnum["VALUE"]) {$checked = true; break;}
        												}
        											}
        											else
        											{
        												if ($arEnum["DEF"] == "Y") $checked = true;
        											}
        											?>
        								<option value="<?=$key?>" <?=$checked ? " selected=\"selected\"" : ""?>><?=$arEnum["VALUE"]?></option>
        											<?
        										}
        									?>
        							</select>
        									<?
        									break;
        
        								endswitch;
        							break;
        						endswitch;?>
        					</div>
        				</div>
        			<?endforeach;?>
					<?$ar_city = CUriCity::get_instance()->get_current_element()?>
					<input type="hidden" name="PROPERTY[61][0]" value="<?= $ar_city["name"]?>">
        			<?if($arParams["USE_CAPTCHA"] == "Y" && $arParams["ID"] <= 0):?>
        				<div class="control-group">
        				    <label class="control-label"><?=GetMessage("IBLOCK_FORM_CAPTCHA_TITLE")?></label>
        					<input type="hidden" name="captcha_sid" value="<?=$arResult["CAPTCHA_CODE"]?>" />
        					<img src="/bitrix/tools/captcha.php?captcha_sid=<?=$arResult["CAPTCHA_CODE"]?>" width="180" height="40" alt="CAPTCHA" />
                            <div class="controls">
            					<?=GetMessage("IBLOCK_FORM_CAPTCHA_PROMPT")?><span class="starrequired">*</span>
            					<input type="text" name="captcha_word" maxlength="50" value=""/>
                            </div>
        				</div>
        			<?endif?>
        		<?endif?>
            <div class="modal-footer">
        		<input class="button yellow" type="submit" name="iblock_submit" value="<?=GetMessage("IBLOCK_FORM_SUBMIT")?>" id="submit_anketa" />
        		<?if (strlen($arParams["LIST_URL"]) > 0 && $arParams["ID"] > 0):?><input type="submit" name="iblock_apply" value="<?=GetMessage("IBLOCK_FORM_APPLY")?>" /><?endif?>
        		<?/*<input type="reset" value="<?=GetMessage("IBLOCK_FORM_RESET")?>" />*/?>
            	<?if (strlen($arParams["LIST_URL"]) > 0):?><a href="<?=$arParams["LIST_URL"]?>"><?=GetMessage("IBLOCK_FORM_BACK")?></a><?endif?>
            </div>
        </form>
		</div>
    </div>
</div>
</div>