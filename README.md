# SuiteCRM integration into Nextcloud

⊞ Interact with your CRM solution in Nextcloud!

SuiteCRM integration provides a dashboard widget displaying your important notifications,
a search provider for contacts/accounts/leads/opportunities/cases
and notifications for activity in SuiteCRM.

## 🔧 Configuration

### Admin settings

* On the SuiteCRM side
	* You need to create openSSL private and public keys like explained in
[SuiteCRM documentation](https://docs.suitecrm.com/developer/api/developer-setup-guide/json-api/#_generate_private_and_public_key_for_oauth2)
	  otherwise authentication won't work.
	* Then go to the "OAuth2 Clients and Tokens" admin settings section and create
	  a "new password client". Don't forget to manually choose the client secret.
	  Once your password client is created, get its client ID.

* On the Nextcloud side
  there is a "Connected accounts" **admin** settings section where you can set your SuiteCRM
  instance URL and the client ID/secret that you just created in SuiteCRM.

### User settings

The account configuration happens in the "Connected accounts" user settings section.
Just enter your SuiteCRM login and password there.
