<?php

namespace PrestaShop\PrestaShop\Cloud\Test;

use PrestaShop\PSTest\TestCase\RemotePrestaShopTest;

use PrestaShop\PSTest\Shop\RemoteShop;

use Exception;

class CloudUpgradeVerificationTest extends RemotePrestaShopTest
{
    public function contextProvider()
    {
        if (($domain = getenv('DOMAIN'))) {
            return [[
                'url' => 'http://' . $domain
            ]];
        }

        $contexts = [];

        $h = fopen(__DIR__ . DIRECTORY_SEPARATOR . 'urls.csv', 'r');

        $headers = null;
        while (($row = fgetcsv($h))) {

            if (null === $headers) {
                $headers = $row;
            } else {
                $row = array_combine($headers, $row);
                $contexts[] = [
                    'url' => 'http://' . $row['domain']
                ];
            }
        }
        fclose($h);

        return $contexts;
    }

    private $product_url;
    private $customer;
    private $id_order;

    /**
     * @beforeClass
     */
    public function initialize()
    {
        $this->shop = (new RemoteShop)
                    ->setBrowser($this->browser)
                    ->setFrontOfficeURL($this->context('url'))
                    ->setBackOfficeURL(rtrim($this->context('url'), '/') . '/backoffice')
                    ->registerServices()
        ;
    }

    public function test_Onboarding_Module_Is_Displayed()
    {
        if (getenv('NO_CHECKLIST')) {
            $this->info('Skipping checklist-related test as asked.');
            return;
        }

        $this->browser->visit($this->shop->getBackOfficeURL());
        $this->browser
                ->waitFor('.upgrade-check.panel-popup')
                ->click('.btn.finished')
        ;
    }

    public function test_I_Can_Login_To_The_BackOffice()
    {
        $this->shop->get('back-office')->login('pub@prestashop.com', '123456789');
    }

    public function test_Checklist_Can_Be_Checked()
    {
        if (getenv('NO_CHECKLIST')) {
            $this->info('Skipping checklist-related test as asked.');
            return;
        }

        foreach ($this->browser->all('.content-checklist li[onclick]') as $checkListItem) {
            $checkListItem->click();
        }

        // This delay is necessary because the checklist widget says it is OK
        // slightly before it really is :)
        sleep(5);

        $this->browser->waitFor('.content-checklist .btn.finished');
    }

    public function test_goLive()
    {
        if (getenv('NO_CHECKLIST')) {
            $this->info('Skipping checklist-related test as asked.');
            return;
        }

        $this->browser
             ->click('.content-checklist .btn.finished')
             ->reload()
        ;

        sleep(5);

        if ($this->browser->hasVisible('.content-checklist')) {
            throw new Exception(
                'Checklist should have disappeard after clicking on "Go Live" and refreshing the page.'
            );
        }
    }

    public function test_I_Can_Access_A_Product_Sheet()
    {
        $this->shop->get('back-office')->visitController('AdminProducts');
        $this->browser
             // only consider active products
             ->select('[name="productFilter_active"]', 1)
             ->click('#submitFilterButtonproduct')
             // sort by decreasing quantity to be sure the product can be ordered later
             ->click('{xpath}//a[contains(@href, "productOrderby=sav_quantity&productOrderway=desc")]')
        ;

        // choose first enabled product with stock
        $this->browser->click('#table-product tr.odd:first-child a.edit');

        $this->product_url = $this->browser->getAttribute('#page-header-desc-product-preview', 'href');

        $this->browser->visit($this->product_url);

        $this->browser->waitFor('#add_to_cart');
    }

    public function test_BankWire_Is_Installed_Or_Install_It()
    {
        $this->shop->get('back-office')->visitController('AdminModules');
        $this->browser
             ->fillIn('#moduleQuicksearch', 'bankwire')
             ->waitFor('#anchorBankwire')
        ;

        try {
            $install_link = $this->browser->find('{xpath}//a[contains(@href, "install=bankwire")]');
            $install_link->click();
        } catch (Exception $e) {
            $this->info('Looks like bankwire is already installed, will reset it.');
            $this->shop->get('back-office')->visitController('AdminModules', [
                'module_name' => 'bankwire',
                'reset'
            ]);
        }

        $this->browser->waitFor('div.alert.alert-success');
    }

