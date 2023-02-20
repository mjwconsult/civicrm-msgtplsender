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

use Civi\Api4\Email;
use Civi\Api4\MessageTemplate;

/**
 * This class provides the functionality to email a group of contacts.
 */
class CRM_Msgtplsender_Form_Email extends CRM_Core_Form {

  /**
   * @var bool
   */
  public $submitOnce = TRUE;

  /**
   * @var string The prefix for a message template (eg. "Email") will look for all messagetemplates with name "Email:.."
   */
  protected $tplPrefix = '';

  /**
   * @var array List of emails keyed by email ID that will be loaded into the "To" element
   */
  protected $listOfEmails = [];

  /**
   * Contacts form whom emails could not be sent.
   *
   * An array of contact ids and the relevant message details.
   *
   * @var array
   */
  protected $suppressedEmails = [];

  public function getTableName() {
    return 'civicrm_contact';
  }

  /**
   * Build all the data structures needed to build the form.
   */
  public function preProcess() {
    // Set redirect context
    $destination = CRM_Utils_Request::retrieve('destination', 'String', $this);
    if (!empty($destination)) {
      CRM_Core_Session::singleton()->replaceUserContext(CRM_Utils_System::url($destination, NULL, TRUE));
      $this->set('destination', $destination);
    }

    // store case id if present
    $this->_caseId = CRM_Utils_Request::retrieve('caseid', 'String', $this);
    $cid = CRM_Utils_Request::retrieve('cid', 'String', $this);

    if ($cid) {
      $this->set('cid', $cid);

      $cid = explode(',', $cid);
      $displayName = [];

      foreach ($cid as $val) {
        $displayName[] = CRM_Contact_BAO_Contact::displayName($val);
      }
    }

    $this->setTitle(implode(',', $displayName) . ' - ' . ts('Email'));
    self::preProcessFromAddress($this);

    $this->addExpectedSmartyVariable('rows');
    // E-notice prevention in Task.tpl
    $this->assign('isSelectedContacts', FALSE);
    $this->assign('isAdmin', CRM_Core_Permission::check('administer CiviCRM'));
  }

  /**
   * Pre Process Form Addresses to be used in Quickform
   *
   * @param CRM_Core_Form $form
   * @param bool $bounce determine if we want to throw a status bounce.
   *
   * @throws \CRM_Core_Exception
   */
  public static function preProcessFromAddress(&$form, $bounce = TRUE) {
    $form->_contactIds = [CRM_Core_Session::getLoggedInContactID()];

    $fromEmailValues = CRM_Core_BAO_Email::getFromEmail();

    if ($bounce) {
      if (empty($fromEmailValues)) {
        CRM_Core_Error::statusBounce(ts('Your user record does not have a valid email address and no from addresses have been configured.'));
      }
    }

    $defaults = [];
    $form->_fromEmails = $fromEmailValues;
    if (is_numeric(key($form->_fromEmails))) {
      $emailID = (int) key($form->_fromEmails);
      $defaults = CRM_Core_BAO_Email::getEmailSignatureDefaults($emailID);
    }
    if (!Civi::settings()->get('allow_mail_from_logged_in_contact')) {
      $defaults['from_email_address'] = CRM_Core_BAO_Domain::getFromEmail();
    }
    $form->setDefaults($defaults);
  }

