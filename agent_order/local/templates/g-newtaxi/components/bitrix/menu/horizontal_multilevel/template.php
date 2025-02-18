<? if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die(); ?>
<? $this->setFrameMode(true); ?>
<? if (!empty($arResult)): ?>

    <nav>
        <ul class="clearfix">
            <?
            $previousLevel = 0;
            if (($_SERVER['SCRIPT_NAME'] == '/news/index.php') || ($_SERVER['REAL_FILE_PATH'] == '/news/index.php')) $news = true; else $news = false;

            foreach ($arResult as $arItem): ?>

            <? if ($previousLevel && $arItem["DEPTH_LEVEL"] < $previousLevel): ?>
                <?= str_repeat("</ul></li>", ($previousLevel - $arItem["DEPTH_LEVEL"])); ?>
            <? endif ?>

            <? if ($arItem["IS_PARENT"]): ?>
            <? if ($arItem["DEPTH_LEVEL"] == 1): ?>
            <li class="<? if ($arItem["SELECTED"] && !$news): ?>active<? endif ?>">
                <a href="<?= $arItem["LINK"] ?>"><?= $arItem["TEXT"] ?></a>
                <ul>
                    <? else: ?>
                    <li class="<? if ($arItem["SELECTED"] && !$news): ?>active<? endif ?>">
                        <a href="<?= $arItem["LINK"] ?>"><?= $arItem["TEXT"] ?></a>
                        <ul>
                            <? endif ?>

                            <? else: ?>
                                <? if ($arItem["PERMISSION"] > "D"): ?>
                                    <? if ($arItem["DEPTH_LEVEL"] == 1): ?>
                                        <? if (strpos($arItem["LINK"], '://') !== false): ?>
                                            <li class="<? if ($arItem["SELECTED"] && !$news): ?>active<? endif ?>">
                                                <a href="<?= $arItem["LINK"] ?>"><?= $arItem["TEXT"] ?></a>
                                            </li>
                                        <? else: ?>
                                            <li class="<? if ($arItem["SELECTED"] && !$news): ?>active<? endif ?>">
                                                <a href="<?= $arItem["LINK"] ?>"><?= $arItem["TEXT"] ?></a>
                                            </li>
                                        <? endif ?>
                                    <? else: ?>
                                        <li class="<? if ($arItem["SELECTED"] && !$news): ?>active<? endif ?>">
                                            <a href="<?= $arItem["LINK"] ?>"><?= $arItem["TEXT"] ?></a>
                                        </li>
                                    <? endif ?>
                                <? else: ?>
                                    <? if ($arItem["DEPTH_LEVEL"] == 1): ?>
                                        <li class="denied"><a title="<?= GetMessage("MENU_ITEM_ACCESS_DENIED") ?>"
                                                              href=""><?= $arItem["TEXT"] ?></a></li>
                                    <? else: ?>
                                        <li class="denied"><a title="<?= GetMessage("MENU_ITEM_ACCESS_DENIED") ?>"
                                                              href=""><?= $arItem["TEXT"] ?></a></li>
                                    <? endif ?>
                                <? endif ?>
                            <? endif ?>
                            <? $previousLevel = $arItem["DEPTH_LEVEL"]; ?>
                            <? endforeach ?>

                            <? if ($previousLevel > 1)://close last item tags?>
                                <?= str_repeat("</ul></li>", ($previousLevel - 1)); ?>
                            <? endif ?>

                        </ul>
    </nav>
<? endif ?>