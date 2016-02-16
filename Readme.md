
# SkypeToGmail
Import your Skype conversations to your Gmail account to make them searchable.

## Introduction
A lot of my business communication goes via Skype. In contrast to email Skype does not provide full text search ability
across conversations.

The best full text search IMO is provided by Gmail. This tool imports your Skype messages from your local SQLite database
into your personal Gmail account, using the [Gmail API](https://developers.google.com/gmail/api/). They can be stored under
a label of your choice.

![Example of imported message](/example-message.png)


## Requirements
* PHP >5.4
* tested with Ubuntu and OS X


## Setup

### Config file

Copy `config/config.php.dist` to `config/config.php` and modify the following values:

 *  Replace `YOUR_SKYPE_USERNAME` with your skype username (doh)
 *  Replace `SKYPE_DB` with the path to your sqlite database. On OS X this is something like `sqlite:/Users/OSX_USERNAME/Library/Application Support/Skype/SKYPE_USERNAME/main.db`

### Install dependencies
Use `composer install` to install the dependencies.

### Gmail API
Follow the "Step 1" of the instructions [here](https://developers.google.com/gmail/api/quickstart/php) to get API credentials for your
Gmail account. As application name use "SkypeToGmail" instead of "Gmail API Quickstart". And store the `client_secret.json`
file to your `config/` folder.

 1. Use [this wizard](https://console.developers.google.com/start/api?id=gmail) to create or select a project in the Google
   Developers Console and automatically turn on the API. Click Continue, then select "OAuth" application.
 2. Select an Email address, enter a Product name if not already set, and click the Save button.
 3. In the "Create Credentials" tab, click the Add credentials button and select OAuth 2.0 client ID.
 4. In the "Configure consent screen" enter the name "Skype2Gmail", and click the Save button.
 5. In the "Create Client ID" screen choose "Other" and enter "Skype2Gmail" again.
 6. Click OK to dismiss the resulting dialog.
 7. Click the file_download (Download JSON) button to the right of the client ID. Move this file to the directory `config` and rename it client_secret.json.

### Initialize
Run the following command to get an API token and initialize the Status-Database

    php run.php init


## Commands

### import
Imports all Skype messages that have not been imported yet

    php run.php import [-p|--progress] [-v]

### labels
Shows all labels of your Gmail account with their internal name to use for the `config.php` file

    php run.php labels

## Map Skype names to email addresses

The script creates dummy mail addresses in the form `skypename@unknown.com`. I you would like to use real mail addresses add an entry in the table `skypenameToEmail`:

| skypename | email                                | name             |
|-----------|--------------------------------------|------------------|
| silvanm75 | silvan@muehlemann.com                | Silvan Mühlemann |


