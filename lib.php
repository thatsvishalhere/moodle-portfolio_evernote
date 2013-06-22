<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Evernote Portfolio Plugin
 * @package   portfolio_evernote
 * @copyright Vishal Raheja  (email:thatsvishalhere@gmail.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define("EVERNOTE_LIBS", dirname(__FILE__) . DIRECTORY_SEPARATOR . "lib");
ini_set("include_path", ini_get("include_path") . PATH_SEPARATOR . EVERNOTE_LIBS);
require_once($CFG->libdir.'/portfolio/plugin.php');

require_once "Thrift.php";
require_once 'packages/Limits/Limits_types.php';
require_once 'packages/Errors/Errors_types.php';
require_once 'packages/Types/Types_types.php';
require_once "transport/TTransport.php";
require_once "transport/THttpClient.php";
require_once "transport/TBufferedTransport.php";
require_once "protocol/TProtocol.php";
require_once "protocol/TBinaryProtocol.php";
require_once "packages/UserStore/UserStore_types.php";
require_once "packages/UserStore/UserStore_constants.php";
require_once "packages/UserStore/UserStore.php";
require_once "packages/NoteStore/NoteStore_types.php";
require_once "packages/NoteStore/NoteStore.php";
require_once 'Evernote/Client.php';



// Import the classes that we're going to be using
use EDAM\Error\EDAMSystemException,
    EDAM\Error\EDAMUserException,
    EDAM\Error\EDAMErrorCode,
    EDAM\Error\EDAMNotFoundException;

// To use the main Evernote API interface.
use Evernote\Client;

//import Note class
use EDAM\Types\Note;
use EDAM\Types\NoteAttributes;
use EDAM\NoteStore\NoteStoreClient;

class portfolio_plugin_evernote extends portfolio_plugin_push_base {
	
	//token received after checking the user credentials and the permissions by the user.
	private $oauthtoken = null;
	
	//to create a new client which would contact the evernote
	private $client = null;
	
	//token after the checking the config credentials
	private $requestToken = null;
	
	//since this plugin needs authentication from external sources, we have to disable this.
	public static function allows_multiple_exports() {
		return false;
	}
	
	public static function get_name() {
        return get_string('pluginname', 'portfolio_evernote');
    }
	
	//add the xml part over here and remove the unwanted elements like head, body, script, etc....
	public function prepare_package() {
        // We send the files as they are, no prep required.
        return true;
    }
	
	//send the package to the evernote here
	public function send_package() {
        // We send the files as they are, no prep required.
        return true;
    }
	
	//unsure about it
	public function get_interactive_continue_url() {
        return false;
    }
	
	public function expected_time($callertime) {
        // We trust what the portfolio says.
        return $callertime;
    }
	
	//tell that the admin has to set some configurations
	public static function has_admin_config() {
        return true;
    }
	
	//form to configure the consumer key and the consumer secret
	public static function admin_config_form(&$mform) {
        $mform->addElement('static', null, '', get_string('oauthinfo', 'portfolio_evernote'));

        $mform->addElement('text', 'consumerkey', get_string('consumerkey', 'portfolio_evernote'));
        $mform->setType('consumerkey', PARAM_RAW_TRIMMED);
        $mform->addElement('text', 'secret', get_string('secret', 'portfolio_evernote'));
        $mform->setType('secret', PARAM_RAW_TRIMMED);

        $strrequired = get_string('required');
        $mform->addRule('consumerkey', $strrequired, 'required', null, 'client');
        $mform->addRule('secret', $strrequired, 'required', null, 'client');
    }
	
	//return the consumerkey and the secret
	public static function get_allowed_config() {
        return array('consumerkey', 'secret');
    }
	
	//multiple instances not allowed
	public static function allows_multiple_instances() {
        return false;
    }
	
	/* This is used to get the file formats supported to export. 
	Unsure whether to use this function	
	public function supported_formats() {
        return array(PORTFOLIO_FORMAT_FILE, PORTFOLIO_FORMAT_RICHHTML, PORTFOLIO_FORMAT_IMAGE, PORTFOLIO_FORMAT_PDF, PORTFOLIO_FORMAT_PLAINHTML, PORTFOLIO_FORMAT_PRESENTATION, PORTFOLIO_FORMAT_RICH, PORTFOLIO_FORMAT_TEXT, PORTFOLIO_FORMAT_VIDEO);
    }
	*/
	
	/* 
	Use this function to initialize the oauth, get the temporary credentials and to get the token/permission from the user
	I guess we would be using stage 1, 2 and 3 over here and carry out the entire authentication here.
	Description:
	During any part of the export process, a plugin can completely steal control away from portfolio/add.php. 
	This is useful, for example, for plugins that need the user go to log into a remote system and grant an application access. 
	It could be also used for a completely custom screen provided by the plugin. 
	If you need this, override this function to return a url, and the user will be redirected there. 
	When you're finished, return to $CFG->wwwroot/portfolio/add.php?postcontrol=1 and processing will continue. 
	If you override this, it might be useful to also override post_control.
	*/
	public function steal_control($stage) {
        global $CFG;
        if ($stage != PORTFOLIO_STAGE_CONFIG) {
            return false;
        }
		
		//first stage of oauth from the user configuration
		$this->getTokenFromConfig();
		
		//second stage oauth authentication
		if($this->client)
		{
			$redirecturl = $this->getRedirectUrl();
			redirect($redirecturl);
		}
		/*
        $this->initialize_oauth();
        if ($this->googleoauth->is_logged_in()) {
            return false;
        } else {
            return $this->googleoauth->get_login_url();
        }*/
    }
	
	/*
	Use this function to process the token that we received. 
	That is store the received authenticated token and store it for further use when exporting content.
	Description:
	After control is returned after steal_control, post_control will be called before the next stage is processed, and passed any request parameters. 
	For an example of how this is used, see the box.net plugin, which uses steal_control to redirect to box.net to get the user to authenticate and 
	then box.net redirects back to a url passing an authentication token to use for the rest of the session. 
	Since it's part of the request parameters, it's passed through to post_control, which stores whatever it needs before the next stage.
	*/
	public function post_control($stage, $params) {
        if ($stage != PORTFOLIO_STAGE_CONFIG) {
            return;
        }
		/*
        $this->initialize_oauth();
        if ($this->googleoauth->is_logged_in()) {
            return false;
        } else {
            return $this->googleoauth->get_login_url();
        }
		*/
    }
	
	/*
	Checking out if consumer key or consumer secret are left empty
	*/
	public function instance_sanity_check() {
        $consumerkey = $this->get_config('consumerkey');
        $secret = $this->get_config('secret');

        // If there is no oauth config (e.g. plugins upgraded from < 2.3 then
        // there will be no config and this plugin should be disabled.
        if (empty($consumerkey) or empty($secret)) {
            return 'nooauthcredentials';
        }
        return 0;
    }
	
	/*
		To get the token by verifying the Evernote API Consumer key and Secret
	*/
	function getTokenFromConfig() {
		$consumerkey = $this->get_config('consumerkey');
        $secret = $this->get_config('secret');
		$sandboxflag = true; //change the flag to false for production
		$this->client = new Client(array(
                'consumerKey' => $consumerkey,
                'consumerSecret' => $secret,
                'sandbox' => $sandboxflag
            ));
		if($this->client)
		{
			$tempOauthToken = $this->client->getRequestToken($this->getCallbackUrl());
			if($tempOauthToken) {
				$this->requestToken = new StdClass;
				$this->requestToken->oauth = $tempOauthToken['oauth_token'];
				$this->requestToken->secret = $tempOauthToken['oauth_token_secret'];
			}
			else {
				//throw failed operation exception.
				throw new portfolio_plugin_exception('failedtoken', 'portfolio_evernote');
			}
		}
		else {
			//throw invalid credentials exception.
				throw new portfolio_plugin_exception('improperkey', 'portfolio_evernote');
		}
		
	}
	
	/*
     * Unsure:Get the URL of this application. This URL is passed to the server (Evernote)
     * while obtaining unauthorized temporary credentials (step 1). The resource owner
     * is redirected to this URL after authorizing the temporary credentials (step 2).
     */
    private function getCallbackUrl()
    {
		global $CFG;
        return $CFG->wwwroot . '/portfolio/add.php?postcontrol=1&type=evernote';
    }
	
	/*
     * Get the Evernote URL from where to get the permissions from the user.
     */
    function getRedirectUrl()
    {
		$consumerkey = $this->get_config('consumerkey');
        $secret = $this->get_config('secret');
		$sandboxflag = true; //change the flag to false for production
		$new_client = new Client(array(
                'consumerKey' => $consumerkey,
                'consumerSecret' => $secret,
                'sandbox' => $sandboxflag
            ));

        return $new_client->getAuthorizeUrl($this->requestToken->oauth);
    }
 }