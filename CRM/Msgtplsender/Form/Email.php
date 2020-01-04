<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

/**
 * This class provides the functionality to email a group of contacts.
 */
class CRM_Msgtplsender_Form_Email extends CRM_Contact_Form_Task {

  /**
   * Are we operating in "single mode".
   *
   * Single mode means sending email to one specific contact.
   *
   * @var bool
   */
  public $_single = TRUE;

  /**
   * Are we operating in "single mode", i.e. sending email to one
   * specific contact?
   *
   * @var bool
   */
  public $_noEmails = FALSE;

  /**
   * All the existing templates in the system.
   *
   * @var array
   */
  public $_templates = NULL;

  /**
   * Store "to" contact details.
   * @var array
   */
  public $_toContactDetails = [];

  /**
   * Store all selected contact id's, that includes to, cc and bcc contacts
   * @var array
   */
  public $_allContactIds = [];

  /**
   * Store only "to" contact ids.
   * @var array
   */
  public $_toContactIds = [];

  /**
   * Store only "cc" contact ids.
   * @var array
   */
  public $_ccContactIds = [];

  /**
   * Store only "bcc" contact ids.
   * @var array
   */
  public $_bccContactIds = [];

  /**
   * Build all the data structures needed to build the form.
   */
  public function preProcess() {
    // store case id if present
    $this->_caseId = CRM_Utils_Request::retrieve('caseid', 'String', $this, FALSE);
    $this->_context = CRM_Utils_Request::retrieve('context', 'Alphanumeric', $this);

    $cid = CRM_Utils_Request::retrieve('cid', 'String', $this, FALSE);

    // Allow request to specify email id rather than contact id
    $toEmailId = CRM_Utils_Request::retrieve('email_id', 'String', $this);
    if ($toEmailId) {
      $toEmail = civicrm_api('email', 'getsingle', ['version' => 3, 'id' => $toEmailId]);
      if (!empty($toEmail['email']) && !empty($toEmail['contact_id'])) {
        $this->_toEmail = $toEmail;
      }
      if (!$cid) {
        $cid = $toEmail['contact_id'];
        $this->set('cid', $cid);
      }
    }

    if ($cid) {
      $cid = explode(',', $cid);
      $displayName = [];

      foreach ($cid as $val) {
        $displayName[] = CRM_Contact_BAO_Contact::displayName($val);
      }

      CRM_Utils_System::setTitle(implode(',', $displayName) . ' - ' . ts('Email'));
    }
    else {
      CRM_Utils_System::setTitle(ts('New Email'));
    }
    CRM_Contact_Form_Task_EmailCommon::preProcessFromAddress($this);

    if (!$cid && $this->_context != 'standalone') {
      parent::preProcess();
    }

    $this->assign('single', $this->_single);
    if (CRM_Core_Permission::check('administer CiviCRM')) {
      $this->assign('isAdmin', 1);
    }
  }

  /**
   * Build the form object.
   */
  public function buildQuickForm() {
    //enable form element
    $this->assign('suppressForm', FALSE);
    $this->assign('emailTask', TRUE);

    CRM_Contact_Form_Task_EmailCommon::buildQuickForm($this);

    $this->removeElement('to');

    $emails = civicrm_api3('Email', 'get', [
      'contact_id' => $this->get('cid'),
      'options' => ['sort' => "is_primary DESC"],
    ])['values'];
    $listOfEmails = [];
    foreach ($emails as $emailID => $emailDetail) {
      $listOfEmails[$emailID] = $emailDetail['email'];
    }

    $emailAttributes = [
      'class' => 'huge',
    ];
    $this->add('select', 'to', ts('To'), $listOfEmails, TRUE, $emailAttributes);

    $filtertpl = CRM_Utils_Request::retrieve('filtertpl', 'String', $this, FALSE);
    $messageTemplateParams = [
      'workflow_id' => ['IS NULL' => 1],
      'is_sms' => 0,
    ];
    if (!empty($filtertpl)) {
      $messageTemplateParams['msg_title'] = ['LIKE' => $filtertpl];
    }
    $templates = civicrm_api3('MessageTemplate', 'get', $messageTemplateParams)['values'];
    $listOfTemplates = [];
    foreach ($templates as $templateID => $templateDetail) {
      $listOfTemplates[$templateID] = $templateDetail['msg_title'];
    }

    $this->assign('templates', TRUE);
    $this->removeElement('template');
    if (!empty($listOfTemplates)) {
      $this->add('select', "template", ts('Use Template'),
        ['' => ts('- select -')] + $listOfTemplates, FALSE,
        ['onChange' => "selectValue( this.value, '');", 'class' => 'huge']
      );
    }
  }

