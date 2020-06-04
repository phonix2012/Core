<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 5 2020
 *
 */

namespace MikoPBX\Tests\AdminCabinet\Lib;

use Exception;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use MikoPBX\Tests\AdminCabinet\Tests\LoginTrait;


class MikoPBXTestsBase extends BrowserStackTest
{
    use  LoginTrait;

    /**
     * Assert that menu item not found on the page
     *
     * @param $by
     */
    protected function assertElementNotFound($by): void
    {
        $els = self::$driver->findElements($by);
        if (count($els)) {
            $this->fail("Unexpectedly element was found by " . $by . PHP_EOL);
        }
        // increment assertion counter
        $this->assertTrue(true);
    }

    /**
     * Select dropdown menu item
     *
     * @param $name  string menu name identifier
     * @param $value string menu value for select
     *
     */
    protected function selectDropdownItem(string $name, string $value): void
    {
        $xpath = '//select[@name="' . $name . '"]/ancestor::div[contains(@class, "ui") and contains(@class ,"dropdown")]';
        $xpath .='| //div[@id="' . $name . '" and contains(@class, "ui") and contains(@class ,"dropdown") ]';
        try {
            $selectItem = self::$driver->findElement(WebDriverBy::xpath($xpath));
            $selectItem->click();
            $this->waitForAjax();

            // If search field exists input them before select
            $xpath = '//select[@name="' . $name . '"]/ancestor::div[contains(@class, "ui") and contains(@class ,"dropdown")]/input[contains(@class,"search")]';
            $xpath .='| //div[@id="' . $name . '" and contains(@class, "ui") and contains(@class ,"dropdown") ]/input[contains(@class,"search")]';
            $inputItems = self::$driver->findElements(WebDriverBy::xpath($xpath));
            foreach ($inputItems as $inputItem) {
                $inputItem->click();
                $inputItem->clear();
                $inputItem->sendKeys($value);
            }

            // Находим строчку с нужной опцией по значению
            $xpath    = '//div[contains(@class, "menu") and contains(@class ,"visible")]/div[@data-value="' . $value . '"]';
            $menuItem = self::$driver->wait()->until(
                WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::xpath($xpath))
            );
            $menuItem->click();
        } catch (NoSuchElementException $e) {
            $this->fail('Not found select with name ' . $name . PHP_EOL);
        } catch (TimeoutException $e) {
            $this->fail('Not found menuitem ' . $value . PHP_EOL);
        } catch (Exception $e) {
            $this->fail('Unknown error ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * Wait until jquery will be ready
     */
    protected function waitForAjax(): void
    {
        while (true) // Handle timeout somewhere
        {
            $ajaxIsComplete = (bool)(self::$driver->executeScript("return jQuery.active == 0"));
            if ($ajaxIsComplete) {
                break;
            }
            sleep(1);
        }
    }

    /**
     * Assert that menu item selected
     *
     * @param $name         string menu name
     * @param $checkedValue string checked value
     */
    protected function assertMenuItemSelected(string $name, string $checkedValue): void
    {
        $xpath             = '//select[@name="' . $name . '"]/option[@selected="selected"]';
        $selectedExtension = self::$driver->findElements(WebDriverBy::xpath($xpath));
        foreach ($selectedExtension as $element) {
            $currentValue = $element->getAttribute('value');
            $message      = "{$name} check failure, because {$checkedValue} != {$currentValue}";
            $this->assertEquals($checkedValue, $currentValue, $message);
        }
    }

    /**
     * Change textarea with name $name value to $value
     *
     * @param string $name
     * @param string $value
     */
    protected function changeTextAreaValue(string $name, string $value): void
    {
        $xpath         = ('//textarea[@name="' . $name . '"]');
        $textAreaItems = self::$driver->findElements(WebDriverBy::xpath($xpath));
        foreach ($textAreaItems as $textAreaItem) {
            $textAreaItem->click();
            $textAreaItem->clear();
            $textAreaItem->sendKeys($value);
        }
    }

    /**
     * Assert that textArea value is equal
     *
     * @param string $name         textArea name
     * @param string $checkedValue checked value
     */
    protected function assertTextAreaValueIsEqual(string $name, string $checkedValue): void
    {
        $xpath        = '//textarea[@name="' . $name . '"]';
        $textAreaItem = self::$driver->findElement(WebDriverBy::xpath($xpath));
        $currentValue = $textAreaItem->getAttribute('value');
        $message      = "{$name} check failure, because {$checkedValue} != {$currentValue}";
        $this->assertEquals($checkedValue, $currentValue, $message);
    }

    /**
     * If file filed with $name exists on the page, it value will be changed on $value
     *
     * @param string $name
     * @param string $value
     */
    protected function changeFileField(string $name, string $value): void
    {
        $xpath      = '//input[@name="' . $name . '" and (@type = "file")]';
        $inputItems = self::$driver->findElements(WebDriverBy::xpath($xpath));
        foreach ($inputItems as $inputItem) {
            $inputItem->sendKeys($value);
        }
    }

    /**
     * If input filed with $name exists on the page, it value will be changed on $value
     *
     * @param string $name
     * @param string $value
     */
    protected function changeInputField(string $name, string $value): void
    {
        $xpath      = '//input[@name="' . $name . '" and (@type="text" or @type="password" or @type="hidden" or @type="number")]';
        $inputItems = self::$driver->findElements(WebDriverBy::xpath($xpath));
        foreach ($inputItems as $inputItem) {
            $inputItem->click();
            $inputItem->clear();
            $inputItem->sendKeys($value);
        }
    }

    /**
     * Assert that input field with name $name value is equal to $checkedValue
     *
     * @param string $name
     * @param string $checkedValue
     */
    protected function assertInputFieldValueEqual(string $name, string $checkedValue): void
    {
        $xpath      = '//input[@name="' . $name . '" and (@type="text" or @type="password" or @type="hidden")]';
        $inputItems = self::$driver->findElements(WebDriverBy::xpath($xpath));
        foreach ($inputItems as $inputItem) {
            $currentValue = $inputItem->getAttribute('value');
            $message      = "input field: '{$name}' check failure, because {$checkedValue} != {$currentValue}";
            $this->assertEquals($checkedValue, $currentValue, $message);
        }
    }

    /**
     * Change checkbox state according the $enabled value if checkbox with the $name exist on the page
     *
     * @param string $name
     * @param bool   $enabled
     */
    protected function changeCheckBoxState(string $name, bool $enabled): void
    {
        $xpath         = '//input[@name="' . $name . '" and @type="checkbox"]';
        $checkBoxItems = self::$driver->findElements(WebDriverBy::xpath($xpath));
        foreach ($checkBoxItems as $checkBoxItem) {
            if (
                ($enabled && ! $checkBoxItem->isSelected())
                ||
                ( ! $enabled && $checkBoxItem->isSelected())
            ) {
                $xpath        = '//input[@name="' . $name . '" and @type="checkbox"]/parent::div';
                $checkBoxItem = self::$driver->findElement(WebDriverBy::xpath($xpath));
                $checkBoxItem->click();
            }
        }
    }

    /**
     * Assert that checkBox state is equal to the $enabled if checkbox with the $name exist on the page
     *
     * @param string $name    checkBox name
     * @param bool   $enabled checked state
     */
    protected function assertCheckBoxStageIsEqual(string $name, bool $enabled): void
    {
        $xpath         = '//input[@name="' . $name . '" and @type="checkbox"]';
        $checkBoxItems = self::$driver->findElements(WebDriverBy::xpath($xpath));
        foreach ($checkBoxItems as $checkBoxItem) {
            if ($enabled) {
                $this->assertTrue($checkBoxItem->isSelected(), "{$name} must be checked" . PHP_EOL);
            } else {
                $this->assertFalse($checkBoxItem->isSelected(), "{$name} must be unchecked" . PHP_EOL);
            }
        }
    }

    /**
     * Submit form with id - $formId and wait until form send
     *
     * @param string $formId
     *
     */
    protected function submitForm(string $formId): void
    {
        $xpath = '//form[@id="' . $formId . '"]//ancestor::div[@id="submitbutton"]';
        try {
            $button_Submit = self::$driver->findElement(WebDriverBy::xpath($xpath));

            $button_Submit->click();
            $this->waitForAjax();
            self::$driver->wait(10, 500)->until(
                function ($driver) use ($xpath) {
                    $button_Submit = $driver->findElement(WebDriverBy::xpath($xpath));

                    return $button_Submit->isEnabled();
                }
            );
        } catch (NoSuchElementException $e) {
            $this->fail('Not found submit button on this page' . PHP_EOL);
        } catch (TimeoutException $e) {
            $this->fail('Form doesn\'t send after 10 seconds timeout' . PHP_EOL);
        } catch (Exception $e) {
            $this->fail('Unknown error ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * Click on the left sidebar menu item
     *
     * @param string $href
     */
    protected function clickSidebarMenuItemByHref(string $href): void
    {
        try {
            $xpath       = '//div[@id="sidebar-menu"]//ancestor::a[contains(@class, "item") and contains(@href ,"' . $href . '")]';
            $sidebarItem = self::$driver->findElement(WebDriverBy::xpath($xpath));
            $sidebarItem->click();
            $this->waitForAjax();
        } catch (NoSuchElementException $e) {
            $this->fail('Not found sidebar item with href=' . $href . ' on this page' . PHP_EOL);
        } catch (Exception $e) {
            $this->fail('Unknown error ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * Find modify button on row with text $text and click it
     *
     * @param string $text
     */
    protected function clickModifyButtonOnRowWithText(string $text): void
    {
        $xpath = ('//td[contains(text(),"' . $text . '")]/parent::tr[contains(@class, "row")]//a[contains(@href,"modify")]');
        try {
            $tableButtonModify = self::$driver->findElement(WebDriverBy::xpath($xpath));
            $tableButtonModify->click();
            $this->waitForAjax();
        } catch (NoSuchElementException $e) {
            $this->fail('Not found row with text=' . $text . ' on this page' . PHP_EOL);
        } catch (Exception $e) {
            $this->fail('Unknown error ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * Find modify button on row with id $text and click it
     *
     * @param string $id
     */
    protected function clickModifyButtonOnRowWithID(string $id): void
    {
        $xpath = ('//tr[contains(@class, "row") and @id="' . $id . '"]//a[contains(@href,"modify")]');
        try {
            $tableButtonModify = self::$driver->findElement(WebDriverBy::xpath($xpath));
            $tableButtonModify->click();
            $this->waitForAjax();
        } catch (NoSuchElementException $e) {
            $this->fail('Not found row with id=' . $id . ' on this page' . PHP_EOL);
        } catch (Exception $e) {
            $this->fail('Unknown error ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * Find modify button on row with text $text and click it
     *
     * @param string $text
     */
    protected function clickDeleteButtonOnRowWithText(string $text): void
    {
        $xpath = ('//td[contains(text(),"' . $text . '")]/ancestor::tr[contains(@class, "row")]//a[contains(@href,"delete")]');
        try {
            $deleteButton = self::$driver->findElement(WebDriverBy::xpath($xpath));
            $deleteButton->click();
            sleep(2);
            $deleteButton->click();
            $this->waitForAjax();
        } catch (NoSuchElementException $e) {
            echo('Not found row with text=' . $text . ' on this page' . PHP_EOL);
        } catch (Exception $e) {
            $this->fail('Unknown error ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * Click on add new button by href
     *
     * @param string $href
     */
    protected function clickButtonByHref(string $href): void
    {
        try {
            $xpath         = "//a[@href = '{$href}']";
            $button_AddNew = self::$driver->findElement(WebDriverBy::xpath($xpath));
            $button_AddNew->click();
            $this->waitForAjax();
        } catch (NoSuchElementException $e) {
            $this->fail('Not found button with href=' . $href . ' on this page' . PHP_EOL);
        } catch (Exception $e) {
            $this->fail('Unknown error ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * Select tab in tabular menu by anchor
     *
     * @param $anchor
     */
    protected function changeTabOnCurrentPage($anchor): void
    {
        try {
            $xpath = "//div[contains(@class, 'tabular') and contains(@class, 'menu')]//a[contains(@data-tab,'{$anchor}')]";
            $tab   = self::$driver->findElement(WebDriverBy::xpath($xpath));
            $tab->click();
        } catch (NoSuchElementException $e) {
            $this->fail('Not found tab with anchor=' . $anchor . ' on this page' . PHP_EOL);
        } catch (Exception $e) {
            $this->fail('Unknown error ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * Open additional settings under accordion element
     */
    protected function openAccordionOnThePage(): void
    {
        try {
            $xpath = "//div[contains(@class, 'ui') and contains(@class, 'accordion')]";
            $tab   = self::$driver->findElement(WebDriverBy::xpath($xpath));
            $tab->click();
        } catch (NoSuchElementException $e) {
            $this->fail('Not found usual accordion element on this page' . PHP_EOL);
        } catch (Exception $e) {
            $this->fail('Unknown error ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * Get ID from hidden input at form
     *
     * @return string
     */
    protected function getCurrentRecordID(): string
    {
        try {
            $xpath = '//input[@name="id" and (@type="hidden")]';
            $input = self::$driver->findElement(WebDriverBy::xpath($xpath));
            return $input->getAttribute('value')??'';
        } catch (NoSuchElementException $e) {
            $this->fail('Not found input with name ID on this page' . PHP_EOL);
        } catch (Exception $e) {
            $this->fail('Unknown error ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * Delete all records from table
     *
     * @param string $tableId
     *
     * @return void
     */
    protected function deleteAllRecordsOnTable(string $tableId): void
    {
        $xpath         = '//table[@id="' . $tableId . '"]//a[contains(@href,"delete")]';
        $deleteButtons = self::$driver->findElements(WebDriverBy::xpath($xpath));
        while (count($deleteButtons) > 0) {
            try {
                $deleteButton = self::$driver->findElement(WebDriverBy::xpath($xpath));
                $deleteButton->click();
                sleep(2);
                $deleteButton->click();
                $this->waitForAjax();
                unset($deleteButtons[0]);
            } catch (NoSuchElementException $e) {
                break;
            }
        }
    }


}