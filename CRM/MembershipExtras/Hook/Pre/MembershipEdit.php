<?php

use CRM_MembershipExtras_Service_MembershipEndDateCalculator as MembershipEndDateCalculator;

/**
 * Implements hook to be run before a membership is created/edited.
 */
class CRM_MembershipExtras_Hook_Pre_MembershipEdit {

  /**
   * Parameters that will be used to create the membership.
   *
   * @var array
   */
  private $params;

  /**
   * ID of the membership.
   *
   * @var int
   */
  private $id;

  /**
   * The membership payment contribution ID.
   *
   * @var int
   */
  private $paymentContributionID;


  public function __construct($id, &$params, $contributionID) {
    $this->id = $id;
    $this->params = &$params;
    $this->paymentContributionID = $contributionID;
  }

  /**
   * Preprocesses parameters used for Membership operations.
   */
  public function preProcess() {
    if ($this->paymentContributionID && $this->isOfflineNonPendingPaymentPlanMembership()) {
      $this->preventExtendingPaymentPlanMembership();
    }

    $this->updateMembershipPeriods();
  }

  /**
   * Prevents extending offline non pending payment plan Membership.
   *
   * If a membership price will be paid using
   * payment plan then each time an installment get
   * paid the membership will get extended.
   * For example if you have 12 installments for
   * a 1 year membership, then each time an
   * installment get paid the membership will get extended
   * by one year, this method prevent civicrm from doing that
   * so the membership gets only extended at completing
   * the first payment.
   */
  public function preventExtendingPaymentPlanMembership() {
    unset($this->params['end_date']);
  }

  /**
   * Determines if the payment for a membership
   * subscription is offline (pay later), non pending and paid
   * as payment plan.
   *
   * @return bool
   */
  private function isOfflineNonPendingPaymentPlanMembership() {
    $recContributionID = $this->getPaymentRecurringContributionID();

    if ($recContributionID === NULL) {
      return FALSE;
    }

    return $this->isOfflineNonPendingPaymentPlanContribution($recContributionID);
  }

  /**
   * Determines if the recurring contribution
   * is offline (pay later) and is for
   * a payment plan.
   *
   * @param $recurringContributionID
   * @return bool
   */
  private function isOfflineNonPendingPaymentPlanContribution($recurringContributionID) {
    $recurringContribution = civicrm_api3('ContributionRecur', 'get', [
      'sequential' => 1,
      'id' => $recurringContributionID,
    ])['values'][0];

    $isPaymentPlanRecurringContribution = !empty($recurringContribution['installments']);

    $manualPaymentProcessors = CRM_MembershipExtras_Service_ManualPaymentProcessors::getIDs();
    $isOfflineContribution = empty($recurringContribution['payment_processor_id']) ||
      in_array($recurringContribution['payment_processor_id'], $manualPaymentProcessors);

    $pendingContributionStatusId = array_search('Pending', CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name'));
    $isNonPending = !($recurringContribution['contribution_status_id'] == $pendingContributionStatusId);

    if ($isOfflineContribution && $isPaymentPlanRecurringContribution && $isNonPending) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Gets the associated recurring contribution ID for
   * the membership payment(contribution) if it does exist.
   *
   * @return int|null
   *   The recurring contribution ID or NULL
   *   if no recurring contribution exist.
   */
  private function getPaymentRecurringContributionID() {
    $paymentContribution = civicrm_api3('Contribution', 'get', [
      'sequential' => 1,
      'id' => $this->paymentContributionID,
      'return' => ['id', 'contribution_recur_id'],
    ]);

    if (empty($paymentContribution['values'][0]['contribution_recur_id'])) {
      return NULL;
    }

    return $paymentContribution['values'][0]['contribution_recur_id'];
  }

  /**
   * Update membership periods upon membership
   * edit which might result on updating existing
   * periods or creating new ones or both.
   */
  private function updateMembershipPeriods() {
    $membershipPeriodUpdate = new CRM_MembershipExtras_Hook_Pre_MembershipPeriodUpdater($this->id, $this->params, $this->paymentContributionID);
    $membershipPeriodUpdate->process();
  }

}