  /**
   * Build the form object.
   */
  public function buildQuickForm() {
    // Suppress form might not be required but perhaps there was a risk some other  process had set it to TRUE.
    $this->assign('suppressForm', FALSE);
    $this->assign('emailTask', TRUE);

    $toArray = [];
    //here we are getting logged in user id as array but we need target contact id. CRM-5988
    $cid = $this->get('cid');
    if ($cid) {
      $this->_contactIds = explode(',', $cid);
    }
    // The default in CRM_Core_Form_Task is null, but changing it there gives
    // errors later.
    if (is_null($this->_contactIds)) {
      $this->_contactIds = [];
    }

    $emailAttributes = [
      'class' => 'huge',
    ];
    $this->add('text', 'to', ts('To'), $emailAttributes, TRUE);

    $this->addEntityRef('cc_id', ts('CC'), [
      'entity' => 'Email',
      'multiple' => TRUE,
    ]);

    $this->addEntityRef('bcc_id', ts('BCC'), [
      'entity' => 'Email',
      'multiple' => TRUE,
    ]);

    $setDefaults = TRUE;

    $this->_allContactIds = $this->_toContactIds = $this->_contactIds;

    //get the group of contacts as per selected by user in case of Find Activities
    if (!empty($this->_activityHolderIds)) {
      $contact = $this->get('contacts');
      $this->_allContactIds = $this->_toContactIds = $this->_contactIds = $contact;
    }

    // check if we need to setdefaults and check for valid contact emails / communication preferences
    if (!empty($this->_allContactIds) && $setDefaults) {
      // get the details for all selected contacts ( to, cc and bcc contacts )
      $allContactDetails = civicrm_api3('Contact', 'get', [
        'id' => ['IN' => $this->_allContactIds],
        'return' => ['sort_name', 'email', 'do_not_email', 'is_deceased', 'on_hold', 'display_name'],
        'options' => ['limit' => 0],
      ])['values'];

      // The contact task supports passing in email_id in a url. It supports a single email
      // and is marked as having been related to CiviHR.
      // The array will look like $this->_toEmail = ['email' => 'x', 'contact_id' => 2])
      // If it exists we want to use the specified email which might be different to the primary email
      // that we have.
      if (!empty($this->_toEmail['contact_id']) && !empty($allContactDetails[$this->_toEmail['contact_id']])) {
        $allContactDetails[$this->_toEmail['contact_id']]['email'] = $this->_toEmail['email'];
      }

      // perform all validations on unique contact Ids
      foreach ($allContactDetails as $contactId => $value) {
        if ($value['do_not_email'] || empty($value['email']) || !empty($value['is_deceased']) || $value['on_hold']) {
          $this->setSuppressedEmail($contactId, $value);
        }
        elseif (in_array($contactId, $this->_toContactIds)) {
          $this->_toContactDetails[$contactId] = $this->_contactDetails[$contactId] = $value;
          $toArray[] = [
            'text' => '"' . $value['sort_name'] . '" <' . $value['email'] . '>',
            'id' => "$contactId::{$value['email']}",
          ];
        }
      }

      if (empty($toArray)) {
        $message = ts('Selected contact(s) do not have a valid email address, or communication preferences specify DO NOT EMAIL, or they are deceased or Primary email address is On Hold.');
        CRM_Utils_System::setUFMessage($message);
        CRM_Core_Error::statusBounce($message);
      }
    }

    $this->assign('toContact', json_encode($toArray));

    $this->assign('suppressedEmails', count($this->suppressedEmails));

    $this->add('text', 'subject', ts('Subject'), ['size' => 50, 'maxlength' => 254], TRUE);

    $this->add('select', 'from_email_address', ts('From'), $this->getFromEmails(), TRUE, ['class' => 'crm-select2 huge']);

    CRM_Mailing_BAO_Mailing::commonCompose($this);

    // add attachments
    CRM_Core_BAO_File::buildAttachment($this, NULL);

    CRM_Core_Session::singleton()->replaceUserContext($this->getRedirectUrl());

    $this->addDefaultButtons(ts('Send Email'), 'upload', 'cancel');

    //add followup date
    $this->add('datepicker', 'followup_date', ts('in'));

    $this->addFormRule([__CLASS__, 'saveTemplateFormRule'], $this);
    $this->addFormRule([__CLASS__, 'deprecatedTokensFormRule'], $this);
    CRM_Core_Resources::singleton()->addScriptFile('civicrm', 'templates/CRM/Contact/Form/Task/EmailCommon.js', 0, 'html-header');

    // Replace the "To" text field with a select field containing all email addresses for the contact.
    $emails = Email::get(FALSE)
      ->addWhere('contact_id', '=', $this->get('cid'))
      ->addOrderBy('is_primary', 'DESC')
      ->execute()
      ->indexBy('id');
    $this->listOfEmails = [];
    foreach ($emails as $emailID => $emailDetail) {
      $this->listOfEmails[$emailID] = $emailDetail['email'];
    }

    // Previously added in EmailTrait::buildQuickForm as a text field.
    // We remove and re-add here as a select field containing email addresses for the contact
    $this->removeElement('to');
    $this->add('select', 'to', ts('To'), $this->listOfEmails, TRUE, ['class' => 'huge']);

    // Add a filtered select list to replace the standard select template field
    $messageTemplates = MessageTemplate::get(FALSE)
      ->addWhere('workflow_name', 'IS NULL')
      ->addWhere('is_sms', '=', FALSE)
      ->addWhere('is_active', '=', TRUE)
      ->addOrderBy('msg_title', 'ASC');
    $this->tplPrefix = CRM_Utils_Request::retrieveValue('tplprefix', 'String');
    if (!empty($this->tplPrefix)) {
      $messageTemplates->addWhere('msg_title', 'LIKE', $this->tplPrefix . ':%');
    }
    $templates = $messageTemplates->execute()->indexBy('id');
    $listOfTemplates = [];
    foreach ($templates as $templateID => $templateDetail) {
      $listOfTemplates[$templateID] = $templateDetail['msg_title'];
    }

    // Previously added via CRM_Mailing_BAO_Mailing::commonCompose()
    $this->removeElement('template');
    $this->add('select', 'template', ts('Use Template'),
      ['' => ts('- select -')] + $listOfTemplates, FALSE,
      ['onChange' => "selectValue( this.value, '');", 'class' => 'huge']
    );
  }

