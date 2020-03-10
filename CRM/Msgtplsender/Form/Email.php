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
   * @var array
   */
  public $_toContactEmails = [];

  /**
   * Build all the data structures needed to build the form.
   */
  public function preProcess() {
    // Set redirect context
    $destination = CRM_Utils_Request::retrieve('destination', 'String');
    if (!empty($destination)) {
      CRM_Core_Session::singleton()->replaceUserContext(CRM_Utils_System::url($destination, NULL, TRUE));
    }

    // store case id if present
    $this->_caseId = CRM_Utils_Request::retrieve('caseid', 'String', $this, FALSE);
    $this->_context = CRM_Utils_Request::retrieve('context', 'Alphanumeric', $this);

    $cid = CRM_Utils_Request::retrieve('cid', 'String', $this, FALSE);

    // Allow request to specify email id rather than contact id
    $toEmailId = CRM_Utils_Request::retrieve('email_id', 'String', $this);
    if ($toEmailId) {
      $toEmail = civicrm_api3('email', 'getsingle', ['id' => $toEmailId]);
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

    self::emailCommonBuildQuickForm($this);

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
   * Build the form object. Copied from CRM_Contact_Form_Task_EmailCommon::buildQuickForm()
   * Only change is statusBounce -> setUFMessage
   *
   * @param \CRM_Msgtplsender_Form_Email $form
   */
  public static function emailCommonBuildQuickForm(&$form) {
    $toArray = $ccArray = $bccArray = array();
    $suppressedEmails = 0;
    //here we are getting logged in user id as array but we need target contact id. CRM-5988
    $cid = $form->get('cid');
    if ($cid) {
      $form->_contactIds = explode(',', $cid);
    }
    if (count($form->_contactIds) > 1) {
      $form->_single = FALSE;
    }
    CRM_Contact_Form_Task_EmailCommon::bounceIfSimpleMailLimitExceeded(count($form->_contactIds));

    $emailAttributes = array(
      'class' => 'huge',
    );
    $to = $form->add('text', 'to', ts('To'), $emailAttributes, TRUE);
    $cc = $form->add('text', 'cc_id', ts('CC'), $emailAttributes);
    $bcc = $form->add('text', 'bcc_id', ts('BCC'), $emailAttributes);

    $setDefaults = TRUE;
    if (property_exists($form, '_context') && $form->_context == 'standalone') {
      $setDefaults = FALSE;
    }

    $elements = array('to', 'cc', 'bcc');
    $form->_allContactIds = $form->_toContactIds = $form->_contactIds;
    foreach ($elements as $element) {
      if ($$element->getValue()) {
        $allEmails = explode(',', $$element->getValue());
        if ($element == 'to') {
          $form->_toContactIds = $form->_contactIds = array();
        }

        foreach ($allEmails as $value) {
          list($contactId, $email) = explode('::', $value);
          if ($contactId) {
            switch ($element) {
              case 'to':
                $form->_contactIds[] = $form->_toContactIds[] = $contactId;
                $form->_toContactEmails[] = $email;
                break;

              case 'cc':
                $form->_ccContactIds[] = $contactId;
                break;

              case 'bcc':
                $form->_bccContactIds[] = $contactId;
                break;
            }

            $form->_allContactIds[] = $contactId;
          }
        }

        $setDefaults = TRUE;
      }
    }

    //get the group of contacts as per selected by user in case of Find Activities
    if (!empty($form->_activityHolderIds)) {
      $contact = $form->get('contacts');
      $form->_allContactIds = $form->_contactIds = $contact;
    }

    // check if we need to setdefaults and check for valid contact emails / communication preferences
    if (is_array($form->_allContactIds) && $setDefaults) {
      $returnProperties = array(
        'sort_name' => 1,
        'email' => 1,
        'do_not_email' => 1,
        'is_deceased' => 1,
        'on_hold' => 1,
        'display_name' => 1,
        'preferred_mail_format' => 1,
      );

      // get the details for all selected contacts ( to, cc and bcc contacts )
      list($form->_contactDetails) = CRM_Utils_Token::getTokenDetails($form->_allContactIds,
        $returnProperties,
        FALSE,
        FALSE
      );

      // make a copy of all contact details
      $form->_allContactDetails = $form->_contactDetails;

      // perform all validations on unique contact Ids
      foreach (array_unique($form->_allContactIds) as $key => $contactId) {
        $value = $form->_contactDetails[$contactId];
        if ($value['do_not_email'] || empty($value['email']) || !empty($value['is_deceased']) || $value['on_hold']) {
          $suppressedEmails++;

          // unset contact details for contacts that we won't be sending email. This is prevent extra computation
          // during token evaluation etc.
          unset($form->_contactDetails[$contactId]);
        }
        else {
          $email = $value['email'];

          // build array's which are used to setdefaults
          if (in_array($contactId, $form->_toContactIds)) {
            $form->_toContactDetails[$contactId] = $form->_contactDetails[$contactId];
            // If a particular address has been specified as the default, use that instead of contact's primary email
            if (!empty($form->_toEmail) && $form->_toEmail['contact_id'] == $contactId) {
              $email = $form->_toEmail['email'];
            }
            $toArray[] = array(
              'text' => '"' . $value['sort_name'] . '" <' . $email . '>',
              'id' => "$contactId::{$email}",
            );
          }
          elseif (in_array($contactId, $form->_ccContactIds)) {
            $ccArray[] = array(
              'text' => '"' . $value['sort_name'] . '" <' . $email . '>',
              'id' => "$contactId::{$email}",
            );
          }
          elseif (in_array($contactId, $form->_bccContactIds)) {
            $bccArray[] = array(
              'text' => '"' . $value['sort_name'] . '" <' . $email . '>',
              'id' => "$contactId::{$email}",
            );
          }
        }
      }

      if (empty($toArray)) {
        $message = ts('Selected contact(s) do not have a valid email address, or communication preferences specify DO NOT EMAIL, or they are deceased or Primary email address is On Hold.');
        CRM_Utils_System::setUFMessage($message);
        CRM_Core_Error::statusBounce($message);
      }
    }

    $form->assign('toContact', json_encode($toArray));
    $form->assign('ccContact', json_encode($ccArray));
    $form->assign('bccContact', json_encode($bccArray));

    $form->assign('suppressedEmails', $suppressedEmails);

    $form->assign('totalSelectedContacts', count($form->_contactIds));

    $form->add('text', 'subject', ts('Subject'), 'size=50 maxlength=254', TRUE);

    $form->add('select', 'from_email_address', ts('From'), $form->_fromEmails, TRUE);

    CRM_Mailing_BAO_Mailing::commonCompose($form);

    // add attachments
    CRM_Core_BAO_File::buildAttachment($form, NULL);

    if ($form->_single) {
      // also fix the user context stack
      if ($form->_caseId) {
        $ccid = CRM_Core_DAO::getFieldValue('CRM_Case_DAO_CaseContact', $form->_caseId,
          'contact_id', 'case_id'
        );
        $url = CRM_Utils_System::url('civicrm/contact/view/case',
          "&reset=1&action=view&cid={$ccid}&id={$form->_caseId}"
        );
      }
      elseif ($form->_context) {
        $url = CRM_Utils_System::url('civicrm/dashboard', 'reset=1');
      }
      else {
        $url = CRM_Utils_System::url('civicrm/contact/view',
          "&show=1&action=browse&cid={$form->_contactIds[0]}&selectedChild=activity"
        );
      }

      $session = CRM_Core_Session::singleton();
      $session->replaceUserContext($url);
      $form->addDefaultButtons(ts('Send Email'), 'upload', 'cancel');
    }
    else {
      $form->addDefaultButtons(ts('Send Email'), 'upload');
    }

    $fields = array(
      'followup_assignee_contact_id' => array(
        'type' => 'entityRef',
        'label' => ts('Assigned to'),
        'attributes' => array(
          'multiple' => TRUE,
          'create' => TRUE,
          'api' => array('params' => array('is_deceased' => 0)),
        ),
      ),
      'followup_activity_type_id' => array(
        'type' => 'select',
        'label' => ts('Followup Activity'),
        'attributes' => array('' => '- ' . ts('select activity') . ' -') + CRM_Core_PseudoConstant::ActivityType(FALSE),
        'extra' => array('class' => 'crm-select2'),
      ),
      'followup_activity_subject' => array(
        'type' => 'text',
        'label' => ts('Subject'),
        'attributes' => CRM_Core_DAO::getAttribute('CRM_Activity_DAO_Activity',
          'subject'
        ),
      ),
    );

    //add followup date
    $form->add('datepicker', 'followup_date', ts('in'));

    foreach ($fields as $field => $values) {
      if (!empty($fields[$field])) {
        $attribute = CRM_Utils_Array::value('attributes', $values);
        $required = !empty($values['required']);

        if ($values['type'] == 'select' && empty($attribute)) {
          $form->addSelect($field, array('entity' => 'activity'), $required);
        }
        elseif ($values['type'] == 'entityRef') {
          $form->addEntityRef($field, $values['label'], $attribute, $required);
        }
        else {
          $form->add($values['type'], $field, $values['label'], $attribute, $required, CRM_Utils_Array::value('extra', $values));
        }
      }
    }

    //Added for CRM-15984: Add campaign field
    CRM_Campaign_BAO_Campaign::addCampaign($form);

    $form->addFormRule(array('CRM_Contact_Form_Task_EmailCommon', 'formRule'), $form);
    CRM_Core_Resources::singleton()->addScriptFile('civicrm', 'templates/CRM/Contact/Form/Task/EmailCommon.js', 0, 'html-header');
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
   * @throws \CRM_Core_Exception
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
