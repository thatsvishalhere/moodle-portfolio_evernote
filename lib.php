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
 * Portfolio to access and add Evernote content.
 *
 * @package    portfolio
 * @subpackage evernote
 * @copyright  2013 Vishal Raheja
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/portfolio/plugin.php');
require_once($CFG->libdir . '/oauthlib.php');
require_once(dirname(__FILE__).'/lib/Evernote/Client.php');
require_once(dirname(__FILE__).'/lib/packages/Types/Types_types.php');
if (!isset($GLOBALS['THRIFT_ROOT'])) {
    $GLOBALS['THRIFT_ROOT'] = dirname(__FILE__).'/lib';
}

// Import the classes that we're going to be using.
use EDAM\Error\EDAMSystemException,
    EDAM\Error\EDAMUserException,
    EDAM\Error\EDAMErrorCode,
    EDAM\Error\EDAMNotFoundException;

// To use the main Evernote API interface.
use Evernote\Client;

// Import Note class.
use EDAM\Types\Note;
use EDAM\Types\NoteAttributes;
use EDAM\NoteStore\NoteStoreClient;

/**
 * Portfolio class to access/add notes to the Evernote accounts.
 *
 * This class uses the Evernote API in the library to access and then
 * Add to the Evernote accounts of the users after modifying the contents for the
 * Evernote Markup Language.
 *
 * @package    portfolio_evernote
 * @category   portfolio
 * @copyright  2013 Vishal Raheja
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class portfolio_plugin_evernote extends portfolio_plugin_push_base {

    /**
     * Token received from Evernote after checking the user credentials and the permissions by the user.
     * @var string
     */
    private $oauthtoken = null;

    /**
     * To create a new client which would contact the Evernote.
     * @var Client Object
     */
    private $client = null;

    /**
     * Token after the checking the config credentials provided by the administrator.
     * @var string
     */
    private $request_token = null;

    /**
     * The evernote markup language content that is to be shown in the notebook.
     * @var string
     */
    private $enml_content;

    /**
     * The verifier string if the user allows the application to access his evernote account.
     * @var string
     */
    private $oauth_verifier;

    /**
     * Notebooks in the Evernote account of the user.
     * @var array
     */
    private $notebook_array;

    /**
     * The user's default notebook guid.
     * @var string
     */
    private $default_notebook_guid;

    /**
     * Sandbox flag. Turn it to false for production.
     * @var boolean
     */
    private $sandboxflag = true;

    public static function allows_multiple_exports() {
        // Since this plugin needs authentication from external sources, we have to disable this.
        return false;
    }

    public static function get_name() {
        return get_string('pluginname', 'portfolio_evernote');
    }

    public function has_export_config() {
        // To ensure that the user configures before exporting the package.
        return true;
    }

    public function export_config_form(&$mform) {
        $mform->addElement('text', 'plugin_notetitle', get_string('customnotetitlelabel', 'portfolio_evernote'));
        $mform->setDefault('plugin_notetitle', get_string('defaultnotetitle', 'portfolio_evernote'));
        $this->list_notebooks();
        $notebookselect = $mform->addElement('select', 'plugin_notebooks', 'Select Notebook', $this->notebook_array);
        $notebookselect->setSelected($this->default_notebook_guid);
        $strrequired = get_string('required');
        $mform->addRule('plugin_notetitle', $strrequired, 'required', null, 'client');
        $mform->addRule('plugin_notebooks', $strrequired, 'required', null, 'client');
    }

    public function get_export_summary() {
        return array('Note Title'=>$this->get_export_config('notetitle'),
            'Notebook'=>$this->notebook_array[$this->get_export_config('notebooks')]);
    }

    public function get_allowed_export_config() {
        return array('notetitle', 'notebooks');
    }

    public function prepare_package() {
        $files = $this->exporter->get_tempfiles();
        $writefile = "write.txt";
        $current = file_get_contents($writefile);
        foreach ($files as $file) {
            // echo $file->get_filename()."<br />";
            // Get the enml of only those files which have been modified content of the current page and converted into html.
            if ($file->get_filepath() == "/") {
                $htmlcontents = $file->get_content();
                /*$myFile = "write.html";
                $fh = fopen($myFile, 'w');*/
                $this->enml_content = $this->getenml($htmlcontents);
                /*fwrite($fh, $this->enml_content);
                //fwrite($fh, $htmlcontents);
                fclose($fh);*/
            }
        }
    }

    public function send_package() {
        // $this->create_note($this->enml_content);
        $this->create_note();
        return true;
    }

    // Unsure about it.
    public function get_interactive_continue_url() {
        return false;
    }

    public function expected_time($callertime) {
        // We trust what the portfolio says.
        return $callertime;
    }

    public static function has_admin_config() {
        return true;
    }

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

    public static function get_allowed_config() {
        return array('consumerkey', 'secret');
    }

    public static function allows_multiple_instances() {
        return false;
    }

    public function supported_formats() {
        return array(/*PORTFOLIO_FORMAT_RICHHTML, */PORTFOLIO_FORMAT_PLAINHTML);
    }

    public function steal_control($stage) {
        global $CFG;
        if ($stage != PORTFOLIO_STAGE_CONFIG) {
            return false;
        }

        // First stage of oauth from the user configuration.
        $this->get_token_from_config();

        // Redirect to the give permissions page or the login page.
        if ($this->client) {
            $redirecturl = $this->get_redirect_url();
            redirect($redirecturl);
        }
    }

    public function post_control($stage, $params) {
        if ($stage != PORTFOLIO_STAGE_CONFIG) {
            return;
        }

        // Second stage of authentication.
        // Checking if the user has authenticated the application to get access to the evernote account.
        if (isset($_GET['oauth_verifier'])) {
            $this->oauth_verifier = $_GET['oauth_verifier'];
        } else {
            // If the User clicks "decline" instead of "authorize", no verification code is sent.
            throw new portfolio_plugin_exception('noauthfromuser', 'portfolio_evernote');
        }

        // Third and final step for oauth authentication.
        // Storing the access credentials for the user.
        $this->get_token_credentials();
    }

    public function instance_sanity_check() {
        $consumerkey = $this->get_config('consumerkey');
        $secret = $this->get_config('secret');

        // If there is no oauth config
        // there will be no config and this plugin should be disabled.
        if (empty($consumerkey) or empty($secret)) {
            return 'nooauthcredentials';
        }
        return 0;
    }

    /**
     * To get the token by verifying the Evernote API Consumer key and Secret
     */
    private function get_token_from_config() {
        $consumerkey = $this->get_config('consumerkey');
        $secret = $this->get_config('secret');
        $this->client = new Client(array(
                'consumerKey' => $consumerkey,
                'consumerSecret' => $secret,
                'sandbox' => $this->sandboxflag
            ));
        if ($this->client) {
            $temp_oauth_token = $this->client->getRequestToken($this->get_callback_url());
            if ($temp_oauth_token) {
                $this->request_token = new StdClass;
                $this->request_token->oauth = $temp_oauth_token['oauth_token'];
                $this->request_token->secret = $temp_oauth_token['oauth_token_secret'];
                $_SESSION['evernoteoauth'] = $temp_oauth_token['oauth_token'];
                $_SESSION['evernotesecret'] = $temp_oauth_token['oauth_token_secret'];
            } else {
                // Throw failed operation exception.
                throw new portfolio_plugin_exception('failedtoken', 'portfolio_evernote');
            }
        } else {
            // Throw invalid credentials exception.
                throw new portfolio_plugin_exception('improperkey', 'portfolio_evernote');
        }

    }

    /**
     * Get the URL of this application. This URL is passed to the server (Evernote)
     * while obtaining unauthorized temporary credentials (step 1). The resource owner
     * is redirected to this URL after authorizing the temporary credentials (step 2).
     * 
     * @return string
     */
    private function get_callback_url() {
        global $CFG;
        return $CFG->wwwroot . '/portfolio/add.php?postcontrol=1&type=evernote';
    }

    /**
     * Get the Evernote URL from where to get the permissions from the user.
     * 
     * @return string
     */
    private function get_redirect_url() {
        $consumerkey = $this->get_config('consumerkey');
        $secret = $this->get_config('secret');
        $new_client = new Client(array(
                'consumerKey' => $consumerkey,
                'consumerSecret' => $secret,
                'sandbox' => $this->sandboxflag
            ));

        return $new_client->getAuthorizeUrl($this->request_token->oauth);
    }

    /**
     * To get the token for the user after the user has granted access to his Evernote account.
     */
    private function get_token_credentials() {
        $consumerkey = $this->get_config('consumerkey');
        $secret = $this->get_config('secret');
        $this->client = new Client(array(
                'consumerKey' => $consumerkey,
                'consumerSecret' => $secret,
                'sandbox' => $this->sandboxflag
            ));
        if ($this->client) {
            $access_token_info = $this->client->getAccessToken($_SESSION['evernoteoauth'],
                $_SESSION['evernotesecret'], $this->oauth_verifier);
            if ($access_token_info) {
                $this->oauthtoken = $access_token_info['oauth_token'];
            } else {
                // Throw failed operation exception.
                throw new portfolio_plugin_exception('failedtoken', 'portfolio_evernote');
            }
        } else {
            // Throw invalid credentials exception.
            throw new portfolio_plugin_exception('improperkey', 'portfolio_evernote');
        }
        unset($_SESSION['evernoteoauth']);
        unset($_SESSION['evernotesecret']);
    }

    /**
     * Create the note from the class variable of enml content.
     */
    private function create_note() {
        $access_token = $this->oauthtoken;
        $client = new Client(array(
            'token' => $access_token,
            'sandbox' => $this->sandboxflag
        ));
        if ($client) {
            $note = new Note();
            $note->title = $this->get_export_config('notetitle');
            $note->content = $this->enml_content;
            $note->notebookGuid = $this->get_export_config('notebooks');
            $notebooks = $client->getNoteStore()->createNote($note);
        } else {
            // Throw invalid credentials exception.
            throw new portfolio_plugin_exception('errorcreatingnotebook', 'portfolio_evernote');
        }
    }

    /**
     * To get the list of the user's notebooks.
     */
    private function list_notebooks() {
        $access_token = $this->oauthtoken;
        $client = new Client(array(
            'token' => $access_token,
            'sandbox' => $this->sandboxflag
        ));
        if ($client) {
            $notebooks = $client->getNoteStore()->listNotebooks();
            $this->notebook_array = array();
            if (!empty($notebooks)) {
                foreach ($notebooks as $notebook) {
                    if ($notebook->defaultNotebook) {
                        $this->default_notebook_guid = $notebook->guid;
                        $this->notebook_array[$notebook->guid] = '(Default) '.$notebook->name;
                    } else {
                        $this->notebook_array[$notebook->guid] = $notebook->name;
                    }
                }
            }
        } else {
            // Throw invalid credentials exception.
            throw new portfolio_plugin_exception('errorlistingnotebook', 'portfolio_evernote');
        }
    }

    /**
     * To convert HTML content into ENML content.
     *
     * @param string $htmlcontents HTML string that is needed to convert to ENML.
     * @return string  ENML string of the given HTML string.
     */
    private function getenml($htmlcontents) {
        $htmlcontents = strip_tags($htmlcontents, '<a><abbr><acronym><address><area><b><bdo><big><blockquote><br><caption>'.
            '<center><cite><code><col><colgroup><dd><del><dfn><div><dl><dt><em><font><h1><h2><h3><h4><h5><h6><hr><i><img><ins>'.
            '<kbd><li><map><ol><p><pre><q><s><samp><small><span><strike><strong><sub><sup><table><tbody><td><tfoot><th><thead>'.
            '<title><tr><tt><u><ul><var><xmp>');
        // Removing the disallowed attributes.
        $htmlcontents = '<body>'.$htmlcontents.'</body>';
        $dom = new DOMDocument();
        $dom->loadHTML($htmlcontents);
        $elements = $dom->getElementsByTagName('body')->item(0);
        $elements = $this->reform_style_attribute($elements);
        $htmlcontents = $this->get_inner_html($elements);
        $enmlcontents = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<!DOCTYPE en-note SYSTEM "http://xml.evernote.com/pub/enml2.dtd">' .
            '<en-note>'.$htmlcontents.'</en-note>';
        return $enmlcontents;
    }

    /**
     * Subsidiary function to get the inner html of the DOM element.
     *
     * @param DOM element $node for which we need the inner html.
     * @return string of the inner html of the DOM element given.
     */
    private function get_inner_html( $node ) {
        $inner_html= '';
        if ($node->childNodes!==null) {
            $children = $node->childNodes;
            foreach ($children as $child) {
                $inner_html .= $child->ownerDocument->saveXML( $child );
            }
        }
        return $inner_html;
    }

    /**
     * Subsidiary function to remove all the unsupported attributes but to retain some font attributes from the style attribute.
     *
     * @param DOM element $elements
     * @return DOM element without the unsupported attributes and the necessary font effects retained by modifying the inner html.
     */
    private function reform_style_attribute ($elements) {
        if ($elements->childNodes!==null) {
            $numchildnodes = $elements->childNodes->length;
            for ($i=0; $i<$numchildnodes; $i++) {
                $element = $this->reform_style_attribute($elements->childNodes->item(0));
                $elements->removeChild($elements->childNodes->item(0));
                $elements->appendChild($element);
            }
            if ($elements->hasAttribute('class')) {
                $elements->removeAttribute('class');
            }
            if ($elements->hasAttribute('id')) {
                $elements->removeAttribute('id');
            }
            if ($elements->hasAttribute('onclick')) {
                $elements->removeAttribute('onclick');
            }
            if ($elements->hasAttribute('ondblclick')) {
                $elements->removeAttribute('ondblclick');
            }
            if ($elements->hasAttribute('accesskey')) {
                $elements->removeAttribute('accesskey');
            }
            if ($elements->hasAttribute('data')) {
                $elements->removeAttribute('data');
            }
            if ($elements->hasAttribute('dynsrc')) {
                $elements->removeAttribute('dynsrc');
            }
            if ($elements->hasAttribute('tabindex')) {
                $elements->removeAttribute('tabindex');
            }
            if ($elements->hasAttribute('style')) {
                $style_string = $elements->getAttribute('style');
                $elements->removeAttribute('style');
                $style_string = preg_replace('/\s+/', '', $style_string);
                $individualpropertiesarray = explode(';', $style_string);
                $property = array();
                $value = array();
                foreach ($individualpropertiesarray as $individual) {
                    $array = array();
                    $array = explode(':', $individual);
                    $property[] = $array[0];
                    if (array_key_exists(1, $array)) {
                        $value[] = $array[1];
                    } else {
                        $value[] = '';
                    }
                }
                $to_be_added = '';
                $end_string = '';
                if (in_array("color", $property) || in_array("font-family", $property) || in_array("font-style", $property) ||
                    in_array("font-weight", $property) || in_array("text-decoration", $property) ||
                    in_array("vertical-align", $property)) {
                    $to_be_added .= '<font ';
                    $end_string .= '';
                    $fontfamily_key = array_search('font-family', $property);
                    $color_key = array_search('color', $property);
                    $font_style_key = array_search('font-style', $property);
                    $fontweight_key = array_search('font-weight', $property);
                    $textdecoration_key = array_search('text-decoration', $property);
                    $verticalalign_key = array_search('vertical-align', $property);
                    if ($fontfamily_key!==false) {
                        $to_be_added .= ' face ="' . str_replace("'", "\\'", $value[$fontfamily_key]).'"';
                    }
                    if ($color_key!==false) {
                        $to_be_added .= ' color ="' . $value[$color_key] . '"';
                    }
                    $to_be_added .= '>';
                    if ($font_style_key!==false && ($value[$font_style_key]=="italic" || $value[$font_style_key]=="oblique")) {
                        $to_be_added .= '<i>';
                        $end_string .= '</i>';
                    }
                    if ($fontweight_key!==false && $value[$font_style_key]=="bold") {
                        $to_be_added .= '<b>';
                        $end_string .= '</b>';
                    }
                    if ($textdecoration_key!==false) {
                        if ($value[$textdecoration_key]=="underline") {
                            $to_be_added .= '<u>';
                            $end_string .= '</u>';
                        } else if ($value[$textdecoration_key]=="line-through") {
                            $to_be_added .= '<strike>';
                            $end_string .= '</strike>';
                        }
                    }
                    if ($verticalalign_key!==false) {
                        if ($value[$verticalalign_key]=="super") {
                            $to_be_added .= '<sup>';
                            $end_string .= '</sup>';
                        } else if ($value[$verticalalign_key]=="sub") {
                            $to_be_added .= '<sub>';
                            $end_string .= '</sub>';
                        }
                    }
                    $end_string .= '</font>';
                }
                $innerhtml = $this->get_inner_html( $elements );
                $nochildnodes = $elements->childNodes->length;
                for ($x=0; $x<$nochildnodes; $x++) {
                    $elements->removeChild($elements->childNodes->item(0));
                }
                $f = $elements->ownerDocument->createDocumentFragment();
                $result = $f->appendXML($to_be_added.$innerhtml.$end_string);
                if ($f->hasChildNodes()) {
                    $elements->appendChild($f);
                }
            }
        }
        return $elements;
    }
}