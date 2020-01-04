{*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
*}
<div class="crm-block crm-form-block crm-contactEmail-form-block">
<div class="crm-submit-buttons">{include file="CRM/common/formButtons.tpl" location="top"}</div>
{if $suppressedEmails > 0}
    <div class="status">
        <p>{ts count=$suppressedEmails plural='Email will NOT be sent to %count contacts - (no email address on file, or communication preferences specify DO NOT EMAIL, or contact is deceased).'}Email will NOT be sent to %count contact - (no email address on file, or communication preferences specify DO NOT EMAIL, or contact is deceased).{/ts}</p>
    </div>
{/if}
{crmSetting var="logged_in_email_setting" name="allow_mail_from_logged_in_contact"}
<table class="form-layout-compressed">
  <tr id="selectEmailFrom" class="crm-contactEmail-form-block-fromEmailAddress crm-email-element">
    <td class="label">{$form.from_email_address.label}</td>
    <td>{$form.from_email_address.html} {help id="id-from_email" file="CRM/Contact/Form/Task/Email.hlp" isAdmin=$isAdmin logged_in_email_setting=$logged_in_email_setting}</td>
  </tr>
    <tr class="crm-contactEmail-form-block-recipient">
       <td class="label">{$form.to.label}</td>
       <td>
         {$form.to.html}
       </td>
    </tr>
    <tr class="crm-contactEmail-form-block-cc_id" {if !$form.cc_id.value}style="display:none;"{/if}>
      <td class="label">{$form.cc_id.label}</td>
      <td>
        {$form.cc_id.html}
        <a class="crm-hover-button clear-cc-link" rel="cc_id" title="{ts}Clear{/ts}" href="#"><i class="crm-i fa-times"></i></a>
      </td>
    </tr>
    <tr class="crm-contactEmail-form-block-bcc_id" {if !$form.bcc_id.value}style="display:none;"{/if}>
      <td class="label">{$form.bcc_id.label}</td>
      <td>
        {$form.bcc_id.html}
        <a class="crm-hover-button clear-cc-link" rel="bcc_id" title="{ts}Clear{/ts}" href="#"><i class="crm-i fa-times"></i></a>
      </td>
    </tr>
    <tr>
      <td></td>
      <td>
        <div>
          <a href="#" rel="cc_id" class="add-cc-link crm-hover-button" {if $form.cc_id.value}style="display:none;"{/if}>{ts}Add CC{/ts}</a>&nbsp;&nbsp;
          <a href="#" rel="bcc_id" class="add-cc-link crm-hover-button" {if $form.bcc_id.value}style="display:none;"{/if}>{ts}Add BCC{/ts}</a>
        </div>
      </td>
    </tr>

{if $emailTask}
    <tr class="crm-contactEmail-form-block-template">
        <td class="label">{$form.template.label}</td>
        <td>{$form.template.html}</td>
    </tr>
{/if}
    <tr class="crm-contactEmail-form-block-subject">
       <td class="label">{$form.subject.label}</td>
       <td>
         {$form.subject.html|crmAddClass:huge}&nbsp;
         <input class="crm-token-selector big" data-field="subject" />
         {help id="id-token-subject" tplFile=$tplFile isAdmin=$isAdmin file="CRM/Contact/Form/Task/Email.hlp"}
       </td>
    </tr>
  {* CRM-15984 --add campaign to email activities *}
  {include file="CRM/Campaign/Form/addCampaignToComponent.tpl" campaignTrClass="crm-contactEmail-form-block-campaign_id"}
