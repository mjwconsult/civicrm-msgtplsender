# msgtplsender

Adds a form to send an email using a message template.

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v7.1+
* CiviCRM 5.19+

## Installation

See: https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/#installing-a-new-extension

## Usage

https://example.org/civicrm/msgtplsender/email?cid=103&filtertpl=test:%&destination=node/1

Where:
* cid: The contact ID of the contacty you are sending an email to (the "To" addresses will be pre-populated).
* filtertpl: Filter the message templates that are shown (msg_title LIKE XX).
* destination: A path internal to the site to redirect to after submit.
