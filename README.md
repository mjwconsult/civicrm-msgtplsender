# msgtplsender

Adds a form to send an email using a message template.

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Installation

See: https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/#installing-a-new-extension

## Usage

https://example.org/civicrm/msgtplsender/email?cid=103&tplprefix=test&destination=node/1

Where:
* `cid`: The contact ID of the contact you are sending an email to (the "To" addresses will be pre-populated).
* `tplprefix`: Filter the message templates that are shown (msg_title LIKE XX: ...). Also messagetemplates will be saved with this prefix if you save/update via the "Send Enail" form.
* `destination`: A path internal to the site to redirect to after submit.