</table>

  <div class="crm-accordion-wrapper crm-html_email-accordion ">
    <div class="crm-accordion-header">
      {ts}HTML Format{/ts}
      {help id="id-message-text" file="CRM/Contact/Form/Task/Email.hlp"}
    </div><!-- /.crm-accordion-header -->
    <div class="crm-accordion-body">
      <div class="helpIcon" id="helphtml">
        <input class="crm-token-selector big" data-field="html_message" />
        {help id="id-token-html" tplFile=$tplFile isAdmin=$isAdmin file="CRM/Contact/Form/Task/Email.hlp"}
      </div>
      <div class="clear"></div>
      <div class='html'>
        {if $editor EQ 'textarea'}
          <div class="help description">{ts}NOTE: If you are composing HTML-formatted messages, you may want to enable a Rich Text (WYSIWYG) editor (Administer &raquo; Customize Data & Screens &raquo; Display Preferences).{/ts}</div>
        {/if}
        {$form.html_message.html}<br />
      </div>
    </div><!-- /.crm-accordion-body -->
  </div><!-- /.crm-accordion-wrapper -->

  <div id="editMessageDetails" class="section crm-accordion-wrapper crm-save-details-accordion collapsed">
    <div class="crm-accordion-header">
      {ts}Update / Save Template{/ts}
    </div><!-- /.crm-accordion-header -->
    <div class="crm-accordion-body">
      <div class="description">{ts}If you have made changes that you would like to use again, you can update the selected template or save it as a new one.{/ts}</div>
      <div id="updateDetails" class="section">
        {$form.updateTemplate.html}&nbsp;{$form.updateTemplate.label}
      </div>
      <div class="section">
        {$form.saveTemplate.html}&nbsp;{$form.saveTemplate.label}
      </div>
      <div id="saveDetails" class="section" style="display: none;">
        <div class="content">{$form.saveTemplateName.label} {$form.saveTemplateName.html|crmAddClass:huge}</div>
      </div>
    </div>
  </div>

  {if ! $noAttach}
    {include file="CRM/Form/attachment.tpl"}
  {/if}

  {include file="CRM/Mailing/Form/InsertTokens.tpl"}

<div class="spacer"> </div>

{if $suppressedEmails > 0}
   {ts count=$suppressedEmails plural='Email will NOT be sent to %count contacts.'}Email will NOT be sent to %count contact.{/ts}
{/if}
<div class="crm-submit-buttons">{include file="CRM/common/formButtons.tpl" location="bottom"}</div>
</div>
<script type="text/javascript">

{literal}
CRM.$(function($) {
  var $form = $("form.{/literal}{$form.formClass}{literal}");

  $('#updateTemplate', $form).change(function(e) {
    if ($('#updateTemplate').prop('checked')) {
      $('#saveTemplate').prop('checked', false);
      $('#saveDetails').hide();
    }
  });

  $('#saveTemplate', $form).change(function(e) {
    if ($('#saveTemplate').prop('checked')) {
      $('#updateTemplate').prop('checked', false);
    }
  });

  $('.add-cc-link', $form).click(function(e) {
    e.preventDefault();
    var type = $(this).attr('rel');
    $(this).hide();
    $('.crm-contactEmail-form-block-'+type, $form).show();
  });

  $('.clear-cc-link', $form).click(function(e) {
    e.preventDefault();
    var type = $(this).attr('rel');
    $('.add-cc-link[rel='+type+']', $form).show();
    $('.crm-contactEmail-form-block-'+type, $form).hide().find('input.crm-ajax-select').select2('data', []);
  });

  var sourceDataUrl = "{/literal}{crmURL p='civicrm/ajax/checkemail' q='id=1' h=0 }{literal}";

  function emailSelect(el, prepopulate) {
    $(el, $form).data('api-entity', 'contact').css({width: '40em', 'max-width': '90%'}).crmSelect2({
      minimumInputLength: 1,
      multiple: true,
      ajax: {
        url: sourceDataUrl,
        data: function(term) {
          return {
            name: term
          };
        },
        results: function(response) {
          return {
            results: response
          };
        }
      }
    }).select2('data', prepopulate);
  }

  {/literal}
  var toContact = {if $toContact}{$toContact}{else}''{/if},
    ccContact = {if $ccContact}{$ccContact}{else}''{/if},
    bccContact = {if $bccContact}{$bccContact}{else}''{/if};
  {literal}
  //emailSelect('#to', toContact);
  emailSelect('#cc_id', ccContact);
  emailSelect('#bcc_id', bccContact);
});


</script>
{/literal}
