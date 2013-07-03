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
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir.'/portfolio/plugin.php');
require_once($CFG->libdir . '/oauthlib.php');

define("EVERNOTE_LIBS", dirname(__FILE__) . DIRECTORY_SEPARATOR . "lib");
ini_set("include_path", ini_get("include_path") . PATH_SEPARATOR . EVERNOTE_LIBS);
if (!isset($GLOBALS['THRIFT_ROOT'])) {
  $GLOBALS['THRIFT_ROOT'] = dirname(__FILE__).'/lib';
}
require_once 'Evernote/Client.php';
if(!class_exists('EDAM\Types\PrivilegeLevel'))
	require_once 'packages/Types/Types_types.php';


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
	
	//the evernote markup language content that is to be shown in the notebook
	private $enmlContent;
	
	//the verifier after the user allows the application to access his evernote account
	private $oauthVerifier;
	
	private $notebook_array;
	private $default_notebook_guid;
	
	//since this plugin needs authentication from external sources, we have to disable this.
	public static function allows_multiple_exports() {
		return false;
	}
	
	public static function get_name() {
        return get_string('pluginname', 'portfolio_evernote');
    }
	
	//to ensure that the user configures before exporting the package
	public function has_export_config() {
		return true;
	}
	
	//user settings for the note before exporting
	public function export_config_form(&$mform) {
		$mform->addElement('text', 'plugin_notetitle', get_string('customnotetitlelabel', 'portfolio_evernote'));
		$mform->setDefault('plugin_notetitle', get_string('defaultnotetitle', 'portfolio_evernote'));
		$this->listNotebooks();
		$notebookselect = $mform->addElement('select', 'plugin_notebooks', 'Select Notebook', $this->notebook_array);
		$notebookselect->setSelected($this->default_notebook_guid);
		
		$strrequired = get_string('required');
        $mform->addRule('plugin_notetitle', $strrequired, 'required', null, 'client');
        $mform->addRule('plugin_notebooks', $strrequired, 'required', null, 'client');
	}
	public function get_export_summary() {
		return array('Note Title'=>$this->get_export_config('notetitle'), 'Notebook'=>$this->notebook_array[$this->get_export_config('notebooks')]);
	}
	public function get_allowed_export_config() {
		return array('notetitle', 'notebooks');
	}
	
	//add the xml part over here and remove the unwanted elements like head, body, script, etc....
	public function prepare_package() {
		$files = $this->exporter->get_tempfiles();
		$writefile = "write.txt";
		$current = file_get_contents($writefile);
		foreach($files as $file)
		{	
			//echo $file->get_filename()."<br />";
			if($file->get_filename() == "post.html" || $file->get_filename() == "discussion.html")
			{
				$htmlcontents = $file->get_content();
				/*$myFile = "write.html";
				$fh = fopen($myFile, 'w');*/
				$this->enmlContent = getenml($htmlcontents);
				/*fwrite($fh, $this->enmlContent);
				//fwrite($fh, $htmlcontents);
				fclose($fh);*/
			}
		}
    }
	
	//send the package to the evernote here
	public function send_package() {
        //$this->createNote($this->enmlContent);
        $this->createNote();
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
	
	// This is used to get the file formats supported to export. 
	public function supported_formats() {
        return array(/*PORTFOLIO_FORMAT_RICHHTML, */PORTFOLIO_FORMAT_PLAINHTML);
    }
	
	
	/* 
	Use this function to initialize the oauth, get the temporary credentials and to get the token/permission from the user
	I guess we would be using stage 1 over here and carry out the next steps of the authentication in post_control function.
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
		
		//redirect to the give permissions page or the login page
		if($this->client)
		{
			$redirecturl = $this->getRedirectUrl();
			redirect($redirecturl);
		}
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
		
		//second stage of authentication
		//Checking if the user has authenticated the application to get access to the evernote account
		if (isset($_GET['oauth_verifier'])) {
            $this->oauthVerifier = $_GET['oauth_verifier'];
        } else {
            // If the User clicks "decline" instead of "authorize", no verification code is sent
			throw new portfolio_plugin_exception('noauthfromuser', 'portfolio_evernote');
        }
		
		//third and final step for oauth authentication.
		//Storing the access credentials for the user
		$this->getTokenCredentials();
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
				$_SESSION['evernoteoauth'] = $tempOauthToken['oauth_token'];
				$_SESSION['evernotesecret'] = $tempOauthToken['oauth_token_secret'];
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
    private function getRedirectUrl()
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
	
	private function getTokenCredentials()
    {
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
            $accessTokenInfo = $this->client->getAccessToken($_SESSION['evernoteoauth'], $_SESSION['evernotesecret'], $this->oauthVerifier);
            if ($accessTokenInfo) {
                $this->oauthtoken = $accessTokenInfo['oauth_token'];
            } else {
                //throw failed operation exception.
				throw new portfolio_plugin_exception('failedtoken', 'portfolio_evernote');
            }
        } 
		else {
			//throw invalid credentials exception.
			throw new portfolio_plugin_exception('improperkey', 'portfolio_evernote');
		}
		unset($_SESSION['evernoteoauth']);
		unset($_SESSION['evernotesecret']);
    }
	
	function createNote() {
        $accessToken = $this->oauthtoken;
		$sandboxflag = true; //change the flag to false for production
        $client = new Client(array(
            'token' => $accessToken,
            'sandbox' => $sandboxflag
        ));
		if($client) {
			$note = new Note();
			$note->title = $this->get_export_config('notetitle');
			$note->content = $this->enmlContent;
			$note->notebookGuid = $this->get_export_config('notebooks');
            $notebooks = $client->getNoteStore()->createNote($note);
        } else {
			//throw invalid credentials exception.
			throw new portfolio_plugin_exception('errorcreatingnotebook', 'portfolio_evernote');
		}
    }
	
	function listNotebooks() {
        $accessToken = $this->oauthtoken;
		$sandboxflag = true; //change the flag to false for production
        $client = new Client(array(
            'token' => $accessToken,
            'sandbox' => $sandboxflag
        ));
		if($client) {
			$notebooks = $client->getNoteStore()->listNotebooks();
			$this->notebook_array = array();
			if (!empty($notebooks)) {
                foreach ($notebooks as $notebook) {                    
					if($notebook->defaultNotebook) {
						$this->default_notebook_guid = $notebook->guid;
						$this->notebook_array[$notebook->guid] = '(Default) '.$notebook->name;
					}
					else {
						$this->notebook_array[$notebook->guid] = $notebook->name;
					}
                }
            }
        } else {
			//throw invalid credentials exception.
			throw new portfolio_plugin_exception('errorlistingnotebook', 'portfolio_evernote');
		}
    }
}
 
function getenml($htmlcontents) {
$htmlcontents = strip_tags($htmlcontents, '<a><abbr><acronym><address><area><b><bdo><big><blockquote><br><caption><center><cite><code><col><colgroup><dd><del><dfn><div><dl><dt><em><font><h1><h2><h3><h4><h5><h6><hr><i><img><ins><kbd><li><map><ol><p><pre><q><s><samp><small><span><strike><strong><sub><sup><table><tbody><td><tfoot><th><thead><title><tr><tt><u><ul><var><xmp>');
//removing the disallowed attributes
//$htmlcontents = preg_replace('/(<[^>]+) class=".*?"/i', '$1', $htmlcontents);
$htmlcontents = '<body>'.$htmlcontents.'</body>';
$dom = new DOMDocument();
$dom->loadHTML($htmlcontents);

$elements = $dom->getElementsByTagName('body')->item(0);
//echo get_inner_html($elements->firstChild);
$elements = reform_style_attribute($elements);
$htmlcontents = get_inner_html($elements);

$enmlcontents = '<?xml version="1.0" encoding="UTF-8"?>' .
    '<!DOCTYPE en-note SYSTEM "http://xml.evernote.com/pub/enml2.dtd">' .
    '<en-note>'.$htmlcontents.'</en-note>';
return $enmlcontents;
}

function get_inner_html( $node ) {
    $innerHTML= '';
	if($node->childNodes!==NULL)
	{
		$children = $node->childNodes;
		foreach ($children as $child) {
			$innerHTML .= $child->ownerDocument->saveXML( $child );
		}
	}
    return $innerHTML;
}
function reform_style_attribute ($elements) {
	if($elements->childNodes!==NULL)
	{
		$numchildnodes = $elements->childNodes->length;
		for($i=0;$i<$numchildnodes;$i++) {
			$element = reform_style_attribute($elements->childNodes->item(0));
			$elements->removeChild($elements->childNodes->item(0));
			$elements->appendChild($element);
		}
		if($elements->hasAttribute('class'))
		{
			$elements->removeAttribute('class');
		}
		if($elements->hasAttribute('id'))
		{
			$elements->removeAttribute('id');
		}
		if($elements->hasAttribute('onclick'))
		{
			$elements->removeAttribute('onclick');
		}
		if($elements->hasAttribute('ondblclick'))
		{
			$elements->removeAttribute('ondblclick');
		}
		if($elements->hasAttribute('accesskey'))
		{
			$elements->removeAttribute('accesskey');
		}
		if($elements->hasAttribute('data'))
		{
			$elements->removeAttribute('data');
		}
		if($elements->hasAttribute('dynsrc'))
		{
			$elements->removeAttribute('dynsrc');
		}
		if($elements->hasAttribute('tabindex'))
		{
			$elements->removeAttribute('tabindex');
		}
		if($elements->hasAttribute('style'))
		{
			$styleString = $elements->getAttribute('style');
			$elements->removeAttribute('style');
			$styleString = preg_replace('/\s+/', '', $styleString);
			$individualpropertiesarray = explode(';',$styleString);
			$property = array();
			$value = array();
			foreach($individualpropertiesarray as $individual)
			{
				$array = array();
				$array = explode(':',$individual);
				$property[] = $array[0];
				if (array_key_exists(1, $array))
					$value[] = $array[1];
				else
					$value[] = '';
			}
			$toBeAdded = '';
			$endString = '';
			if(in_array("color", $property) || in_array("font-family", $property) || in_array("font-style", $property) || in_array("font-weight", $property) || in_array("text-decoration", $property) || in_array("vertical-align", $property))
			{
				$toBeAdded .= '<font ';
				$endString .= '';
				$fontfamilyKey = array_search('font-family', $property);
				$colorKey = array_search('color', $property);
				$fontstyleKey = array_search('font-style', $property);
				$fontweightKey = array_search('font-weight', $property);
				$textdecorationKey = array_search('text-decoration', $property);
				$verticalalignKey = array_search('vertical-align', $property);
				if($fontfamilyKey!==false)
					$toBeAdded .= ' face ="' . str_replace("'","\\'",$value[$fontfamilyKey]).'"';
				if($colorKey!==false)
					$toBeAdded .= ' color ="' . $value[$colorKey] . '"';
				$toBeAdded .= '>';
				if($fontstyleKey!==false && ($value[$fontstyleKey]=="italic" || $value[$fontstyleKey]=="oblique")) {
					$toBeAdded .= '<i>';
					$endString .= '</i>';
				}
				if($fontweightKey!==false && $value[$fontstyleKey]=="bold") {
					$toBeAdded .= '<b>';
					$endString .= '</b>';
				}
				if($textdecorationKey!==false)
				{
					if($value[$textdecorationKey]=="underline") {
						$toBeAdded .= '<u>';
						$endString .= '</u>';
					}
					else if($value[$textdecorationKey]=="line-through") {
						$toBeAdded .= '<strike>';
						$endString .= '</strike>';
					}
				}
				if($verticalalignKey!==false)
				{
					if($value[$verticalalignKey]=="super") {
						$toBeAdded .= '<sup>';
						$endString .= '</sup>';
					}
					else if($value[$verticalalignKey]=="sub") {
						$toBeAdded .= '<sub>';
						$endString .= '</sub>';
					}
				}
				$endString .= '</font>';
			}
			$innerhtml = get_inner_html( $elements );
			$nochildnodes = $elements->childNodes->length;
			for ($x=0; $x<$nochildnodes; $x++) {
				$elements->removeChild($elements->childNodes->item(0));
			}
			$f = $elements->ownerDocument->createDocumentFragment();
					// appendXML() expects well-formed markup (XHTML)
			$result = $f->appendXML($toBeAdded.$innerhtml.$endString);
			if ($f->hasChildNodes()) $elements->appendChild($f);
		}
	}
	return $elements;
}