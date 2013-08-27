Evernote API Library for PHP
==================================

The Evernote API, a Moodle extension to the Evernote API and the readme files are kept in this directory.

A Moodle extension (Moodle_THttpClient.php) to the Evernote API had to be introduced because of the
bug in the Evernote API which did not support Proxy connections. The Moodle extension takes care of
this issue and the plugin works even when the server is behind a proxy. This file basically contains
a class which is an extension of the class THttpClient of the Evernote API and adds the proxy host,
proxy host and the authentication details of the proxy to the connection details.

So, while updating the Evernote API, check out if Evernote has introduced the proxy settings for the
API in the file THttpClient.php and make the required changes in the file Moodle_THttpClient.php.

Information
-----------

URL: https://github.com/evernote/evernote-sdk-php/tree/master/lib
Download from: https://github.com/evernote/evernote-sdk-php/releases
Documentation: http://dev.evernote.com/support/glossary.php

Downloaded version: 1.25.0