  /**
   * Process the form after the input has been submitted and validated.
   *
   */
  public function postProcess() {
    // check and ensure that
    $formValues = $this->controller->exportValues($this->getName());
    $email = civicrm_api3('Email', 'getvalue', ['id' => $formValues['to'], 'return' => 'email']);
    $formValues['to'] = $this->get('cid') . "::{$email}";
    self::submit($this, $formValues);
  }

  /**
   * Submit the form values.
   *
   * This is also accessible for testing.
   *
   * @param CRM_Core_Form $form
   * @param array $formValues
   */
  public static function submit(&$form, $formValues) {
    self::saveMessageTemplate($formValues);

    $form->_contactIds = $form->_toContactIds = $form->_allContactIds = [$form->get('cid')];
    $form->_toContactDetails = [$form->get('cid') => civicrm_api3('Contact', 'getsingle', ['id' => $form->get('cid')])];

    $from = CRM_Utils_Array::value('from_email_address', $formValues);
    // dev/core#357 User Emails are keyed by their id so that the Signature is able to be added
    // If we have had a contact email used here the value returned from the line above will be the
    // numerical key where as $from for use in the sendEmail in Activity needs to be of format of "To Name" <toemailaddress>
    $from = CRM_Utils_Mail::formatFromAddress($from);
    $subject = $formValues['subject'];

    // CRM-13378: Append CC and BCC information at the end of Activity Details and format cc and bcc fields
    $elements = array('cc_id', 'bcc_id');
    $additionalDetails = NULL;
    $ccValues = $bccValues = array();
    foreach ($elements as $element) {
      if (!empty($formValues[$element])) {
        $allEmails = explode(',', $formValues[$element]);
        foreach ($allEmails as $value) {
          list($contactId, $email) = explode('::', $value);
          $contactURL = CRM_Utils_System::url('civicrm/contact/view', "reset=1&force=1&cid={$contactId}", TRUE);
          switch ($element) {
            case 'cc_id':
              $ccValues['email'][] = '"' . $form->_contactDetails[$contactId]['sort_name'] . '" <' . $email . '>';
              $ccValues['details'][] = "<a href='{$contactURL}'>" . $form->_contactDetails[$contactId]['display_name'] . "</a>";
              break;

            case 'bcc_id':
              $bccValues['email'][] = '"' . $form->_contactDetails[$contactId]['sort_name'] . '" <' . $email . '>';
              $bccValues['details'][] = "<a href='{$contactURL}'>" . $form->_contactDetails[$contactId]['display_name'] . "</a>";
              break;
          }
        }
      }
    }

    $cc = $bcc = '';
    if (!empty($ccValues)) {
      $cc = implode(',', $ccValues['email']);
      $additionalDetails .= "\ncc : " . implode(", ", $ccValues['details']);
    }
    if (!empty($bccValues)) {
      $bcc = implode(',', $bccValues['email']);
      $additionalDetails .= "\nbcc : " . implode(", ", $bccValues['details']);
    }

    // CRM-5916: prepend case id hash to CiviCase-originating emails’ subjects
    if (isset($form->_caseId) && is_numeric($form->_caseId)) {
      $hash = substr(sha1(CIVICRM_SITE_KEY . $form->_caseId), 0, 7);
      $subject = "[case #$hash] $subject";
    }

    $attachments = array();
    CRM_Core_BAO_File::formatAttachment($formValues,
      $attachments,
      NULL, NULL
    );

    // format contact details array to handle multiple emails from same contact
    $formattedContactDetails = array();
    $tempEmails = array();
    foreach ($form->_contactIds as $key => $contactId) {
      // if we dont have details on this contactID, we should ignore
      // potentially this is due to the contact not wanting to receive email
      if (!isset($form->_contactDetails[$contactId])) {
        continue;
      }
      $email = $form->_toContactEmails[$key];
      // prevent duplicate emails if same email address is selected CRM-4067
      // we should allow same emails for different contacts
      $emailKey = "{$contactId}::{$email}";
      if (!in_array($emailKey, $tempEmails)) {
        $tempEmails[] = $emailKey;
        $details = $form->_contactDetails[$contactId];
        $details['email'] = $email;
        unset($details['email_id']);
        $formattedContactDetails[] = $details;
      }
    }

    $contributionIds = array();
    if ($form->getVar('_contributionIds')) {
      $contributionIds = $form->getVar('_contributionIds');
    }

    // send the mail
    list($sent, $activityId) = CRM_Activity_BAO_Activity::sendEmail(
      $formattedContactDetails,
      $subject,
      $formValues['text_message'],
      $formValues['html_message'],
      NULL,
      NULL,
      $from,
      $attachments,
      $cc,
      $bcc,
      array_keys($form->_toContactDetails),
      $additionalDetails,
      $contributionIds,
      CRM_Utils_Array::value('campaign_id', $formValues)
    );

    $followupStatus = '';
    if ($sent) {
      $followupActivity = NULL;
      if (!empty($formValues['followup_activity_type_id'])) {
        $params['followup_activity_type_id'] = $formValues['followup_activity_type_id'];
        $params['followup_activity_subject'] = $formValues['followup_activity_subject'];
        $params['followup_date'] = $formValues['followup_date'];
        $params['target_contact_id'] = $form->_contactIds;
        $params['followup_assignee_contact_id'] = explode(',', $formValues['followup_assignee_contact_id']);
        $followupActivity = CRM_Activity_BAO_Activity::createFollowupActivity($activityId, $params);
        $followupStatus = ts('A followup activity has been scheduled.');

        if (Civi::settings()->get('activity_assignee_notification')) {
          if ($followupActivity) {
            $mailToFollowupContacts = array();
            $assignee = array($followupActivity->id);
            $assigneeContacts = CRM_Activity_BAO_ActivityAssignment::getAssigneeNames($assignee, TRUE, FALSE);
            foreach ($assigneeContacts as $values) {
              $mailToFollowupContacts[$values['email']] = $values;
            }

            $sentFollowup = CRM_Activity_BAO_Activity::sendToAssignee($followupActivity, $mailToFollowupContacts);
            if ($sentFollowup) {
              $followupStatus .= '<br />' . ts("A copy of the follow-up activity has also been sent to follow-up assignee contacts(s).");
            }
          }
        }
      }

      $count_success = count($form->_toContactDetails);
      CRM_Core_Session::setStatus(ts('One message was sent successfully. ', array(
          'plural' => '%count messages were sent successfully. ',
          'count' => $count_success,
        )) . $followupStatus, ts('Message Sent', array('plural' => 'Messages Sent', 'count' => $count_success)), 'success');
    }

    // Display the name and number of contacts for those email is not sent.
    // php 5.4 throws out a notice since the values of these below arrays are arrays.
    // the behavior is not documented in the php manual, but it does the right thing
    // suppressing the notices to get things in good shape going forward
    $emailsNotSent = @array_diff_assoc($form->_allContactDetails, $form->_contactDetails);

    if ($emailsNotSent) {
      $not_sent = array();
      foreach ($emailsNotSent as $contactId => $values) {
        $displayName = $values['display_name'];
        $email = $values['email'];
        $contactViewUrl = CRM_Utils_System::url('civicrm/contact/view', "reset=1&cid=$contactId");
        $not_sent[] = "<a href='$contactViewUrl' title='$email'>$displayName</a>" . ($values['on_hold'] ? '(' . ts('on hold') . ')' : '');
      }
      $status = '(' . ts('because no email address on file or communication preferences specify DO NOT EMAIL or Contact is deceased or Primary email address is On Hold') . ')<ul><li>' . implode('</li><li>', $not_sent) . '</li></ul>';
      CRM_Core_Session::setStatus($status, ts('One Message Not Sent', array(
        'count' => count($emailsNotSent),
        'plural' => '%count Messages Not Sent',
      )), 'info');
    }

    if (isset($form->_caseId)) {
      // if case-id is found in the url, create case activity record
      $cases = explode(',', $form->_caseId);
      foreach ($cases as $key => $val) {
        if (is_numeric($val)) {
          $caseParams = array(
            'activity_id' => $activityId,
            'case_id' => $val,
          );
          CRM_Case_BAO_Case::processCaseActivity($caseParams);
        }
      }
    }
  }