    public function test_I_Can_Get_An_Address()
    {
        $this->shop->get('back-office')->visitController('AdminAddresses');
        $this->browser
             // order by decreasing id to increase likelihood of finding a valid address
             ->click('{xpath}//a[contains(@href, "addressOrderby=id_address&addressOrderway=desc")]')
             ->click('#form-address table tr.odd:first-child a.edit')
        ;

        $uid = md5(microtime());
        $this->customer = [
            'firstname'     => 'Selenium',
            'lastname'      => 'LeChat',
            'email'         => $uid . '@example.com',
            'address1'      => $this->browser->getValue('#address1'),
            'postcode'      => $this->browser->getValue('#postcode'),
            'city'          => $this->browser->getValue('#city'),
            'country'       => $this->browser->getSelectedValue('#id_country'),
            'phone'         => $this->browser->getValue('#phone'),
            'phone_mobile'  => $this->browser->getValue('#phone_mobile')
        ];
    }

    public function test_I_Can_Register_As_A_New_Customer()
    {
        $this->browser->visit($this->shop->getFrontOfficeURL());
        $this->browser
             ->click('.header_user_info a.login')
             ->fillIn('#email_create', $this->customer['email'])
             ->click('#SubmitCreate')
             ->fillIn('#customer_firstname' , $this->customer['firstname'])
             ->fillIn('#customer_lastname'  , $this->customer['lastname'])
             ->fillIn('#passwd', '123456789');

        foreach ($this->browser->all('#center_column input[type="checkbox"]') as $checkbox) {
            $checkbox->click();
        }

        $this->browser
             ->click('#submitAccount')
        ;

        $this->browser->sendKeys(\WebDriverKeys::ESCAPE); // make stupid modal of makeupatelier disappear

        $this->browser->all('i.fa-building, i.icon-building')[0]->click();

        $this->browser
             ->fillIn('#address1', $this->customer['address1'])
             ->select('#id_country', $this->customer['country'])
             ->fillIn('#city', $this->customer['city'])
             ->fillIn('#postcode', $this->customer['postcode'])
             ->fillIn('#phone', $this->customer['phone'])
             ->fillIn('#phone_mobile', $this->customer['phone_mobile'])
             ->click('#submitAddress')
        ;

        if ($this->browser->hasVisible('.alert.alert-danger')) {
            throw new Exception('Address was not saved.');
        }
    }

    public function test_I_Can_Order_A_Product()
    {
        $this->browser->visit($this->product_url);

        sleep(15); // Black magic seems to be going on behind the scene.

        $this->browser->waitFor('#add_to_cart')
                      ->click('#add_to_cart button');

        try {
            $this->browser->click('.layer_cart_product .cross');
        } catch (Exception $e){
            // Nah, I don't care, maybe no modal.
        }

        sleep(5);

        $this->browser->all('.shopping_cart a')[0]->click();

        if ($this->browser->hasVisible('label[for="cgv"]')) {
            $id_order = $this->orderOPC();
        } else {
            $id_order = $this->orderFiveSteps();
        }

        if ($id_order <= 0) {
            throw new Exception('Order doesnt seem to have been succesful.');
        }

        $this->id_order = $id_order;
    }

    public function orderOPC()
    {
        $this->info('Proceeding to checkout in OPC.');

        $this->browser
             ->clickLabelFor('cgv')
             ->waitFor('a.bankwire')
             ->click('a.bankwire')
             ->click('#center_column form button[type="submit"]')
        ;

        return (int)$this->browser->getURLParameter('id_order');
    }

    public function orderFiveSteps()
    {
        $this->info('Proceeding to checkout in Five Steps.');

        $this->browser
             ->click('.btn.btn-default.standard-checkout')
             ->clickButtonNamed('processAddress')
        ;

        try {
            $this->browser->clickLabelFor('cgv');
        } catch (Exception $e) {
            // sometimes T&Cs are disabled, never mind
        }

        $this->browser
             ->clickButtonNamed('processCarrier')
             ->click('a.bankwire')
             ->click('#center_column form button[type="submit"]')
        ;

        return (int)$this->browser->getURLParameter('id_order');
    }

    public function test_As_A_Merchant_I_Can_Validate_The_Order_Just_Made()
    {
        $this->shop
             ->get('back-office')
             ->get('orders')
             ->visitById($this->id_order)
             ->validate()
        ;

        if ( 2 !== count($this->browser->all('table.history-status tr'))) {
            throw new Exception('Order status was not changed.');
        }
    }
}