  /**
   * Set relevant default values.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  public function setDefaultValues(): array {
    $defaults = parent::setDefaultValues() ?: [];
    $fromEmails = $this->getFromEmails();
    if (is_numeric(key($fromEmails))) {
      $emailID = (int) key($fromEmails);
      $defaults = CRM_Core_BAO_Email::getEmailSignatureDefaults($emailID);
    }
    if (!Civi::settings()->get('allow_mail_from_logged_in_contact')) {
      $defaults['from_email_address'] = CRM_Core_BAO_Domain::getFromEmail();
    }
    return $defaults;
  }

  /**
   * Process the form after the input has been submitted and validated.
   *
   */
  public function postProcess() {
    $formValues = $this->controller->exportValues($this->getName());
    $this->submit($formValues);
  }

  /**
   * Submit the form values.
   *
   * This is also accessible for testing.
   *
   * @param array $formValues
   *
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \CRM_Core_Exception
   */
  public function submit($formValues): void {
    $this->saveMessageTemplate($formValues);
    $from = $formValues['from_email_address'];
    // dev/core#357 User Emails are keyed by their id so that the Signature is able to be added
    // If we have had a contact email used here the value returned from the line above will be the
    // numerical key where as $from for use in the sendEmail in Activity needs to be of format of "To Name" <toemailaddress>
    $from = CRM_Utils_Mail::formatFromAddress($from);

    $cc = $this->getCc();
    $additionalDetails = empty($cc) ? '' : "\ncc : " . $this->getEmailUrlString($this->getCcArray());

    $bcc = $this->getBcc();
    $additionalDetails .= empty($bcc) ? '' : "\nbcc : " . $this->getEmailUrlString($this->getBccArray());

    // send the mail
    [$sent, $activityIds] = $this->sendEmail(
      $this->getSubmittedValue('text_message'),
      $this->getSubmittedValue('html_message'),
      $from,
      $this->getAttachments($formValues),
      $cc,
      $bcc,
      $additionalDetails,
      $formValues['campaign_id'] ?? NULL,
      $this->getCaseID()
    );

    if ($sent) {
      CRM_Core_Session::setStatus(ts('One message was sent successfully. ', [
          'plural' => '%count messages were sent successfully. ',
          'count' => $sent,
        ]), ts('Message Sent', ['plural' => 'Messages Sent', 'count' => $sent]), 'success');
    }

    if (!empty($this->suppressedEmails)) {
      $status = '(' . ts('because no email address on file or communication preferences specify DO NOT EMAIL or Contact is deceased or Primary email address is On Hold') . ')<ul><li>' . implode('</li><li>', $this->suppressedEmails) . '</li></ul>';
      CRM_Core_Session::setStatus($status, ts('One Message Not Sent', [
        'count' => count($this->suppressedEmails),
        'plural' => '%count Messages Not Sent',
      ]), 'info');
    }
  }

  /**
   * Get any attachments.
   *
   * @param array $formValues
   *
   * @return array
   */
  protected function getAttachments(array $formValues): array {
    $attachments = [];
    CRM_Core_BAO_File::formatAttachment($formValues,
      $attachments,
      NULL, NULL
    );
    return $attachments;
  }

