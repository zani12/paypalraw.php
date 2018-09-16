require File.dirname(__FILE__) + '/../test_helper'

class PaypalDepositAccountTest < ActiveSupport::TestCase
  all_fixtures

  def setup
    I18n.locale = :"en-US"
  end

  def test_gateway
    assert PaypalDepositAccount.gateway.is_a?(ActiveMerchant::Billing::Gateway)
  end

  def test_should_validate_with_new
    da = PaypalDepositAccount.new(:person => people(:homer), :paypal_account => "adam@smith.txt")
    assert da.valid?
  end
  
  def test_should_instantiate_by_type_and_class_name
    da = DepositAccount.new(:type => 'PaypalDepositAccount')
    assert_equal 'PaypalDepositAccount', da.class.name
    
    da = DepositAccount.new(:type => PaypalDepositAccount)
    assert_equal 'PaypalDepositAccount', da.class.name
  end

  def test_should_instantiate_by_type_and_kind
    da = DepositAccount.new(:type => :paypal)
    assert_equal 'PaypalDepositAccount', da.class.name
  end
  
  def test_should_get_kind
    assert_equal :paypal, PaypalDepositAccount.kind
    assert_equal :paypal, PaypalDepositAccount.new.kind
  end

  def test_should_get_transaction_fee
    da = build_deposit_account
    assert_equal Money.new(-50, 'USD'), da.transaction_fee
  end

  def test_should_get_min_transfer_amount
    da = build_deposit_account
    assert_equal Money.new(100, 'USD'), da.min_transfer_amount
  end

  def test_should_get_max_transfer_amount
    da = build_deposit_account
    assert_equal Money.new(10000, 'USD'), da.max_transfer_amount
  end

  def test_should_get_min_transfer_amount_cents
    assert_equal 100, PaypalDepositAccount.min_transfer_amount_cents
  end

  def test_should_get_max_transfer_amount_cents
    assert_equal 10000, PaypalDepositAccount.max_transfer_amount_cents
  end

  def test_should_get_available_transfer_amount
    da = build_deposit_account
    assert_equal Money.new(500, 'USD'), da.available_transfer_amount
  end
  
  def test_should_set_transfer_amount
    da = build_deposit_account
    da.transfer_amount = Money.new(100, 'USD')
    assert_equal Money.new(100, 'USD'), da.transfer_amount
  end

  def test_should_set_transfer_amount_with_string
    da = build_deposit_account
    da.transfer_amount = "$1.00"
    assert_equal Money.new(100, 'USD'), da.transfer_amount
  end
  
  def test_should_set_transfer_amount_with_number
    da = build_deposit_account
    da.transfer_amount = 1.25
    assert_equal Money.new(125, 'USD'), da.transfer_amount
  end

  def test_should_set_transfer_amount_with_nil
    da = build_deposit_account
    da.transfer_amount = nil
    assert_equal Money.new(0, 'USD'), da.transfer_amount
  end
  
  def required_account_balance
    da = build_deposit_account(:transfer_amount => Money.new(130))
    assert_equal "1.80", da.required_account_balance.to_s
  end

  def test_default_transfer_amount
    da = build_deposit_account
    assert '4.50', da.default_transfer_amount.to_s
  end

  def test_default_transfer_amount_with_max
    da = build_deposit_account
    da.person.piggy_bank.direct_deposit(100.to_money)
    assert '99.50', da.default_transfer_amount.to_s
  end
  
  def test_should_not_validate_underruns_min_amount
    da = build_deposit_account(:transfer_amount => Money.new(99, 'USD'))
    assert !da.valid?
    assert da.errors.invalid?(:transfer_amount)
    assert_equal "must be greater than or equal to $1.00",
      da.errors.on(:transfer_amount)
  end

  def test_should_not_validate_exceeds_max_amount
    da = build_deposit_account(:transfer_amount => Money.new(10001, 'USD'))
    assert !da.valid?
    assert da.errors.invalid?(:transfer_amount)
    assert_equal "must be less than or equal to $100.00",
      da.errors.on(:transfer_amount).first
  end

  def test_should_not_validate_exceeds_available_amount_minus_fee
    da = build_deposit_account(:transfer_amount => Money.new(501, 'USD'))
    assert !da.valid?
    assert da.errors.invalid?(:transfer_amount)
    assert_equal "$5.01 exceeds your available account balance of $5.00",
      da.errors.on(:transfer_amount)
  end

  def test_should_get_net_transfer_amount
    da = build_deposit_account(:transfer_amount => Money.new(500, 'USD'))
    assert_equal Money.new(450, 'USD'), da.net_transfer_amount
  end

  def test_should_get_gross_transfer_amount
    da = build_deposit_account(:transfer_amount => Money.new(500, 'USD'))
    assert_equal Money.new(500, 'USD'), da.gross_transfer_amount
  end

  def test_should_validate_paypal_account
    da = build_deposit_account(
      :transfer_amount => Money.new(400, 'USD'),
      :paypal_account => 'adam@smith.tst'
    )
    assert da.valid?
    assert !da.errors.invalid?(:paypal_account)
  end

  def test_should_not_validate_bogus_paypal_account
    da = build_deposit_account(
      :transfer_amount => Money.new(400, 'USD'),
      :paypal_account => 'bogus'
    )
    assert !da.valid?
    assert da.errors.invalid?(:paypal_account)
    assert 'appears to be invalid', da.errors.on(:paypal_account)
  end

  def test_should_not_validate_empty_paypal_account
    da = build_deposit_account(
      :transfer_amount => Money.new(400, 'USD'),
      :paypal_account => nil
    )
    assert !da.valid?
    assert da.errors.invalid?(:paypal_account)
    assert 'appears to be invalid', da.errors.on(:paypal_account)
  end

  def test_should_transfer
    da = create_deposit_account(
      :transfer_amount => Money.new(400, 'USD'),
      :paypal_account => 'adam@smith.tst'
    )
    assert_difference PiggyBankAccountTransaction, :count, 2 do
      result = da.transfer
      assert result.success?
      assert :transfer, result.action
      assert_equal '4.00', result.amount.to_s
      assert_equal '-0.50', result.fee.to_s
      assert_equal "$3.50 was transferred from Homer Simpson at Luleka to Paypal account adam@smith.tst on #{Date.today.to_s(:short)}. The total transfer amount was $4.00 and a transaction fee of $0.50 was charged.", 
        result.description
      
      assert_equal '1.00', da.person.piggy_bank.balance.to_s
      assert_equal '1.00', da.person.piggy_bank.available_balance.to_s

      assert_equal '0.50', Organization.probono.piggy_bank.balance.to_s
      assert_equal '0.00', Organization.probono.piggy_bank.available_balance.to_s
    end
  end

  def test_should_not_transfer_to_invalid_account
    da = build_deposit_account(
      :transfer_amount => Money.new(400, 'USD'),
      :paypal_account => 'bogus'
    )
    result = da.transfer
    assert !result.success?
    assert_equal '5.00', da.person.piggy_bank.balance.to_s
    assert_equal '5.00', da.person.piggy_bank.available_balance.to_s
  end

  def test_should_not_transfer_with_no_transfer_amount
    da = build_deposit_account(
      :paypal_account => 'valid@paypal.com'
    )
    assert da.valid?
    assert_nil da.transfer_amount
    result = da.transfer
    assert !result.success?
    assert_equal '5.00', da.person.piggy_bank.balance.to_s
    assert_equal '5.00', da.person.piggy_bank.available_balance.to_s
  end

  def test_should_not_transfer_due_to_invalid_merchant_response_code
    da = build_deposit_account(
      :transfer_amount => Money.new(400, 'USD'),
      :paypal_account => 'fail@error.tst'
    )
    result = da.transfer
    assert !result.success?
    assert_equal '5.00', da.person.piggy_bank.balance.to_s
    assert_equal '5.00', da.person.piggy_bank.available_balance.to_s
  end

  def test_should_not_transfer_due_to_exception
    da = build_deposit_account(
      :transfer_amount => Money.new(400, 'USD'),
      :paypal_account => 'error@error.tst'
    )
    result = da.transfer
    assert !result.success?
    assert_equal 'Bogus Gateway: Use CreditCard number 1 for success, 2 for exception and anything else for error',
      result.description
    assert_equal '5.00', da.person.piggy_bank.balance.to_s
    assert_equal '5.00', da.person.piggy_bank.available_balance.to_s
  end

  def test_should_content_attributes
    attributes = {
      :transfer_amount => Money.new(400, 'USD'),
      :paypal_account => 'error@error.tst'
    }
    da = build_deposit_account(attributes)