  /**
   * Save the template if update selected.
   *
   * @param array $formValues
   */
  protected static function saveMessageTemplate($formValues) {
    if (!empty($formValues['saveTemplate']) || !empty($formValues['updateTemplate'])) {
      $messageTemplate = array(
        'msg_text' => $formValues['text_message'],
        'msg_html' => $formValues['html_message'],
        'msg_subject' => $formValues['subject'],
        'is_active' => TRUE,
      );

      if (!empty($formValues['saveTemplate'])) {
        $messageTemplate['msg_title'] = $formValues['saveTemplateName'];
        CRM_Core_BAO_MessageTemplate::add($messageTemplate);
      }

      if (!empty($formValues['template']) && !empty($formValues['updateTemplate'])) {
        $messageTemplate['id'] = $formValues['template'];
        unset($messageTemplate['msg_title']);
        CRM_Core_BAO_MessageTemplate::add($messageTemplate);
      }
    }
  }

  /**
   * List available tokens for this form.
   *
   * @return array
   */
  public function listTokens() {
    $tokens = CRM_Core_SelectValues::contactTokens();

    if (isset($this->_caseId) || isset($this->_caseIds)) {
      // For a single case, list tokens relevant for only that case type
      $caseTypeId = isset($this->_caseId) ? CRM_Core_DAO::getFieldValue('CRM_Case_DAO_Case', $this->_caseId, 'case_type_id') : NULL;
      $tokens += CRM_Core_SelectValues::caseTokens($caseTypeId);
    }

    return $tokens;
  }

}