  /**
   * Send the message to all the contacts.
   *
   * Do not use this function outside of core tested code. It will change.
   *
   * It will also become protected once tests no longer call it.
   *
   * @internal
   *
   * Also insert a contact activity in each contacts record.
   *
   * @param $text
   * @param $html
   * @param string $from
   * @param array|null $attachments
   *   The array of attachments if any.
   * @param string|null $cc
   *   Cc recipient.
   * @param string|null $bcc
   *   Bcc recipient.
   * @param string|null $additionalDetails
   *   The additional information of CC and BCC appended to the activity Details.
   * @param int|null $campaignId
   * @param int|null $caseId
   *
   * @return array
   *   bool $sent FIXME: this only indicates the status of the last email sent.
   *   array $activityIds The activity ids created, one per "To" recipient.
   *
   * @throws \CRM_Core_Exception
   * @throws \PEAR_Exception
   * @internal
   *
   * Also insert a contact activity in each contacts record.
   *
   * @internal
   *
   * Also insert a contact activity in each contacts record.
   */
  protected function sendEmail(
    $text,
    $html,
    $from,
    $attachments = NULL,
    $cc = NULL,
    $bcc = NULL,
    $additionalDetails = NULL,
    $campaignId = NULL,
    $caseId = NULL
  ) {

    $userID = CRM_Core_Session::getLoggedInContactID();

    $sent = 0;
    $attachmentFileIds = [];
    $activityIds = [];
    $firstActivityCreated = FALSE;
    foreach ($this->getRowsForEmails() as $values) {
      $contactId = $values['contact_id'];
      $emailAddress = $values['email'];
      $renderedTemplate = CRM_Core_BAO_MessageTemplate::renderTemplate([
        'messageTemplate' => [
          'msg_text' => $text,
          'msg_html' => $html,
          'msg_subject' => $this->getSubject(),
        ],
        'tokenContext' => array_merge(['schema' => $this->getTokenSchema()], ($values['schema'] ?? [])),
        'contactId' => $contactId,
        'disableSmarty' => !CRM_Utils_Constant::value('CIVICRM_MAIL_SMARTY'),
      ]);

      // To minimize storage requirements, only one copy of any file attachments uploaded to CiviCRM is kept,
      // even when multiple contacts will receive separate emails from CiviCRM.
      if (!empty($attachmentFileIds)) {
        $attachments = array_replace_recursive($attachments, $attachmentFileIds);
      }

      // Create email activity.
      $activityID = $this->createEmailActivity($userID, $renderedTemplate['subject'], $renderedTemplate['html'], $renderedTemplate['text'], $additionalDetails, $campaignId, $attachments, $caseId);
      $activityIds[] = $activityID;

      if ($firstActivityCreated == FALSE && !empty($attachments)) {
        $attachmentFileIds = CRM_Activity_BAO_Activity::getAttachmentFileIds($activityID, $attachments);
        $firstActivityCreated = TRUE;
      }

      if ($this->sendMessage(
        $from,
        $contactId,
        $renderedTemplate['subject'],
        $renderedTemplate['text'],
        $renderedTemplate['html'],
        $emailAddress,
        $activityID,
        // get the set of attachments from where they are stored
        CRM_Core_BAO_File::getEntityFile('civicrm_activity', $activityID),
        $cc,
        $bcc
      )
      ) {
        $sent++;
      }
    }

    return [$sent, $activityIds];
  }

  /**
   * Send message - under refactor.
   *
   * @param $from
   * @param $toID
   * @param $subject
   * @param $text_message
   * @param $html_message
   * @param $emailAddress
   * @param $activityID
   * @param null $attachments
   * @param null $cc
   * @param null $bcc
   *
   * @return bool
   * @throws \CRM_Core_Exception
   * @throws \PEAR_Exception
   */
  protected function sendMessage(
    $from,
    $toID,
    $subject,
    $text_message,
    $html_message,
    $emailAddress,
    $activityID,
    $attachments = NULL,
    $cc = NULL,
    $bcc = NULL
  ) {
    [$toDisplayName, $toEmail, $toDoNotEmail] = CRM_Contact_BAO_Contact::getContactDetails($toID);
    if ($emailAddress) {
      $toEmail = trim($emailAddress);
    }

    // make sure both email addresses are valid
    // and that the recipient wants to receive email
    if (empty($toEmail) or $toDoNotEmail) {
      return FALSE;
    }
    if (!trim($toDisplayName)) {
      $toDisplayName = $toEmail;
    }

    $activityContacts = CRM_Activity_BAO_ActivityContact::buildOptions('record_type_id', 'validate');
    $targetID = CRM_Utils_Array::key('Activity Targets', $activityContacts);

    // create the params array
    $mailParams = [
      'groupName' => 'Activity Email Sender',
      'from' => $from,
      'toName' => $toDisplayName,
      'toEmail' => $toEmail,
      'subject' => $subject,
      'cc' => $cc,
      'bcc' => $bcc,
      'text' => $text_message,
      'html' => $html_message,
      'attachments' => $attachments,
    ];

    if (!CRM_Utils_Mail::send($mailParams)) {
      return FALSE;
    }

    // add activity target record for every mail that is send
    $activityTargetParams = [
      'activity_id' => $activityID,
      'contact_id' => $toID,
      'record_type_id' => $targetID,
    ];
    CRM_Activity_BAO_ActivityContact::create($activityTargetParams);
    return TRUE;
  }