#    assert_equal 2, da.content_attributes.to_a.size
    assert_equal attributes[:transfer_amount], da.content_attributes[:transfer_amount]
    assert_equal attributes[:paypal_account], da.content_attributes[:paypal_account]
  end

  def test_state_machine_should_work_with_main_branch
    da = build_deposit_account(
      :transfer_amount => Money.new(400, 'USD'),
      :paypal_account => 'error@error.tst'
    )
#    assert da.save
    assert_equal :created, da.current_state
    assert da.register!
    assert_equal :pending, da.current_state
    assert_nil da.activated_at
    assert da.activate!
    assert_equal :active, da.current_state
    assert da.activated_at
    assert da.suspend!
    assert_equal :suspended, da.current_state
    assert da.unsuspend!
    assert_equal :active, da.current_state
  end
  
  protected
  
  def valid_deposit_account_attributes(options={})
    {
      :type => PaypalDepositAccount,
      :person => people(:homer), 
      :paypal_account => "adam@smith.txt"
    }.merge(options)
  end
  
  def build_deposit_account(options={})
    da = DepositAccount.new(valid_deposit_account_attributes(options))
    da.person.piggy_bank.direct_deposit(Money.new(500, 'USD'))
    da
  end

  def create_deposit_account(options={})
    da = DepositAccount.create(valid_deposit_account_attributes(options))
    da.person.piggy_bank.direct_deposit(Money.new(500, 'USD'))
    da
  end
  
end
