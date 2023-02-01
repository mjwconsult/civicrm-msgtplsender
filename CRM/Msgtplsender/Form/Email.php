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
class CRM_Msgtplsender_Form_Email extends CRM_Contact_Form_Task {

  use CRM_Contact_Form_Task_EmailTrait;

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

  public function getTableName() {
    return 'civicrm_contact';
  }

  /**
   * Build all the data structures needed to build the form.
   */
  public function preProcess() {
    $this->_single = TRUE;

    // Set redirect context
    $destination = CRM_Utils_Request::retrieveValue('destination', 'String');
    if (!empty($destination)) {
      CRM_Core_Session::singleton()->replaceUserContext(CRM_Utils_System::url($destination, NULL, TRUE));
    }

    // store case id if present
    $this->_caseId = CRM_Utils_Request::retrieveValue('caseid', 'String');
    $cid = CRM_Utils_Request::retrieveValue('cid', 'String');

    if ($cid) {
      $this->set('cid', $cid);

      $cid = explode(',', $cid);
      $displayName = [];

      foreach ($cid as $val) {
        $displayName[] = CRM_Contact_BAO_Contact::displayName($val);
      }
    }

    $this->setTitle(implode(',', $displayName) . ' - ' . ts('Email'));
    CRM_Contact_Form_Task_EmailCommon::preProcessFromAddress($this);

    $this->setIsSearchContext(FALSE);
    $this->traitPreProcess();
  }

  /**
   * Build the form object.
   * Copied from EmailTrait: Only change is statusBounce -> setUFMessage
   *
   * @throws \CRM_Core_Exception
   */
  public function buildQuickFormFromEmailTrait559() {
    // Suppress form might not be required but perhaps there was a risk some other  process had set it to TRUE.
    $this->assign('suppressForm', FALSE);
    $this->assign('emailTask', TRUE);

    $toArray = [];
    $suppressedEmails = 0;
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
    if (count($this->_contactIds) > 1) {
      $this->_single = FALSE;
    }
    $this->bounceIfSimpleMailLimitExceeded(count($this->_contactIds));

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
    if (property_exists($this, '_context') && $this->_context === 'standalone') {
      $setDefaults = FALSE;
    }

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

    $this->assign('totalSelectedContacts', count($this->_contactIds));

    $this->add('text', 'subject', ts('Subject'), ['size' => 50, 'maxlength' => 254], TRUE);

    $this->add('select', 'from_email_address', ts('From'), $this->getFromEmails(), TRUE, ['class' => 'crm-select2 huge']);

    CRM_Mailing_BAO_Mailing::commonCompose($this);

    // add attachments
    CRM_Core_BAO_File::buildAttachment($this, NULL);

    if ($this->_single) {
      CRM_Core_Session::singleton()->replaceUserContext($this->getRedirectUrl());
    }
    $this->addDefaultButtons(ts('Send Email'), 'upload', 'cancel');

    $fields = [
      'followup_assignee_contact_id' => [
        'type' => 'entityRef',
        'label' => ts('Assigned to'),
        'attributes' => [
          'multiple' => TRUE,
          'create' => TRUE,
          'api' => ['params' => ['is_deceased' => 0]],
        ],
      ],
      'followup_activity_type_id' => [
        'type' => 'select',
        'label' => ts('Followup Activity'),
        'attributes' => ['' => '- ' . ts('select activity') . ' -'] + CRM_Core_PseudoConstant::ActivityType(FALSE),
        'extra' => ['class' => 'crm-select2'],
      ],
      'followup_activity_subject' => [
        'type' => 'text',
        'label' => ts('Subject'),
        'attributes' => CRM_Core_DAO::getAttribute('CRM_Activity_DAO_Activity',
          'subject'
        ),
      ],
    ];

    //add followup date
    $this->add('datepicker', 'followup_date', ts('in'));

    foreach ($fields as $field => $values) {
      if (!empty($fields[$field])) {
        $attribute = $values['attributes'] ?? NULL;
        $required = !empty($values['required']);

        if ($values['type'] === 'select' && empty($attribute)) {
          $this->addSelect($field, ['entity' => 'activity'], $required);
        }
        elseif ($values['type'] === 'entityRef') {
          $this->addEntityRef($field, $values['label'], $attribute, $required);
        }
        else {
          $this->add($values['type'], $field, $values['label'], $attribute, $required, CRM_Utils_Array::value('extra', $values));
        }
      }
    }

    //Added for CRM-15984: Add campaign field
    CRM_Campaign_BAO_Campaign::addCampaign($this);

    $this->addFormRule([__CLASS__, 'saveTemplateFormRule'], $this);
    $this->addFormRule([__CLASS__, 'deprecatedTokensFormRule'], $this);
    CRM_Core_Resources::singleton()->addScriptFile('civicrm', 'templates/CRM/Contact/Form/Task/EmailCommon.js', 0, 'html-header');
  }

  /**
   * Build the form object.
   */
  public function buildQuickForm() {
    $this->buildQuickFormFromEmailTrait559();

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
      $messageTemplates->addWhere('msg_title', 'LIKE', 'Email:%');
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
   * Process the form after the input has been submitted and validated.
   *
   */
  public function postProcess() {
    $formValues = $this->controller->exportValues($this->getName());
    $email = Email::get(FALSE)
      ->addSelect('email')
      ->addWhere('id', '=', $formValues['to'])
      ->execute()
      ->first()['email'] ?? '';
    $formValues['to'] = $this->get('cid') . "::{$email}";
    $this->_submitValues['to'] = $formValues['to'];
    $this->submit($formValues);
  }

}