  /**
   * @param int $sourceContactID
   *   The contact ID of the email "from".
   * @param string $subject
   * @param string $html
   * @param string $text
   * @param string $additionalDetails
   *   The additional information of CC and BCC appended to the activity details.
   * @param int $campaignID
   * @param array $attachments
   * @param int $caseID
   *
   * @return int
   *   The created activity ID
   * @throws \CRM_Core_Exception
   */
  protected function createEmailActivity($sourceContactID, $subject, $html, $text, $additionalDetails, $campaignID, $attachments, $caseID) {
    $activityTypeID = CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'Email');

    // CRM-6265: save both text and HTML parts in details (if present)
    if ($html and $text) {
      $details = "-ALTERNATIVE ITEM 0-\n{$html}{$additionalDetails}\n-ALTERNATIVE ITEM 1-\n{$text}{$additionalDetails}\n-ALTERNATIVE END-\n";
    }
    else {
      $details = $html ? $html : $text;
      $details .= $additionalDetails;
    }

    $activityParams = [
      'source_contact_id' => $sourceContactID,
      'activity_type_id' => $activityTypeID,
      'activity_date_time' => date('YmdHis'),
      'subject' => $subject,
      'details' => $details,
      'status_id' => CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'status_id', 'Completed'),
      'campaign_id' => $this->getSubmittedValue('campaign_id'),
    ];
    if (!empty($caseID)) {
      $activityParams['case_id'] = $caseID;
    }

    // CRM-5916: strip [case #…] before saving the activity (if present in subject)
    $activityParams['subject'] = preg_replace('/\[case #([0-9a-h]{7})\] /', '', $activityParams['subject']);

    // add the attachments to activity params here
    if ($attachments) {
      // first process them
      $activityParams = array_merge($activityParams, $attachments);
    }

    $activity = civicrm_api3('Activity', 'create', $activityParams);

    return $activity['id'];
  }

  /**
   * Get the subject for the message.
   *
   * @return string
   */
  protected function getSubject():string {
    return (string) $this->getSubmittedValue('subject');
  }

  /**
   * Get the result rows to email.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  protected function getRowsForEmails(): array {
    $rows = [];
    foreach ($this->getRows() as $row) {
      $rows[$row['contact_id']][] = $row;
    }
    // format contact details array to handle multiple emails from same contact
    $formattedContactDetails = [];
    foreach ($this->getEmails() as $details) {
      $contactID = $details['contact_id'];
      $index = $contactID . '::' . $details['email'];
      if (!isset($rows[$contactID])) {
        $formattedContactDetails[$index] = $details;
        continue;
      }
      if ($this->isGroupByContact()) {
        foreach ($rows[$contactID] as $rowDetail) {
          $details['schema'] = $rowDetail['schema'] ?? [];
        }
        $formattedContactDetails[$index] = $details;
      }
      else {
        foreach ($rows[$contactID] as $key => $rowDetail) {
          $index .= '_' . $key;
          $formattedContactDetails[$index] = $details;
          $formattedContactDetails[$index]['schema'] = $rowDetail['schema'] ?? [];
        }
      }

    }
    return $formattedContactDetails;
  }

  /**
   * Only send one email per contact.
   *
   * This has historically been done for contributions & makes sense if
   * no entity specific tokens are in use.
   *
   * @return bool
   */
  protected function isGroupByContact(): bool {
    return TRUE;
  }

  /**
   * Get the url string.
   *
   * This is called after the contacts have been retrieved so we don't need to re-retrieve.
   *
   * @param array $emailIDs
   *
   * @return string
   *   e.g. <a href='{$contactURL}'>Bob Smith</a>'
   */
  protected function getEmailUrlString(array $emails): string {
    $urls = [];
    foreach ($emails as $email) {
      $contactURL = CRM_Utils_System::url('civicrm/contact/view', ['reset' => 1, 'cid' => $this->contactEmails[$email]['contact_id']], TRUE);
      $urls[] = "<a href='{$contactURL}'>" . $this->contactEmails[$email]['contact_id.display_name'] . '</a>';
    }
    return implode(', ', $urls);
  }

  /**
   * Save the template if update selected.
   *
   * @param array $formValues
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function saveMessageTemplate($formValues) {
    if (!empty($formValues['saveTemplate']) || !empty($formValues['updateTemplate'])) {
      $messageTemplate = [
        'msg_text' => $formValues['text_message'],
        'msg_html' => $formValues['html_message'],
        'msg_subject' => $formValues['subject'],
        'is_active' => TRUE,
      ];

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
   * @return string
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function getBcc(): string {
    return $this->getEmailString($this->getBccArray());
  }

  /**
   * @return string
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function getCc(): string {
    return $this->getEmailString($this->getCcArray());
  }

  /**
   * Get the string for the email IDs.
   *
   * @param array $emailIDs
   *   Array of email IDs.
   *
   * @return string
   *   e.g. "Smith, Bob<bob.smith@example.com>".
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function getEmailString(array $emails): string {
    if (empty($emails)) {
      return '';
    }
    $emailEntities = Email::get(FALSE)
      ->addWhere('email', 'IN', $emails)
      ->setSelect(['contact_id', 'email', 'contact_id.sort_name', 'contact_id.display_name'])->execute();
    $emailStrings = [];
    foreach ($emailEntities as $email) {
      $this->contactEmails[$email['email']] = $email;
      $emailStrings[] = '"' . $email['contact_id.sort_name'] . '" <' . $email['email'] . '>';
    }
    return implode(',', $emailStrings);
  }

  /**
   * Get the emails from the added element.
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  protected function getEmails(): array {
    // To comes in as an ID. We need it as a string
    $email = Email::get(FALSE)
      ->addSelect('email')
      ->addWhere('contact_id', '=', $this->getContactIDs()[0])
      ->execute()
      ->first()['email'] ?? '';
    $allEmails = [$this->getContactIDs()[0] . "::{$email}"];

    $return = [];
    $contactIDs = [];
    foreach ($allEmails as $value) {
      $values = explode('::', $value);
      $return[$values[0]] = ['contact_id' => $values[0], 'email' => $values[1]];
      $contactIDs[] = $values[0];
    }
    $this->suppressedEmails = [];
    $suppressionDetails = Email::get(FALSE)
      ->addWhere('contact_id', 'IN', $contactIDs)
      ->addWhere('is_primary', '=', TRUE)
      ->addSelect('email', 'contact_id', 'contact_id.is_deceased', 'on_hold', 'contact_id.do_not_email', 'contact_id.display_name')
      ->execute();
    foreach ($suppressionDetails as $details) {
      if (empty($details['email']) || $details['contact_id.is_deceased'] || $details['contact_id.do_not_email'] || $details['on_hold']) {
        $this->setSuppressedEmail($details['contact_id'], [
          'on_hold' => $details['on_hold'],
          'is_deceased' => $details['contact_id.is_deceased'],
          'email' => $details['email'],
          'display_name' => $details['contact_id.display_name'],
        ]);
        unset($return[$details['contact_id']]);
      }
    }
    return $return;
  }

  /**
   * @return array
   */
  protected function getCcArray() {
    if ($this->getSubmittedValue('cc_id')) {
      $ccStrings = explode(',', $this->getSubmittedValue('cc_id'));
      foreach ($ccStrings as $cc) {
        list($_, $ccEmails[]) = explode('::', $cc);
      }
      return $ccEmails;
    }
    return [];
  }

  /**
   * @return array
   */
  protected function getBccArray() {
    if ($this->getSubmittedValue('bcc_id')) {
      $bccStrings = explode(',', $this->getSubmittedValue('bcc_id'));
      foreach ($bccStrings as $bcc) {
        list($_, $bccEmails[]) = explode('::', $bcc);
      }
      return $bccEmails;
    }
    return [];
  }

  /**
   * Get the rows from the results.
   *
   * @return array
   */
  protected function getRows(): array {
    $rows = [];
    foreach ($this->getContactIDs() as $contactID) {
      $rows[] = ['contact_id' => $contactID, 'schema' => ['contactId' => $contactID]];
    }
    return $rows;
  }

  /**
   * Get the relevant contact IDs.
   *
   * @return array
   */
  protected function getContactIDs(): array {
    return $this->_contactIds ?? [];
  }

  /**
   * Get the token processor schema required to list any tokens for this task.
   *
   * @return array
   */
  protected function getTokenSchema(): array {
    return ['contactId'];
  }

  /**
   * Set the emails that are not to be sent out.
   *
   * @param int $contactID
   * @param array $values
   */
  protected function setSuppressedEmail($contactID, $values) {
    $contactViewUrl = CRM_Utils_System::url('civicrm/contact/view', 'reset=1&cid=' . $contactID);
    $this->suppressedEmails[$contactID] = "<a href='$contactViewUrl' title='{$values['email']}'>{$values['display_name']}</a>" . ($values['on_hold'] ? '(' . ts('on hold') . ')' : '');
  }

  /**
   * @return array
   */
  protected function getFromEmails(): array {
    $fromEmailValues = CRM_Core_BAO_Email::getFromEmail();

    if (empty($fromEmailValues)) {
      CRM_Core_Error::statusBounce(ts('Your user record does not have a valid email address and no from addresses have been configured.'));
    }
    return $fromEmailValues;
  }

  /**
   * Get the url to redirect the user's browser to.
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getRedirectUrl(): string {
    $destination = $this->get('destination');
    return CRM_Utils_System::url($destination);
  }

  /**
   * Get case ID - if any.
   *
   * @return int|null
   *
   * @throws \CRM_Core_Exception
   */
  protected function getCaseID(): ?int {
    $caseID = CRM_Utils_Request::retrieve('caseid', 'String', $this);
    if ($caseID) {
      return (int) $caseID;
    }
    return NULL;
  }

  /**
   * Form rule.
   *
   * @param array $fields
   *   The input form values.
   *
   * @return bool|array
   *   true if no errors, else array of errors
   */
  public static function saveTemplateFormRule(array $fields) {
    $errors = [];
    //Added for CRM-1393
    if (!empty($fields['saveTemplate']) && empty($fields['saveTemplateName'])) {
      $errors['saveTemplateName'] = ts('Enter name to save message template');
    }
    return empty($errors) ? TRUE : $errors;
  }

  /**
   * Prevent submission of deprecated tokens.
   *
   * Note this rule can be removed after a transition period.
   * It's mostly to help to ensure users don't get missing tokens
   * or unexpected output after the 5.43 upgrade until any
   * old templates have aged out.
   *
   * @param array $fields
   *
   * @return bool|string[]
   */
  public static function deprecatedTokensFormRule(array $fields) {
    $deprecatedTokens = [
      '{case.status_id}' => '{case.status_id:label}',
      '{case.case_type_id}' => '{case.case_type_id:label}',
      '{contribution.campaign}' => '{contribution.campaign_id:label}',
      '{contribution.payment_instrument}' => '{contribution.payment_instrument_id:label}',
      '{contribution.contribution_id}' => '{contribution.id}',
      '{contribution.contribution_source}' => '{contribution.source}',
      '{contribution.contribution_status}' => '{contribution.contribution_status_id:label}',
      '{contribution.contribution_cancel_date}' => '{contribution.cancel_date}',
      '{contribution.type}' => '{contribution.financial_type_id:label}',
      '{contribution.contribution_page_id}' => '{contribution.contribution_page_id:label}',
    ];
    $tokenErrors = [];
    foreach ($deprecatedTokens as $token => $replacement) {
      if (strpos($fields['html_message'], $token) !== FALSE) {
        $tokenErrors[] = ts('Token %1 is no longer supported - use %2 instead', [$token, $replacement]);
      }
    }
    return empty($tokenErrors) ? TRUE : ['html_message' => implode('<br>', $tokenErrors)];
  }

}
