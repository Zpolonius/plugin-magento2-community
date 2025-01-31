import Order from '../PageObjects/objects'

describe('Payments', function () {

    it('CC full capture and refund', function () {
        const ord = new Order()
        ord.clrcookies()
        ord.visit()
        cy.get('body').then(($body) => {
            if ($body.text().includes('DKK') === false) {
                ord.clrcookies()
                ord.admin()
                ord.change_currency_to_DKK()
            }
            ord.visit()
            ord.addproduct()
            cy.fixture('config').then((admin) => {
                if (admin.CC_TERMINAL_NAME != "") {
                    cy.get('body').wait(3000).then(($a) => {
                        if ($a.find("label:contains('" + admin.CC_TERMINAL_NAME + "')").length) {
                            ord.cc_payment(admin.CC_TERMINAL_NAME)
                            ord.clrcookies()
                            ord.admin()
                            ord.capture()
                            ord.refund()
                        } else {
                            cy.log(admin.CC_TERMINAL_NAME + ' not found in page')
                            this.skip()
                        }

                    })
                }
                else {
                    cy.log('CC_TERMINAL_NAME skipped')
                    this.skip()
                }
            })
        })
    })


    it('Klarna full capture and refund', function () {
        const ord = new Order()
        ord.clrcookies()
        ord.visit()
        cy.get('body').then(($body) => {
            if ($body.text().includes('DKK') === false) {
                ord.admin()
                ord.change_currency_to_DKK()
            }
            ord.visit()
            ord.addproduct()
            cy.fixture('config').then((admin) => {
                if (admin.KLARNA_DKK_TERMINAL_NAME != "") {
                    cy.get('body').then(($a) => {
                        if ($a.find("label:contains('" + admin.KLARNA_DKK_TERMINAL_NAME + "')").length) {
                            ord.klarna_payment(admin.KLARNA_DKK_TERMINAL_NAME)
                            ord.admin()
                            ord.capture()
                            ord.refund()
                        } else {
                            cy.log(admin.KLARNA_DKK_TERMINAL_NAME + ' not found in page')
                            this.skip()
                        }
                    })
                }
                else {
                    cy.log('KLARNA_DKK_TERMINAL_NAME skipped')
                    this.skip()
                }
            })
        })
    })

    it('iDEAL Payment', function () {
        const ord = new Order()
        ord.visit()
        cy.get('body').then(($body) => {

            if ($body.text().includes('€') === false) {
                ord.admin()
                ord.change_currency_to_EUR_for_iDEAL()
            }
            //ord.clrcookies()
            ord.visit()
            ord.addproduct()
            cy.fixture('config').then((admin) => {
                if (admin.iDEAL_EUR_TERMINAL != "") {
                    cy.get('body').wait(3000).then(($a) => {
                        if ($a.find("label:contains('" + admin.iDEAL_EUR_TERMINAL + "')").length) {
                            ord.ideal_payment(admin.iDEAL_EUR_TERMINAL)
                            ord.admin()
                            ord.ideal_refund()
                        } else {
                            cy.log(admin.iDEAL_EUR_TERMINAL + ' not found in page')
                            this.skip()
                        }

                    })
                }
                else {
                    cy.log('iDEAL_EUR_TERMINAL skipped')
                    this.skip()
                }
            })
        })
    })

    it('Subscription', function () {
        const ord = new Order()
        ord.clrcookies()
        ord.visit()
        ord.signin()
        ord.subscription_product()
        cy.get('body').then(($a) => {
            if ($a.find("label:contains('Subscribe to this product.')").length) {
                cy.contains('Subscribe to this product.')
                    .click({ force: true })
                ord.subscrition_check()
                ord.subscription_payment()
                ord.admin()
                ord.capture()
            }
            else {
                cy.log('Subscription product not found')
                this.skip()
            }

        })
    })

    it('CC partial capture', function () {
        const ord = new Order()
        ord.clrcookies()
        ord.visit()
        cy.get('body').then(($body) => {
            if ($body.text().includes('DKK') === false) {
                ord.admin()
                ord.change_currency_to_DKK()
            }
            ord.visit()
            ord.addpartial_product()
            ord.addproduct()
            cy.fixture('config').then((admin) => {
                if (admin.CC_TERMINAL_NAME != "") {
                    cy.get('body').then(($a) => {
                        if ($a.find("label:contains('" + admin.CC_TERMINAL_NAME + "')").length) {
                            ord.cc_payment(admin.CC_TERMINAL_NAME)
                            ord.admin()
                            ord.partial_capture()
                        } else {
                            cy.log(admin.CC_TERMINAL_NAME + ' not found in page')
                            this.skip()
                        }
                    })
                }
                else {
                    cy.log('CC_TERMINAL_NAME skipped')
                    this.skip()
                }
            })
        })
    })

    it('Klarna partial capture', function () {
        const ord = new Order()
        ord.clrcookies()
        ord.visit()
        cy.get('body').then(($body) => {
            if ($body.text().includes('DKK') === false) {
                ord.admin()
                ord.change_currency_to_DKK()
            }
            ord.visit()
            ord.addpartial_product()
            ord.addproduct()
            cy.fixture('config').then((admin) => {
                if (admin.KLARNA_DKK_TERMINAL_NAME != "") {
                    cy.get('body').then(($a) => {
                        if ($a.find("label:contains('" + admin.KLARNA_DKK_TERMINAL_NAME + "')").length) {
                            ord.klarna_payment(admin.KLARNA_DKK_TERMINAL_NAME)
                            ord.admin()
                            ord.partial_capture()
                        } else {
                            cy.log(admin.KLARNA_DKK_TERMINAL_NAME + ' not found in page')
                            this.skip()
                        }
                    })
                }
                else {
                    cy.log('KLARNA_DKK_TERMINAL_NAME skipped')
                    this.skip()
                }
            })
        })
    })

    it('CC partial refund', function () {
        const ord = new Order()
        ord.clrcookies()
        ord.visit()
        cy.get('body').then(($body) => {
            if ($body.text().includes('DKK') === false) {
                ord.admin()
                ord.change_currency_to_DKK()
            }
            ord.visit()
            ord.addpartial_product()
            ord.addproduct()
            cy.fixture('config').then((admin) => {
                if (admin.CC_TERMINAL_NAME != "") {
                    cy.get('body').then(($a) => {
                        if ($a.find("label:contains('" + admin.CC_TERMINAL_NAME + "')").length) {
                            ord.cc_payment(admin.CC_TERMINAL_NAME)
                            ord.admin()
                            ord.capture()
                            ord.partial_refund()

                        } else {
                            cy.log(admin.CC_TERMINAL_NAME + ' not found in page')
                            this.skip()
                        }
                    })
                }
                else {
                    cy.log('CC_TERMINAL_NAME skipped')
                    this.skip()
                }
            })
        })
    })

    it('Klarna partial refund', function () {
        const ord = new Order()
        ord.clrcookies()
        ord.visit()
        cy.get('body').then(($body) => {
            if ($body.text().includes('DKK') === false) {
                ord.admin()
                ord.change_currency_to_DKK()
            }
            ord.visit()
            ord.addpartial_product()
            ord.addproduct()
            cy.fixture('config').then((admin) => {
                if (admin.KLARNA_DKK_TERMINAL_NAME != "") {
                    cy.get('body').then(($a) => {
                        if ($a.find("label:contains('" + admin.KLARNA_DKK_TERMINAL_NAME + "')").length) {
                            ord.klarna_payment(admin.KLARNA_DKK_TERMINAL_NAME)
                            ord.admin()
                            ord.capture()
                            ord.partial_refund()
                        } else {
                            cy.log(admin.KLARNA_DKK_TERMINAL_NAME + ' not found in page')
                            this.skip()
                        }
                    })
                }
                else {
                    cy.log('KLARNA_DKK_TERMINAL_NAME skipped')
                    this.skip()
                }
            })
        })
    })

    it('CC release payment', function () {
        const ord = new Order()
        ord.clrcookies()
        ord.visit()
        cy.get('body').then(($body) => {
            if ($body.text().includes('DKK') === false) {
                ord.admin()
                ord.change_currency_to_DKK()
            }
            ord.visit()
            ord.addproduct()
            cy.fixture('config').then((admin) => {
                if (admin.CC_TERMINAL_NAME != "") {
                    cy.get('body').then(($a) => {
                        if ($a.find("label:contains('" + admin.CC_TERMINAL_NAME + "')").length) {
                            ord.cc_payment(admin.CC_TERMINAL_NAME)
                            ord.admin()
                            ord.release_payment()
                        } else {
                            cy.log(admin.CC_TERMINAL_NAME + ' not found in page')
                            this.skip()
                        }
                    })
                }
                else {
                    cy.log('CC_TERMINAL_NAME skipped')
                    this.skip()
                }
            })
        })
    })

    it('Klarna release payment', function () {

        const ord = new Order()
        ord.clrcookies()
        ord.visit()
        cy.get('body').then(($body) => {
            if ($body.text().includes('DKK') === false) {
                ord.admin()
                ord.change_currency_to_DKK()
            }
            ord.visit()
            ord.addproduct()
            cy.fixture('config').then((admin) => {
                if (admin.KLARNA_DKK_TERMINAL_NAME != "") {
                    cy.get('body').then(($a) => {
                        if ($a.find("label:contains('" + admin.KLARNA_DKK_TERMINAL_NAME + "')").length) {
                            ord.klarna_payment(admin.KLARNA_DKK_TERMINAL_NAME)
                            ord.admin()
                            ord.release_payment()
                        } else {
                            cy.log(admin.KLARNA_DKK_TERMINAL_NAME + ' not found in page')
                            this.skip()
                        }
                    })
                }
                else {
                    cy.log('KLARNA_DKK_TERMINAL_NAME skipped')
                    this.skip()
                }
            })

        })
    })

    
    it('CC Pay by link', function () {
        const ord = new Order()
        ord.clrcookies()
    
        cy.get('body').then(($body) => {
            if ($body.text().includes('DKK') === false) {
                ord.admin()
                ord.change_currency_to_DKK()
            } 
            cy.fixture('config').then((admin) => {
                if (admin.CC_TERMINAL_NAME != "") {
                    cy.get('#menu-magento-sales-sales').click()
                    cy.get('.item-sales-order > a').click()
                    cy.get('#add').click()
                    cy.get('#sales_order_create_customer_grid_table > tbody > tr:nth-child(1)').click().wait(3000)
                    cy.reload()
                    cy.get('#add_products').click()
                    cy.get('#sales_order_create_search_grid_table > tbody > tr:nth-child(2)').click()
                    cy.get('#order-search > div.admin__page-section-title > div').click().wait(2000)
                    cy.get('#order-shipping-method-summary > a').click({ force: true }) 
                    cy.get('.admin__order-shipment-methods-options-list > li:first').click().wait(3000)
                    cy.contains('EmbraceIT Integration Test Terminal').click().wait(3000)   
                    cy.get('#submit_order_top_button').click().wait(2000)            
                    cy.get('.payment_link > code').then(($a) => {
                        const payment_link = $a.text();
                        cy.origin('https://pensio.com', { args: { payment_link } }, ({ payment_link }) => {
                            cy.visit(payment_link)   
                            cy.get('#creditCardNumberInput').type('4111111111111111')
                            cy.get('#emonth').type('01')
                            cy.get('#eyear').type('2023')
                            cy.get('#cvcInput').type('123')
                            cy.get('#cardholderNameInput').type('testname')
                            cy.get('#pensioCreditCardPaymentSubmitButton').click().wait(3000)                     
                        })
                    
                        cy.get('.page-title > span').should('have.text','Thank you for your purchase!')
                    })
                }
                else {
                    cy.log('CC_TERMINAL_NAME skipped')
                    this.skip()
                }
            })
        })
    })

    it('Klarna Pay by link', function () {

        const ord = new Order()
        ord.clrcookies()
    
        cy.get('body').then(($body) => {
            if ($body.text().includes('DKK') === false) {
                ord.admin()
                ord.change_currency_to_DKK()
            }            
            cy.fixture('config').then((admin) => {
                if (admin.KLARNA_DKK_TERMINAL_NAME != "") {
                    cy.get('#menu-magento-sales-sales').click()
                    cy.get('.item-sales-order > a').click()
                    cy.get('#add').click()
                    cy.get('#sales_order_create_customer_grid_table > tbody > tr:nth-child(1)').click().wait(4000)
                    cy.reload()
                    cy.get('#add_products').click().wait(2000)
                    cy.get('#sales_order_create_search_grid_table > tbody > tr:nth-child(2)').click()
                    cy.get('#order-search > div.admin__page-section-title > div').click().wait(2000)
                    cy.get('#order-shipping-method-summary > a').click({ force: true }) 
                    cy.get('.admin__order-shipment-methods-options-list > li:first').click().wait(3000)
                    cy.contains('EmbraceIT Klarna Integration Test Terminal').click().wait(3000)   
                    cy.get('#submit_order_top_button').click().wait(2000)
                    cy.get('.payment_link > code').then(($a) => {
                        const payment_link = $a.text();
                        cy.origin('https://pensio.com', { args: { payment_link } }, ({ payment_link }) => {                            
                            cy.visit(payment_link)
                            cy.get('[id=submitbutton]').click().wait(5000)
                            cy.wait(5000)
                            cy.get('[id=klarna-pay-later-fullscreen]').then(function ($iFrame) {
                                const mobileNum = $iFrame.contents().find('[id=email_or_phone]')
                                cy.wrap(mobileNum).type('20222222')
                                const continueBtn = $iFrame.contents().find('[id=onContinue]')
                                cy.wrap(continueBtn).click().wait(2000)
                            })
                            cy.get('[id=klarna-pay-later-fullscreen]').wait(4000).then(function($iFrame){
                                const otp = $iFrame.contents().find('[id=otp_field]')
                                cy.wrap(otp).type('123456').wait(2000)
                            })  
                            cy.get('[id=klarna-pay-later-fullscreen]').wait(2000).then(function($iFrame){
                                const contbtn = $iFrame.contents().find('[id=invoice_kp-purchase-review-continue-button]')
                                cy.wrap(contbtn).click().wait(2000)
                            })
                            
                        })
                    
                    })
                    cy.get('.page-title > span').should('have.text','Thank you for your purchase!')
                }
                else {
                    cy.log('KLARNA_DKK_TERMINAL_NAME skipped')
                    this.skip()
                }
            })

        })
    })

})
